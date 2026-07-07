<?php
/**
 * WebNIC order state substrate.
 *
 * One durable row per (module row, service, domain) that is simultaneously the
 * FR18a intent record (proof an order was attempted, written before the API call)
 * and the FR39a state machine (where the async order is in its lifecycle). This
 * model is the single writer of the `state` column: `transition()` is the guarded
 * mover, and `openIntent()` seeds/revives the row. Everything downstream (saga
 * 3.2, reconciler 3.3, UI 3.4a) reads this row and asks the model to move it.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
namespace Webnic;

/**
 * Thrown when a caller asks for a state move that is not in the legal-move map,
 * or names an unknown target state. An illegal edge is a programming error (the
 * saga asked for an impossible move), never customer input — so it surfaces as
 * an unmissable exception, not a Blesta Input error (which would risk the
 * forbidden "Input error for an async order" anti-pattern, INV-4).
 */
class IllegalTransitionException extends \LogicException
{
}

/**
 * Owns the `webnic_orders` table: the 9-value lifecycle, the legal-move guard,
 * the idempotency key, and the intent upsert-revive.
 */
class Orders
{
    // ── The 9 lifecycle states (architecture L472-474). These are the ONLY legal
    //    `state` literals in the module; no synonyms, no string literals elsewhere.
    //    Distinct from WebnicStatus::* (the 5-alias rendered badge lexicon) — do
    //    not conflate the two vocabularies (Dev Notes §K).
    public const STATE_INTENT = 'intent';
    public const STATE_CONTACTS_DONE = 'contacts_done';
    public const STATE_REGISTRANT_DONE = 'registrant_done';
    public const STATE_HOSTS_DONE = 'hosts_done';
    public const STATE_SUBMITTED = 'submitted';
    public const STATE_REGISTRAR_PENDING = 'registrar_pending';
    public const STATE_ACTIVE = 'active';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';

    /**
     * @var array The lifecycle states in stable order (mirror WebnicStatus::ALIASES).
     */
    public const STATES = [
        self::STATE_INTENT,
        self::STATE_CONTACTS_DONE,
        self::STATE_REGISTRANT_DONE,
        self::STATE_HOSTS_DONE,
        self::STATE_SUBMITTED,
        self::STATE_REGISTRAR_PENDING,
        self::STATE_ACTIVE,
        self::STATE_FAILED,
        self::STATE_CANCELLED,
    ];

    /**
     * @var array The terminal states a row may be revived FROM (Dev Notes §H).
     */
    public const TERMINAL_STATES = [
        self::STATE_FAILED,
        self::STATE_CANCELLED,
    ];

    // ── The operation discriminator (Dev Notes §B: `operation`, NOT `kind`).
    //    register is wired this story; transfer is a registered-but-stub slot so
    //    Epic 4 is purely additive (Decision #3) — do NOT build transfer logic here.
    public const OPERATION_REGISTER = 'register';
    public const OPERATION_TRANSFER = 'transfer';

    /**
     * @var array The legal operation discriminators.
     */
    public const OPERATIONS = [
        self::OPERATION_REGISTER,
        self::OPERATION_TRANSFER,
    ];

    public const INTENT_STATUS_CREATED = 'created';
    public const INTENT_STATUS_EXISTING_LIVE = 'existing_live';
    public const INTENT_STATUS_REVIVED = 'revived';
    // WN-4-1 (T5/AC8): a terminal row that did NOT revive because it was no longer in the
    // caller's EXPECTED terminal state (a concurrent resolve raced the recovery preflight).
    // The caller MUST NOT dispatch — re-seeding a now-resolved order is the bug this prevents.
    public const INTENT_STATUS_NOT_REVIVED = 'not_revived';

    /**
     * @var array The legal-move map (INV-3, architecture L492-502). `transition()`
     *  MUST reject any edge not present here; every self-edge (x->x) is illegal.
     *  17 legal edges over the 9x9 cross-product; importable by the truth-table test.
     *  Terminal states (failed/cancelled) never return to `intent` — that revival is
     *  deliberately NOT a transition edge but a re-seed (Dev Notes §H).
     *
     *  WN-4-1: `registrant_done -> submitted` is the ONE new edge transfer-in needs.
     *  The OTE capture (config/webnic.php T0 block) proved transfer-in REQUIRES the
     *  4 contact handles + registrantUserId (so the saga runs contacts->registrant)
     *  but NOT hosts — so transfer SKIPS the hosts_done step, going straight from
     *  registrant_done to submitted. Register never traverses this edge (it always
     *  goes registrant_done -> hosts_done), so the map stays operation-agnostic — no
     *  per-operation keying. (This supersedes the story's pre-capture `intent ->
     *  submitted` guess, which assumed a contacts-less "lean" transfer saga.)
     */
    public const TRANSITIONS = [
        self::STATE_INTENT            => [self::STATE_CONTACTS_DONE, self::STATE_FAILED, self::STATE_CANCELLED],
        self::STATE_CONTACTS_DONE     => [self::STATE_REGISTRANT_DONE, self::STATE_FAILED],
        self::STATE_REGISTRANT_DONE   => [self::STATE_HOSTS_DONE, self::STATE_SUBMITTED, self::STATE_FAILED],
        self::STATE_HOSTS_DONE        => [self::STATE_SUBMITTED, self::STATE_FAILED],
        self::STATE_SUBMITTED         => [self::STATE_REGISTRAR_PENDING, self::STATE_ACTIVE, self::STATE_FAILED],
        self::STATE_REGISTRAR_PENDING => [self::STATE_ACTIVE, self::STATE_FAILED],
        self::STATE_ACTIVE            => [self::STATE_CANCELLED],
        self::STATE_FAILED            => [self::STATE_CANCELLED],
        self::STATE_CANCELLED         => [], // terminal
    ];

    /**
     * Initializes the Record-backed order store.
     */
    public function __construct()
    {
        \Loader::loadComponents($this, ['Record']);
    }

    /**
     * Determines whether a state move is permitted by the legal-move map (INV-3).
     *
     * Pure: no Record, no Loader, no time() — so the exhaustive truth-table test
     * runs with no DB and no Blesta.
     *
     * @param string $from The current state
     * @param string $to The proposed target state
     * @return bool True iff (from, to) is a legal edge
     */
    public static function isLegalTransition(string $from, string $to): bool
    {
        if (!isset(self::TRANSITIONS[$from])) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * Picks the most attention-worthy order row from a by-domain set (WN-4-1 C10 b / round-1 D2, pure).
     *
     * A `failed` row signals a recovery need and must NOT be masked by a newer non-failed row
     * (AC6) — UNLESS it has been SUPERSEDED by a strictly-newer `active` success of the (typically
     * other) operation, in which case the domain is live and the stale failed must not drive the
     * badge / Recovery tab (round-1 D2). A newer *pending* row does NOT supersede a failed row. With
     * no un-superseded failed row, prefer pending > active > cancelled, latest id within a class; a
     * superseded `failed` ranks last so it never re-wins over the active that superseded it.
     *
     * Pure and ORDER-INDEPENDENT (round-2 R2-2): "newest" is computed by MAX id, and the fallback
     * tiebreak compares ids explicitly — callers need not pass the rows in any particular order.
     *
     * @param array $orders The by-domain rows (any order)
     * @return \stdClass|null The preferred row, or null when the set is empty
     */
    public static function preferOrderRow(array $orders)
    {
        if (empty($orders)) {
            return null;
        }

        $newest_failed = null;
        $newest_active = null;
        foreach ($orders as $order) {
            $state = $order->state ?? '';
            $id = (int) ($order->id ?? 0);
            if ($state === self::STATE_FAILED && ($newest_failed === null || $id > (int) ($newest_failed->id ?? 0))) {
                $newest_failed = $order;
            } elseif ($state === self::STATE_ACTIVE && ($newest_active === null || $id > (int) ($newest_active->id ?? 0))) {
                $newest_active = $order;
            }
        }

        // A failed row wins (recovery surfacing) unless a strictly-newer active row supersedes it.
        if ($newest_failed !== null) {
            $superseded = $newest_active !== null
                && (int) ($newest_active->id ?? 0) > (int) ($newest_failed->id ?? 0);
            if (!$superseded) {
                return $newest_failed;
            }
        }

        $rank = static function ($state): int {
            switch ($state) {
                case self::STATE_ACTIVE:
                    return 1;
                case self::STATE_CANCELLED:
                    return 2;
                case self::STATE_FAILED:
                    return 3;
                default:
                    return 0; // registrar_pending / submitted / interior = pending/in-flight
            }
        };

        $best = null;
        foreach ($orders as $order) {
            if ($best === null) {
                $best = $order;
                continue;
            }
            $p = $rank($order->state ?? '');
            $bp = $rank($best->state ?? '');
            if ($p < $bp || ($p === $bp && (int) ($order->id ?? 0) > (int) ($best->id ?? 0))) {
                $best = $order;
            }
        }

        return $best;
    }

    /**
     * Derives the service/domain portion of the intent key (FR18a/INV-5/AR7).
     *
     * The table uniqueness is scoped by module_row_id as
     * UNIQUE(module_row_id, service_id, domain); this helper is the pure domain
     * normalization piece that guarantees two spellings of the same domain don't
     * create two rows inside the owning module row.
     *
     * @param int|string $service_id The Blesta service id
     * @param string $domain The domain (any spelling)
     * @return string The stable idempotency key
     */
    public static function idempotencyKey($service_id, string $domain): string
    {
        $normalized_domain = self::normalizeDomain($domain);
        self::assertUsableDomain($normalized_domain);

        return $service_id . '|' . $normalized_domain;
    }

    /**
     * Normalizes a domain so two spellings of the same FQDN collapse to one key.
     *
     * Rule: strip surrounding whitespace and the FQDN root dot, then ASCII
     * case-fold. `Example.COM`, `example.com.`, `  example.com  ` => `example.com`.
     * Already-punycode input (`xn--…`) is ASCII so it case-folds idempotently.
     *
     * IDN DECISION (Dev Notes §F): full Unicode case-folding and Unicode<->punycode
     * canonicalization for IDNs is DEFERRED, NOT silently assumed. PHP's
     * idn_to_ascii() needs ext-intl, which is ABSENT from the unit-test toolchain
     * container (present only in the full Blesta runtime) — calling it here would
     * make the key environment-dependent, a worse trap than the one it solves.
     * Registries are punycode, so the upstream contract is: callers MUST supply
     * IDNs already in lowercase punycode before they reach the order layer. This
     * function deliberately leaves multibyte bytes untouched (deterministic) rather
     * than half-normalizing them.
     *
     * @param string $domain The domain (any spelling)
     * @return string The normalized domain used for keying and storage
     */
    public static function normalizeDomain(string $domain): string
    {
        return strtolower(rtrim(trim($domain), '.'));
    }

    /**
     * Resolves the per-operation strategy slot (Decision #3 / AC5).
     *
     * A "resolver" is the per-operation by-domain reconcile strategy Epic 3/4
     * consume; this story only proves the seam exists. An unknown operation is
     * rejected (never silently defaulted). register is wired; transfer is a
     * registered-but-stub slot (Story 4.1 fleshes it out).
     *
     * @param string $operation One of self::OPERATIONS
     * @return array The registered resolver descriptor
     * @throws \InvalidArgumentException When no resolver is registered for $operation
     */
    public static function resolverFor(string $operation): array
    {
        $resolvers = \Configure::get('Webnic.order_resolvers');

        if (!is_array($resolvers) || !array_key_exists($operation, $resolvers)) {
            throw new \InvalidArgumentException(
                sprintf('No WebNIC order resolver registered for operation "%s".', $operation)
            );
        }

        return $resolvers[$operation];
    }

    /**
     * Moves an order to a new state through the single-writer guard (INV-3/AR5).
     *
     * The ONLY path that mutates `webnic_orders.state` after the intent seed. An
     * illegal edge throws and writes nothing. The legal write is state-CAS guarded
     * (WHERE id=:id AND state=:from) so two concurrent callers cannot both apply
     * the same move — the loser sees 0 affected rows (the same CAS philosophy as
     * the 3.3 poller claim; avoids forUpdate(), which Blesta Record lacks).
     *
     * @param int $id The webnic_orders row id (already row-specific; INV-1 satisfied by PK)
     * @param string $to The target state
     * @return bool True iff this caller applied the move (1 row affected)
     * @throws IllegalTransitionException On an unknown target, missing row, or illegal edge
     */
    public function transition($id, $to)
    {
        if (!in_array($to, self::STATES, true)) {
            throw new IllegalTransitionException(sprintf('Unknown webnic_orders target state "%s".', $to));
        }

        $row = $this->Record->select(['id', 'state'])
            ->from('webnic_orders')
            ->where('id', '=', $id)
            ->fetch();

        if (!$row) {
            throw new IllegalTransitionException(sprintf('webnic_orders row %s not found for transition.', $id));
        }

        $from = $row->state;

        if (!self::isLegalTransition($from, $to)) {
            throw new IllegalTransitionException(
                sprintf('Illegal webnic_orders transition: %s -> %s.', $from, $to)
            );
        }

        // State-CAS: only apply while the row is still in $from.
        $this->Record->where('id', '=', $id)
            ->where('state', '=', $from)
            ->update('webnic_orders', ['state' => $to]);

        return $this->Record->affectedRows() > 0;
    }

    /**
     * Seeds or revives the durable intent row before any API call (FR18a/INV-5).
     *
     * SELECT-then-act (NOT a blind duplicate()->insert(), which cannot be
     * conditioned on the current state and would clobber a live order — an INV-5
     * money-safety bug). Three outcomes (Dev Notes §H):
     *   1. No row        -> birth a fresh `intent` row.
     *   2. Live row       -> return it UNTOUCHED so the caller reconciles by-domain
     *                        and does not resubmit (never clobber created/webnic_order_id).
     *   3. Terminal row   -> revive in place: a WHERE-guarded reset to `intent`
     *                        (a new lifecycle on the same PK). This is deliberately
     *                        NOT a transition() edge — the legal-move map has no
     *                        failed/cancelled -> intent edge, and adding one would
     *                        let the saga jump backwards mid-flight.
     *
     * The fresh insert and the revive are the ONLY `state` writes besides
     * transition(); both live inside this model, so it remains the single writer
     * (the INV-3 rule is "no scattered bare UPDATEs", not "literally one method").
     *
     * @param int $service_id The Blesta service id (half of the idempotency key)
     * @param string $domain The domain (normalized via self::normalizeDomain)
     * @param int $module_row_id The owning module row (INV-1 scope, Dev Notes §L)
     * @param string $operation One of self::OPERATIONS (default register)
     * @param string|null $expected_state WN-4-1 atomic-revive guard: when set, a TERMINAL row
     *  is revived ONLY while it is still in EXACTLY this state (the state a recovery preflight
     *  observed). If it has since moved (e.g. a concurrent resolve drove failed->cancelled), the
     *  revive is skipped and the row returns with intent_status=NOT_REVIVED so the caller does not
     *  re-seed a resolved order (T5/AC8). null = revive any terminal state (fresh-order behavior).
     * @return \stdClass The current order row (freshly read)
     * @throws \InvalidArgumentException On an unknown operation (AC5)
     */
    public function openIntent($service_id, string $domain, $module_row_id, string $operation = self::OPERATION_REGISTER, ?string $expected_state = null)
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Unknown webnic_orders operation "%s".', $operation)
            );
        }

        // Round-2 R2-4 (P14a): a revive guard may only be anchored to a TERMINAL state — the
        // atomic revive re-seeds a failed/cancelled row, never an interior/active one. Reject a
        // non-terminal $expected_state explicitly so the contract is self-documenting (the existing
        // only-terminal-revive + state-equality guards already prevent an illegal revive, but a
        // caller passing e.g. 'contacts_done' is a programming error, not a silent no-revive).
        if ($expected_state !== null && !in_array($expected_state, self::TERMINAL_STATES, true)) {
            throw new \InvalidArgumentException(
                sprintf('openIntent $expected_state must be a terminal state, got "%s".', $expected_state)
            );
        }

        // Fail fast if the configured resolver seam is missing for a valid operation.
        self::resolverFor($operation);

        $normalized_domain = self::normalizeDomain($domain);
        self::assertUsableDomain($normalized_domain);
        $now = gmdate('Y-m-d H:i:s');

        // INV-1: every (service_id, domain) lookup MUST carry module_row_id scope.
        $existing = $this->findIntent($service_id, $normalized_domain, $module_row_id);

        // (1) No row -> birth a fresh intent row (not a transition).
        if (!$existing) {
            try {
                $this->Record->insert('webnic_orders', [
                    'service_id' => $service_id,
                    'module_row_id' => $module_row_id,
                    'operation' => $operation,
                    'domain' => $normalized_domain,
                    'state' => self::STATE_INTENT,
                    'attempts' => 0,
                    'created' => $now,
                ]);

                $created = $this->getById($this->Record->lastInsertId());
                $created->intent_status = self::INTENT_STATUS_CREATED;

                return $created;
            } catch (\PDOException $e) {
                if (!self::isDuplicateKeyException($e)) {
                    throw $e;
                }

                $this->Record->reset();

                // Another worker won the first-insert race. Re-read the scoped row and
                // apply the normal live/terminal decision instead of surfacing a 1062.
                $existing = $this->findIntent($service_id, $normalized_domain, $module_row_id);
                if (!$existing) {
                    throw new \LogicException(
                        'webnic_orders unique key exists outside the requested module_row_id scope.'
                    );
                }
            }
        }

        return $this->handleExistingIntent($existing, $operation, $expected_state);
    }

    /**
     * Resolves an existing scoped intent row: return live rows untouched, revive terminal rows.
     *
     * @param \stdClass $existing The existing row from findIntent()/getById()
     * @param string $operation The requested operation discriminator
     * @param string|null $expected_state WN-4-1 atomic-revive guard (see openIntent)
     * @return \stdClass The current order row
     * @throws IllegalTransitionException If the stored state is corrupt
     * @throws \InvalidArgumentException If a live row belongs to a different operation
     */
    private function handleExistingIntent($existing, string $operation, ?string $expected_state = null)
    {
        if (!in_array($existing->state, self::STATES, true)) {
            throw new IllegalTransitionException(
                sprintf('Unknown webnic_orders current state "%s".', $existing->state)
            );
        }

        // (2) Live row -> do not touch; caller reconciles by-domain (INV-5).
        if (!in_array($existing->state, self::TERMINAL_STATES, true)) {
            if ($existing->operation !== $operation) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Existing live webnic_orders row uses operation "%s", not "%s".',
                        $existing->operation,
                        $operation
                    )
                );
            }

            $live = $this->getById($existing->id);
            $live->intent_status = self::INTENT_STATUS_EXISTING_LIVE;

            return $live;
        }

        // (3) Terminal row -> revive in place. WN-4-1 atomic-revive guard (T5/AC8): when the
        //     caller named an EXPECTED terminal state (a recovery preflight observed it), the
        //     row must still be in EXACTLY that state, else a concurrent resolve raced the
        //     preflight and reviving would re-seed a now-resolved order — return NOT_REVIVED.
        if ($expected_state !== null && $existing->state !== $expected_state) {
            $not_revived = $this->getById($existing->id);
            $not_revived->intent_status = self::INTENT_STATUS_NOT_REVIVED;

            return $not_revived;
        }

        // The revive set is the single expected state (recovery) or any terminal (fresh order),
        // so the CAS WHERE both detects a concurrent move AND enforces the expected-state guard.
        $revive_states = $expected_state !== null ? [$expected_state] : self::TERMINAL_STATES;
        $now = gmdate('Y-m-d H:i:s');
        $this->Record->where('id', '=', $existing->id)
            ->where('state', 'in', $revive_states)
            ->update('webnic_orders', [
                'operation' => $operation,
                'state' => self::STATE_INTENT,
                'attempts' => 0,
                'webnic_order_id' => null,
                'transfer_id' => null,
                'last_polled' => null,
                'next_poll_at' => null,
                'give_up_at' => null,
                'locked_until' => null,
                'locked_by' => null,
                'created' => $now,
            ]);

        if ($this->Record->affectedRows() === 0) {
            $refreshed = $this->getById($existing->id);
            if (!$refreshed) {
                throw new IllegalTransitionException(
                    sprintf('webnic_orders row %s disappeared while reviving intent.', $existing->id)
                );
            }

            return $this->handleExistingIntent($refreshed, $operation, $expected_state);
        }

        $revived = $this->getById($existing->id);
        $revived->intent_status = self::INTENT_STATUS_REVIVED;

        return $revived;
    }

    /**
     * Reads a single order row by primary key.
     *
     * @param int $id The webnic_orders row id
     * @return \stdClass|false The row, or false if absent
     */
    private function getById($id)
    {
        return $this->Record->select()
            ->from('webnic_orders')
            ->where('id', '=', $id)
            ->fetch();
    }

    /**
     * Reads an intent row through the required module-row scope.
     *
     * @param int $service_id The Blesta service id
     * @param string $normalized_domain The normalized domain
     * @param int $module_row_id The owning module row
     * @return \stdClass|false The row, or false when absent in this scope
     */
    private function findIntent($service_id, string $normalized_domain, $module_row_id)
    {
        return $this->Record->select(['id', 'state', 'operation', 'created', 'webnic_order_id', 'transfer_id'])
            ->from('webnic_orders')
            ->where('service_id', '=', $service_id)
            ->where('domain', '=', $normalized_domain)
            ->where('module_row_id', '=', $module_row_id)
            ->fetch();
    }

    /**
     * Rejects an unusable normalized domain before it becomes an idempotency key.
     *
     * @param string $normalized_domain The normalized domain
     * @throws \InvalidArgumentException When the normalized domain is empty
     */
    private static function assertUsableDomain(string $normalized_domain): void
    {
        if ($normalized_domain === '') {
            throw new \InvalidArgumentException('A webnic_orders domain is required.');
        }
    }

    /**
     * Identifies a MySQL duplicate-key error from the first-insert race.
     *
     * @param \PDOException $e The caught PDO exception
     * @return bool True iff the exception represents a duplicate key
     */
    private static function isDuplicateKeyException(\PDOException $e): bool
    {
        if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
            return true;
        }

        return strpos($e->getMessage(), '1062') !== false
            && strpos($e->getMessage(), 'Duplicate') !== false;
    }
}
