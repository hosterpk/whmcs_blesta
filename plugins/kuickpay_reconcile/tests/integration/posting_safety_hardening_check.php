<?php
/**
 * Opt-in DB-backed proof of the Story 5.3 reconcile/posting safety hardening.
 *
 * Proves, against the real Blesta + MySQL/MariaDB stack:
 *   AC3 migration — the 1.9.0 -> 1.10.0 upgrade adds posting_attempts
 *         (INT UNSIGNED NOT NULL DEFAULT 0); existing rows default to 0; the
 *         guard is idempotent; and a fresh-install application of the SAME ALTER
 *         converges on the identical column definition.
 *   AC1 — a status-guarded terminal write against a confirmed_unposted voucher
 *         matches ZERO rows (no demotion), the row keeps its date_paid, and the
 *         company-scoped re-read returns the real current status (a no-op).
 *   AC3 (clock) — date_expires = CURDATE() is reconcilable and NOT expirable;
 *         date_expires = CURDATE() - 1 day is expirable and NOT reconcilable
 *         (exact complement on one clock; the limbo window is removed, not guarded).
 *   AC5a — a write failure inside the per-Voucher transaction rolls the Voucher
 *         edit back (no partial confirmed_unposted state).
 *   AC5b — a duplicate (company_id, lock_name) insert returns false (lock held).
 *   AC5c — a deterministically-failing post increments posting_attempts and, at
 *         POSTING_RETRY_LIMIT, escalates the Voucher to manual_review so the next
 *         postable batch advances past it.
 *
 * This script MUTATES the configured database: it runs the real plugin upgrade
 * and seeds/deletes disposable voucher + lock rows (synthetic high invoice ids;
 * no real customer invoices or transactions are touched). It cleans up every row
 * it creates. Single-process: the guards are exercised deterministically against
 * the real DB -- true multi-process concurrency is NOT claimed.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This harness is CLI-only.\n");
    exit(2);
}

$options = getopt('', [
    'company-id::',
    'plugin-id::',
    'gateway-id::',
    'client-id::',
    'i-understand-this-mutates-kuickpay-vouchers',
    'help',
]);

if (isset($options['help'])) {
    kp53_usage();
    exit(0);
}

if (!array_key_exists('i-understand-this-mutates-kuickpay-vouchers', $options)) {
    fwrite(STDERR, "Refusing to run without --i-understand-this-mutates-kuickpay-vouchers.\n\n");
    kp53_usage();
    exit(2);
}

$companyId = isset($options['company-id']) ? (int) $options['company-id'] : 1;
$gatewayId = isset($options['gateway-id']) ? (int) $options['gateway-id'] : 1;
$clientId = isset($options['client-id']) ? (int) $options['client-id'] : 1;

$root = dirname(__DIR__, 4);
$container = include $root . '/lib/init.php';
error_reporting(E_ALL);

$pluginLib = $root . '/plugins/kuickpay_reconcile/lib';
$gatewayLib = $root . '/components/gateways/nonmerchant/kuickpay/lib';
Loader::load($gatewayLib . '/KuickPayRedactor.php');
Loader::load($gatewayLib . '/KuickPayEvidence.php');
Loader::load($gatewayLib . '/KuickPayResponseParser.php');
Loader::load($pluginLib . '/KuickPayValidationResult.php');
Loader::load($pluginLib . '/KuickPayVoucherRepository.php');
Loader::load($pluginLib . '/KuickPayReconciliationRunRepository.php');
Loader::load($pluginLib . '/KuickPayReconciliationItemRepository.php');
Loader::load($pluginLib . '/KuickPayReconcileLockRepository.php');
Loader::load($pluginLib . '/KuickPayAuditRepository.php');
Loader::load($pluginLib . '/KuickPayAuditService.php');
Loader::load($pluginLib . '/KuickPayInvoiceReader.php');
Loader::load($pluginLib . '/KuickPayEvidenceValidator.php');
Loader::load($pluginLib . '/KuickPayReconcileService.php');
Loader::load($pluginLib . '/KuickPayPostingService.php');
Loader::load($root . '/plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php');

$bootstrap = new stdClass();
Loader::loadComponents($bootstrap, ['Record']);
Loader::loadModels($bootstrap, ['PluginManager']);
Loader::loadModels($bootstrap, ['KuickpayReconcile.KuickpayVouchers', 'KuickpayReconcile.KuickpayReconcileLocks']);
$record = $bootstrap->Record;
$pluginManager = $bootstrap->PluginManager;
$vouchersModel = $bootstrap->KuickpayVouchers;
$locksModel = $bootstrap->KuickpayReconcileLocks;

$pluginId = isset($options['plugin-id'])
    ? (int) $options['plugin-id']
    : kp53_discover_plugin_id($record, $companyId);

if (!$pluginId) {
    fwrite(STDERR, "Could not resolve the kuickpay_reconcile plugin id for company {$companyId}; pass --plugin-id.\n");
    exit(1);
}

$scratchTable = 'kuickpay_vouchers_kp53_scratch';
$evidence = [];
$createdVoucherIds = [];
$harnessLockName = 'kp53_harness_lock';
$failures = [];

$gatewayConfig = [
    'wsdl_url' => 'https://example.invalid/api.asmx?WSDL',
    'institution_id' => 'KP01',
    'inquiry_username' => 'inq',
    'inquiry_password' => 'inq',
    'voucher_username' => 'vou',
    'voucher_password' => 'vou',
    'inquiry_same_as_voucher' => 'false',
    'underpayment_policy' => 'manual_review',
    'overpayment_policy' => 'manual_review',
    'late_payment_policy' => 'manual_review',
];

try {
    // ---- AC3 migration: capture BEFORE state -----------------------------
    $before = [
        'plugin_version' => kp53_plugin_version($record, $pluginId),
        'posting_attempts' => kp53_column_exists($record, 'kuickpay_vouchers', 'posting_attempts'),
    ];
    $evidence['migration_before'] = $before;

    // Fresh-install scratch: apply the SAME ALTER to a pre-upgrade-shape table.
    kp53_drop_table($record, $scratchTable);
    $record->query("CREATE TABLE `{$scratchTable}` LIKE `kuickpay_vouchers`");
    if (kp53_column_exists($record, $scratchTable, 'posting_attempts')) {
        $record->query("ALTER TABLE `{$scratchTable}` DROP COLUMN `posting_attempts`");
    }
    $record->query(KuickpayReconcilePlugin::postingAttemptsColumnSql($scratchTable));
    $scratchFragment = kp53_posting_attempts_fragment(kp53_show_create($record, $scratchTable));

    // Run the REAL 1.9.0 -> 1.10.0 upgrade (once).
    $upgradeRan = false;
    if (version_compare((string) $before['plugin_version'], '1.10.0', '<')) {
        $pluginManager->upgrade($pluginId);
        if (($errors = $pluginManager->errors())) {
            $failures[] = 'PluginManager::upgrade reported errors';
            $evidence['upgrade_errors'] = kp53_redact_errors($errors);
        }
        $upgradeRan = true;
    }

    $after = [
        'plugin_version' => kp53_plugin_version($record, $pluginId),
        'posting_attempts' => kp53_column_exists($record, 'kuickpay_vouchers', 'posting_attempts'),
    ];
    $upgradeFragment = kp53_posting_attempts_fragment(kp53_show_create($record, 'kuickpay_vouchers'));
    $evidence['migration_after'] = $after;
    $evidence['migration_upgrade_ran_this_invocation'] = $upgradeRan;
    $evidence['migration_fresh_install_fragment'] = $scratchFragment;
    $evidence['migration_upgrade_fragment'] = $upgradeFragment;
    $evidence['migration_fresh_matches_upgrade'] = ($scratchFragment !== '' && $scratchFragment === $upgradeFragment);

    if (!$after['posting_attempts']) {
        $failures[] = 'Migration: posting_attempts column missing after upgrade';
    }
    if ($scratchFragment === '' || $scratchFragment !== $upgradeFragment) {
        $failures[] = 'Migration: fresh-install column differs from upgrade column';
    }

    // Existing rows default posting_attempts = 0.
    $nonZero = (int) ($record->query(
        'SELECT COUNT(*) c FROM kuickpay_vouchers WHERE posting_attempts <> 0'
    )->fetch()->c ?? -1);
    $evidence['migration_existing_rows_nonzero'] = $nonZero;
    if ($nonZero !== 0) {
        $failures[] = 'Migration: existing rows did not default posting_attempts to 0';
    }

    // Idempotent re-run via the real plugin upgrade branch.
    $plugin = new KuickpayReconcilePlugin();
    $plugin->upgrade('1.9.0', $pluginId);
    $idempotentErrors = $plugin->errors();
    $evidence['migration_idempotent_rerun_clean'] = empty($idempotentErrors);
    if (!empty($idempotentErrors)) {
        $failures[] = 'Migration: guard re-run was not a clean no-op';
        $evidence['idempotent_errors'] = kp53_redact_errors($idempotentErrors);
    }

    $repository = new KuickPayVoucherRepository();

    // ---- AC1: status-guarded terminal write is a no-op on a confirmed row -
    $ac1 = kp53_seed_voucher($record, $companyId, $gatewayId, $clientId, 'pending', null);
    $createdVoucherIds[] = $ac1['id'];
    // Simulate the cron having won the race: the row is already confirmed_unposted
    // with a date_paid before the manual reconcile's terminal write runs.
    $record->query(
        "UPDATE kuickpay_vouchers SET status = 'confirmed_unposted', date_paid = '2026-06-09 00:00:00',"
        . ' kuickpay_reference = ?, date_updated = NOW() WHERE id = ?',
        'KP53-REF-' . $ac1['suffix'],
        $ac1['id']
    );
    // The racing manual reconcile would write manual_review; the guarded write must match 0 rows.
    $transitioned = $repository->editIfActive((int) $ac1['id'], $companyId, [
        'status' => 'manual_review',
        'diagnostic_summary' => json_encode(['validation_errors' => ['stale_voucher']]),
        'date_last_checked' => date('Y-m-d H:i:s'),
    ]);
    $ac1Row = $record->query(
        'SELECT status, date_paid FROM kuickpay_vouchers WHERE id = ?',
        $ac1['id']
    )->fetch();
    $ac1ReadBack = $repository->getForCompany((int) $ac1['id'], $companyId);
    $evidence['ac1_guarded_write_transitioned'] = $transitioned;
    $evidence['ac1_status_after'] = (string) ($ac1Row->status ?? '');
    $evidence['ac1_date_paid_intact'] = ((string) ($ac1Row->date_paid ?? '')) === '2026-06-09 00:00:00';
    $evidence['ac1_readback_status'] = (string) ($ac1ReadBack->status ?? '');
    if ($transitioned !== false
        || (string) ($ac1Row->status ?? '') !== 'confirmed_unposted'
        || (string) ($ac1ReadBack->status ?? '') !== 'confirmed_unposted'
        || ((string) ($ac1Row->date_paid ?? '')) !== '2026-06-09 00:00:00'
    ) {
        $failures[] = 'AC1: a racing manual reconcile demoted a confirmed_unposted voucher';
    }

    // ---- AC3 clock: reconcilable/expirable are exact complements ----------
    $today = kp53_seed_voucher($record, $companyId, $gatewayId, $clientId, 'pending', 'CURDATE()');
    $yesterday = kp53_seed_voucher($record, $companyId, $gatewayId, $clientId, 'pending', 'CURDATE() - INTERVAL 1 DAY');
    $createdVoucherIds[] = $today['id'];
    $createdVoucherIds[] = $yesterday['id'];

    $reconcilableIds = kp53_ids($repository->getReconcilable($companyId, 500, 0, date('Y-m-d H:i:s', time() + 86400)));
    $expirableIds = kp53_ids($repository->getExpirable($companyId, 500, 0));

    $todayReconcilable = in_array($today['id'], $reconcilableIds, true);
    $todayExpirable = in_array($today['id'], $expirableIds, true);
    $yesterdayReconcilable = in_array($yesterday['id'], $reconcilableIds, true);
    $yesterdayExpirable = in_array($yesterday['id'], $expirableIds, true);
    $evidence['ac3_today_reconcilable_not_expirable'] = ($todayReconcilable && !$todayExpirable);
    $evidence['ac3_yesterday_expirable_not_reconcilable'] = ($yesterdayExpirable && !$yesterdayReconcilable);
    if (!$todayReconcilable || $todayExpirable || !$yesterdayExpirable || $yesterdayReconcilable) {
        $failures[] = 'AC3: reconcilable/expirable selectors are not exact complements on one clock';
    }

    // ---- AC5a: per-Voucher transaction rolls back on a mid-block failure ---
    $ac5a = kp53_seed_voucher($record, $companyId, $gatewayId, $clientId, 'pending', 'CURDATE() + INTERVAL 7 DAY');
    $createdVoucherIds[] = $ac5a['id'];
    $ac5aVoucher = $repository->getForCompany((int) $ac5a['id'], $companyId);
    $rollbackService = new KuickPayReconcileService([
        'voucher_repository' => $repository,
        'run_repository' => new Kp53NullRunRepository(),
        'item_repository' => new Kp53ThrowingItemRepository(),
        'lock_repository' => new Kp53NullLockRepository(),
        'audit_service' => new Kp53NullAuditService(),
        'parser' => new KuickPayResponseParser(),
        'evidence_validator' => new Kp53ValidEvidenceValidator(),
        'gateway_config' => $gatewayConfig,
    ]);
    $client = new Kp53PaidInquiryClient($ac5a['consumer'], '1000.00');
    $rollbackOutcome = $rollbackService->processVoucher($companyId, 0, $ac5aVoucher, $client, $gatewayConfig);
    $ac5aRow = $record->query('SELECT status FROM kuickpay_vouchers WHERE id = ?', $ac5a['id'])->fetch();
    $evidence['ac5a_outcome_error'] = (bool) $rollbackOutcome['error'];
    $evidence['ac5a_status_after_rollback'] = (string) ($ac5aRow->status ?? '');
    if (!$rollbackOutcome['error'] || (string) ($ac5aRow->status ?? '') !== 'pending') {
        $failures[] = 'AC5a: the per-Voucher transaction did not roll the Voucher edit back';
    }

    // ---- AC5b: duplicate lock insert returns false (lock held) ------------
    $expires = date('Y-m-d H:i:s', time() + 600);
    $record->query(
        'DELETE FROM kuickpay_reconcile_locks WHERE company_id = ? AND lock_name = ?',
        $companyId,
        $harnessLockName
    );
    $firstInsert = $locksModel->insertLock($companyId, $harnessLockName, 'kp53-token-1', $expires);
    $duplicateInsert = $locksModel->insertLock($companyId, $harnessLockName, 'kp53-token-2', $expires);
    $evidence['ac5b_first_insert_true'] = $firstInsert;
    $evidence['ac5b_duplicate_returns_false'] = ($duplicateInsert === false);
    $evidence['ac5b_infra_surface'] = 'unit-proven (a real infra failure cannot be safely induced here)';
    if ($firstInsert !== true || $duplicateInsert !== false) {
        $failures[] = 'AC5b: duplicate lock insert was not classified as lock_held';
    }
    $record->query(
        'DELETE FROM kuickpay_reconcile_locks WHERE company_id = ? AND lock_name = ?',
        $companyId,
        $harnessLockName
    );

    // ---- AC5c: posting retry cap escalates and clears the head ------------
    $ac5c = kp53_seed_voucher($record, $companyId, $gatewayId, $clientId, 'confirmed_unposted', null, [
        'date_paid' => '2026-06-09 00:00:00',
        'kuickpay_reference' => 'KP53-POST-',
    ]);
    $createdVoucherIds[] = $ac5c['id'];
    $postingService = new KuickPayPostingService([
        'voucher_repository' => $repository,
        'evidence_validator' => new Kp53ValidEvidenceValidator(),
        'audit_service' => new Kp53NullAuditService(),
        'lock_repository' => new Kp53NullLockRepository(),
        'transactions' => new Kp53FailingTransactions(),
    ]);
    $ac5cOutcomes = [];
    for ($i = 0; $i < KuickPayPostingService::POSTING_RETRY_LIMIT; $i++) {
        $voucherRow = $repository->getForCompany((int) $ac5c['id'], $companyId);
        $ac5cOutcomes[] = $postingService->postVoucher($companyId, $voucherRow)['outcome'];
    }
    $ac5cRow = $record->query(
        'SELECT status, posting_attempts FROM kuickpay_vouchers WHERE id = ?',
        $ac5c['id']
    )->fetch();
    $postableIds = kp53_ids($repository->getPostable($companyId, 500, 0));
    $evidence['ac5c_outcomes'] = $ac5cOutcomes;
    $evidence['ac5c_status_after'] = (string) ($ac5cRow->status ?? '');
    $evidence['ac5c_posting_attempts'] = (int) ($ac5cRow->posting_attempts ?? -1);
    $evidence['ac5c_postable_advanced_past_it'] = !in_array($ac5c['id'], $postableIds, true);
    if ((string) ($ac5cRow->status ?? '') !== 'manual_review'
        || (int) ($ac5cRow->posting_attempts ?? -1) !== KuickPayPostingService::POSTING_RETRY_LIMIT
        || end($ac5cOutcomes) !== 'manual_review'
        || in_array($ac5c['id'], $postableIds, true)
    ) {
        $failures[] = 'AC5c: posting retry cap did not escalate and clear the head-of-line block';
    }
} catch (Throwable $e) {
    $failures[] = 'Harness exception: ' . kp53_redact_message($e->getMessage());
} finally {
    $createdVoucherIds = array_values(array_unique(array_filter($createdVoucherIds)));
    foreach ($createdVoucherIds as $id) {
        try {
            $record->query('DELETE FROM kuickpay_voucher_invoices WHERE voucher_id = ?', $id);
            $record->query('DELETE FROM kuickpay_audit_events WHERE voucher_id = ?', $id);
            $record->query('DELETE FROM kuickpay_reconciliation_items WHERE voucher_id = ?', $id);
            $record->query('DELETE FROM kuickpay_vouchers WHERE id = ?', $id);
        } catch (Throwable $e) {
            $failures[] = 'Cleanup failed for voucher ' . $id . ': ' . kp53_redact_message($e->getMessage());
        }
    }
    try {
        $record->query(
            'DELETE FROM kuickpay_reconcile_locks WHERE company_id = ? AND lock_name = ?',
            $companyId,
            $harnessLockName
        );
    } catch (Throwable $e) {
        $failures[] = 'Lock cleanup failed: ' . kp53_redact_message($e->getMessage());
    }
    try {
        kp53_drop_table($record, $scratchTable);
    } catch (Throwable $e) {
        $failures[] = 'Scratch table drop failed: ' . kp53_redact_message($e->getMessage());
    }
    $evidence['cleanup_voucher_ids'] = $createdVoucherIds;
}

$evidence['result'] = empty($failures) ? 'PASS' : 'FAIL';
$evidence['failures'] = $failures;

echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(empty($failures) ? 0 : 1);

// ---------------------------------------------------------------------------

function kp53_usage(): void
{
    fwrite(STDERR, <<<'TEXT'
Usage:
  php plugins/kuickpay_reconcile/tests/integration/posting_safety_hardening_check.php \
    --i-understand-this-mutates-kuickpay-vouchers [--company-id=1] [--gateway-id=1] \
    [--client-id=1] [--plugin-id=<id>]

Runs the real 1.9.0 -> 1.10.0 plugin upgrade (once), then proves the Story 5.3
reconcile/posting safety hardening (AC1/AC3/AC5 + migration) on the real DB using
disposable synthetic rows. Cleans up every row it creates and drops its scratch
table. No real customer invoices or transactions are touched.

TEXT);
}

function kp53_seed_voucher($record, int $companyId, int $gatewayId, int $clientId, string $status, ?string $expiresExpr, array $extra = []): array
{
    $suffix = strtoupper(bin2hex(random_bytes(5)));
    $consumer = 'KP53CON' . $suffix;
    $reg = 'KP53REG' . $suffix;
    $contextKey = sha1('kp53:' . $suffix);
    $expires = $expiresExpr === null ? 'NULL' : $expiresExpr;
    $datePaid = isset($extra['date_paid']) ? "'" . $extra['date_paid'] . "'" : 'NULL';
    $reference = isset($extra['kuickpay_reference']) ? ($extra['kuickpay_reference'] . $suffix) : null;

    $record->query(
        'INSERT INTO kuickpay_vouchers'
        . ' (company_id, gateway_id, client_id, currency, amount, status, registration_number,'
        . ' consumer_number, context_key, date_expires, date_paid, kuickpay_reference, date_created, date_updated)'
        . " VALUES (?,?,?, 'PKR', '1000.00', ?, ?, ?, ?, {$expires}, {$datePaid}, ?, NOW(), NOW())",
        $companyId,
        $gatewayId,
        $clientId,
        $status,
        $reg,
        $consumer,
        $contextKey,
        $reference
    );

    return [
        'id' => (int) $record->lastInsertId(),
        'consumer' => $consumer,
        'suffix' => $suffix,
    ];
}

function kp53_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int) ($row->id ?? 0);
    }

    return $ids;
}

function kp53_discover_plugin_id($record, int $companyId): int
{
    $row = $record->query(
        "SELECT id FROM plugins WHERE dir = 'kuickpay_reconcile' AND company_id = ? LIMIT 1",
        $companyId
    )->fetch();

    return (int) ($row->id ?? 0);
}

function kp53_plugin_version($record, int $pluginId): string
{
    $row = $record->query('SELECT version FROM plugins WHERE id = ?', $pluginId)->fetch();

    return (string) ($row->version ?? '');
}

function kp53_column_exists($record, string $table, string $column): bool
{
    return (bool) $record->query('SHOW COLUMNS FROM `' . $table . '` LIKE ?', $column)->fetch();
}

function kp53_show_create($record, string $table): string
{
    $row = (array) $record->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
    foreach ($row as $key => $value) {
        if (strcasecmp((string) $key, 'Create Table') === 0) {
            return (string) $value;
        }
    }

    return '';
}

function kp53_posting_attempts_fragment(string $createSql): string
{
    foreach (preg_split('/\r?\n/', $createSql) as $line) {
        $normalized = preg_replace('/\s+/', ' ', trim(rtrim(trim($line), ',')));
        if (strpos($normalized, '`posting_attempts`') === 0) {
            return $normalized;
        }
    }

    return '';
}

function kp53_drop_table($record, string $table): void
{
    $record->query('DROP TABLE IF EXISTS `' . $table . '`');
}

function kp53_redact_message(string $message): string
{
    return preg_replace('/Duplicate entry \'[^\']*\'/', "Duplicate entry '<redacted>'", $message);
}

function kp53_redact_errors(array $errors): array
{
    $flat = [];
    array_walk_recursive($errors, function ($value) use (&$flat) {
        $flat[] = kp53_redact_message((string) $value);
    });

    return $flat;
}

/** Run repository that opens no real run rows. */
class Kp53NullRunRepository
{
    public function getResumeCursor(int $company_id, string $trigger_type = 'cron'): int
    {
        return 0;
    }

    public function open(int $company_id, string $trigger_type, int $cursor): int
    {
        return 0;
    }

    public function openBulk(int $company_id, string $run_date): int
    {
        return 0;
    }

    public function updateCursor(int $run_id, int $company_id, int $cursor): void
    {
    }

    public function close(int $run_id, int $company_id, string $status, array $counts, int $cursor, string $summary): void
    {
    }
}

/** Item repository that fails inside the wrapped block to force a rollback. */
class Kp53ThrowingItemRepository
{
    public function record(array $vars): void
    {
        throw new RuntimeException('simulated item write failure');
    }
}

class Kp53NullLockRepository
{
    public function acquire(int $company_id, string $lockName, int $ttlSeconds): ?string
    {
        return 'kp53-owner';
    }

    public function release(int $company_id, string $lockName, string $ownerToken): void
    {
    }
}

class Kp53NullAuditService
{
    public function record(string $eventName, array $context): void
    {
    }
}

class Kp53ValidEvidenceValidator
{
    public function validate($voucher, array $invoiceLinks, KuickPayEvidence $evidence, array $allowedStatuses = ['pending', 'retry']): KuickPayValidationResult
    {
        return new KuickPayValidationResult(true, []);
    }
}

/** SOAP client stub returning a paid-exact single inquiry result. */
class Kp53PaidInquiryClient
{
    private string $consumer;
    private string $amount;

    public function __construct(string $consumer, string $amount)
    {
        $this->consumer = $consumer;
        $this->amount = $amount;
    }

    public function billPaymentInquiry(array $params): array
    {
        return [
            'ok' => true,
            'operation' => 'BillPaymentInquiry',
            'raw_result' => '00,' . $this->consumer . ',20260609,' . $this->amount . ',1234567890,KP53-REF,PKR',
            'error_class' => null,
            'redacted_trace_id' => 'kp53_trace',
        ];
    }
}

/** Transactions stub whose add() always fails, to drive the posting retry cap. */
class Kp53FailingTransactions
{
    public function getByTransactionId($transaction_id, $client_id = null, $gateway_id = null)
    {
        return false;
    }

    public function getList($client_id = null, $status = 'approved', $page = 1, $order_by = ['date_added' => 'DESC'], array $filters = []): array
    {
        return [];
    }

    public function add(array $vars)
    {
        return null;
    }

    public function apply($transaction_id, array $vars): void
    {
    }

    public function getApplied($transaction_id = null, $invoice_id = null): array
    {
        return [];
    }

    public function errors(): array
    {
        return ['transaction_id' => ['failed']];
    }
}
