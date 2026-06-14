<?php
/**
 * Opt-in DB-backed proof of the Story 5.2 active-context concurrency guard.
 *
 * Proves, against the real Blesta + MySQL/MariaDB stack:
 *   AC3 — the 1.8.0 -> 1.9.0 migration adds context_key, the STORED generated
 *         active_context_key, and uniq_kuickpay_vouchers_active_context; existing
 *         rows backfill non-NULL; the guard is idempotent; and a fresh-install
 *         application of the SAME guard converges on the identical schema.
 *   AC1 — two same-invoice-set issuance attempts resolve to exactly one active
 *         pending voucher (the loser's INSERT fails on the unique key and the
 *         create-null fall-through returns the winner).
 *   Release semantics — cancelling a voucher releases the slot (generated column
 *         recomputes to NULL on UPDATE); a paid 'posted' voucher keeps it.
 *
 * This script MUTATES the configured database: it runs the real plugin upgrade
 * and seeds/deletes disposable voucher rows (synthetic high invoice ids; no real
 * customer invoices are touched). It cleans up every row it creates and drops
 * its scratch table. Single-process: the unique-key collision is the
 * deterministic, real-DB proof — true multi-process concurrency is NOT claimed.
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
    kp52_usage();
    exit(0);
}

if (!array_key_exists('i-understand-this-mutates-kuickpay-vouchers', $options)) {
    fwrite(STDERR, "Refusing to run without --i-understand-this-mutates-kuickpay-vouchers.\n\n");
    kp52_usage();
    exit(2);
}

$companyId = isset($options['company-id']) ? (int) $options['company-id'] : 1;
$gatewayId = isset($options['gateway-id']) ? (int) $options['gateway-id'] : 1;
$clientId = isset($options['client-id']) ? (int) $options['client-id'] : 1;

$root = dirname(__DIR__, 4);
$container = include $root . '/lib/init.php';
error_reporting(E_ALL);

$pluginLib = $root . '/plugins/kuickpay_reconcile/lib';
Loader::load($pluginLib . '/KuickPayVoucherRepository.php');
Loader::load($pluginLib . '/KuickPayAuditService.php');
Loader::load($pluginLib . '/KuickPayVoucherReferenceService.php');
Loader::load($root . '/plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php');

$bootstrap = new stdClass();
Loader::loadComponents($bootstrap, ['Record']);
Loader::loadModels($bootstrap, ['PluginManager']);
$record = $bootstrap->Record;
$pluginManager = $bootstrap->PluginManager;

$pluginId = isset($options['plugin-id'])
    ? (int) $options['plugin-id']
    : kp52_discover_plugin_id($record, $companyId);

if (!$pluginId) {
    fwrite(STDERR, "Could not resolve the kuickpay_reconcile plugin id for company {$companyId}; pass --plugin-id.\n");
    exit(1);
}

$scratchTable = 'kuickpay_vouchers_kp52_scratch';
$evidence = [];
$createdVoucherIds = [];
$failures = [];

try {
    // ---- AC3: capture BEFORE state ---------------------------------------
    $before = [
        'plugin_version' => kp52_plugin_version($record, $pluginId),
        'context_key' => kp52_column_exists($record, 'kuickpay_vouchers', 'context_key'),
        'active_context_key' => kp52_column_exists($record, 'kuickpay_vouchers', 'active_context_key'),
        'unique_index' => kp52_index_exists($record, 'kuickpay_vouchers', 'uniq_kuickpay_vouchers_active_context'),
    ];
    $beforeDdl = kp52_show_create($record, 'kuickpay_vouchers');
    $evidence['ac3_before'] = $before;
    $evidence['ac3_before_ddl_fragment'] = kp52_schema_fragment($beforeDdl);

    // ---- AC3: fresh-install scratch (same guard SQL, empty 1.8.0-shape) ---
    kp52_drop_table($record, $scratchTable);
    $record->query("CREATE TABLE `{$scratchTable}` LIKE `kuickpay_vouchers`");
    kp52_strip_guard($record, $scratchTable);
    kp52_apply_guard_sql($record, $scratchTable);
    $scratchDdl = kp52_show_create($record, $scratchTable);

    // ---- AC3: run the REAL 1.8.0 -> 1.9.0 upgrade ------------------------
    $upgradeRan = false;
    if (version_compare((string) $before['plugin_version'], '1.9.0', '<')) {
        $pluginManager->upgrade($pluginId);
        if (($errors = $pluginManager->errors())) {
            $failures[] = 'PluginManager::upgrade reported errors';
            $evidence['upgrade_errors'] = kp52_redact_errors($errors);
        }
        $upgradeRan = true;
    }

    $after = [
        'plugin_version' => kp52_plugin_version($record, $pluginId),
        'context_key' => kp52_column_exists($record, 'kuickpay_vouchers', 'context_key'),
        'active_context_key' => kp52_column_exists($record, 'kuickpay_vouchers', 'active_context_key'),
        'unique_index' => kp52_index_exists($record, 'kuickpay_vouchers', 'uniq_kuickpay_vouchers_active_context'),
    ];
    $afterDdl = kp52_show_create($record, 'kuickpay_vouchers');
    $evidence['ac3_after'] = $after;
    $evidence['ac3_after_ddl_fragment'] = kp52_schema_fragment($afterDdl);
    $evidence['ac3_upgrade_ran_this_invocation'] = $upgradeRan;

    if (!$after['context_key'] || !$after['active_context_key'] || !$after['unique_index']) {
        $failures[] = 'AC3: schema objects missing after upgrade';
    }

    // Backfill: every linked voucher row carries a non-NULL context_key.
    $nullBackfill = (int) ($record->query(
        'SELECT COUNT(*) c FROM kuickpay_vouchers v'
        . ' JOIN kuickpay_voucher_invoices i ON i.voucher_id = v.id'
        . ' WHERE v.context_key IS NULL'
    )->fetch()->c ?? -1);
    $evidence['ac3_rows_with_links_missing_context_key'] = $nullBackfill;
    if ($nullBackfill !== 0) {
        $failures[] = 'AC3: linked rows missing backfilled context_key';
    }

    // Fresh-install (scratch) vs upgrade (real) must produce identical defns.
    $freshFragment = kp52_schema_fragment($scratchDdl);
    $upgradeFragment = kp52_schema_fragment($afterDdl);
    $evidence['ac3_fresh_install_fragment'] = $freshFragment;
    $evidence['ac3_fresh_install_matches_upgrade'] = ($freshFragment === $upgradeFragment);
    if ($freshFragment !== $upgradeFragment) {
        $failures[] = 'AC3: fresh-install schema differs from upgrade schema';
    }

    // ---- AC3: idempotency — re-run the SAME guard branch ------------------
    if (!class_exists('KuickpayReconcilePlugin')) {
        Loader::load($root . '/plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php');
    }
    $plugin = new KuickpayReconcilePlugin();
    $plugin->upgrade('1.8.0', $pluginId);
    $idempotentErrors = $plugin->errors();
    $indexCount = (int) $record->query(
        "SELECT COUNT(*) c FROM information_schema.statistics"
        . " WHERE table_schema = DATABASE() AND table_name = 'kuickpay_vouchers'"
        . " AND index_name = 'uniq_kuickpay_vouchers_active_context'"
    )->fetch()->c;
    // One unique index over a 2-column key reports 2 statistics rows (one/column).
    $evidence['ac3_idempotent_rerun_clean'] = empty($idempotentErrors);
    $evidence['ac3_unique_index_columns'] = $indexCount;
    if (!empty($idempotentErrors) || $indexCount !== 2) {
        $failures[] = 'AC3: guard re-run was not a clean no-op';
        if (!empty($idempotentErrors)) {
            $evidence['idempotent_errors'] = kp52_redact_errors($idempotentErrors);
        }
    }

    // ---- AC1: single active context, against the real DB -----------------
    $invoiceId = 1900000000 + random_int(0, 89999999);
    $contextKey = sha1((string) $invoiceId);
    $repository = new KuickPayVoucherRepository();
    $service = new KuickPayVoucherReferenceService($repository);

    $context = [
        'company_id' => $companyId,
        'gateway_id' => $gatewayId,
        'client_id' => $clientId,
        'currency' => 'PKR',
        'amount' => '1000.00',
        'institution_id' => 'KP01',
        'invoice_amounts' => [['id' => $invoiceId, 'amount' => '1000.00']],
    ];

    $winner = $service->getOrCreateForInvoiceContext($context);
    if (!$winner) {
        throw new RuntimeException('Failed to seed the winner voucher via the real service.');
    }
    $createdVoucherIds[] = (int) $winner['id'];
    // flatten() does not expose context_key, so read it from the DB to confirm
    // the issuance path wrote the canonical value.
    $winnerCtxRow = $record->query(
        'SELECT context_key FROM kuickpay_vouchers WHERE id = ?',
        (int) $winner['id']
    )->fetch();
    $evidence['ac1_winner_context_key'] = ((string) ($winnerCtxRow->context_key ?? '')) === $contextKey
        ? 'matches_canonical'
        : 'MISMATCH';
    if (((string) ($winnerCtxRow->context_key ?? '')) !== $contextKey) {
        $failures[] = 'AC1: winner context_key did not match the canonical sha1';
    }

    // (a) A raw second INSERT for the same (company, context_key) is rejected
    //     by the unique key (different identity, so the reg/consumer keys do
    //     not fire first).
    $rawRejectedByActiveContext = false;
    try {
        $record->query(
            'INSERT INTO kuickpay_vouchers'
            . ' (company_id, gateway_id, client_id, currency, amount, status,'
            . ' registration_number, consumer_number, context_key, date_created, date_updated)'
            . " VALUES (?,?,?,?,?, 'pending', ?,?,?, NOW(), NOW())",
            $companyId,
            $gatewayId,
            $clientId,
            'PKR',
            '1000.00',
            'KP52RAWREG' . $invoiceId,
            'KP52RAWCON' . $invoiceId,
            $contextKey
        );
        // Unexpected success: capture and schedule for cleanup.
        $createdVoucherIds[] = (int) $record->lastInsertId();
    } catch (Throwable $e) {
        $rawRejectedByActiveContext = kp52_is_active_context_violation($e);
        $record->reset();
    }
    $evidence['ac1_raw_duplicate_rejected_by_unique_key'] = $rawRejectedByActiveContext;
    if (!$rawRejectedByActiveContext) {
        $failures[] = 'AC1: raw same-context INSERT was not rejected by the active-context unique key';
    }

    // (b) The real service, when its pre-lookup misses (the race), attempts a
    //     create that fails on the unique key and falls through to the winner.
    $raceRepository = new Kp52ActiveContextRaceRepository($repository);
    $raceService = new KuickPayVoucherReferenceService($raceRepository);
    $loser = $raceService->getOrCreateForInvoiceContext($context);
    if ($loser && !in_array((int) $loser['id'], $createdVoucherIds, true)) {
        $createdVoucherIds[] = (int) $loser['id'];
    }
    $fallThroughReturnedWinner = $loser && (int) $loser['id'] === (int) $winner['id'];
    $evidence['ac1_create_attempted_under_race'] = $raceRepository->createAttempted;
    $evidence['ac1_fall_through_returned_winner'] = $fallThroughReturnedWinner;
    if (!$raceRepository->createAttempted || !$fallThroughReturnedWinner) {
        $failures[] = 'AC1: race create did not fall through to the single winner';
    }

    // (c) Exactly one active pending row exists for that invoice set.
    $pendingCount = (int) $record->query(
        'SELECT COUNT(*) c FROM kuickpay_vouchers v'
        . ' JOIN kuickpay_voucher_invoices i ON i.voucher_id = v.id'
        . " WHERE i.invoice_id = ? AND v.company_id = ? AND v.status = 'pending'",
        $invoiceId,
        $companyId
    )->fetch()->c;
    $evidence['ac1_pending_rows_for_set'] = $pendingCount;
    if ($pendingCount !== 1) {
        $failures[] = 'AC1: expected exactly one pending row for the invoice set';
    }

    // ---- Release semantics: generated column recomputes on UPDATE --------
    // Cancel the winner -> active_context_key becomes NULL -> slot released.
    $record->query(
        "UPDATE kuickpay_vouchers SET status = 'cancelled', date_updated = NOW() WHERE id = ?",
        (int) $winner['id']
    );
    $afterCancelInsertOk = false;
    try {
        $record->query(
            'INSERT INTO kuickpay_vouchers'
            . ' (company_id, gateway_id, client_id, currency, amount, status,'
            . ' registration_number, consumer_number, context_key, date_created, date_updated)'
            . " VALUES (?,?,?,?,?, 'pending', ?,?,?, NOW(), NOW())",
            $companyId,
            $gatewayId,
            $clientId,
            'PKR',
            '1000.00',
            'KP52FRESHREG' . $invoiceId,
            'KP52FRESHCON' . $invoiceId,
            $contextKey
        );
        $freshId = (int) $record->lastInsertId();
        $createdVoucherIds[] = $freshId;
        $afterCancelInsertOk = true;
    } catch (Throwable $e) {
        $freshId = null;
    }
    $evidence['release_cancel_frees_slot'] = $afterCancelInsertOk;
    if (!$afterCancelInsertOk) {
        $failures[] = 'Release: a fresh active voucher could not be created after cancel';
    }

    // Post the fresh voucher -> active_context_key stays = context_key -> a new
    // same-set active voucher is blocked.
    $postedBlocksNew = false;
    if ($freshId) {
        $record->query(
            "UPDATE kuickpay_vouchers SET status = 'posted', date_updated = NOW() WHERE id = ?",
            $freshId
        );
        try {
            $record->query(
                'INSERT INTO kuickpay_vouchers'
                . ' (company_id, gateway_id, client_id, currency, amount, status,'
                . ' registration_number, consumer_number, context_key, date_created, date_updated)'
                . " VALUES (?,?,?,?,?, 'pending', ?,?,?, NOW(), NOW())",
                $companyId,
                $gatewayId,
                $clientId,
                'PKR',
                '1000.00',
                'KP52BLOCKREG' . $invoiceId,
                'KP52BLOCKCON' . $invoiceId,
                $contextKey
            );
            $createdVoucherIds[] = (int) $record->lastInsertId();
        } catch (Throwable $e) {
            $postedBlocksNew = kp52_is_active_context_violation($e);
            $record->reset();
        }
    }
    $evidence['release_posted_blocks_new_active'] = $postedBlocksNew;
    if (!$postedBlocksNew) {
        $failures[] = 'Release: posted voucher did not block a new same-set active voucher';
    }
} catch (Throwable $e) {
    $failures[] = 'Harness exception: ' . kp52_redact_message($e->getMessage());
} finally {
    // ---- Cleanup: delete every disposable row and drop the scratch -------
    $createdVoucherIds = array_values(array_unique(array_filter($createdVoucherIds)));
    foreach ($createdVoucherIds as $id) {
        try {
            $record->query('DELETE FROM kuickpay_voucher_invoices WHERE voucher_id = ?', $id);
            $record->query('DELETE FROM kuickpay_audit_events WHERE voucher_id = ?', $id);
            $record->query('DELETE FROM kuickpay_vouchers WHERE id = ?', $id);
        } catch (Throwable $e) {
            $failures[] = 'Cleanup failed for voucher ' . $id . ': ' . kp52_redact_message($e->getMessage());
        }
    }
    try {
        kp52_drop_table($record, $scratchTable);
    } catch (Throwable $e) {
        $failures[] = 'Scratch table drop failed: ' . kp52_redact_message($e->getMessage());
    }
    $evidence['cleanup_voucher_ids'] = $createdVoucherIds;
}

$evidence['result'] = empty($failures) ? 'PASS' : 'FAIL';
$evidence['failures'] = $failures;

echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(empty($failures) ? 0 : 1);

// ---------------------------------------------------------------------------

function kp52_usage(): void
{
    fwrite(STDERR, <<<'TEXT'
Usage:
  php plugins/kuickpay_reconcile/tests/integration/active_context_guard_check.php \
    --i-understand-this-mutates-kuickpay-vouchers [--company-id=1] [--gateway-id=1] \
    [--client-id=1] [--plugin-id=<id>]

Runs the real 1.8.0 -> 1.9.0 plugin upgrade (once), then proves the active-context
unique key on the real DB using disposable synthetic invoice ids. Cleans up every
row it creates and drops its scratch table. No real customer invoices are touched.

TEXT);
}

function kp52_discover_plugin_id($record, int $companyId): int
{
    $row = $record->query(
        "SELECT id FROM plugins WHERE dir = 'kuickpay_reconcile' AND company_id = ? LIMIT 1",
        $companyId
    )->fetch();

    return (int) ($row->id ?? 0);
}

function kp52_plugin_version($record, int $pluginId): string
{
    $row = $record->query('SELECT version FROM plugins WHERE id = ?', $pluginId)->fetch();

    return (string) ($row->version ?? '');
}

function kp52_column_exists($record, string $table, string $column): bool
{
    return (bool) $record->query('SHOW COLUMNS FROM `' . $table . '` LIKE ?', $column)->fetch();
}

function kp52_index_exists($record, string $table, string $index): bool
{
    return (bool) $record->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', $index)->fetch();
}

function kp52_show_create($record, string $table): string
{
    $row = (array) $record->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
    // Blesta lowercases column keys, so match "create table" case-insensitively.
    foreach ($row as $key => $value) {
        if (strcasecmp((string) $key, 'Create Table') === 0) {
            return (string) $value;
        }
    }

    return '';
}

function kp52_drop_table($record, string $table): void
{
    $record->query('DROP TABLE IF EXISTS `' . $table . '`');
}

/**
 * Strips the Story 5.2 additions from a table so it returns to the 1.8.0 base
 * shape (drop order: index, generated column, then base column).
 */
function kp52_strip_guard($record, string $table): void
{
    if (kp52_index_exists($record, $table, 'uniq_kuickpay_vouchers_active_context')) {
        $record->query('ALTER TABLE `' . $table . '` DROP INDEX `uniq_kuickpay_vouchers_active_context`');
    }
    if (kp52_column_exists($record, $table, 'active_context_key')) {
        $record->query('ALTER TABLE `' . $table . '` DROP COLUMN `active_context_key`');
    }
    if (kp52_column_exists($record, $table, 'context_key')) {
        $record->query('ALTER TABLE `' . $table . '` DROP COLUMN `context_key`');
    }
}

/**
 * Applies the structural ALTERs of addActiveContextGuard() to a scratch table
 * using the same SQL provider as the production guard. The fresh-install/upgrade
 * DDL comparison fails loudly if this ever drifts from the plugin method.
 */
function kp52_apply_guard_sql($record, string $table): void
{
    $sql = KuickpayReconcilePlugin::activeContextGuardSql($table);
    $record->query($sql['add_context_key']);
    $record->query($sql['set_group_concat_max_len']);
    $record->query($sql['backfill_context_key']);
    $record->query($sql['add_active_context_key']);
    $record->query($sql['add_unique_key']);
}

/**
 * Extracts the three Story 5.2 schema lines (context_key column, generated
 * active_context_key column, unique key) from a SHOW CREATE TABLE, normalized
 * so two tables with different names compare equal.
 */
function kp52_schema_fragment(string $createSql): array
{
    $lines = preg_split('/\r?\n/', $createSql);
    $fragment = ['context_key' => '', 'active_context_key' => '', 'unique_key' => ''];
    foreach ($lines as $line) {
        $trimmed = trim(rtrim(trim($line), ','));
        $normalized = preg_replace('/\s+/', ' ', $trimmed);
        if (preg_match('/^`context_key` /', $normalized)) {
            $fragment['context_key'] = $normalized;
        } elseif (strpos($normalized, '`active_context_key`') === 0) {
            $fragment['active_context_key'] = $normalized;
        } elseif (strpos($normalized, 'UNIQUE KEY `uniq_kuickpay_vouchers_active_context`') !== false) {
            $fragment['unique_key'] = $normalized;
        }
    }

    return $fragment;
}

function kp52_is_active_context_violation(Throwable $e): bool
{
    $message = $e->getMessage();

    return stripos($message, 'uniq_kuickpay_vouchers_active_context') !== false
        || (stripos($message, 'Duplicate entry') !== false && stripos($message, 'active_context') !== false);
}

/**
 * Redacts a DB exception message to its structural shell, dropping any value
 * payload (e.g. the 'Duplicate entry ...' data) while keeping the index name.
 */
function kp52_redact_message(string $message): string
{
    if (stripos($message, 'uniq_kuickpay_vouchers_active_context') !== false) {
        return 'duplicate active-context key (uniq_kuickpay_vouchers_active_context)';
    }

    return preg_replace('/Duplicate entry \'[^\']*\'/', "Duplicate entry '<redacted>'", $message);
}

function kp52_redact_errors(array $errors): array
{
    $flat = [];
    array_walk_recursive($errors, function ($value) use (&$flat) {
        $flat[] = kp52_redact_message((string) $value);
    });

    return $flat;
}

/**
 * Repository decorator that simulates the issuance race on the real DB: the
 * pending pre-lookup misses until a create has been attempted, forcing the real
 * service to attempt a create (which the unique key rejects) and then exercise
 * its create-null fall-through against the committed winner.
 */
class Kp52ActiveContextRaceRepository
{
    /** @var KuickPayVoucherRepository */
    private $inner;

    /** @var bool */
    public $createAttempted = false;

    public function __construct(KuickPayVoucherRepository $inner)
    {
        $this->inner = $inner;
    }

    public function getPendingByInvoiceId(int $invoiceId, int $companyId)
    {
        return $this->createAttempted ? $this->inner->getPendingByInvoiceId($invoiceId, $companyId) : null;
    }

    public function getPendingByInvoiceSet(array $invoiceIds, int $companyId)
    {
        return $this->createAttempted ? $this->inner->getPendingByInvoiceSet($invoiceIds, $companyId) : null;
    }

    public function create(array $voucherData, array $invoiceLinks)
    {
        $this->createAttempted = true;

        return $this->inner->create($voucherData, $invoiceLinks);
    }

    public function __call(string $name, array $arguments)
    {
        return $this->inner->{$name}(...$arguments);
    }
}
