<?php
/**
 * WebNIC failed-order recovery presenter (WN-3-4a).
 *
 * The lib-resident collaborator behind the admin Recovery tab (retro T4: collaborators
 * live in lib/, not on the Webnic god-class). It owns the money-safe recovery actions
 * that route through the EXISTING primitives (Dev Notes §H) — never a new state edge,
 * never a blind Register resubmit:
 *
 *   - resolve  = Orders::transition(failed -> cancelled)  (the one legal edge out of failed)
 *   - finalise = WebnicDomains::info() + decideReconcileRegister(): a domain that is
 *     registry-confirmed registered+active is driven to `active` via revive -> legal
 *     walk (no failed->active edge, no registry write); otherwise nothing changes.
 *
 * The third action, retry, re-runs the full RegistrationSaga and needs Blesta client/
 * contact models, so it stays in Webnic::addService() (the god-class already owns that
 * machinery); this class is not involved in retry beyond the shared INV-9 record shape.
 *
 * Every action emits a scrubbed INV-9 structured-log record (command='recover.*') and
 * is scoped by module_row_id (INV-1). `state` only ever moves through Orders (INV-3).
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
namespace Webnic;

/**
 * Owns the failed-order recovery actions (resolve/finalise), the masked tracking
 * record, and the correlated module-log trace read.
 */
class FailedOrder
{
    /**
     * @var Orders The single-writer state machine (transition()/openIntent()).
     */
    private $orders;

    /**
     * @var callable|null INV-9 structured-log sink: function(array $record): void.
     */
    private $logger;

    /**
     * @var string Per-instance observability id stamped onto every INV-9 record.
     */
    private $run_id;

    /**
     * @var array The legal revive->active walk (intent -> ... -> active). No registry
     *  call is made; these are pure state moves reflecting a registry-confirmed
     *  registration, since there is intentionally NO failed->active edge (Dev Notes §H).
     */
    private const ACTIVATE_WALK = [
        Orders::STATE_CONTACTS_DONE,
        Orders::STATE_REGISTRANT_DONE,
        Orders::STATE_HOSTS_DONE,
        Orders::STATE_SUBMITTED,
        Orders::STATE_ACTIVE,
    ];

    /**
     * Initializes the recovery presenter.
     *
     * @param Orders $orders The single-writer order state machine
     * @param callable|null $logger INV-9 sink: function(array $record): void
     */
    public function __construct(Orders $orders, callable $logger = null)
    {
        \Loader::loadComponents($this, ['Record']);
        $this->orders = $orders;
        $this->logger = $logger;
        $this->run_id = uniqid('wnrec_', true);
    }

    /**
     * Marks a failed order resolved: transition(failed -> cancelled) (AC5).
     *
     * The clean, legal acknowledgement of a dead order. Records command='recover.resolve'.
     *
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param string $domain The order domain
     * @return array ['ok' => bool, 'from_state' => string|null, 'to_state' => string|null,
     *  'message_key' => string]
     */
    public function resolve($module_row_id, string $domain): array
    {
        $order = $this->findFailed($module_row_id, $domain);
        if ($order === null) {
            return ['ok' => false, 'from_state' => null, 'to_state' => null, 'message_key' => 'recover.not_failed'];
        }

        try {
            $applied = $this->orders->transition((int) $order->id, Orders::STATE_CANCELLED);
        } catch (\Throwable $e) {
            $this->emit($order, 'recover.resolve', 'failed', Orders::STATE_CANCELLED, 'indeterminate', 'transition_error:' . $e->getMessage());

            return ['ok' => false, 'from_state' => 'failed', 'to_state' => null, 'message_key' => 'recover.resolve_failed'];
        }

        if (!$applied) {
            $this->emit($order, 'recover.resolve', 'failed', Orders::STATE_CANCELLED, 'indeterminate', 'cas_lost');

            return ['ok' => false, 'from_state' => 'failed', 'to_state' => null, 'message_key' => 'recover.resolve_failed'];
        }

        $this->emit($order, 'recover.resolve', 'failed', Orders::STATE_CANCELLED, null, 'resolved');

        return ['ok' => true, 'from_state' => 'failed', 'to_state' => Orders::STATE_CANCELLED, 'message_key' => 'recover.resolve_ok'];
    }

    /**
     * Finalises a failed-but-registered order to active, money-safe (AC5/§H).
     *
     * Targeted recovery for "it actually registered but we marked it failed": reconcile
     * by-domain via info(); only a registry-confirmed registered + active-alias domain
     * is driven to `active` (revive -> legal walk, NO Register resubmit, NO new edge).
     * Anything else leaves the order untouched and tells the operator to use retry.
     *
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param string $domain The order domain
     * @param mixed $domains A WebnicDomains-shaped collaborator exposing info($domain)
     * @return array ['ok' => bool, 'to_state' => string|null, 'message_key' => string]
     */
    public function finalise($module_row_id, string $domain, $domains): array
    {
        $order = $this->findFailed($module_row_id, $domain);
        if ($order === null) {
            return ['ok' => false, 'to_state' => null, 'message_key' => 'recover.not_failed'];
        }

        try {
            $resp = $domains->info($domain);
        } catch (\Throwable $e) {
            $this->emit($order, 'recover.finalise', 'failed', null, 'indeterminate', 'finalise_info_error');

            return ['ok' => false, 'to_state' => null, 'message_key' => 'recover.finalise_unavailable'];
        }

        $decision = \WebnicDomains::decideReconcileRegister($resp);
        if ($decision['outcome'] !== 'registered') {
            $this->emit($order, 'recover.finalise', 'failed', null, 'indeterminate', 'not_registered:' . $decision['outcome']);

            return ['ok' => false, 'to_state' => null, 'message_key' => 'recover.finalise_not_registered'];
        }

        $data = $resp->data();
        $suspended = is_array($data) ? (bool) ($data['suspended'] ?? false) : false;
        $alias = \WebnicStatus::fromRegistryStatus((string) ($decision['status'] ?? ''), $suspended);
        if ($alias !== \WebnicStatus::ACTIVE) {
            $this->emit($order, 'recover.finalise', 'failed', null, 'indeterminate', 'registered_not_active:' . (string) $decision['status']);

            return ['ok' => false, 'to_state' => null, 'message_key' => 'recover.finalise_not_active'];
        }

        // Revive (failed -> intent) then walk the legal edges to active. No registry
        // write here: the live getExpirationDate read-through stays authoritative (AR18).
        // The order is keyed by the sentinel service_id=0 (Dev Notes §B). The whole
        // revive+walk is guarded: openIntent()/transition() throw on a CAS loss or an
        // illegal edge, and an unhandled throw here would fatal the Recovery tab and could
        // strand the order mid-walk (e.g. contacts_done) — surface it as a graceful
        // recovery error with an INV-9 record instead.
        // from_state starts at `failed`: if openIntent() itself throws, the order is still
        // failed, so the INV-9 record must not claim `intent` (it advances only after a
        // successful revive).
        $from = Orders::STATE_FAILED;
        $revived_id = null;
        try {
            // WN-4-1 atomic-revive guard (T5/AC8): revive ONLY while the row is still `failed`.
            // A concurrent resolve(failed->cancelled) between the findFailed preflight above and
            // here returns NOT_REVIVED — never re-seed a resolved order; tell the operator.
            $revived = $this->orders->openIntent(0, $domain, $module_row_id, Orders::OPERATION_REGISTER, Orders::STATE_FAILED);
            if (($revived->intent_status ?? null) === Orders::INTENT_STATUS_NOT_REVIVED) {
                $this->emit($order, 'recover.finalise', 'failed', null, 'indeterminate', 'not_failed_at_revive');

                return ['ok' => false, 'to_state' => null, 'message_key' => 'recover.not_failed'];
            }
            $revived_id = (int) $revived->id;
            $from = 'intent';

            foreach (self::ACTIVATE_WALK as $to) {
                if (!$this->orders->transition($revived_id, $to)) {
                    $this->emit($order, 'recover.finalise', $from, $to, 'indeterminate', 'cas_lost');
                    $this->restoreToFailed($revived_id);

                    return ['ok' => false, 'to_state' => null, 'message_key' => 'recover.finalise_failed'];
                }
                $from = $to;
            }
        } catch (\Throwable $e) {
            // A DB error mid-walk would otherwise strand the already-revived order in a
            // non-claimable interior state (contacts_done/registrant_done/hosts_done) that
            // no recovery path can reach. Best-effort restore it to `failed` so the
            // Recovery tab and give-up path still see it (every interior edge to failed is
            // legal). `submitted` is left alone — the reconciler claims it and self-heals.
            $this->emit($order, 'recover.finalise', $from, null, 'indeterminate', 'finalise_walk_error');
            if ($revived_id !== null) {
                $this->restoreToFailed($revived_id);
            }

            return ['ok' => false, 'to_state' => null, 'message_key' => 'recover.finalise_failed'];
        }

        $this->emit($order, 'recover.finalise', 'failed', Orders::STATE_ACTIVE, null, 'finalised_active');

        return ['ok' => true, 'to_state' => Orders::STATE_ACTIVE, 'message_key' => 'recover.finalise_ok'];
    }

    /**
     * Builds the masked tracking record for the failed-order view (AC4/INV-7).
     *
     * webnic_order_id is a secret and is deliberately never carried out of this class.
     *
     * @param \stdClass $order The webnic_orders row
     * @return \stdClass The view-safe tracking record
     */
    public function maskedTracking($order): \stdClass
    {
        return (object) [
            'id' => $order->id ?? null,
            'state' => $order->state ?? null,
            'attempts' => $order->attempts ?? 0,
            'created' => $order->created ?? null,
            'last_polled' => $order->last_polled ?? null,
            'give_up_at' => $order->give_up_at ?? null,
        ];
    }

    /**
     * Reads the correlated end-to-end trace from Blesta's module log (AC6).
     *
     * Reads `log_modules` THROUGH the Logs model (never raw SQL — project rule),
     * filtered by the domain (which the INV-9 records carry), scoped to this module,
     * newest-first. Each row's data is the serialize()d, already-scrubbed INV-9 record
     * (reconciler group cron/reconcile_orders, saga group domain/v2/register, recovery
     * group webnic/recover). Records were redacted at write time; the view masks again
     * defensively.
     *
     * @param mixed $logs The Blesta Logs model (getModuleList)
     * @param int $module_id The owning module id (correlation scope)
     * @param string $domain The order domain to correlate by
     * @return array A newest-first list of trace entries (date_added, url, status, record)
     */
    public function trace($logs, $module_id, string $domain): array
    {
        if ($domain === '' || !is_object($logs) || !method_exists($logs, 'getModuleList')) {
            return [];
        }

        // getModuleList() is paginated, so reading page 1 alone silently truncates the
        // "end-to-end" trace once a failed order accumulates more than one page of saga/
        // reconcile/recovery rows (AC6). Page through to exhaustion, de-duping by log row
        // id so a non-advancing reader (were the page arg ignored) can never loop, with a
        // hard page cap as a final backstop.
        $entries = [];
        $seen = [];
        $max_pages = 200;

        for ($page = 1; $page <= $max_pages; $page++) {
            try {
                // `id` tiebreaks `date_added` — saga/reconcile/recovery rows for one order
                // are often written in the same second, and OFFSET pagination needs a
                // deterministic order or a boundary row could be skipped (claude F2).
                $rows = (array) $logs->getModuleList($page, ['date_added' => 'DESC', 'id' => 'DESC'], false, ['string' => $domain]);
            } catch (\Throwable $e) {
                break;
            }
            if (empty($rows)) {
                break;
            }

            $new = 0;
            foreach ($rows as $row) {
                $rid = isset($row->id) ? (int) $row->id : null;
                if ($rid !== null) {
                    if (isset($seen[$rid])) {
                        continue;
                    }
                    $seen[$rid] = true;
                }
                $new++;

                if ((int) ($row->module_id ?? 0) !== (int) $module_id) {
                    continue;
                }

                $record = @unserialize((string) ($row->data ?? ''));
                // Records are scrubbed at write time; re-scrub defensively before render so
                // a pre-fix or hand-written row can never surface a raw secret (INV-7, §I).
                if (is_array($record) && class_exists('\Webnic\Support\Redactor')) {
                    $record = \Webnic\Support\Redactor::scrub($record);
                }

                $entries[] = (object) [
                    'date_added' => $row->date_added ?? null,
                    'url' => $row->url ?? null,
                    'status' => $row->status ?? null,
                    'record' => is_array($record) ? $record : null,
                ];
            }

            // No previously-unseen rows this page => the reader is not advancing (or the
            // result set is exhausted). Stop rather than re-scan the same page forever.
            if ($new === 0) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Reads the scoped failed order for a domain within its module row (INV-1).
     *
     * @param int $module_row_id The owning module row
     * @param string $domain The order domain (normalized)
     * @return \stdClass|null The failed order row, or null when none is in failed state
     */
    public function findFailed($module_row_id, string $domain)
    {
        $normalized = Orders::normalizeDomain($domain);
        if ($normalized === '' || (int) $module_row_id <= 0) {
            return null;
        }

        $order = $this->Record->select()
            ->from('webnic_orders')
            ->where('module_row_id', '=', (int) $module_row_id)
            ->where('domain', '=', $normalized)
            ->where('state', '=', Orders::STATE_FAILED)
            ->order(['id' => 'desc'])
            ->fetch();
        $this->Record->reset();

        return $order ?: null;
    }

    /**
     * Best-effort restores a revived order to `failed` after a mid-walk failure (AC5/§H).
     *
     * If finalise()'s revive→active walk fails partway (a thrown DB error, or a CAS loss),
     * the order can be stranded in a non-claimable interior state that no recovery path
     * reaches. Every such state has a legal edge to `failed`, so pulling it back makes it
     * visible to the Recovery tab / give-up path again. `submitted` is intentionally NOT
     * restored — the reconciler claims it and drives it to active on its own. Fully
     * guarded: a restore failure must never mask the original error.
     *
     * @param int $id The revived order id
     */
    private function restoreToFailed(int $id): void
    {
        try {
            $row = $this->Record->select(['state'])
                ->from('webnic_orders')
                ->where('id', '=', $id)
                ->fetch();
            $this->Record->reset();
            if (!$row) {
                return;
            }

            $interior = [
                Orders::STATE_INTENT,
                Orders::STATE_CONTACTS_DONE,
                Orders::STATE_REGISTRANT_DONE,
                Orders::STATE_HOSTS_DONE,
            ];
            if (in_array((string) $row->state, $interior, true)) {
                $this->orders->transition($id, Orders::STATE_FAILED);
            }
        } catch (\Throwable $e) {
            // Best-effort only; the give-up path / a later finalise can still recover it.
        }
    }

    /**
     * Emits a scrubbed INV-9 recovery record to the injected sink (AC5/AC6/INV-9).
     *
     * Mirrors the reconciler's emit(): level implied by error_class (terminal => error,
     * retryable/indeterminate => notice, normal => info). The sink performs the Redactor
     * scrub before writing; observability never interrupts a recovery action.
     *
     * @param \stdClass $order The order row
     * @param string $command One of recover.retry|recover.finalise|recover.resolve
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
            \call_user_func($this->logger, [
                'run_id' => $this->run_id,
                'level' => self::levelFor($error_class),
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
            // Observability must never interrupt a recovery action.
        }
    }

    /**
     * Maps an error class to the INV-9 structured log level.
     *
     * @param string|null $error_class retryable|terminal|indeterminate, or null
     * @return string info|notice|error
     */
    private static function levelFor($error_class): string
    {
        if ($error_class === 'terminal') {
            return 'error';
        }

        if (in_array($error_class, ['retryable', 'indeterminate'], true)) {
            return 'notice';
        }

        return 'info';
    }
}
