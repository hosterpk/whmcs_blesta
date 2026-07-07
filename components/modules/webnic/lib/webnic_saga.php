<?php
/**
 * WebNIC registration + transfer saga (WN-3-2 register / WN-4-1 transfer).
 *
 * Webnic\Saga\CompensationPlanBuilder is a PURE function (no DB, no I/O, no time()):
 * given the state the saga reached and the resources it created this attempt, it
 * returns an ordered list of compensating-action descriptors in reverse creation
 * order (AR16). Computing the plan is deliberately separate from executing it, so a
 * reconciler can retry execution without re-deriving completed compensation.
 *
 * Endpoints WITH a usable inverse (contact/create -> delete, host/create -> delete)
 * emit a 'delete' descriptor; endpoints WITHOUT one (the shared registrant account;
 * a submitted Register / Transfer, whose deletion is a separate FR26 lifecycle op, not
 * a saga rollback) emit a 'track_orphan' descriptor — tracked/logged, NEVER auto-deleted.
 *
 * The op-agnostic saga machinery (open intent flow, contacts->registrant steps, the
 * guarded move()/emit()/persist() primitives, the INV-4 success/failure builders and
 * the AR16 compensation runner) lives in the SagaSupport trait, shared verbatim by the
 * register saga (RegistrationSaga) and the transfer saga (TransferSaga, WN-4-1). The
 * two sagas differ only in their final step and pre-steps: register does
 * contacts->registrant->>=2 hosts->Register; transfer does contacts->registrant->
 * Submit Registrar Transfer In (no hosts — OTE-captured, config/webnic.php T0 block).
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
namespace Webnic\Saga;

class CompensationPlanBuilder
{
    /**
     * The compensating action for a resource that can be inverted.
     */
    public const ACTION_DELETE = 'delete';

    /**
     * The compensating action for a resource with no usable inverse op.
     */
    public const ACTION_TRACK_ORPHAN = 'track_orphan';

    /**
     * Builds the ordered compensation plan for a partial-saga failure (pure, AR16).
     *
     * No DB, no I/O, no time(): the plan is a deterministic function of (reached
     * state, resources created this attempt). Resources are compensated in reverse
     * creation order (last created, first undone). A resource type with a known
     * inverse becomes a 'delete'; one without becomes a 'track_orphan' marker that a
     * caller logs (INV-9) and never auto-deletes.
     *
     * @param string $reached_state The last durable state the saga reached
     * @param array $resources Resources created this attempt, in creation order; each
     *  is ['type' => 'contact'|'host'|'registrant'|'register'|'transfer', 'id' => string|null]
     * @return array Ordered descriptors: [
     *  'order'         => int,    // 0-based compensation order
     *  'resource'      => string, // resource type
     *  'id'            => mixed,  // resource id (redacted by the caller before a log)
     *  'action'        => 'delete'|'track_orphan',
     *  'reversible'    => bool,
     *  'reason'        => string,
     *  'reached_state' => string  // echoed for the observability record
     * ]
     */
    public static function build(string $reached_state, array $resources): array
    {
        $plan = [];
        $order = 0;

        foreach (array_reverse($resources) as $resource) {
            $type = isset($resource['type']) ? (string) $resource['type'] : 'unknown';
            $descriptor = self::descriptorFor($type);
            $descriptor['order'] = $order++;
            $descriptor['id'] = $resource['id'] ?? null;
            $descriptor['reached_state'] = $reached_state;
            $plan[] = $descriptor;
        }

        return $plan;
    }

    /**
     * Maps a resource type to its inverse-action descriptor (pure).
     *
     * @param string $type The resource type
     * @return array The descriptor skeleton (order/id/reached_state filled by build())
     */
    private static function descriptorFor(string $type): array
    {
        switch ($type) {
            case 'contact':
                return [
                    'resource' => 'contact',
                    'action' => self::ACTION_DELETE,
                    'reversible' => true,
                    'reason' => 'contact/create has an inverse (delete/tombstone)',
                ];
            case 'host':
                return [
                    'resource' => 'host',
                    'action' => self::ACTION_DELETE,
                    'reversible' => true,
                    'reason' => 'host/create has an inverse (delete)',
                ];
            case 'registrant':
                return [
                    'resource' => 'registrant',
                    'action' => self::ACTION_TRACK_ORPHAN,
                    'reversible' => false,
                    'reason' => 'registrant account has no inverse op (AR16) — track, never auto-delete',
                ];
            case 'register':
                return [
                    'resource' => 'register',
                    'action' => self::ACTION_TRACK_ORPHAN,
                    'reversible' => false,
                    'reason' => 'submitted Register has no saga-rollback inverse; deletion is FR26 lifecycle (AR16)',
                ];
            case 'transfer':
                return [
                    'resource' => 'transfer',
                    'action' => self::ACTION_TRACK_ORPHAN,
                    'reversible' => false,
                    'reason' => 'submitted Transfer In has no saga-rollback inverse; cancellation is a lifecycle op (AR16)',
                ];
            default:
                return [
                    'resource' => $type,
                    'action' => self::ACTION_TRACK_ORPHAN,
                    'reversible' => false,
                    'reason' => 'unknown resource — tracked, never auto-deleted',
                ];
        }
    }
}

/**
 * The op-agnostic saga machinery shared by RegistrationSaga and TransferSaga (WN-4-1).
 *
 * These helpers were extracted VERBATIM from RegistrationSaga so the proven money-safe
 * register path is behaviorally unchanged (its full suite is the regression net) while
 * the transfer saga reuses the same contacts->registrant steps, the guarded move()/
 * emit()/persist() primitives, the INV-4 success/failure builders, and the AR16
 * compensation runner — one source of truth, no duplication.
 */
trait SagaSupport
{
    /**
     * @var mixed Webnic\Orders (openIntent/transition)
     */
    protected $orders;

    /**
     * @var mixed Webnic\ContactsMap (findByClient/store)
     */
    protected $contactsMap;

    /**
     * @var mixed WebnicContacts (createContact/queryContact/createRegistrant)
     */
    protected $contactsApi;

    /**
     * @var callable|null Structured-log sink (record is scrubbed at the sink, INV-9)
     */
    protected $logger;

    /**
     * @var callable|null Persists the order correlation id onto the row ($id, $value)
     */
    protected $persistId;

    /**
     * Resolves the four contact handles, reusing the client's cached handles (FR14).
     *
     * One contact is created and its id reused for all four roles (the observed
     * multi-role-capable spike). Returns null on a malformed-success / error so the
     * caller surfaces terminal — never proceed to Register/Transfer without all four.
     *
     * @param array $ctx The saga context
     * @param \stdClass $order The order row
     * @param array $resources Created-this-attempt resource list (by reference)
     * @return array|null ['registrant','admin','technical','billing' => handle] or null
     */
    protected function ensureContacts(array $ctx, $order, array &$resources)
    {
        $cached = $this->contactsMap->findByClient($ctx['client_id'], $ctx['module_row_id']);
        if ($cached !== null && !empty($cached->registrant_handle)) {
            return $this->fourRoleHandles($cached->registrant_handle, $cached);
        }

        $response = $this->contactsApi->createContact('registrant', $ctx['contact']);
        $contact_id = \WebnicContacts::parseContactId($response);
        $this->emit($ctx, $order, 'domain/v2/contact/create', null, null, $response->success() ? null : $response->errorClass(), $contact_id === null ? 'malformed' : 'ok');

        if ($contact_id === null) {
            return null;
        }

        $resources[] = ['type' => 'contact', 'id' => $contact_id];
        $this->contactsMap->store($ctx['client_id'], $ctx['module_row_id'], [
            'registrant_handle' => $contact_id,
            'admin_handle' => $contact_id,
            'technical_handle' => $contact_id,
            'billing_handle' => $contact_id,
        ]);

        return $this->fourRoleHandles($contact_id, null);
    }

    /**
     * Ensures the shared registrant account exists, reusing the client's id (FR14).
     *
     * @param array $ctx The saga context
     * @param \stdClass $order The order row
     * @param array $resources Created-this-attempt resource list (by reference)
     * @return string|null The registrant account id, or null on malformed/error
     */
    protected function ensureRegistrant(array $ctx, $order, array &$resources)
    {
        $cached = $this->contactsMap->findByClient($ctx['client_id'], $ctx['module_row_id']);
        if ($cached !== null && !empty($cached->registrant_user_id)) {
            return $cached->registrant_user_id;
        }

        $response = $this->contactsApi->createRegistrant($ctx['registrant_username']);
        $registrant_user_id = \WebnicContacts::parseRegistrantUserId($response);
        $this->emit($ctx, $order, 'domain/v2/registrant/create', null, null, $response->success() ? null : $response->errorClass(), $registrant_user_id === null ? 'malformed' : 'ok');

        if ($registrant_user_id === null) {
            return null;
        }

        $resources[] = ['type' => 'registrant', 'id' => $registrant_user_id];
        $this->contactsMap->store($ctx['client_id'], $ctx['module_row_id'], [
            'registrant_user_id' => $registrant_user_id,
        ]);

        return $registrant_user_id;
    }

    /**
     * Maps a single contact id onto the four role handles, preferring cached roles.
     *
     * @param string $default_handle The fallback handle (the reused contact id)
     * @param \stdClass|null $cached The cached row, if reusing
     * @return array The four role handles
     */
    protected function fourRoleHandles($default_handle, $cached): array
    {
        return [
            'registrant' => $default_handle,
            'admin' => ($cached && !empty($cached->admin_handle)) ? $cached->admin_handle : $default_handle,
            'technical' => ($cached && !empty($cached->technical_handle)) ? $cached->technical_handle : $default_handle,
            'billing' => ($cached && !empty($cached->billing_handle)) ? $cached->billing_handle : $default_handle,
        ];
    }

    /**
     * Computes the compensation plan, executes it, and drives the row to failed (AR16).
     *
     * @param array $ctx The saga context
     * @param \stdClass $order The order row
     * @param string $reached_state The last durable state reached
     * @param array $resources Resources created this attempt
     * @param string $error_key The language-key suffix to surface
     * @return array A failed result
     */
    protected function compensateAndFail(array $ctx, $order, string $reached_state, array $resources, $error_key): array
    {
        $plan = CompensationPlanBuilder::build($reached_state, $resources);
        $this->executeCompensation($ctx, $order, $plan);

        if ($reached_state !== \Webnic\Orders::STATE_FAILED) {
            try {
                $this->move($ctx, $order, $reached_state, \Webnic\Orders::STATE_FAILED);
            } catch (\Throwable $e) {
                // EPP-safety (AC2/§F, round-2 R2-3): this shared sink is reachable by the transfer
                // saga, so emit the exception TYPE, never its message — a secret can never ride a
                // class name into the INV-9 log (mirrors the P7 submit/reconcile catches).
                $this->emit($ctx, $order, 'exception', $reached_state, \Webnic\Orders::STATE_FAILED, 'indeterminate', get_class($e));
            }
        }

        return $this->failure($error_key, \Webnic\Orders::STATE_FAILED);
    }

    /**
     * Executes a compensation plan — tracked, never destructive in WN-3-2 (AR16).
     *
     * Execution is intentionally conservative: every action is TRACKED (logged via the
     * redaction sink, INV-9), not fired. Contacts are reusable assets cached for the
     * client (deleting one would break reuse), and the contact/host delete + the
     * registrant/Register inverses are [UNVERIFIED] (no captured delete contract).
     * Tracked-orphan resources are never deleted by contract. A later story flips
     * verified deletes on; computing the plan is already separate from executing it.
     *
     * @param array $ctx The saga context
     * @param \stdClass $order The order row
     * @param array $plan The ordered compensation plan
     */
    protected function executeCompensation(array $ctx, $order, array $plan): void
    {
        foreach ($plan as $action) {
            $this->emit(
                $ctx,
                $order,
                'compensate:' . $action['resource'],
                null,
                null,
                $action['reversible'] ? null : 'tracked_orphan',
                $action['action']
            );
        }
    }

    /**
     * Applies a guarded transition and emits the INV-9 record.
     *
     * @param array $ctx The saga context
     * @param \stdClass $order The order row
     * @param string $from The current state
     * @param string $to The target state
     */
    protected function move(array $ctx, $order, string $from, string $to): void
    {
        $applied = $this->orders->transition($order->id, $to);
        $this->emit($ctx, $order, 'transition', $from, $to, $applied ? null : 'indeterminate', $applied ? 'applied' : 'noop');
        if (!$applied) {
            throw new \RuntimeException(sprintf('webnic_orders transition %s -> %s did not apply.', $from, $to));
        }
    }

    /**
     * Persists the order correlation id onto the row via the injected writer.
     *
     * @param \stdClass $order The order row
     * @param string|null $value The correlation id (webnic_order_id or transfer_id)
     */
    protected function persist($order, $value): void
    {
        if ($value === null || $this->persistId === null) {
            return;
        }

        call_user_func($this->persistId, $order->id, $value);
    }

    /**
     * Builds a success result with the complete service-field array (INV-4).
     *
     * @param string $status active|pending|reused
     * @param string $domain The registered/transferred domain
     * @param string|null $order_id The correlation id, if any
     * @param string|null $dtexpire The registry expiry, if known
     * @param string[] $nameservers The nameservers to persist (register only)
     * @return array The success result
     */
    protected function success(string $status, string $domain, $order_id, $dtexpire, array $nameservers = []): array
    {
        return [
            'outcome' => 'success',
            'status' => $status,
            'fields' => $this->serviceFields($domain, $dtexpire, $nameservers),
            'order_id' => $order_id,
            'error_key' => null,
        ];
    }

    /**
     * Builds a failed result (false + Input error at the addService boundary).
     *
     * @param string $error_key The language-key suffix
     * @param string $state The final state
     * @return array The failed result
     */
    protected function failure(string $error_key, string $state): array
    {
        return [
            'outcome' => 'failed',
            'status' => $state,
            'fields' => null,
            'order_id' => null,
            'error_key' => $error_key,
        ];
    }

    /**
     * Builds the complete service-field array Blesta full-replaces via setFields().
     *
     * @param string $domain The registered/transferred domain
     * @param string|null $dtexpire The registry expiry, if known
     * @param string[] $nameservers The registered nameservers, persisted as ns1..ns5
     * @return array The numerically-indexed service-field array
     */
    protected function serviceFields(string $domain, $dtexpire, array $nameservers = []): array
    {
        $fields = [
            ['key' => 'domain', 'value' => $domain, 'encrypted' => 0],
        ];

        if ($dtexpire !== null && $dtexpire !== '') {
            $fields[] = ['key' => 'expiration_date', 'value' => $dtexpire, 'encrypted' => 0];
        }

        // Persist the registered nameservers (public hostnames -> encrypted=0) so a
        // failed-order retry can REPLAY the customer's choice instead of silently
        // reverting to the WebNIC shared NS (WN-3-4a P15/D3). setFields() full-replaces
        // the service fields, so these must be re-emitted on EVERY success/reconcile path
        // that returns fields, or a reconcile would wipe them.
        $i = 1;
        foreach (array_values($nameservers) as $ns) {
            if ($i > 5) {
                break;
            }
            $ns = trim((string) $ns);
            if ($ns !== '') {
                $fields[] = ['key' => 'ns' . $i, 'value' => $ns, 'encrypted' => 0];
                $i++;
            }
        }

        return $fields;
    }

    /**
     * Emits an INV-9 structured-log record to the injected sink (scrubbed there).
     *
     * @param array $ctx The saga context
     * @param \stdClass $order The order row
     * @param string $command The saga command / step
     * @param string|null $from_state The from state, if a transition
     * @param string|null $to_state The to state, if a transition
     * @param string|null $error_class retryable|terminal|indeterminate, or null
     * @param string|null $message A short, non-secret message
     */
    protected function emit(array $ctx, $order, string $command, $from_state, $to_state, $error_class, $message): void
    {
        if ($this->logger === null) {
            return;
        }

        call_user_func($this->logger, [
            'run_id' => $ctx['run_id'] ?? null,
            'module_row_id' => $ctx['module_row_id'] ?? null,
            'service_id' => $ctx['service_id'] ?? null,
            'domain' => $ctx['domain'] ?? null,
            'command' => $command,
            'from_state' => $from_state,
            'to_state' => $to_state,
            'error_class' => $error_class,
            'attempt' => isset($order->attempts) ? (int) $order->attempts : null,
            'message' => $message,
        ]);
    }
}

/**
 * The deterministic registration saga (WN-3-2, AC1/AC2/AC3/AC5).
 *
 * The money-safe orchestration core behind Webnic::addService(): it opens the
 * durable intent, runs the contacts -> registrant -> >=2 hosts -> Register saga
 * (each step a legal Webnic\Orders::transition()), parses the Register response, and
 * returns a transport-neutral result the thin addService glue maps onto Blesta's
 * INV-4 return contract (complete service-field array on success / false + Input
 * error only on failed — never an Input error for an async-pending order).
 *
 * Collaborators are injected (duck-typed, like WebnicApi's token store) so the saga
 * runs against fixture-replay command groups with no DB and no spend (AC8). The saga
 * never returns from an interior state: every failure path drives the durable row to
 * `failed` first, and a live/registered order is reconciled-by-domain, never blind-
 * resubmitted (AC5/INV-5).
 */
class RegistrationSaga
{
    use SagaSupport;

    /**
     * @var mixed WebnicHosts (checkHost/createHost)
     */
    private $hostsApi;

    /**
     * @var mixed WebnicDomains (register/info)
     */
    private $domainsApi;

    /**
     * @var array The pre-register interior states (never a legal addService return).
     */
    private const INTERIOR_STATES = [
        'contacts_done',
        'registrant_done',
        'hosts_done',
        'submitted',
    ];

    /**
     * @var array The two live "already done" states a retry must never clobber.
     */
    private const LIVE_DONE_STATES = ['active', 'registrar_pending'];

    /**
     * @var string[] Default WebNIC shared nameservers (registry-known, no host/create).
     */
    public const DEFAULT_NAMESERVERS = ['ns1.webnic.cc', 'ns2.webnic.cc'];

    /**
     * Wires the saga collaborators.
     *
     * @param mixed $orders Webnic\Orders
     * @param mixed $contactsMap Webnic\ContactsMap
     * @param mixed $contactsApi WebnicContacts
     * @param mixed $hostsApi WebnicHosts
     * @param mixed $domainsApi WebnicDomains
     * @param callable|null $logger Structured-log sink (scrubs + writes)
     * @param callable|null $persistOrderId Persists webnic_order_id onto the order row
     */
    public function __construct(
        $orders,
        $contactsMap,
        $contactsApi,
        $hostsApi,
        $domainsApi,
        callable $logger = null,
        callable $persistOrderId = null
    ) {
        $this->orders = $orders;
        $this->contactsMap = $contactsMap;
        $this->contactsApi = $contactsApi;
        $this->hostsApi = $hostsApi;
        $this->domainsApi = $domainsApi;
        $this->logger = $logger;
        $this->persistId = $persistOrderId;
    }

    /**
     * Runs the registration saga and returns a transport-neutral result (INV-4).
     *
     * @param array $ctx Resolved registration context: run_id, service_id,
     *  module_row_id, client_id, domain (normalized), ext, term, nameservers,
     *  contact (mapClientToContact output), registrant_username, prior_state.
     * @return array Result: [
     *  'outcome'   => 'success'|'failed',
     *  'status'    => string,        // active|pending|reused (success) | final state
     *  'fields'    => array|null,    // complete service-field array on success
     *  'order_id'  => string|null,
     *  'error_key' => string|null,   // language-key suffix on failure
     * ]
     */
    public function run(array $ctx): array
    {
        // WN-4-1 atomic-revive guard (T5/AC8): when this run is a recovery retry of a terminal
        // order (prior_state failed/cancelled), revive ONLY while the row is still in that exact
        // state — a concurrent resolve(failed->cancelled) between the preflight and here must not
        // re-seed a resolved order. A fresh/live order passes null (revive-any / existing-live).
        $expected_state = in_array($ctx['prior_state'] ?? null, \Webnic\Orders::TERMINAL_STATES, true)
            ? $ctx['prior_state']
            : null;
        $order = $this->orders->openIntent(
            $ctx['service_id'],
            $ctx['domain'],
            $ctx['module_row_id'],
            \Webnic\Orders::OPERATION_REGISTER,
            $expected_state
        );

        if (($order->intent_status ?? null) === \Webnic\Orders::INTENT_STATUS_NOT_REVIVED) {
            // The order left its expected terminal state since the preflight — never re-seed it.
            return $this->failure('register_already_resolved', $order->state);
        }

        // (a) A live, already-completed order — never clobber it (INV-5).
        if (in_array($order->state, self::LIVE_DONE_STATES, true)) {
            return $this->reuseLiveOrder($ctx, $order);
        }

        // (b) A live, in-flight interior order — reconcile, never blind-resubmit.
        if (in_array($order->state, self::INTERIOR_STATES, true)
            || (
                $order->state === \Webnic\Orders::STATE_INTENT
                && ($order->intent_status ?? null) === \Webnic\Orders::INTENT_STATUS_EXISTING_LIVE
            )
        ) {
            return $this->reconcileInFlight($ctx, $order);
        }

        // state == intent below. (c) A revived terminal row is a retry: reconcile
        // by-domain BEFORE resubmitting (AC5). A definitive not-registered is the ONLY
        // green light; registered/indeterminate must not resubmit.
        if (in_array($ctx['prior_state'] ?? null, \Webnic\Orders::TERMINAL_STATES, true)) {
            try {
                $info = $this->domainsApi->info($ctx['domain']);
                $decision = \WebnicDomains::decideReconcileRegister($info);
            } catch (\Throwable $e) {
                // The revive (failed -> intent) already committed at the top of run(); a
                // throw in this by-domain reconcile (e.g. a transport blip) would otherwise
                // strand the order in `intent` — non-claimable and invisible to recovery.
                // Drive it back to failed and surface indeterminate so the operator can
                // retry; never leave a revived retry orphaned (round-2 codex #2).
                $this->emit($ctx, $order, 'reconcile/retry', $order->state, \Webnic\Orders::STATE_FAILED, 'indeterminate', 'reconcile_error');
                $this->move($ctx, $order, $order->state, \Webnic\Orders::STATE_FAILED);

                return $this->failure('register_reconcile_indeterminate', \Webnic\Orders::STATE_FAILED);
            }

            $this->emit($ctx, $order, 'reconcile/retry', $order->state, null, null, $decision['outcome']);

            if ($decision['outcome'] === 'registered') {
                // Already registered out-of-band: never resubmit (no double-charge).
                // Recovering a failed-but-registered order is Story 3.4a's job.
                $this->move($ctx, $order, $order->state, \Webnic\Orders::STATE_FAILED);

                return $this->failure('register_already_registered', \Webnic\Orders::STATE_FAILED);
            }

            if ($decision['outcome'] !== 'not_registered') {
                $this->move($ctx, $order, $order->state, \Webnic\Orders::STATE_FAILED);

                return $this->failure('register_reconcile_indeterminate', \Webnic\Orders::STATE_FAILED);
            }
        }

        // (d) Fresh intent, or a retry confirmed not-registered — run the saga.
        return $this->runSaga($ctx, $order);
    }

    /**
     * Executes the four saga steps from a fresh intent row.
     *
     * @param array $ctx The registration context
     * @param \stdClass $order The intent order row
     * @return array The saga result
     */
    private function runSaga(array $ctx, $order): array
    {
        $resources = [];
        $state = \Webnic\Orders::STATE_INTENT;

        try {
            // Step 1 — four contact handles, reused by client (FR14).
            $handles = $this->ensureContacts($ctx, $order, $resources);
            if ($handles === null) {
                return $this->compensateAndFail($ctx, $order, $state, $resources, 'register_malformed');
            }
            $this->move($ctx, $order, $state, \Webnic\Orders::STATE_CONTACTS_DONE);
            $state = \Webnic\Orders::STATE_CONTACTS_DONE;

            // Step 2 — shared registrant account (FR14).
            $registrant = $this->ensureRegistrant($ctx, $order, $resources);
            if ($registrant === null) {
                return $this->compensateAndFail($ctx, $order, $state, $resources, 'register_malformed');
            }
            $this->move($ctx, $order, $state, \Webnic\Orders::STATE_REGISTRANT_DONE);
            $state = \Webnic\Orders::STATE_REGISTRANT_DONE;

            // Step 3 — >=2 registry-known host objects (FR15).
            $nameservers = $this->ensureHosts($ctx, $order, $resources);
            if ($nameservers === null) {
                return $this->compensateAndFail($ctx, $order, $state, $resources, 'register_host_unavailable');
            }
            $this->move($ctx, $order, $state, \Webnic\Orders::STATE_HOSTS_DONE);
            $state = \Webnic\Orders::STATE_HOSTS_DONE;

            // Step 4 — Register (FR16). Submit only after the durable submitted move.
            $this->move($ctx, $order, $state, \Webnic\Orders::STATE_SUBMITTED);
            $state = \Webnic\Orders::STATE_SUBMITTED;

            $response = $this->domainsApi->register(
                $this->buildRegisterBody($ctx, $handles, $registrant, $nameservers)
            );
            $parsed = \WebnicResponse::parseRegister($response);
            $this->emit($ctx, $order, 'domain/v2/register', $state, null, $parsed['error_class'] ?? null, $parsed['outcome']);

            if ($parsed['outcome'] === 'active') {
                $this->move($ctx, $order, $state, \Webnic\Orders::STATE_ACTIVE);

                return $this->success('active', $ctx['domain'], null, $parsed['dtexpire'], $ctx['nameservers'] ?? []);
            }

            if ($parsed['outcome'] === 'pending') {
                $this->persist($order, $parsed['order_id']);
                $this->move($ctx, $order, $state, \Webnic\Orders::STATE_REGISTRAR_PENDING);

                return $this->success('pending', $ctx['domain'], $parsed['order_id'], $parsed['dtexpire'], $ctx['nameservers'] ?? []);
            }

            // failed: a non-terminal class means the Register MIGHT have placed — track
            // it as a potential orphan (AR16) before driving to failed. A clean terminal
            // reject (DOM2400/DOM4000) placed nothing, so it adds no register resource.
            if (($parsed['error_class'] ?? null) !== 'terminal') {
                $resources[] = ['type' => 'register', 'id' => $ctx['domain']];
            }

            return $this->compensateAndFail($ctx, $order, $state, $resources, $parsed['error_key']);
        } catch (\Throwable $e) {
            $this->emit($ctx, $order, 'exception', $state, null, 'indeterminate', $e->getMessage());

            return $this->compensateAndFail($ctx, $order, $state, $resources, 'register_failed');
        }
    }

    /**
     * Ensures >=2 registry-known host objects before Register (FR15).
     *
     * available=false hosts (the observed WebNIC shared NS) are used directly; an
     * available=true host is created; an indeterminate host check, or fewer than two
     * usable hosts, fails the step. NOTE (WN-3-6 §H): the create branch is UNWIRED today
     * (ctx host_ips is never populated, so it early-returns) and still reads the result via
     * `$created->success()`, which is FALSE on the live `code:3000` host/create batch envelope.
     * Wiring this branch (Epic 5 / WN-4-1) MUST switch it to WebnicHosts::decideHostCreateResult();
     * the saga is left untouched here (verification-only, §H). WN-4-1 confirmed transfer-in does
     * NOT create hosts (no NS/host fields in the captured body), so the §H fix stays in Epic 5.
     *
     * @param array $ctx The registration context
     * @param \stdClass $order The order row
     * @param array $resources Created-this-attempt resource list (by reference)
     * @return array|null The usable nameserver list (>=2), or null on failure
     */
    private function ensureHosts(array $ctx, $order, array &$resources)
    {
        $usable = [];

        foreach ($ctx['nameservers'] as $nameserver) {
            $check = $this->hostsApi->checkHost($nameserver, $ctx['ext']);
            $decision = \WebnicHosts::decideHostCreate($check);
            $this->emit($ctx, $order, 'domain/v2/host/check', null, null, $check->success() ? null : $check->errorClass(), $decision['outcome']);

            if ($decision['outcome'] === 'ready') {
                $usable[] = $nameserver;
                continue;
            }

            if ($decision['outcome'] === 'create') {
                $ip_list = $ctx['host_ips'][$nameserver] ?? [];
                if (empty($ip_list)) {
                    $this->emit($ctx, $order, 'domain/v2/host/create/extension', null, null, 'indeterminate', 'missing_host_ips');

                    return null;
                }

                $created = $this->hostsApi->createHost($nameserver, $ip_list, $ctx['ext']);
                if (!$created->success()) {
                    return null;
                }
                $resources[] = ['type' => 'host', 'id' => $nameserver];
                $usable[] = $nameserver;
                continue;
            }

            return null;
        }

        return count($usable) >= 2 ? $usable : null;
    }

    /**
     * Builds the observed Register request body (config/webnic.php field_map, FR16).
     *
     * @param array $ctx The registration context
     * @param array $handles The four role handles
     * @param string $registrant_user_id The registrant account id
     * @param array $nameservers The usable nameserver list
     * @return array The Register request body
     */
    private function buildRegisterBody(array $ctx, array $handles, $registrant_user_id, array $nameservers): array
    {
        return [
            'domainName' => $ctx['domain'],
            'term' => (int) $ctx['term'],
            'nameservers' => array_values($nameservers),
            'registrantContactId' => $handles['registrant'],
            'administratorContactId' => $handles['admin'],
            'technicalContactId' => $handles['technical'],
            'billingContactId' => $handles['billing'],
            'registrantUserId' => $registrant_user_id,
        ];
    }

    /**
     * Reconciles a live, already-completed order without clobbering it (INV-5).
     *
     * @param array $ctx The registration context
     * @param \stdClass $order The live order row
     * @return array A success result (no resubmit)
     */
    private function reuseLiveOrder(array $ctx, $order): array
    {
        $dtexpire = null;
        try {
            $info = $this->domainsApi->info($ctx['domain']);
            $decision = \WebnicDomains::decideReconcileRegister($info);
            $dtexpire = $decision['dtexpire'];
            $this->emit($ctx, $order, 'reconcile/reuse', $order->state, null, $info->success() ? null : $info->errorClass(), 'reused');
        } catch (\Throwable $e) {
            $this->emit($ctx, $order, 'reconcile/reuse', $order->state, null, 'indeterminate', $e->getMessage());
        }

        return $this->success('reused', $ctx['domain'], $order->webnic_order_id ?? null, $dtexpire, $ctx['nameservers'] ?? []);
    }

    /**
     * Reconciles a live, in-flight interior order (AC5/INV-4).
     *
     * Only a registered domain whose state can legally reach `active` (submitted /
     * registrar_pending) resolves to success; everything else drives to `failed`, so
     * the saga never returns from an interior state.
     *
     * @param array $ctx The registration context
     * @param \stdClass $order The in-flight order row
     * @return array The reconcile result
     */
    private function reconcileInFlight(array $ctx, $order): array
    {
        $info = $this->domainsApi->info($ctx['domain']);
        $decision = \WebnicDomains::decideReconcileRegister($info);
        $can_activate = \Webnic\Orders::isLegalTransition($order->state, \Webnic\Orders::STATE_ACTIVE);
        $this->emit($ctx, $order, 'reconcile/in-flight', $order->state, null, $decision['outcome'] === 'registered' ? null : 'indeterminate', $decision['outcome']);

        if ($decision['outcome'] === 'registered' && $can_activate) {
            $this->move($ctx, $order, $order->state, \Webnic\Orders::STATE_ACTIVE);

            return $this->success('reused', $ctx['domain'], $order->webnic_order_id ?? null, $decision['dtexpire'], $ctx['nameservers'] ?? []);
        }

        $this->move($ctx, $order, $order->state, \Webnic\Orders::STATE_FAILED);

        return $this->failure('register_in_flight_unresolved', \Webnic\Orders::STATE_FAILED);
    }
}

/**
 * The deterministic transfer-in saga (WN-4-1, AC1/AC6).
 *
 * The transfer analog of RegistrationSaga: open the durable `operation=transfer`
 * intent, run contacts -> registrant (the OTE-captured transfer-in body REQUIRES the 4
 * contact handles + registrantUserId, config/webnic.php T0 block), then Submit Registrar
 * Transfer In with the client's EPP (authInfo). There is NO hosts step (no NS/host fields
 * in the captured body), so the saga skips hosts_done: registrant_done -> submitted is the
 * one new legal edge. A transfer is INHERENTLY async — an accepted submit always lands in
 * registrar_pending (a SUCCESS / full field array, never an Input error — INV-4), and the
 * reconciler finalises it by-domain via transfer-in/status. Never blind-resubmit a live
 * row: reconcile by-domain first (transfer's in-flight read is DIRECT, INV-5).
 */
class TransferSaga
{
    use SagaSupport;

    /**
     * @var mixed WebnicTransfers (submitTransferIn/transferStatus)
     */
    private $transfersApi;

    /**
     * @var mixed WebnicDomains (info — for the dtexpire read on the complete path)
     */
    private $domainsApi;

    /**
     * @var array The pre-submit interior states (never a legal addService return).
     */
    private const INTERIOR_STATES = [
        'contacts_done',
        'registrant_done',
        'submitted',
    ];

    /**
     * Wires the transfer saga collaborators.
     *
     * @param mixed $orders Webnic\Orders
     * @param mixed $contactsMap Webnic\ContactsMap
     * @param mixed $contactsApi WebnicContacts
     * @param mixed $transfersApi WebnicTransfers
     * @param mixed $domainsApi WebnicDomains
     * @param callable|null $logger Structured-log sink (scrubs + writes)
     * @param callable|null $persistTransferId Persists transfer_id onto the order row
     */
    public function __construct(
        $orders,
        $contactsMap,
        $contactsApi,
        $transfersApi,
        $domainsApi,
        callable $logger = null,
        callable $persistTransferId = null
    ) {
        $this->orders = $orders;
        $this->contactsMap = $contactsMap;
        $this->contactsApi = $contactsApi;
        $this->transfersApi = $transfersApi;
        $this->domainsApi = $domainsApi;
        $this->logger = $logger;
        $this->persistId = $persistTransferId;
    }

    /**
     * Runs the transfer saga and returns a transport-neutral result (INV-4).
     *
     * @param array $ctx Resolved transfer context: run_id, service_id, module_row_id,
     *  client_id, domain (normalized), ext, term, auth_code (EPP), contact, registrant_username,
     *  prior_state.
     * @return array Result: same shape as RegistrationSaga::run().
     */
    public function run(array $ctx): array
    {
        // WN-4-1 atomic-revive guard (T5/AC8): on a recovery retry of a terminal order, revive
        // only while still in that exact state (never re-seed a concurrently-resolved order).
        $expected_state = in_array($ctx['prior_state'] ?? null, \Webnic\Orders::TERMINAL_STATES, true)
            ? $ctx['prior_state']
            : null;
        $order = $this->orders->openIntent(
            $ctx['service_id'],
            $ctx['domain'],
            $ctx['module_row_id'],
            \Webnic\Orders::OPERATION_TRANSFER,
            $expected_state
        );

        if (($order->intent_status ?? null) === \Webnic\Orders::INTENT_STATUS_NOT_REVIVED) {
            return $this->failure('transfer_already_resolved', $order->state);
        }

        // (a) Already active — the transfer completed out-of-band; reuse, never resubmit. Read the
        //     registry expiry best-effort so the reused field array carries the real expiration_date
        //     (setFields is a full delete+reinsert replace, so a null expiry would otherwise BLANK
        //     the active service's stored expiry). Round-1 P5.
        if ($order->state === \Webnic\Orders::STATE_ACTIVE) {
            return $this->reuseActiveOrder($ctx, $order);
        }

        // (b) A live in-flight order (registrar_pending / interior / existing-live intent)
        //     — reconcile by transfer-in/status, never blind-resubmit (INV-5/INV-4).
        if ($order->state === \Webnic\Orders::STATE_REGISTRAR_PENDING
            || in_array($order->state, self::INTERIOR_STATES, true)
            || (
                $order->state === \Webnic\Orders::STATE_INTENT
                && ($order->intent_status ?? null) === \Webnic\Orders::INTENT_STATUS_EXISTING_LIVE
            )
        ) {
            return $this->reconcileInFlight($ctx, $order);
        }

        // state == intent (fresh or revived terminal retry).
        // (c) A revived terminal retry: reconcile by-domain BEFORE resubmitting. Only
        //     'absent' (no record) or 'failed' (a prior attempt the registry rejected) is a
        //     green light; an in-flight/complete/indeterminate transfer must NOT be resubmitted.
        if (in_array($ctx['prior_state'] ?? null, \Webnic\Orders::TERMINAL_STATES, true)) {
            try {
                $status = $this->transfersApi->transferStatus($ctx['domain']);
                $decision = \WebnicTransfers::decideReconcileTransfer($status);
            } catch (\Throwable $e) {
                $this->emit($ctx, $order, 'reconcile/retry', $order->state, \Webnic\Orders::STATE_FAILED, 'indeterminate', 'reconcile_error');
                $this->move($ctx, $order, $order->state, \Webnic\Orders::STATE_FAILED);

                return $this->failure('transfer_reconcile_indeterminate', \Webnic\Orders::STATE_FAILED);
            }

            $this->emit($ctx, $order, 'reconcile/retry', $order->state, null, null, $decision['outcome']);

            if ($decision['outcome'] !== 'absent' && $decision['outcome'] !== 'failed') {
                // active / pending / approve / indeterminate -> a transfer exists or is uncertain;
                // never resubmit (no double transfer), and never activate from the saga (D1 —
                // activation is the reconciler-grade path's job). Surface for recovery with an
                // outcome-distinct reason: a COMPLETED transfer is not "in progress" (P3), so the
                // operator/recovery flow knows the domain already transferred in.
                $this->move($ctx, $order, $order->state, \Webnic\Orders::STATE_FAILED);

                if ($decision['outcome'] === 'active') {
                    return $this->failure('transfer_already_active', \Webnic\Orders::STATE_FAILED);
                }

                $key = $decision['outcome'] === 'indeterminate'
                    ? 'transfer_reconcile_indeterminate'
                    : 'transfer_already_in_progress';

                return $this->failure($key, \Webnic\Orders::STATE_FAILED);
            }
        }

        // (d) Fresh intent, or a retry confirmed safe -> run the transfer saga.
        return $this->runTransferSaga($ctx, $order);
    }

    /**
     * Executes the transfer saga steps from a fresh intent row (contacts -> registrant -> submit).
     *
     * @param array $ctx The transfer context
     * @param \stdClass $order The intent order row
     * @return array The saga result
     */
    private function runTransferSaga(array $ctx, $order): array
    {
        $resources = [];
        $state = \Webnic\Orders::STATE_INTENT;

        try {
            // Step 1 — four contact handles, reused by client (FR14).
            $handles = $this->ensureContacts($ctx, $order, $resources);
            if ($handles === null) {
                return $this->compensateAndFail($ctx, $order, $state, $resources, 'transfer_failed');
            }
            $this->move($ctx, $order, $state, \Webnic\Orders::STATE_CONTACTS_DONE);
            $state = \Webnic\Orders::STATE_CONTACTS_DONE;

            // Step 2 — shared registrant account (FR14).
            $registrant = $this->ensureRegistrant($ctx, $order, $resources);
            if ($registrant === null) {
                return $this->compensateAndFail($ctx, $order, $state, $resources, 'transfer_failed');
            }
            $this->move($ctx, $order, $state, \Webnic\Orders::STATE_REGISTRANT_DONE);
            $state = \Webnic\Orders::STATE_REGISTRANT_DONE;

            // Step 3 — Submit Registrar Transfer In (FR20). NO hosts step (OTE-captured).
            // Submit only after the durable submitted move (registrant_done -> submitted edge).
            $this->move($ctx, $order, $state, \Webnic\Orders::STATE_SUBMITTED);
            $state = \Webnic\Orders::STATE_SUBMITTED;

            $response = $this->transfersApi->submitTransferIn(
                $this->buildTransferBody($ctx, $handles, $registrant)
            );
            $decision = \WebnicTransfers::decideTransferSubmit($response);
            $this->emit($ctx, $order, 'domain/v2/transfer-in', $state, null, $decision['error_class'] ?? null, $decision['outcome']);

            if ($decision['outcome'] === 'submitted') {
                // A transfer is inherently async: an accepted submit lands in registrar_pending,
                // and the reconciler finalises it by-domain. Persist the best-effort transfer_id.
                $this->persist($order, $decision['transfer_id']);
                $this->move($ctx, $order, $state, \Webnic\Orders::STATE_REGISTRAR_PENDING);

                return $this->success('pending', $ctx['domain'], $decision['transfer_id'], null, []);
            }

            // failed: a non-terminal class means the submit MIGHT have placed — track it as a
            // potential orphan (AR16). A clean terminal reject placed nothing.
            if (($decision['error_class'] ?? null) !== 'terminal') {
                $resources[] = ['type' => 'transfer', 'id' => $ctx['domain']];
            }

            return $this->compensateAndFail($ctx, $order, $state, $resources, $decision['error_key']);
        } catch (\Throwable $e) {
            // EPP-safety (AC2/§F): the submit body carries the client's EPP, and a transport/decode
            // exception could echo it in free prose that Redactor::scrubString (key=value/JSON only)
            // would not mask. Emit the exception TYPE, never its message — no secret can ride a class
            // name into the INV-9 log. Round-1 P7.
            $this->emit($ctx, $order, 'exception', $state, null, 'indeterminate', get_class($e));

            return $this->compensateAndFail($ctx, $order, $state, $resources, 'transfer_failed');
        }
    }

    /**
     * Reuses a live, already-active transfer order without clobbering it (INV-5; Round-1 P5).
     *
     * Mirrors RegistrationSaga::reuseLiveOrder — reads the registry expiry best-effort so the
     * returned field array carries the real expiration_date (setFields is a full replace; a null
     * expiry would blank the active service's stored expiry). NOT an activation (the row is already
     * active), so it is allowed even under the reconciler-only-activation policy (D1).
     *
     * @param array $ctx The transfer context
     * @param \stdClass $order The active order row
     * @return array A success('reused') result (no resubmit, no transition)
     */
    private function reuseActiveOrder(array $ctx, $order): array
    {
        $dtexpire = $this->readExpiry($ctx);
        $this->emit($ctx, $order, 'reconcile/reuse', $order->state, null, $dtexpire === null ? 'indeterminate' : null, 'reused');

        return $this->success('reused', $ctx['domain'], $order->transfer_id ?? null, $dtexpire, []);
    }

    /**
     * Reconciles a live in-flight transfer order by transfer-in/status (AC6/INV-4/NFR5).
     *
     * Decision #D1 (resolved 2026-06-17): the SAGA NEVER performs a -> active transition.
     * Activation (active + a parseable expiry + the transfer-completed email) is owned exclusively
     * by the reconciler-grade path. So a CLAIMABLE row (submitted / registrar_pending — exactly the
     * states that can legally reach active and that the reconciler polls) is left `pending` for the
     * reconciler to finalise; a definitive registry reject drives to `failed`; and a NON-claimable
     * interior row (contacts_done / registrant_done / an existing-live intent — a prior attempt that
     * crashed before submit) is driven to `failed` (mirror register's "never return from an interior
     * state") rather than returned as a pending success the cron will never claim. Round-1 P1/P4/P5.
     *
     * @param array $ctx The transfer context
     * @param \stdClass $order The live order row
     * @return array The reconcile result
     */
    private function reconcileInFlight(array $ctx, $order): array
    {
        // Claimable === can legally reach active === the states the reconciler polls.
        $claimable = \Webnic\Orders::isLegalTransition($order->state, \Webnic\Orders::STATE_ACTIVE);

        try {
            $status = $this->transfersApi->transferStatus($ctx['domain']);
            $decision = \WebnicTransfers::decideReconcileTransfer($status);
        } catch (\Throwable $e) {
            // EPP-safety (AC2/§F): emit the exception TYPE, never its message (free prose could
            // carry a secret past the keyed-only scrubber). Round-1 P7.
            $this->emit($ctx, $order, 'reconcile/in-flight', $order->state, null, 'indeterminate', get_class($e));

            // A transport blip leaves the outcome unknown. A claimable row stays in flight (the
            // reconciler retries); a non-claimable interior row cannot be claimed, so surface it for
            // recovery instead of stranding a paid order as a never-claimed pending (Round-1 P1).
            if ($claimable) {
                return $this->success('pending', $ctx['domain'], $order->transfer_id ?? null, null, []);
            }

            $this->move($ctx, $order, $order->state, \Webnic\Orders::STATE_FAILED);

            return $this->failure('transfer_in_flight_unresolved', \Webnic\Orders::STATE_FAILED);
        }

        $outcome = $decision['outcome'];
        $this->emit($ctx, $order, 'reconcile/in-flight', $order->state, null, $outcome === 'active' ? null : 'indeterminate', $outcome);

        // A definitive registry reject is terminal regardless of the row's state.
        if ($outcome === 'failed' && \Webnic\Orders::isLegalTransition($order->state, \Webnic\Orders::STATE_FAILED)) {
            $this->move($ctx, $order, $order->state, \Webnic\Orders::STATE_FAILED);

            return $this->failure('transfer_failed', \Webnic\Orders::STATE_FAILED);
        }

        // active / pending / approve / absent / indeterminate: never resubmit, never flip to a wrong
        // terminal, and (D1) never activate here. A claimable row waits for the reconciler-grade
        // finalisation; a non-claimable interior row is surfaced for recovery (never stranded).
        if ($claimable) {
            return $this->success('pending', $ctx['domain'], $order->transfer_id ?? null, null, []);
        }

        $this->move($ctx, $order, $order->state, \Webnic\Orders::STATE_FAILED);

        return $this->failure('transfer_in_flight_unresolved', \Webnic\Orders::STATE_FAILED);
    }

    /**
     * Reads the registry expiry for a completed transfer via Get Domain Info.
     *
     * transfer-in/status carries no dtexpire, so finalisation reads it from info(); any
     * failure returns null (the service activates without an expiry the reconciler later fills).
     *
     * @param array $ctx The transfer context
     * @return string|null The dtexpire ISO string, or null when unavailable
     */
    private function readExpiry(array $ctx)
    {
        try {
            $info = $this->domainsApi->info($ctx['domain']);
            $decision = \WebnicDomains::decideReconcileRegister($info);

            // Only a registered (active-at-registry) info result carries a trustworthy expiry; a
            // non-active or error decision must not yield a stale/wrong dtexpire (Round-1 P12).
            if (($decision['outcome'] ?? null) !== 'registered') {
                return null;
            }

            return $decision['dtexpire'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Builds the OTE-captured Submit Registrar Transfer In body (FR20).
     *
     * Required keys (config/webnic.php T0 block): domainName, term, authInfo (the EPP),
     * the four *ContactId handles, registrantUserId. NO nameservers/hosts (not required).
     *
     * @param array $ctx The transfer context (carries auth_code = the client's EPP)
     * @param array $handles The four role handles
     * @param string $registrant_user_id The registrant account id
     * @return array The transfer-in request body
     */
    private function buildTransferBody(array $ctx, array $handles, $registrant_user_id): array
    {
        return [
            'domainName' => $ctx['domain'],
            'term' => (int) $ctx['term'],
            'authInfo' => (string) ($ctx['auth_code'] ?? ''),
            'registrantContactId' => $handles['registrant'],
            'administratorContactId' => $handles['admin'],
            'technicalContactId' => $handles['technical'],
            'billingContactId' => $handles['billing'],
            'registrantUserId' => $registrant_user_id,
        ];
    }
}
