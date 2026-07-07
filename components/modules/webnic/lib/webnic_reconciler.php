<?php
/**
 * WebNIC async reconciler (WN-3-3).
 *
 * The async-correctness engine that consumes the `registrar_pending` state the
 * WN-3-2 saga produces. The `reconcile_orders` cron task (registered in WN-1-1)
 * dispatches here; this model claims a bounded batch of pending `webnic_orders`
 * via a timestamp-CAS lease (INV-6/AR8 — no `forUpdate()`, no DB transaction
 * across HTTP), polls each domain's registry record by-domain (`info`, never a
 * blind Register resubmit — INV-5), and finalises it to `active`/`failed` or
 * reschedules it with exponential backoff until a 30-day give-up.
 *
 * The single concurrency primitive is the lease claim: a 0-row claim/advance means
 * another worker won — bail, never double-process. `state` only ever moves through
 * Webnic\Orders::transition() (INV-3); the poll/lock/give-up columns are non-state
 * writes this model owns (the WN-3-1 scope fence). Every time-dependent value comes
 * from an injected clock so the scheduling math stays deterministic and testable;
 * the pure scheduling/classification helpers carry no I/O, DB, or time() at all.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
namespace Webnic;

/**
 * Owns the `reconcile_orders` poll loop: claim, poll, advance/reschedule, give up.
 */
class Reconciler
{
    /**
     * @var int Default bound on rows claimed per module row per run (AC1).
     */
    public const DEFAULT_BATCH = 25;

    /**
     * @var int Default per-run wall-clock budget in seconds; the reconciler stops
     *  STARTING new HTTP polls past this so it never starves other cron tasks (AC1).
     */
    public const DEFAULT_RUN_BUDGET_SECONDS = 50;

    /**
     * @var int Default claim lease in seconds (~ read_timeout·(retries+1)+margin).
     *  A crashed worker self-heals when its lease expires and the row is reclaimed.
     */
    public const DEFAULT_LEASE_SECONDS = 120;

    /**
     * @var int The give-up horizon in days (AC5/B5 — a configured default, not a
     *  hard constant). Measured from the order's `created` (when the customer was
     *  charged), not from first reconcile.
     */
    public const GIVE_UP_DAYS = 30;

    /**
     * @var int Backoff base in seconds (AR14): delay = min(MAX, BASE·2^attempt).
     */
    public const BASE_BACKOFF_SECONDS = 60;

    /**
     * @var int Backoff ceiling in seconds (AR14).
     */
    public const MAX_BACKOFF_SECONDS = 3600;

    /**
     * @var float Jitter band (±10 %) applied to the backoff delay (AC4).
     */
    public const JITTER_FRACTION = 0.10;

    /**
     * @var string INV-9 level for normal transitions.
     */
    private const LEVEL_INFO = 'info';

    /**
     * @var string INV-9 level for retryable/indeterminate outcomes.
     */
    private const LEVEL_NOTICE = 'notice';

    /**
     * @var string INV-9 level for terminal/give-up outcomes.
     */
    private const LEVEL_ERROR = 'error';

    /**
     * @var array The claimable states (Dev Notes §D). `registrar_pending` is the
     *  core async state. `submitted` is also claimed: a Register that timed out
     *  between transition(submitted) and parsing leaves an order stuck in
     *  `submitted` with the registration POSSIBLY placed, and addService may never
     *  re-run — reconcile-by-domain (`info`) resolves it money-safely (it reports
     *  whether the domain registered without ever re-issuing Register, INV-5).
     *  Interior states the live saga still owns within one addService call are NOT
     *  claimable.
     */
    public const CLAIMABLE_STATES = [Orders::STATE_REGISTRAR_PENDING, Orders::STATE_SUBMITTED];

    /**
     * @var callable|null INV-9 structured-log sink (scrubs + writes); null => silent.
     */
    private $logger;

    /**
     * @var callable|null Operator push-alert sink (AC3): function(array $alert): void.
     *  Fired AT THE MOMENT a terminal `failed` transition is applied (definitive
     *  registry-fail or 30-day give-up). Injected like the logger so lib/ stays
     *  unit-testable and never hard-wires Blesta Emails; null => no push (silent).
     */
    private $alerter;

    /**
     * @var callable|null Client confirmation-notice sink (AC7): function(array $notice): void.
     *  Fired when a register order reaches `active` IN THE RECONCILER (the async
     *  pending->active path only — the synchronous addService active path never runs
     *  here). Injected like the logger/alerter so lib/ stays unit-testable.
     */
    private $confirmer;

    /**
     * @var callable Returns the current time as a unix timestamp (injected so the
     *  backoff/give-up math is deterministic under test — the only time() boundary).
     */
    private $clock;

    /**
     * @var Orders The single-writer state machine (transition() is the only `state` mover).
     */
    private $orders;

    /**
     * @var string Per-run observability id stamped onto every INV-9 record.
     */
    private $run_id;

    /**
     * @var string Per-run lease owner nonce; makes the claimed-row re-select unambiguous.
     */
    private $nonce;

    /**
     * Initializes the Record-backed reconciler.
     *
     * These four parameters are positionally adjacent, same-type (callable) sinks, so a
     * positional call is easy to transpose. Callers SHOULD pass them by name
     * (logger:/clock:/alerter:/confirmer:) — see Webnic::reconcileOrders().
     *
     * @param callable|null $logger INV-9 sink: function(array $record): void
     * @param callable|null $clock Time provider: function(): int (unix seconds)
     * @param callable|null $alerter Operator push-alert sink: function(array $alert): void
     * @param callable|null $confirmer Client confirmation-notice sink: function(array $notice): void
     */
    public function __construct(
        callable $logger = null,
        callable $clock = null,
        callable $alerter = null,
        callable $confirmer = null
    ) {
        \Loader::loadComponents($this, ['Record']);
        $this->logger = $logger;
        $this->clock = $clock ?: function () {
            return time();
        };
        $this->alerter = $alerter;
        $this->confirmer = $confirmer;
        $this->orders = new Orders();
        $this->run_id = uniqid('wnr_', true);
        $this->nonce = substr(md5($this->run_id), 0, 12);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Pure scheduling / classification helpers (AC2/AC4/AC5) — no I/O, DB, time().
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Computes the exponential backoff delay for an attempt count (AR14).
     *
     * delay = min(MAX_BACKOFF_SECONDS, BASE_BACKOFF_SECONDS · 2^attempt). Negative
     * attempts clamp to 0; large attempts saturate at the ceiling without overflow.
     *
     * @param int $attempt The zero-based attempt count
     * @return int The base backoff delay in seconds (before jitter)
     */
    public static function backoffSeconds(int $attempt): int
    {
        if ($attempt < 0) {
            $attempt = 0;
        }

        // 60·2^6 = 3840 > 3600, so every attempt >= 6 saturates; the early return
        // also guards against 1 << $attempt overflowing for large attempt counts.
        if ($attempt >= 6) {
            return self::MAX_BACKOFF_SECONDS;
        }

        return (int) min(self::MAX_BACKOFF_SECONDS, self::BASE_BACKOFF_SECONDS * (1 << $attempt));
    }

    /**
     * Applies a signed jitter factor to a delay (pure; randomness lives upstream).
     *
     * Keeping jitter in a parameterized pure function lets the truth table pin the
     * math while the impure caller supplies the random factor (AC4 / Dev Notes §K).
     *
     * @param int $seconds The base delay
     * @param float $factor The signed jitter fraction (e.g. -0.07 for -7 %)
     * @return int The jittered delay, never negative
     */
    public static function applyJitter(int $seconds, float $factor): int
    {
        $jittered = (int) round($seconds * (1.0 + $factor));

        return max(0, $jittered);
    }

    /**
     * Computes the base next-poll timestamp (no jitter) for an attempt (AC4).
     *
     * Deterministic given the injected `$now`; jitter is layered in the impure
     * reschedule path so this stays unit-testable.
     *
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @param int $attempt The zero-based attempt count
     * @return string The next-poll UTC `Y-m-d H:i:s`
     */
    public static function nextPollAt(string $now, int $attempt): string
    {
        return self::addSeconds($now, self::backoffSeconds($attempt));
    }

    /**
     * Computes the give-up deadline (AC5): created/now + GIVE_UP_DAYS days.
     *
     * @param string $created_or_now The seed UTC `Y-m-d H:i:s` (prefer the order's created)
     * @return string The give-up UTC `Y-m-d H:i:s`
     */
    public static function giveUpAt(string $created_or_now): string
    {
        return self::addSeconds($created_or_now, self::GIVE_UP_DAYS * 86400);
    }

    /**
     * Decides whether the give-up deadline has been reached (AC5).
     *
     * A null/blank or unparseable deadline is NEVER a give-up — an order is only
     * terminated by a deadline we can actually read (fail-safe, money-safe).
     *
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @param string|null $give_up_at The give-up deadline, if stamped
     * @return bool True iff now >= give_up_at
     */
    public static function isGivenUp(string $now, ?string $give_up_at): bool
    {
        if ($give_up_at === null || trim($give_up_at) === '') {
            return false;
        }

        $now_ts = self::epoch($now);
        $deadline_ts = self::epoch($give_up_at);
        if ($now_ts === null || $deadline_ts === null) {
            return false;
        }

        return $now_ts >= $deadline_ts;
    }

    /**
     * Claim-eligibility predicate mirroring the CAS claim WHERE clause (AC1).
     *
     * @param string $state The order state
     * @param string|null $next_poll_at The scheduled next poll, if any
     * @param string|null $locked_until The active lease, if any
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @param string $operation The order operation discriminator
     * @return bool True iff the row is claimable now
     */
    public static function isClaimable(
        string $state,
        ?string $next_poll_at,
        ?string $locked_until,
        string $now,
        string $operation = Orders::OPERATION_REGISTER
    ): bool {
        // WN-4-1: the reconciler claims BOTH register and transfer rows (an unknown
        // operation is still rejected — never poll a row we have no resolver for).
        if (!in_array($operation, Orders::OPERATIONS, true)) {
            return false;
        }

        if (!in_array($state, self::CLAIMABLE_STATES, true)) {
            return false;
        }

        $now_ts = self::epoch($now);
        if ($now_ts === null) {
            return false;
        }

        if ($next_poll_at !== null && trim($next_poll_at) !== '') {
            $np = self::epoch($next_poll_at);
            if ($np !== null && $np > $now_ts) {
                return false;
            }
        }

        if ($locked_until !== null && trim($locked_until) !== '') {
            $lu = self::epoch($locked_until);
            if ($lu !== null && $lu >= $now_ts) {
                return false;
            }
        }

        return true;
    }

    /**
     * Classifies a Get Domain Info response into a reconcile action (AC2 / §H).
     *
     * Money-safe, never-flip-on-doubt. Only a definitive signal advances/terminates
     * an order; everything else keeps it pending so a transient failure can never
     * flip a service to a wrong terminal state (NFR5):
     *
     *  - registered + active-alias status  -> 'active'  (definitive; carries dtexpire)
     *  - registered + cancelled-alias       -> 'failed'  (definitively terminal registry status)
     *  - registered + pending/suspended     -> 'pending' (registered but not yet definitive)
     *  - not_registered / indeterminate      -> 'pending' ("not found yet" is in-flight, not failure)
     *
     * Classification reuses the existing pure decision points
     * (WebnicDomains::decideReconcileRegister + WebnicStatus::fromRegistryStatus);
     * no bespoke status handling is re-implemented here.
     *
     * @param \WebnicResponse $resp The Get Domain Info response
     * @return array ['action' => 'active'|'failed'|'pending', 'status' => string|null,
     *  'dtexpire' => string|null]
     */
    public static function classifyInfo(\WebnicResponse $resp): array
    {
        $decision = \WebnicDomains::decideReconcileRegister($resp);

        if ($decision['outcome'] !== 'registered') {
            // not_registered / indeterminate / retryable: a pending order whose
            // domain "isn't there yet" is still in flight — only give_up_at ends it.
            return ['action' => 'pending', 'status' => null, 'dtexpire' => null];
        }

        $data = $resp->data();
        $suspended = is_array($data) ? (bool) ($data['suspended'] ?? false) : false;
        $status = (string) ($decision['status'] ?? '');
        $alias = \WebnicStatus::fromRegistryStatus($status, $suspended);

        if (!$suspended && $alias === \WebnicStatus::ACTIVE) {
            if (!is_string($decision['dtexpire']) || self::toUtc($decision['dtexpire']) === null) {
                return ['action' => 'pending', 'status' => $decision['status'], 'dtexpire' => null];
            }

            return ['action' => 'active', 'status' => $decision['status'], 'dtexpire' => $decision['dtexpire']];
        }

        if ($alias === \WebnicStatus::CANCELLED) {
            return ['action' => 'failed', 'status' => $decision['status'], 'dtexpire' => null];
        }

        // Registered but neither definitively active nor terminal (pending/suspended
        // alias): keep polling until the registry resolves it or give_up_at fires.
        return ['action' => 'pending', 'status' => $decision['status'], 'dtexpire' => null];
    }

    /**
     * Normalizes an inconsistent WebNIC datetime to a UTC `Y-m-d H:i:s` (Dev Notes §K).
     *
     * WebNIC datetime zones vary per endpoint (info dtexpire `Z`; info dtcreate
     * `+08:00`; register dtexpire no-zone). A string carrying its own offset is
     * honored; a bare value is interpreted as UTC. Every result is UTC.
     *
     * @param string $iso The provider datetime string
     * @return string|null The UTC `Y-m-d H:i:s`, or null when unparseable
     */
    public static function toUtc(string $iso): ?string
    {
        $iso = trim($iso);
        if ($iso === '') {
            return null;
        }

        try {
            // When $iso carries an offset (Z / +08:00) the constructor ignores the
            // fallback zone and uses the embedded one; a bare value is read as UTC.
            $dt = new \DateTime($iso, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone('UTC'));

            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Impure run loop (claim -> poll -> advance) — uses the injected clock.
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Reconciles every WebNIC module row's pending orders for a company (AC1/AC8).
     *
     * Resolves the company's WebNIC module rows (decrypted meta), then for each row
     * claims a bounded batch and polls each claimed order outside any transaction,
     * stopping when the per-run wall-clock budget is exhausted.
     *
     * @param int $company_id The company whose rows to reconcile
     * @param array $opts Optional overrides: batch, budget, lease (all ints)
     * @return array Per-run counts: ['rows', 'claimed', 'active', 'failed',
     *  'given_up', 'rescheduled', 'skipped']
     */
    public function run($company_id, array $opts = [])
    {
        $batch = (int) ($opts['batch'] ?? self::DEFAULT_BATCH);
        $budget = (int) ($opts['budget'] ?? self::DEFAULT_RUN_BUDGET_SECONDS);
        $lease = (int) ($opts['lease'] ?? self::DEFAULT_LEASE_SECONDS);

        $totals = [
            'rows' => 0, 'claimed' => 0, 'active' => 0, 'failed' => 0,
            'given_up' => 0, 'rescheduled' => 0, 'skipped' => 0,
        ];

        \Loader::loadModels($this, ['ModuleManager']);
        $modules = $this->ModuleManager->getByClass('webnic', $company_id);
        if (!is_array($modules)) {
            return $totals;
        }

        $started = (float) call_user_func($this->clock);

        foreach ($modules as $module) {
            $rows = $this->ModuleManager->getRows($module->id);
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $totals['rows']++;
                $this->reconcileModuleRow($module->id, $row, $batch, $lease, $started, $budget, $totals);
            }
        }

        return $totals;
    }

    /**
     * Claims and processes one module row's pending orders within the run budget.
     *
     * @param int $module_id The owning module id (for service resolution)
     * @param \stdClass $row The module row (decrypted meta)
     * @param int $batch The claim bound
     * @param int $lease The claim lease in seconds
     * @param float $started The run start timestamp (budget origin)
     * @param int $budget The per-run wall-clock budget in seconds
     * @param array $totals The accumulating per-run counters (by reference)
     */
    private function reconcileModuleRow($module_id, $row, $batch, $lease, $started, $budget, array &$totals): void
    {
        if (((float) call_user_func($this->clock)) - $started >= $budget) {
            return;
        }

        $now = $this->nowString();
        $claimed = $this->claimBatch((int) $row->id, $now, $batch, $lease, $this->worker());
        if (empty($claimed)) {
            return;
        }

        $domains = $this->domainsFor($row);
        $transfers = $this->transfersFor($row);

        foreach ($claimed as $order) {
            // Stop STARTING new polls once the budget is spent; leave the rest claimed
            // rows unlocked for the next run instead of parking them behind a lease.
            if (((float) call_user_func($this->clock)) - $started >= $budget) {
                $this->releaseClaim($order);
                $totals['skipped']++;
                continue;
            }

            $totals['claimed']++;
            try {
                $outcome = $this->processClaimedOrder($order, $domains, $module_id, $this->nowString(), $transfers);
            } catch (\Throwable $e) {
                $this->emit(
                    $order,
                    'exception',
                    $order->state ?? null,
                    null,
                    'indeterminate',
                    get_class($e) . ': ' . $e->getMessage()
                );
                $this->releaseClaim($order);
                $outcome = 'skipped';
            }

            if (isset($totals[$outcome])) {
                $totals[$outcome]++;
            }
        }
    }

    /**
     * Atomically claims a bounded batch of due orders via timestamp-CAS (AC1/§C).
     *
     * The UPDATE is the mutex (InnoDB row-locks for the statement; no held
     * transaction, no HTTP under a lock). MinPHP Record cannot express
     * `ORDER BY … LIMIT` on an UPDATE through its builder, so this is a raw
     * parameterized query (precedent webnic.php:1942); the integer LIMIT is
     * interpolated (a value we control, never request input). Record->reset() runs
     * after the write so MinPHP's retained dirty query-state cannot leak into the
     * re-select (the WN-3-1 race bug fixed in 6e52cee3).
     *
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @param int $batch The claim bound
     * @param int $lease_seconds The claim lease length
     * @param string $worker The lease owner (host:pid:nonce)
     * @return array The rows this worker just claimed (possibly empty)
     */
    public function claimBatch($module_row_id, string $now, int $batch, int $lease_seconds, string $worker): array
    {
        $batch = max(1, $batch);
        $lease_until = self::addSeconds($now, max(1, $lease_seconds));

        $params = [
            'lease_until' => $lease_until,
            'worker' => $worker,
            'row' => $module_row_id,
            'now_poll' => $now,
            'now_lock' => $now,
        ];
        $state_placeholders = [];
        foreach (self::CLAIMABLE_STATES as $i => $state) {
            $key = 'claim_state_' . $i;
            $state_placeholders[] = ':' . $key;
            $params[$key] = $state;
        }
        // WN-4-1: claim register AND transfer rows (the reconciler now drives both
        // operations) — parameterize `operation` to the full known set, not a single value.
        $operation_placeholders = [];
        foreach (Orders::OPERATIONS as $i => $operation) {
            $key = 'claim_op_' . $i;
            $operation_placeholders[] = ':' . $key;
            $params[$key] = $operation;
        }

        $sql = 'UPDATE `webnic_orders`'
            . ' SET `locked_until` = :lease_until, `locked_by` = :worker'
            . ' WHERE `module_row_id` = :row'
            . ' AND `operation` IN (' . implode(', ', $operation_placeholders) . ')'
            . ' AND `state` IN (' . implode(', ', $state_placeholders) . ')'
            . ' AND (`next_poll_at` IS NULL OR `next_poll_at` <= :now_poll)'
            . ' AND (`locked_until` IS NULL OR `locked_until` < :now_lock)'
            . ' ORDER BY `next_poll_at`'
            . ' LIMIT ' . (int) $batch;

        try {
            $this->Record->query($sql, $params);
        } finally {
            // MinPHP retains dirty query state after a (possibly throwing) write.
            $this->Record->reset();
        }

        // Re-select exactly the rows this worker just leased. Scoping by
        // module_row_id + worker + the exact lease_until is unambiguous even if two
        // claims share a hostname:pid:nonce in the same second.
        $claimed = $this->Record->select()
            ->from('webnic_orders')
            ->where('module_row_id', '=', $module_row_id)
            ->where('locked_by', '=', $worker)
            ->where('locked_until', '=', $lease_until)
            ->order(['next_poll_at' => 'asc'])
            ->fetchAll();
        $this->Record->reset();

        return is_array($claimed) ? $claimed : [];
    }

    /**
     * Reconciles one claimed order: first-claim stamping, give-up, then poll/advance.
     *
     * @param \stdClass $order The claimed order row
     * @param mixed $domains A WebnicDomains-shaped collaborator exposing info($domain)
     * @param int $module_id The owning module id (service resolution)
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @param mixed $transfers A WebnicTransfers-shaped collaborator (transferStatus); null for register-only
     * @return string The outcome counter key: active|failed|given_up|rescheduled|skipped
     */
    public function processClaimedOrder($order, $domains, $module_id, string $now, $transfers = null): string
    {
        $order = $this->stampFirstClaimIfNeeded($order, $now);
        if ($order === null) {
            return 'skipped';
        }

        if (self::isGivenUp($now, $order->give_up_at ?? null)) {
            return $this->giveUp($order, $module_id, $now);
        }

        return $this->pollAndAdvance($order, $domains, $module_id, $now, $transfers);
    }

    /**
     * Stamps give_up_at + a first next_poll_at the first time an order is claimed (§E).
     *
     * The WN-3-2 saga writes only `state` into `registrar_pending`; the poll/lock/
     * give-up columns are this story's to own. A just-pending row therefore has
     * give_up_at IS NULL, which is the first-claim signal. give_up_at is seeded from
     * the order's `created` (when the customer was charged) so the 30-day horizon
     * does not slip with reconcile timing.
     *
     * @param \stdClass $order The claimed order row
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @return \stdClass|null The order with give_up_at/next_poll_at reflected locally,
     *  or null if this worker no longer owns the claim
     */
    public function stampFirstClaimIfNeeded($order, string $now): ?\stdClass
    {
        if (isset($order->give_up_at) && $order->give_up_at !== null && trim((string) $order->give_up_at) !== '') {
            return $order;
        }

        $seed = (isset($order->created) && trim((string) $order->created) !== '') ? (string) $order->created : $now;
        if (self::epoch($seed) === null) {
            $seed = $now;
        }
        $give_up_at = self::giveUpAt($seed);

        if (!$this->writeOrderColumns((int) $order->id, (int) $order->module_row_id, [
            'give_up_at' => $give_up_at,
            'next_poll_at' => $now,
        ], $order)) {
            $this->emit($order, 'first-claim', $order->state ?? null, null, 'indeterminate', 'cas_lost');

            return null;
        }

        $order->give_up_at = $give_up_at;
        $order->next_poll_at = $now;

        return $order;
    }

    /**
     * Polls the registry by-domain and advances/reschedules one order (AC2/§H).
     *
     * The `info` read happens outside any transaction (INV-6). A definitive result
     * finalises via transition(); anything transient/indeterminate reschedules with
     * backoff and never terminates the order.
     *
     * @param \stdClass $order The claimed order row
     * @param mixed $domains The WebnicDomains-shaped collaborator
     * @param int $module_id The owning module id
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @param mixed $transfers The WebnicTransfers-shaped collaborator (transfer rows only)
     * @return string The outcome counter key
     */
    private function pollAndAdvance($order, $domains, $module_id, string $now, $transfers = null): string
    {
        // WN-4-1: dispatch the by-domain reconcile strategy via the per-operation resolver
        // seam (Decision #3): register reconciles via Get Domain Info (info?domainName=),
        // transfer via transfer-in/status?domainName= — the branch registrations skip.
        $operation = $order->operation ?? Orders::OPERATION_REGISTER;
        try {
            $reconcile = Orders::resolverFor($operation)['reconcile'] ?? 'info';
        } catch (\Throwable $e) {
            // An unknown operation on a CLAIMED row should never happen (isClaimable rejects unknown
            // ops at claim time). If a data-inconsistency race produces one, do NOT silently poll
            // with the wrong (register) resolver — surface it and reschedule (never-flip; give_up_at
            // still bounds it) so the misclassification is visible, not masked. Round-1 P14b.
            $this->emit($order, 'reconcile/resolver', $order->state, null, 'indeterminate', 'unknown_operation:' . $operation);
            if (!$this->reschedule($order, $this->nowString(), 'indeterminate', 'unknown_operation')) {
                return 'skipped';
            }

            return 'rescheduled';
        }

        if ($reconcile === 'transfer_status') {
            return $this->pollAndAdvanceTransfer($order, $transfers, $domains, $module_id, $now);
        }

        try {
            $resp = $domains->info($order->domain);
        } catch (\Throwable $e) {
            // Transport exception: transient — never a terminal flip for a pending order.
            if (!$this->reschedule($order, $this->nowString(), 'retryable', 'poll_exception')) {
                return 'skipped';
            }

            return 'rescheduled';
        }

        $decision = self::classifyInfo($resp);

        if ($decision['action'] === 'active') {
            return $this->activate($order, $module_id, $decision['dtexpire'], $now);
        }

        if ($decision['action'] === 'failed') {
            return $this->failTerminal($order, $decision['status'], $now);
        }

        $error_class = $resp->success() ? null : ($resp->errorClass() ?: 'indeterminate');
        if ($error_class === 'terminal') {
            $error_class = 'indeterminate';
        }
        if (!$this->reschedule($order, $this->nowString(), $error_class, 'not_yet_resolved')) {
            return 'skipped';
        }

        return 'rescheduled';
    }

    /**
     * Polls transfer-in/status by-domain and advances/reschedules one transfer order (AC3/§L).
     *
     * Transfer's in-flight read is DIRECT (transfer-in/status?domainName=), unlike registration's
     * order/info?id=. Maps the status enum (decideReconcileTransfer): complete -> active (+ expiry
     * read separately via info, since transfer-in/status carries no dtexpire); reject/cancel/
     * insert_fail -> failed; pending/approve/absent/indeterminate -> reschedule (never-flip-on-doubt,
     * NFR5 — only give_up_at ends it). A 'complete' whose expiry is not yet readable keeps polling so
     * an activation always carries a real expiry (mirrors classifyInfo's register discipline).
     *
     * @param \stdClass $order The claimed transfer order row
     * @param mixed $transfers The WebnicTransfers-shaped collaborator
     * @param mixed $domains The WebnicDomains-shaped collaborator (the dtexpire read on complete)
     * @param int $module_id The owning module id
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @return string The outcome counter key
     */
    private function pollAndAdvanceTransfer($order, $transfers, $domains, $module_id, string $now): string
    {
        if ($transfers === null) {
            // No transfer collaborator wired — never flip; keep the order pending.
            if (!$this->reschedule($order, $this->nowString(), 'indeterminate', 'transfer_no_collaborator')) {
                return 'skipped';
            }

            return 'rescheduled';
        }

        try {
            $resp = $transfers->transferStatus($order->domain);
        } catch (\Throwable $e) {
            if (!$this->reschedule($order, $this->nowString(), 'retryable', 'poll_exception')) {
                return 'skipped';
            }

            return 'rescheduled';
        }

        $decision = \WebnicTransfers::decideReconcileTransfer($resp);

        if ($decision['outcome'] === 'active') {
            $dtexpire = $this->readTransferExpiry($order, $domains);
            if ($dtexpire !== null && self::toUtc($dtexpire) !== null) {
                return $this->activate($order, $module_id, $dtexpire, $now);
            }

            // Transfer complete but the registry expiry is not yet readable — keep polling so the
            // activation always carries a real expiry (never activate without one — D1).
            //
            // Round-2 R-F1 (money-safety): the transfer DID complete at the registry, so it must
            // NEVER be auto-cancelled + refund-flagged merely because the ANCILLARY info() expiry
            // read is transiently unreadable. Refresh give_up_at off `now` so the give-up gate cannot
            // fire while the status stays `complete`; the order then polls for the expiry rather than
            // being given up (a transfer that has NOT completed is unaffected — only this branch
            // touches give_up_at). A transfer that only completes AFTER its original 30-day window is
            // still bounded by that window (a >30-day transfer is itself anomalous).
            $extended = self::giveUpAt($this->nowString());
            if ($this->writeOrderColumns((int) $order->id, (int) $order->module_row_id, ['give_up_at' => $extended], $order)) {
                $order->give_up_at = $extended;
            }
            if (!$this->reschedule($order, $this->nowString(), null, 'transfer_complete_awaiting_expiry')) {
                return 'skipped';
            }

            return 'rescheduled';
        }

        if ($decision['outcome'] === 'failed') {
            return $this->failTerminal($order, $decision['status'], $now);
        }

        // pending / approve / absent / indeterminate: keep polling (never-flip-on-doubt).
        $error_class = $resp->success() ? null : ($resp->errorClass() ?: 'indeterminate');
        if ($error_class === 'terminal') {
            // DOM2400 "not found" (= absent / no transfer record yet) classifies terminal but
            // is an in-flight signal here, not a failure — keep polling until give_up_at.
            $error_class = 'indeterminate';
        }
        if (!$this->reschedule($order, $this->nowString(), $error_class, 'transfer_not_yet_resolved')) {
            return 'skipped';
        }

        return 'rescheduled';
    }

    /**
     * Reads the registry expiry for a completed transfer via Get Domain Info (§L).
     *
     * transfer-in/status carries no dtexpire, so finalisation reads it from info(); any
     * failure / unparseable value returns null (the caller keeps polling until it appears).
     *
     * @param \stdClass $order The claimed transfer order row
     * @param mixed $domains The WebnicDomains-shaped collaborator
     * @return string|null The dtexpire ISO string, or null when unavailable
     */
    private function readTransferExpiry($order, $domains): ?string
    {
        if ($domains === null) {
            return null;
        }

        try {
            $resp = $domains->info($order->domain);
            $decision = \WebnicDomains::decideReconcileRegister($resp);

            // Only a registered (active-at-registry) info result carries a trustworthy expiry; a
            // non-active or error decision must not yield a stale dtexpire that would activate the
            // transfer with the wrong date (Round-1 P4). The caller keeps polling on null.
            if (($decision['outcome'] ?? null) !== 'registered') {
                return null;
            }

            $dtexpire = $decision['dtexpire'] ?? null;

            return is_string($dtexpire) ? $dtexpire : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Finalises an order to `active`, writes the registry-accurate expiry (AC3/AC7).
     *
     * @param \stdClass $order The claimed order row
     * @param int $module_id The owning module id
     * @param string|null $dtexpire The observed registry expiry
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @return string The outcome counter key
     */
    private function activate($order, $module_id, $dtexpire, string $now): string
    {
        $now = $this->nowString();
        if (!$this->applyTransition($order, Orders::STATE_ACTIVE, $now)) {
            return 'skipped';
        }

        $this->writeOrderColumns((int) $order->id, (int) $order->module_row_id, [
            'last_polled' => $now,
            'locked_until' => null,
            'locked_by' => null,
        ], $order);

        // Make the service's expiry immediately accurate. The module remains the SOLE
        // date writer (AR18): this is the module persisting its own service field, and
        // the live getExpirationDate hook stays the read-through the DM cron consumes.
        $this->writeServiceExpiration($module_id, (int) $order->module_row_id, $order->domain, $dtexpire);

        $this->emit($order, 'reconcile/activate', $order->state, Orders::STATE_ACTIVE, null, 'activated');

        // AC7: a previously-pending registration that resolves to active fires the
        // registration-confirmed-after-pending client notice. This only runs in the
        // reconciler (cron), so it is intrinsically the async pending->active path — the
        // synchronous .xyz addService active path never reaches here.
        $this->confirm($order, $module_id);

        return 'active';
    }

    /**
     * Finalises an order to `failed` on a definitively terminal registry status (AC2).
     *
     * @param \stdClass $order The claimed order row
     * @param string|null $status The terminal registry status
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @return string The outcome counter key
     */
    private function failTerminal($order, $status, string $now): string
    {
        $now = $this->nowString();
        if (!$this->applyTransition($order, Orders::STATE_FAILED, $now)) {
            return 'skipped';
        }

        $this->writeOrderColumns((int) $order->id, (int) $order->module_row_id, [
            'last_polled' => $now,
            'locked_until' => null,
            'locked_by' => null,
        ], $order);

        $this->emit($order, 'reconcile/fail', $order->state, Orders::STATE_FAILED, 'terminal', 'registry_terminal:' . (string) $status);

        // AC3: push the operator alert AT THE MOMENT of the terminal failure. Fired on
        // the transition (not on every poll); the reconciler never re-claims a `failed`
        // row, so this is naturally once-only.
        $this->alert($order, 'registry_terminal', (string) $status);

        return 'failed';
    }

    /**
     * Reschedules an unresolved order with jittered exponential backoff (AC4).
     *
     * Increments attempts, sets next_poll_at = now + jitter(backoff(attempts)), stamps
     * last_polled, and clears the lease. Never touches `state` — a pending order is
     * never advanced or terminated here (NFR5).
     *
     * @param \stdClass $order The claimed order row
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @param string|null $error_class The taxonomy class for the INV-9 record
     * @param string $message The INV-9 marker message
     * @return bool True iff the reschedule write still owned the claim
     */
    private function reschedule($order, string $now, $error_class, string $message): bool
    {
        $attempt = (int) ($order->attempts ?? 0);
        $delay = self::applyJitter(self::backoffSeconds($attempt), $this->jitterFactor());
        $next_poll_at = self::addSeconds($now, $delay);

        $updated = $this->writeOrderColumns((int) $order->id, (int) $order->module_row_id, [
            'attempts' => $attempt + 1,
            'next_poll_at' => $next_poll_at,
            'last_polled' => $now,
            'locked_until' => null,
            'locked_by' => null,
        ], $order);

        if (!$updated) {
            $this->emit($order, 'reconcile/reschedule', $order->state, null, 'indeterminate', 'cas_lost');

            return false;
        }

        $this->emit($order, 'reconcile/reschedule', $order->state, null, $error_class, $message);

        return true;
    }

    /**
     * Executes the 30-day give-up consequence (AC5/§G).
     *
     * (1) transition -> `failed`; (2) auto-cancel the Blesta service (immediate, stop
     * forward billing, NO auto-refund — refunds are operator-driven); (3) raise the
     * operator refund-eligible task as the decided durable sink: the `failed` order
     * row plus a structured INV-9 give-up record at error level (command='give-up').
     * No new table and no operator UI here (that is Story 3.4a).
     *
     * @param \stdClass $order The claimed order row
     * @param int $module_id The owning module id
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @return string The outcome counter key
     */
    private function giveUp($order, $module_id, string $now): string
    {
        $now = $this->nowString();
        if (!$this->applyTransition($order, Orders::STATE_FAILED, $now)) {
            return 'skipped';
        }

        $this->writeOrderColumns((int) $order->id, (int) $order->module_row_id, [
            'last_polled' => $now,
            'locked_until' => null,
            'locked_by' => null,
        ], $order);

        $cancelled = false;
        $cancel_error = null;
        try {
            $cancelled = $this->cancelService($module_id, (int) $order->module_row_id, $order->domain);
        } catch (\Throwable $e) {
            $cancel_error = get_class($e);
        }

        $this->emit(
            $order,
            'give-up',
            $order->state,
            Orders::STATE_FAILED,
            'terminal',
            'gave_up_after_' . self::GIVE_UP_DAYS . 'd; service_cancelled=' . ($cancelled ? '1' : '0')
                . '; refund_eligible=1' . ($cancel_error === null ? '' : '; cancellation_error=' . $cancel_error)
        );

        // AC3: push the operator alert AT THE MOMENT of the give-up terminal failure.
        // Separate from the durable refund-eligible record above (the failed row + the
        // INV-9 give-up record) — this adds the push on top. refund_eligible=1 so the
        // alert flags the order as refund-actionable.
        $this->alert($order, 'gave_up', 'refund_eligible=1; service_cancelled=' . ($cancelled ? '1' : '0'));

        return 'given_up';
    }

    /**
     * Applies a guarded state transition; a CAS loss (0 rows) is a hard stop (§Task3).
     *
     * @param \stdClass $order The order row
     * @param string $to The target state
     * @param string $now The current UTC `Y-m-d H:i:s`
     * @return bool True iff this worker applied the move
     */
    private function applyTransition($order, string $to, string $now): bool
    {
        if (!$this->stillOwnsClaim($order, $this->nowString())) {
            $this->emit($order, 'transition', $order->state, $to, 'indeterminate', 'cas_lost');

            return false;
        }

        try {
            $applied = $this->orders->transition((int) $order->id, $to);
        } catch (\Throwable $e) {
            $this->emit($order, 'transition', $order->state, $to, 'indeterminate', 'transition_error:' . $e->getMessage());

            return false;
        }

        if (!$applied) {
            // 0 rows = another worker already moved it off $from — bail, never double-process.
            $this->emit($order, 'transition', $order->state, $to, 'indeterminate', 'cas_lost');
        }

        return $applied;
    }

    /**
     * Resolves the real Blesta service by domain and auto-cancels it (AC5/§F).
     *
     * Orders are keyed by the sentinel service_id=0, so the live service cannot be
     * looked up by id — it is resolved by-domain through the Services model
     * (searchServiceFields), then scoped to this module row. The cancel runs with
     * use_module=false: the give-up means the registration never confirmed, so no
     * registry op is issued; Blesta simply stops forward billing.
     *
     * @param int $module_id The owning module id
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param string $domain The order domain
     * @return bool True iff a service was found and cancelled without errors
     */
    private function cancelService($module_id, $module_row_id, string $domain): bool
    {
        $service = $this->resolveService($module_id, $module_row_id, $domain);
        if ($service === null) {
            return false;
        }

        if (!isset($this->Services)) {
            \Loader::loadModels($this, ['Services']);
        }

        $this->Services->cancel((int) $service->id, [
            'date_canceled' => gmdate('Y-m-d H:i:s', (int) call_user_func($this->clock)),
            'use_module' => 'false',
        ]);

        return !$this->Services->errors();
    }

    /**
     * Resolves the live, non-cancelled service for a domain within this module row (§F).
     *
     * @param int $module_id The owning module id
     * @param int $module_row_id The owning module row
     * @param string $domain The order domain
     * @return \stdClass|null The resolved service, or null when none matches
     */
    private function resolveService($module_id, $module_row_id, string $domain)
    {
        if (!isset($this->Services)) {
            \Loader::loadModels($this, ['Services']);
        }

        $services = $this->Services->searchServiceFields($module_id, 'domain', $domain);
        if (!is_array($services)) {
            return null;
        }

        foreach ($services as $service) {
            if ((int) ($service->module_row_id ?? 0) !== (int) $module_row_id) {
                continue;
            }
            if (in_array($service->status ?? '', ['canceled', 'cancelled'], true)) {
                continue;
            }

            return $service;
        }

        return null;
    }

    /**
     * Persists the registry-accurate expiry onto the resolved service field (AC3/AC7).
     *
     * Best-effort and scoped: it writes only this module's own `expiration_date`
     * service field for a service this module provisioned (resolved by-domain). A
     * failure here never aborts the already-applied `active` transition — the live
     * getExpirationDate hook remains the authoritative read-through.
     *
     * @param int $module_id The owning module id
     * @param int $module_row_id The owning module row
     * @param string $domain The order domain
     * @param string|null $dtexpire The observed registry expiry
     */
    private function writeServiceExpiration($module_id, $module_row_id, string $domain, $dtexpire): void
    {
        if (!is_string($dtexpire) || trim($dtexpire) === '') {
            return;
        }

        $utc = self::toUtc($dtexpire);
        if ($utc === null) {
            return;
        }

        try {
            $service = $this->resolveService($module_id, $module_row_id, $domain);
            if ($service === null) {
                return;
            }

            $field = $this->Record->select(['id'])
                ->from('service_fields')
                ->where('service_id', '=', (int) $service->id)
                ->where('key', '=', 'expiration_date')
                ->fetch();
            $this->Record->reset();

            if ($field) {
                $this->Record->where('id', '=', (int) $field->id)
                    ->update('service_fields', ['value' => $utc, 'encrypted' => 0]);
            } else {
                $this->Record->insert('service_fields', [
                    'service_id' => (int) $service->id,
                    'key' => 'expiration_date',
                    'value' => $utc,
                    'serialized' => 0,
                    'encrypted' => 0,
                ]);
            }
        } catch (\Throwable $e) {
            // Immediacy nicety only; the read-through getter stays authoritative.
        } finally {
            $this->Record->reset();
        }
    }

    /**
     * Writes scoped non-state columns on an order row (INV-1/INV-3).
     *
     * Poll/lock/give-up/date columns are NOT `state`, so they are written directly
     * here (state only ever moves via Orders::transition()). Every write carries the
     * module_row_id scope and resets MinPHP's dirty query state afterward.
     *
     * @param int $order_id The order row id
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param array $columns The non-state columns to set
     * @param \stdClass|null $claim The claim token this worker must still own
     * @return bool True iff the write affected a row
     */
    private function writeOrderColumns($order_id, $module_row_id, array $columns, $claim = null): bool
    {
        try {
            $this->Record->where('id', '=', $order_id)
                ->where('module_row_id', '=', $module_row_id);

            if ($claim !== null) {
                $this->Record->where('locked_by', '=', $claim->locked_by ?? null)
                    ->where('locked_until', '=', $claim->locked_until ?? null);
            }

            $this->Record->update('webnic_orders', $columns);

            return $this->Record->affectedRows() > 0;
        } finally {
            $this->Record->reset();
        }
    }

    /**
     * Releases a claimed row without polling when this run cannot process it.
     *
     * @param \stdClass $order The claimed order row
     * @return bool True iff this worker released its own claim
     */
    private function releaseClaim($order): bool
    {
        return $this->writeOrderColumns((int) $order->id, (int) $order->module_row_id, [
            'locked_until' => null,
            'locked_by' => null,
        ], $order);
    }

    /**
     * Checks that the current row still carries this worker's unexpired claim.
     *
     * @param \stdClass $order The originally claimed order row
     * @param string $now Current UTC `Y-m-d H:i:s`
     * @return bool True iff the stored claim token still matches and is not expired
     */
    private function stillOwnsClaim($order, string $now): bool
    {
        if (!isset($order->locked_by, $order->locked_until)) {
            return false;
        }

        $lease_ts = self::epoch((string) $order->locked_until);
        $now_ts = self::epoch($now);
        if ($lease_ts === null || $now_ts === null || $lease_ts < $now_ts) {
            return false;
        }

        $current = $this->Record->select(['locked_by', 'locked_until'])
            ->from('webnic_orders')
            ->where('id', '=', (int) $order->id)
            ->where('module_row_id', '=', (int) $order->module_row_id)
            ->fetch();
        $this->Record->reset();

        return $current
            && (string) $current->locked_by === (string) $order->locked_by
            && (string) $current->locked_until === (string) $order->locked_until;
    }

    /**
     * Builds the WebnicDomains collaborator for a module row.
     *
     * @param \stdClass $row The module row (decrypted meta)
     * @return \WebnicDomains The by-domain read command for this row
     */
    private function domainsFor($row)
    {
        $api = new \WebnicApi(
            $row->id,
            $row->meta->username,
            $row->meta->secret,
            $row->meta->environment,
            new TokenStore()
        );

        return new \WebnicDomains($api);
    }

    /**
     * Builds the WebnicTransfers collaborator for a module row (WN-4-1).
     *
     * @param \stdClass $row The module row (decrypted meta)
     * @return \WebnicTransfers The transfer-in status read command for this row
     */
    private function transfersFor($row)
    {
        $api = new \WebnicApi(
            $row->id,
            $row->meta->username,
            $row->meta->secret,
            $row->meta->environment,
            new TokenStore()
        );

        return new \WebnicTransfers($api);
    }

    /**
     * Returns the lease owner for this run (host:pid:run-nonce).
     *
     * @return string The lease owner string
     */
    private function worker(): string
    {
        $host = gethostname();

        return ($host !== false ? $host : 'unknown') . ':' . getmypid() . ':' . $this->nonce;
    }

    /**
     * Returns the current time as a UTC `Y-m-d H:i:s` from the injected clock.
     *
     * @return string The current UTC datetime
     */
    private function nowString(): string
    {
        return gmdate('Y-m-d H:i:s', (int) call_user_func($this->clock));
    }

    /**
     * Returns a random signed jitter factor in [-JITTER_FRACTION, +JITTER_FRACTION].
     *
     * Randomness is confined to this impure wrapper so the pure applyJitter() math
     * stays deterministic under test (AC4 / Dev Notes §K).
     *
     * @return float The signed jitter fraction
     */
    private function jitterFactor(): float
    {
        $unit = mt_rand(0, mt_getrandmax()) / (mt_getrandmax() + 1.0);

        return (($unit * 2.0) - 1.0) * self::JITTER_FRACTION;
    }

    /**
     * Emits a scrubbed INV-9 reconciliation record to the injected sink.
     *
     * Level is implied by error_class (terminal/give-up => error, retryable/
     * indeterminate => notice, normal transition => info) exactly as the saga emits;
     * the sink performs the Redactor scrub before writing.
     *
     * @param \stdClass $order The order row
     * @param string $command The reconcile command/step
     * @param string|null $from_state The from state
     * @param string|null $to_state The to state
     * @param string|null $error_class retryable|terminal|indeterminate, or null
     * @param string $message The redacted marker message
     */
    private function emit($order, string $command, $from_state, $to_state, $error_class, string $message): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            call_user_func($this->logger, [
                'run_id' => $this->run_id,
                'level' => self::levelFor($command, $error_class),
                'module_row_id' => $order->module_row_id ?? null,
                'service_id' => $order->service_id ?? null,
                'domain' => $order->domain ?? null,
                'command' => $command,
                'from_state' => $from_state,
                'to_state' => $to_state,
                'error_class' => $error_class,
                'attempt' => (int) ($order->attempts ?? 0),
                'webnic_order_id' => $order->webnic_order_id ?? null,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            // Observability must never interrupt reconciliation.
        }
    }

    /**
     * Pushes the operator alert at the moment of a terminal failure (AC3).
     *
     * Hands the injected alert sink a compact, non-secret payload (the concrete
     * webnic.php sink turns it into a staff push, degrading to an error-level log). The
     * webnic_order_id is NOT included (INV-7); correlation is by domain/service_id. Like
     * emit(), a sink failure is swallowed so the alert can never interrupt the cron run.
     *
     * @param \stdClass $order The order that just transitioned to failed
     * @param string $reason registry_terminal|gave_up
     * @param string $detail A short non-secret detail string
     */
    private function alert($order, string $reason, string $detail): void
    {
        if ($this->alerter === null) {
            return;
        }

        try {
            call_user_func($this->alerter, [
                'run_id' => $this->run_id,
                'reason' => $reason,
                'module_row_id' => $order->module_row_id ?? null,
                'service_id' => $order->service_id ?? null,
                'domain' => $order->domain ?? null,
                'attempt' => (int) ($order->attempts ?? 0),
                'detail' => $detail,
            ]);
        } catch (\Throwable $e) {
            // A push-alert failure must never interrupt reconciliation.
        }
    }

    /**
     * Fires the client registration-confirmed-after-pending notice (AC7).
     *
     * Hands the injected confirmer sink a non-secret payload; the concrete webnic.php
     * sink resolves the real service by-domain (the order is keyed by sentinel
     * service_id=0) and dispatches the client email. A sink failure is swallowed so the
     * notice can never interrupt reconciliation.
     *
     * @param \stdClass $order The order that just transitioned to active
     * @param int $module_id The owning module id (needed to resolve the real service)
     */
    private function confirm($order, $module_id): void
    {
        if ($this->confirmer === null) {
            return;
        }

        try {
            call_user_func($this->confirmer, [
                'run_id' => $this->run_id,
                'module_id' => $module_id,
                'module_row_id' => $order->module_row_id ?? null,
                'service_id' => $order->service_id ?? null,
                'domain' => $order->domain ?? null,
                // WN-4-1 AC5: the operation discriminator lets the sink pick the transfer-completed
                // email vs the register-confirmed notice (template/copy owned by Story 6.1).
                'operation' => $order->operation ?? Orders::OPERATION_REGISTER,
            ]);
        } catch (\Throwable $e) {
            // A confirmation-notice failure must never interrupt reconciliation.
        }
    }

    /**
     * Maps INV-9 taxonomy to the structured log level.
     *
     * @param string $command The reconcile command
     * @param string|null $error_class retryable|terminal|indeterminate, or null
     * @return string info|notice|error
     */
    private static function levelFor(string $command, $error_class): string
    {
        if ($command === 'give-up' || $error_class === 'terminal') {
            return self::LEVEL_ERROR;
        }

        if (in_array($error_class, ['retryable', 'indeterminate'], true)) {
            return self::LEVEL_NOTICE;
        }

        return self::LEVEL_INFO;
    }

    /**
     * Parses a UTC `Y-m-d H:i:s` (no embedded zone) into a unix timestamp.
     *
     * @param string $utc The UTC datetime (zone-free)
     * @return int|null The unix timestamp, or null when unparseable
     */
    private static function epoch(string $utc): ?int
    {
        $utc = trim($utc);
        if ($utc === '') {
            return null;
        }

        $ts = strtotime($utc . ' UTC');

        return $ts === false ? null : $ts;
    }

    /**
     * Adds a second offset to a UTC `Y-m-d H:i:s`, returning a UTC `Y-m-d H:i:s`.
     *
     * gmdate() with an explicit timestamp is deterministic (it is not "now"), so this
     * stays pure for the scheduling truth tables.
     *
     * @param string $utc The base UTC datetime
     * @param int $seconds The offset in seconds (may be negative)
     * @return string The shifted UTC datetime
     */
    private static function addSeconds(string $utc, int $seconds): string
    {
        $ts = self::epoch($utc);
        if ($ts === null) {
            $ts = 0;
        }

        return gmdate('Y-m-d H:i:s', $ts + $seconds);
    }
}
