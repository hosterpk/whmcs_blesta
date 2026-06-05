<?php
/**
 * Blesta Standalone Upgrader Script
 *
 * A self-contained CLI script with zero Blesta framework dependencies.
 * Designed to be copied outside the installation directory before execution
 * to avoid mixed-code issues during file replacement.
 *
 * Usage: php upgrader.php <job_file.json>
 *
 * The job file contains all parameters needed for the upgrade:
 * - staging_dir: Path to extracted release files
 * - install_dir: Path to the Blesta installation
 * - manifest_path: Path to the new release's manifest.json
 * - preserve_paths: Array of paths to preserve (relative to install_dir)
 * - php_binary: Path to the PHP binary
 * - status_file: Path to write progress updates
 * - lock_file: Path to the upgrade lock file
 * - maintenance_was_enabled: Whether maintenance mode was on before upgrade
 *
 * Database credentials are NOT in the job file — they are loaded directly
 * from {install_dir}/config/blesta.php at runtime so they never touch disk
 * outside the install directory.
 *
 * @package blesta
 * @subpackage upgrader
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Ensure CLI execution only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'This script must be run from the command line.';
    exit(1);
}

/**
 * Minimal stand-in for minphp's Configure class. The Blesta config file calls
 * Configure::set()/get()/errorReporting(); stubbing these lets us `require`
 * the config without pulling any Blesta framework code into this script.
 */
class Configure
{
    private static $store = [];

    public static function set($key, $value)
    {
        self::$store[$key] = $value;
    }

    public static function get($key)
    {
        return self::$store[$key] ?? null;
    }

    public static function errorReporting($level)
    {
    }
}

/**
 * Load Blesta database connection info directly from config/blesta.php.
 *
 * @param string $installDir Blesta installation directory
 * @return array Database info (keys: host, port, database, user, pass, ...)
 * @throws RuntimeException If the config cannot be read or is invalid
 */
function loadDatabaseInfo($installDir)
{
    $configPath = rtrim($installDir, '/') . '/config/blesta.php';

    if (!is_readable($configPath)) {
        throw new RuntimeException('Cannot read Blesta config at ' . $configPath);
    }

    // WEBDIR is referenced inline by the config file
    if (!defined('WEBDIR')) {
        define('WEBDIR', '/');
    }

    // require_once — the upgrader loads the config twice (once for the
    // db_version verification PDO, once for maintenance-mode restore), and
    // the config file contains define() calls that would warn on reload.
    require_once $configPath;

    $dbInfo = Configure::get('Blesta.database_info');

    if (!is_array($dbInfo) || !isset($dbInfo['host'], $dbInfo['user'], $dbInfo['database'])) {
        throw new RuntimeException('Blesta database_info is missing or invalid in ' . $configPath);
    }

    return $dbInfo;
}

if ($argc < 2) {
    fprintf(STDERR, "Usage: php %s <job_file.json>\n", $argv[0]);
    exit(1);
}

$jobFile = $argv[1];

if (!file_exists($jobFile) || !is_readable($jobFile)) {
    fprintf(STDERR, "Error: Job file '%s' does not exist or is not readable.\n", $jobFile);
    exit(1);
}

// Remove the job file on any exit path. It contains no secrets, but it's
// still transient state in a temp dir that should not linger.
register_shutdown_function(function () use ($jobFile) {
    if (is_file($jobFile)) {
        @unlink($jobFile);
    }
});

$job = json_decode(file_get_contents($jobFile), true);

if ($job === null) {
    fprintf(STDERR, "Error: Job file contains invalid JSON.\n");
    exit(1);
}

// Validate required job parameters
$requiredKeys = [
    'staging_dir',
    'install_dir',
    'manifest_path',
    'preserve_paths',
    'php_binary',
    'status_file',
    'lock_file',
    'maintenance_was_enabled',
];

foreach ($requiredKeys as $key) {
    if (!array_key_exists($key, $job)) {
        fprintf(STDERR, "Error: Job file missing required key '%s'.\n", $key);
        exit(1);
    }
}

$statusFile = $job['status_file'];
$totalSteps = 5;

// Fields that should appear in every status write so the UI can render them
// at any point — including after a failure when the user reloads the page.
// launch_nonce is forwarded from the job file and checked by
// SystemUpgrade::verifyUpgraderStarted() to distinguish this run's status
// file from any stale file left behind by a prior completed upgrade.
$persistentExtras = [
    'launch_nonce' => $job['launch_nonce'] ?? null,
    'backup_db_path' => $job['backup_db_path'] ?? null,
    'backup_files_path' => $job['backup_files_path'] ?? null,
    'db_version_before' => $job['db_version_before'] ?? null,
    'db_version_expected' => $job['db_version_expected'] ?? null,
    'db_version_after' => null,
    'db_version_mismatch' => false,
];

/**
 * Write current progress to the status file.
 *
 * @param string $statusFile Path to the status file
 * @param int $step Current step number
 * @param int $totalSteps Total number of steps
 * @param string $stepName Name of the current step
 * @param string $status Status string (running, complete, failed)
 * @param array $messages Array of log messages
 * @param string|null $error Error message if failed
 * @param array $extra Additional fields to include
 */
function writeStatus($statusFile, $step, $totalSteps, $stepName, $status, array $messages, $error = null, array $extra = [])
{
    $data = array_merge([
        'step' => $step,
        'total_steps' => $totalSteps,
        'step_name' => $stepName,
        'progress' => $totalSteps > 0 ? round(($step / $totalSteps) * 100) : 0,
        'status' => $status,
        'messages' => $messages,
        'error' => $error,
        'started_at' => $extra['started_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        'completed_at' => $status === 'complete' || $status === 'failed'
            ? gmdate('Y-m-d\TH:i:s\Z')
            : null,
    ], $extra);

    file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

/**
 * Execute a shell command and return output + exit code.
 *
 * @param string $command The command to execute
 * @return array ['output' => string, 'exit_code' => int]
 */
function runCommand($command)
{
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    return [
        'output' => implode("\n", $output),
        'exit_code' => $exitCode,
    ];
}

// Begin upgrade execution
$startedAt = gmdate('Y-m-d\TH:i:s\Z');
$baseExtras = array_merge($persistentExtras, ['started_at' => $startedAt]);
$messages = [];
$stagingDir = rtrim($job['staging_dir'], '/');
$installDir = rtrim($job['install_dir'], '/');
$manifestPath = $job['manifest_path'];
$preservePaths = $job['preserve_paths'];
$phpBinary = $job['php_binary'];
$lockFile = $job['lock_file'];

// Update lock file with the upgrader's own PID so stale-lock detection works
// correctly. Clear the launch_failed marker (and the stored recovery paths)
// in case this run was kicked off manually after a previous web-launch was
// killed by the host environment.
if (file_exists($lockFile)) {
    $lockData = json_decode(file_get_contents($lockFile), true);
    if ($lockData !== null) {
        $lockData['pid'] = getmypid();
        unset(
            $lockData['launch_failed'],
            $lockData['launch_failed_upgrader_path'],
            $lockData['launch_failed_job_file']
        );
        file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT));
    }
}

// Determine the blesta subdirectory within the staging area
// The release zip extracts to a 'blesta/' subdirectory
$stagingBlestaDir = $stagingDir . '/blesta';
if (!is_dir($stagingBlestaDir)) {
    // Fall back to staging dir itself if no subdirectory
    $stagingBlestaDir = $stagingDir;
}

// ----- Step 1: Replace files using rsync -----
writeStatus($statusFile, 1, $totalSteps, 'Replacing files', 'running', $messages, null, $baseExtras);

// Exclude preserved paths from being overwritten (config/blesta.php, custom
// themes, third-party plugins/modules, uploads/backup dirs inside the install).
$excludeArgs = '';
foreach ($preservePaths as $path) {
    $excludeArgs .= sprintf(' --exclude=%s', escapeshellarg($path));
}

// Intentionally no --delete here. rsync overlays the release onto the install
// directory, adding new files and overwriting changed ones, but leaves every
// other file in place. Removing files that are no longer part of the release
// is the job of the opt-in "Remove stale core files" step below — the UI only
// deletes what it promises to delete.
$rsyncCmd = sprintf(
    'rsync -a %s %s/ %s/',
    $excludeArgs,
    escapeshellarg($stagingBlestaDir),
    escapeshellarg($installDir)
);

$result = runCommand($rsyncCmd);

if ($result['exit_code'] !== 0) {
    $messages[] = 'File replacement failed: ' . $result['output'];
    writeStatus($statusFile, 1, $totalSteps, 'Replacing files', 'failed', $messages, 'rsync failed with exit code ' . $result['exit_code'], $baseExtras);
    exit(1);
}

$messages[] = 'Files replaced successfully';

// ----- Step 2: Clean up stale core files (opt-in) -----
writeStatus($statusFile, 2, $totalSteps, 'Cleaning stale files', 'running', $messages, null, $baseExtras);

$cleanStaleFiles = !empty($job['clean_stale_files']);

if (!$cleanStaleFiles) {
    $messages[] = 'Stale file cleanup skipped (disabled by default)';
} elseif (file_exists($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);

    if ($manifest !== null && isset($manifest['files'])) {
        // Build path lookup — supports both formats:
        // objects with 'path' key, or flat strings
        $manifestFiles = [];
        foreach ($manifest['files'] as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? '') : $entry;
            if ($path !== '') {
                $manifestFiles[$path] = true;
            }
        }

        // Directories managed by core that should be cleaned of stale files
        $coreDirs = ['app/', 'core/', 'components/upgrades/'];

        foreach ($coreDirs as $coreDir) {
            $fullCoreDir = $installDir . '/' . $coreDir;
            if (!is_dir($fullCoreDir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullCoreDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $relativePath = str_replace(
                    '\\',
                    '/',
                    substr($file->getPathname(), strlen($installDir) + 1)
                );

                // Skip preserved paths
                $isPreserved = false;
                foreach ($preservePaths as $preserved) {
                    if (strpos($relativePath, $preserved) === 0) {
                        $isPreserved = true;
                        break;
                    }
                }

                if ($isPreserved) {
                    continue;
                }

                // If file is in a core dir but not in the new manifest, delete it
                if (!isset($manifestFiles[$relativePath])) {
                    unlink($file->getPathname());
                }
            }
        }

        $messages[] = 'Stale core files cleaned up';
    } else {
        $messages[] = 'Warning: Could not parse manifest, skipping stale file cleanup';
    }
} else {
    $messages[] = 'Warning: Manifest not found, skipping stale file cleanup';
}

// ----- Step 3: Run database migrations -----
writeStatus($statusFile, 3, $totalSteps, 'Running database migrations', 'running', $messages, null, $baseExtras);

$upgradeCmd = sprintf(
    '%s %s/index.php admin/upgrade -y',
    escapeshellarg($phpBinary),
    escapeshellarg($installDir)
);

$result = runCommand($upgradeCmd);
$migrationFailed = $result['exit_code'] !== 0;

if ($result['exit_code'] !== 0) {
    $messages[] = 'Database migration output: ' . $result['output'];
    $messages[] = 'Database migration failed. Manual intervention may be required.';
} else {
    $messages[] = 'Database migrations completed successfully';
    if (!empty($result['output'])) {
        $messages[] = 'Migration output: ' . $result['output'];
    }
}

// ----- Verify the core database actually advanced to where we expect -----
// The expected version was computed from the staged mappings file by the
// controller before this process was launched. mappings.php only describes
// CORE upgrades, so extension upgrade failures (plugins/modules/gateways)
// intentionally do not register here — they can be fixed independently after
// the core is in place. This check exists to catch core failures that leave
// the database in a partially-upgraded state: a task failed mid-version, some
// schema changes were applied, and database_version was not bumped.
$dbVersionExpected = $job['db_version_expected'] ?? null;
$dbVersionAfter = null;
$dbVersionMismatch = false;

if ($dbVersionExpected !== null) {
    try {
        $dbInfo = loadDatabaseInfo($installDir);
        $checkDsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbInfo['host'],
            $dbInfo['port'] ?? '3306',
            $dbInfo['database']
        );
        $checkPdo = new PDO(
            $checkDsn,
            $dbInfo['user'],
            $dbInfo['pass'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $checkPdo->prepare("SELECT value FROM settings WHERE `key` = 'database_version' LIMIT 1");
        $stmt->execute();
        $dbVersionAfter = $stmt->fetchColumn();
        if ($dbVersionAfter === false) {
            $dbVersionAfter = null;
        }
        $checkPdo = null;
    } catch (Exception $e) {
        $messages[] = 'Warning: Could not verify database_version after upgrade: ' . $e->getMessage();
    }

    if ($dbVersionAfter !== null && $dbVersionAfter !== $dbVersionExpected) {
        $dbVersionMismatch = true;
        $migrationFailed = true;
        $messages[] = sprintf(
            'Database version verification failed: expected %s but found %s. The database may be in a partially-upgraded state.',
            $dbVersionExpected,
            $dbVersionAfter
        );
    } elseif ($dbVersionAfter !== null) {
        $messages[] = sprintf('Database version verified at %s', $dbVersionAfter);
    }
}

// Make the verified version visible in every subsequent status write
$baseExtras['db_version_after'] = $dbVersionAfter;
$baseExtras['db_version_mismatch'] = $dbVersionMismatch;

// Now write the step 3 failure status if applicable. The success case falls
// through to step 4 which writes its own running status.
if ($migrationFailed) {
    $errorMsg = ($result['exit_code'] === 0 && $dbVersionMismatch)
        ? sprintf(
            'Database version did not advance as expected (expected %s, found %s).',
            $dbVersionExpected,
            $dbVersionAfter ?? 'unknown'
        )
        : 'Database migration failed with exit code ' . $result['exit_code'];
    writeStatus($statusFile, 3, $totalSteps, 'Running database migrations', 'failed', $messages, $errorMsg, $baseExtras);
    // Don't exit - try to clean up gracefully
}

// ----- Step 4: Restore maintenance mode & clear caches -----
writeStatus($statusFile, 4, $totalSteps, 'Finalizing', 'running', $messages, null, $baseExtras);

// Restore maintenance mode via direct database update
$maintenanceWasEnabled = !empty($job['maintenance_was_enabled']);

try {
    $dbInfo = loadDatabaseInfo($installDir);
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbInfo['host'],
        $dbInfo['port'] ?? '3306',
        $dbInfo['database']
    );
    $pdo = new PDO($dsn, $dbInfo['user'], $dbInfo['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    if (!$maintenanceWasEnabled) {
        $stmt = $pdo->prepare("UPDATE settings SET value = 'false' WHERE `key` = 'maintenance_mode'");
        $stmt->execute();
        $messages[] = 'Maintenance mode disabled';
    } else {
        $messages[] = 'Maintenance mode left enabled (was enabled before upgrade)';
    }

    // Clean up the temporary setting
    $stmt = $pdo->prepare("DELETE FROM settings WHERE `key` = 'upgrade_maintenance_was_enabled'");
    $stmt->execute();

    $pdo = null;
} catch (Exception $e) {
    $messages[] = 'Warning: Could not restore maintenance mode via database: ' . $e->getMessage();
    $messages[] = 'You may need to manually disable maintenance mode in Settings.';
}

// Clear caches
$cacheDir = $installDir . '/cache';
if (is_dir($cacheDir)) {
    $cacheIterator = new DirectoryIterator($cacheDir);
    foreach ($cacheIterator as $item) {
        if ($item->isDot() || $item->getFilename() === '.htaccess') {
            continue;
        }
        if ($item->isFile()) {
            unlink($item->getPathname());
        }
    }
    $messages[] = 'Cache cleared';
}

// ----- Step 5: Release lock & clean up -----
writeStatus($statusFile, 5, $totalSteps, 'Cleaning up', 'running', $messages, null, $baseExtras);

// Remove staging directory
if (is_dir($stagingDir)) {
    $rmResult = runCommand(sprintf('rm -rf %s', escapeshellarg($stagingDir)));
    if ($rmResult['exit_code'] === 0) {
        $messages[] = 'Staging directory cleaned up';
    }
}

// Remove job file
if (file_exists($jobFile)) {
    unlink($jobFile);
    $messages[] = 'Job file cleaned up';
}

// Release upgrade lock
if (file_exists($lockFile)) {
    unlink($lockFile);
    $messages[] = 'Upgrade lock released';
}

// Write final status
if ($migrationFailed) {
    writeStatus($statusFile, 5, $totalSteps, 'Completed with errors', 'failed', $messages, 'Database migration failed. Files were replaced but database schema may need manual migration.', $baseExtras);
    exit(1);
}

$messages[] = 'Upgrade completed successfully';
writeStatus($statusFile, 5, $totalSteps, 'Complete', 'complete', $messages, null, $baseExtras);
exit(0);
