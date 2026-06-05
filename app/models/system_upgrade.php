<?php

namespace Blesta\App\Models;

use Blesta\App\AppModel;
use Configure;
use Language;
use Loader;

/**
 * System self-upgrade management
 *
 * Handles checking for updates, environment validation, backup creation,
 * release download/verification, and coordinating the standalone upgrader.
 *
 * @package blesta
 * @subpackage app.models
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SystemUpgrade extends AppModel
{
    /**
     * @var string The default release check URL
     */
    private $defaultCheckUrl = 'https://blesta.com/api/releases.json';

    /**
     * @var int Default cache duration in seconds (12 hours)
     */
    private $defaultCacheDuration = 43200;

    /**
     * Initialize SystemUpgrade
     */
    public function __construct()
    {
        parent::__construct();
        Language::loadLang(['system_upgrade']);
    }

    /**
     * Fetches release information from the API endpoint. Uses cached results
     * if available and not expired.
     *
     * @param bool $force_refresh If true, bypasses the cache
     * @return array An array with 'latest' and 'releases' keys, or empty array on failure
     */
    public function checkForUpdates($force_refresh = false)
    {
        Loader::loadModels($this, ['Settings']);
        Loader::loadComponents($this, ['SettingsCollection']);

        $check_url = $this->getSettingValue('upgrade_check_url', $this->defaultCheckUrl);
        $cache_duration = (int) $this->getSettingValue('upgrade_check_cache', $this->defaultCacheDuration);

        // Check cache unless forced refresh
        if (!$force_refresh) {
            $last_check = $this->getSettingValue('upgrade_last_check');
            $last_result = $this->getSettingValue('upgrade_last_check_result');

            if ($last_check && $last_result && (time() - (int) $last_check) < $cache_duration) {
                $cached = json_decode($last_result, true);
                if ($cached !== null) {
                    return $cached;
                }
            }
        }

        // Fetch release info
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Blesta/' . (defined('BLESTA_VERSION') ? BLESTA_VERSION : 'unknown'),
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($check_url, false, $context);

        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);

        if ($data === null || !isset($data['latest'])) {
            return [];
        }

        // Cache the result
        $this->Settings->setSetting('upgrade_last_check', (string) time());
        $this->Settings->setSetting('upgrade_last_check_result', json_encode($data));

        return $data;
    }

    /**
     * Returns available upgrade options based on current version.
     *
     * @return array An array with 'patch' and/or 'latest' keys, each containing
     *  release info. Empty array if up to date.
     */
    public function getAvailableUpgrades()
    {
        $release_data = $this->checkForUpdates();

        if (empty($release_data) || !isset($release_data['releases'])) {
            return [];
        }

        $current_version = defined('BLESTA_VERSION') ? BLESTA_VERSION : '0.0.0';
        $current_parts = $this->parseVersion($current_version);
        $upgrades = [];

        // Find latest patch for current major.minor
        $latest_patch = null;
        foreach ($release_data['releases'] as $release) {
            $parts = $this->parseVersion($release['version']);
            if (
                $parts['major'] === $current_parts['major']
                && $parts['minor'] === $current_parts['minor']
                && version_compare($release['version'], $current_version, '>')
            ) {
                if ($latest_patch === null || version_compare($release['version'], $latest_patch['version'], '>')) {
                    $latest_patch = $release;
                }
            }
        }

        if ($latest_patch !== null) {
            $latest_patch['requires_support'] = false;
            $upgrades['patch'] = $latest_patch;
        }

        // Check if latest stable is different and newer
        if (
            isset($release_data['latest'])
            && version_compare($release_data['latest']['version'], $current_version, '>')
        ) {
            $latest = $release_data['latest'];
            $latest_parts = $this->parseVersion($latest['version']);

            // If it's the same as the patch, skip
            if ($latest_patch === null || $latest['version'] !== $latest_patch['version']) {
                $requires_support = ($latest_parts['major'] !== $current_parts['major']
                    || $latest_parts['minor'] !== $current_parts['minor']);
                $latest['requires_support'] = $requires_support;
                $upgrades['latest'] = $latest;
            }
        }

        return $upgrades;
    }

    /**
     * Runs all pre-flight environment checks.
     *
     * @return array An array of check results, each with 'name', 'status' (pass|fail|warn), and 'message'
     */
    public function getEnvironmentStatus()
    {
        $checks = [];

        // 1. Linux OS
        $is_linux = PHP_OS_FAMILY === 'Linux';
        $checks[] = [
            'name' => 'operating_system',
            'status' => $is_linux ? 'pass' : 'fail',
            'message' => $is_linux
                ? Language::_('SystemUpgrade.environment.os_pass', true)
                : Language::_('SystemUpgrade.environment.os_fail', true),
        ];

        if (!$is_linux) {
            // No point checking the rest on non-Linux
            return $checks;
        }

        // 2. exec() available
        $exec_available = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
        $checks[] = [
            'name' => 'exec_available',
            'status' => $exec_available ? 'pass' : 'fail',
            'message' => $exec_available
                ? Language::_('SystemUpgrade.environment.exec_pass', true)
                : Language::_('SystemUpgrade.environment.exec_fail', true),
        ];

        // 3. unzip command
        $unzip_available = $this->commandExists('unzip');
        $checks[] = [
            'name' => 'unzip_available',
            'status' => $unzip_available ? 'pass' : 'fail',
            'message' => $unzip_available
                ? Language::_('SystemUpgrade.environment.unzip_pass', true)
                : Language::_('SystemUpgrade.environment.unzip_fail', true),
        ];

        // 4. mysqldump command
        $mysqldump_available = $this->commandExists('mysqldump');
        $checks[] = [
            'name' => 'mysqldump_available',
            'status' => $mysqldump_available ? 'pass' : 'fail',
            'message' => $mysqldump_available
                ? Language::_('SystemUpgrade.environment.mysqldump_pass', true)
                : Language::_('SystemUpgrade.environment.mysqldump_fail', true),
        ];

        // 5. tar/gzip command
        $tar_available = $this->commandExists('tar');
        $checks[] = [
            'name' => 'tar_available',
            'status' => $tar_available ? 'pass' : 'fail',
            'message' => $tar_available
                ? Language::_('SystemUpgrade.environment.tar_pass', true)
                : Language::_('SystemUpgrade.environment.tar_fail', true),
        ];

        // 6. rsync command
        $rsync_available = $this->commandExists('rsync');
        $checks[] = [
            'name' => 'rsync_available',
            'status' => $rsync_available ? 'pass' : 'fail',
            'message' => $rsync_available
                ? Language::_('SystemUpgrade.environment.rsync_pass', true)
                : Language::_('SystemUpgrade.environment.rsync_fail', true),
        ];

        // 6b. setsid command — required to detach the background upgrader
        // from the web request's process group on jailed hosting.
        $setsid_available = $this->commandExists('setsid');
        $checks[] = [
            'name' => 'setsid_available',
            'status' => $setsid_available ? 'pass' : 'warn',
            'message' => $setsid_available
                ? Language::_('SystemUpgrade.environment.setsid_pass', true)
                : Language::_('SystemUpgrade.environment.setsid_warn', true),
        ];

        // 7. Writable filesystem
        $install_dir = $this->getInstallDir();
        $writable = is_writable($install_dir);
        $checks[] = [
            'name' => 'writable_filesystem',
            'status' => $writable ? 'pass' : 'fail',
            'message' => $writable
                ? Language::_('SystemUpgrade.environment.writable_pass', true)
                : Language::_('SystemUpgrade.environment.writable_fail', true),
        ];

        // 8. File ownership check
        $ownership = $this->checkFileOwnership($install_dir);
        $checks[] = [
            'name' => 'file_ownership',
            'status' => empty($ownership) ? 'pass' : 'fail',
            'message' => empty($ownership)
                ? Language::_('SystemUpgrade.environment.ownership_pass', true)
                : Language::_('SystemUpgrade.environment.ownership_fail', true, count($ownership)),
        ];

        // 9. Disk space (estimate: 3x current install size)
        $disk_check = $this->checkDiskSpace($install_dir);
        $checks[] = [
            'name' => 'disk_space',
            'status' => $disk_check['sufficient'] ? 'pass' : 'warn',
            'message' => $disk_check['sufficient']
                ? Language::_('SystemUpgrade.environment.disk_pass', true, $this->formatBytes($disk_check['free']))
                : Language::_('SystemUpgrade.environment.disk_warn', true, $this->formatBytes($disk_check['free']), $this->formatBytes($disk_check['needed'])),
        ];

        // 10. Config file writable
        $config_path = CONFIGDIR . 'blesta.php';
        $config_writable = is_writable($config_path);
        $checks[] = [
            'name' => 'config_writable',
            'status' => $config_writable ? 'pass' : 'fail',
            'message' => $config_writable
                ? Language::_('SystemUpgrade.environment.config_pass', true)
                : Language::_('SystemUpgrade.environment.config_fail', true),
        ];

        return $checks;
    }

    /**
     * Checks that files in key directories are owned by the web server user.
     *
     * @param string $install_dir The installation directory path
     * @return array An array of problem file/directory paths with mismatched ownership
     */
    public function checkFileOwnership($install_dir)
    {
        $problems = [];

        if (!function_exists('posix_getuid')) {
            return $problems;
        }

        $process_uid = posix_getuid();
        $dirs_to_check = ['app', 'core', 'components', 'config'];

        foreach ($dirs_to_check as $dir) {
            $full_path = rtrim($install_dir, '/') . '/' . $dir;
            if (!is_dir($full_path)) {
                continue;
            }

            $owner = fileowner($full_path);
            if ($owner !== $process_uid) {
                $problems[] = $full_path;
            }
        }

        return $problems;
    }

    /**
     * Acquires the upgrade lock.
     *
     * @param int $staff_id The ID of the staff member initiating the upgrade
     * @param string $target_version The version being upgraded to
     * @return bool True if lock acquired, false if already locked by an active process
     */
    public function acquireLock($staff_id, $target_version, $launch_nonce = null)
    {
        $lock_file = $this->getLockFilePath();
        $existing = $this->getLockStatus();

        if ($existing !== null) {
            return false;
        }

        $lock_data = [
            'pid' => getmypid(),
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'staff_id' => $staff_id,
            'target_version' => $target_version,
            'launch_nonce' => $launch_nonce,
        ];

        return file_put_contents($lock_file, json_encode($lock_data, JSON_PRETTY_PRINT)) !== false;
    }

    /**
     * Releases the upgrade lock.
     */
    public function releaseLock()
    {
        $lock_file = $this->getLockFilePath();
        if (file_exists($lock_file)) {
            unlink($lock_file);
        }
    }

    /**
     * Marks the lock as "launch failed" so the UI treats it as stale even if
     * the PID recorded in it (the web worker's PID) is still alive. The flag
     * is cleared by the upgrader itself when it starts, so a successful
     * manual recovery transitions the UI back to in-progress automatically.
     *
     * The upgrader and job file paths are persisted alongside the flag so
     * that an admin reloading the upgrade page can still see the manual
     * recovery command — the original AJAX response with that command is
     * only delivered once.
     *
     * @param string|null $upgrader_path Path to the copied upgrader script
     * @param string|null $job_file_path Path to the job file
     */
    public function markLockLaunchFailed($upgrader_path = null, $job_file_path = null)
    {
        $lock_file = $this->getLockFilePath();
        if (!file_exists($lock_file)) {
            return;
        }

        $data = json_decode(file_get_contents($lock_file), true);
        if (!is_array($data)) {
            return;
        }

        $data['launch_failed'] = true;
        if ($upgrader_path !== null) {
            $data['launch_failed_upgrader_path'] = $upgrader_path;
        }
        if ($job_file_path !== null) {
            $data['launch_failed_job_file'] = $job_file_path;
        }
        file_put_contents($lock_file, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Returns lock info if an upgrade is currently active.
     *
     * @return array|null Lock data array if active, null if no lock or stale
     */
    public function getLockStatus()
    {
        $lock_file = $this->getLockFilePath();

        if (!file_exists($lock_file)) {
            return null;
        }

        $data = json_decode(file_get_contents($lock_file), true);

        if ($data === null || !isset($data['pid'])) {
            // Invalid lock file, remove it
            unlink($lock_file);
            return null;
        }

        // If we can check process liveness and the PID is dead, the lock is
        // stale — don't make the admin wait out a time grace period before
        // the UI surfaces a "Clear Lock" button. This matters most when the
        // background upgrader never successfully detached on jailed hosting.
        if (function_exists('posix_kill') && !posix_kill($data['pid'], 0)) {
            $data['stale'] = true;
        }

        // Even when the recorded PID looks alive (e.g., a PHP-FPM worker
        // from the original request is still in the pool), respect the
        // explicit launch-failure marker so the UI can offer recovery.
        if (!empty($data['launch_failed'])) {
            $data['stale'] = true;
        }

        return $data;
    }

    /**
     * Returns the current database_version setting value, or null if not set.
     *
     * @return string|null
     */
    public function getCurrentDatabaseVersion()
    {
        Loader::loadModels($this, ['Settings']);

        $setting = $this->Settings->getSetting('database_version');

        return $setting ? $setting->value : null;
    }

    /**
     * Walks an upgrade mappings file to determine the database_version that
     * should be reached after upgrading from $from_version up to $to_version.
     *
     * The expected end-state is not always equal to $to_version: not every
     * release introduces schema changes, so for example file version 5.13.6
     * has no DB tasks beyond 5.13.2 and the database_version setting
     * legitimately stops at 5.13.2 even after a successful upgrade to 5.13.6.
     *
     * Used by the upgrader to verify after the migration runs that the database
     * actually advanced to where it should have, catching partial completion and
     * silent extension-failure cases.
     *
     * @param string $from_version The current database_version
     * @param string $to_version The target file version (the version being upgraded to)
     * @param string $mappings_file Path to a mappings.php file. The staged copy from a
     *                              release zip should be used so any newly-added mapping
     *                              entries are visible.
     * @return string|null The expected post-upgrade database_version, or null if the
     *                     mappings file is missing/unreadable/unparseable
     */
    public function getExpectedDatabaseVersion($from_version, $to_version, $mappings_file)
    {
        // Both versions must be concrete strings — version_compare() with null
        // produces deprecation warnings on PHP 8.1+ and would also walk the
        // entire chain (returning the wrong terminal version) instead of bailing.
        if (empty($from_version) || empty($to_version)) {
            return null;
        }

        if (!file_exists($mappings_file) || !is_readable($mappings_file)) {
            return null;
        }

        // The mappings file calls Configure::set('Upgrade.mappings', [...]).
        // Save the current value, include the file (which clobbers Configure),
        // capture the staged value, and restore. Plain `include` (not include_once)
        // so the file is always re-executed even if a same-named mappings file was
        // loaded earlier in the request.
        $saved = Configure::get('Upgrade.mappings');

        try {
            include $mappings_file;
            $mappings = Configure::get('Upgrade.mappings');
        } finally {
            Configure::set('Upgrade.mappings', $saved);
        }

        if (!is_array($mappings)) {
            return null;
        }

        // Walk the chain the same way Upgrades::start() does. Iterate the mappings
        // in declaration order; for each (start, end) pair, advance the cursor when
        // current <= start AND end <= target. This handles non-canonical starting
        // versions (e.g. patch releases that didn't have schema tasks of their own).
        $current = $from_version;
        foreach ($mappings as $start => $end) {
            if (
                version_compare($current, $start, '<=')
                && version_compare($end, $to_version, '<=')
            ) {
                $current = $end;
            }
        }

        return $current;
    }

    /**
     * Reports whether an upgrade lock is currently active and not stale.
     *
     * Intended for callers (like cron) that only need a yes/no answer for
     * "should I avoid running because an upgrade is in progress?". A stale
     * lock counts as inactive — it must be cleared explicitly via clearlock.
     *
     * @return bool
     */
    public function isLockActive()
    {
        $lock = $this->getLockStatus();

        return $lock !== null && empty($lock['stale']);
    }

    /**
     * Creates a database backup using mysqldump.
     *
     * @param string|null $backup_dir Directory to store the backup. Defaults to configured backup dir.
     * @return string|false Path to the backup file on success, false on failure
     */
    public function createDatabaseBackup($backup_dir = null)
    {
        if ($backup_dir === null) {
            $backup_dir = $this->getBackupDirectory();
        }

        if (!$this->ensureDirectory($backup_dir)) {
            return false;
        }

        $db_info = Configure::get('Database.profile');

        if (empty($db_info) || ($db_info['driver'] ?? '') !== 'mysql') {
            return false;
        }

        $version = defined('BLESTA_VERSION') ? BLESTA_VERSION : 'unknown';
        $timestamp = date('Y-m-d\THis\Z');
        $filename = sprintf('blesta_db_backup_%s_%s.sql.gz', $version, $timestamp);
        $filepath = rtrim($backup_dir, '/') . '/' . $filename;

        $port = $db_info['port'] ?? '3306';

        // Write the password to a temporary defaults file (mode 0600) so it
        // never appears in `ps aux`. The file is always cleaned up via finally.
        // Use the configured Blesta temp dir so this honors open_basedir and
        // any admin-overridden temp_dir setting, matching the rest of the upgrader.
        $cnf_file = tempnam($this->getTempDir(), 'blesta_my_');
        if ($cnf_file === false) {
            return false;
        }
        chmod($cnf_file, 0600);

        // Escape the password for MySQL option-file format. Quoted values
        // honor C-style escapes for backslash, double quote, newline and CR.
        $escaped_pass = strtr((string) $db_info['pass'], [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
        ]);

        $cnf_contents = "[client]\npassword=\"" . $escaped_pass . "\"\n";
        if (file_put_contents($cnf_file, $cnf_contents) === false) {
            @unlink($cnf_file);
            return false;
        }

        try {
            // --defaults-extra-file MUST come first on the mysqldump command line.
            $cmd = sprintf(
                'mysqldump --defaults-extra-file=%s --host=%s --port=%s --user=%s %s | gzip > %s',
                escapeshellarg($cnf_file),
                escapeshellarg($db_info['host']),
                escapeshellarg($port),
                escapeshellarg($db_info['user']),
                escapeshellarg($db_info['database']),
                escapeshellarg($filepath)
            );

            exec($cmd . ' 2>&1', $output, $exit_code);

            if ($exit_code !== 0 || !file_exists($filepath)) {
                return false;
            }

            if (!$this->validateDatabaseBackup($filepath)) {
                unlink($filepath);
                return false;
            }

            return $filepath;
        } finally {
            @unlink($cnf_file);
        }
    }

    /**
     * Validates a database backup file.
     *
     * @param string $file_path Path to the backup file
     * @return bool True if valid, false otherwise
     */
    public function validateDatabaseBackup($file_path)
    {
        if (!file_exists($file_path) || filesize($file_path) === 0) {
            return false;
        }

        // Read first few KB to check for valid mysqldump header
        $gz = gzopen($file_path, 'rb');
        if ($gz === false) {
            return false;
        }

        $header = gzread($gz, 4096);

        // Check for mysqldump markers
        $has_header = strpos($header, '-- MySQL dump') !== false
            || strpos($header, '-- MariaDB dump') !== false
            || strpos($header, 'mysqldump') !== false;

        if (!$has_header) {
            gzclose($gz);
            return false;
        }

        // Read to end and check for completion marker to detect truncated dumps
        $tail = $header;
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 65536);
            if ($chunk === false) {
                break;
            }
            // Only keep the last 4KB — the completion marker is at the very end
            $tail = strlen($chunk) >= 4096 ? $chunk : substr($tail . $chunk, -4096);
        }
        gzclose($gz);

        $has_footer = strpos($tail, '-- Dump completed') !== false;

        return $has_footer;
    }

    /**
     * Creates a compressed file backup of the installation.
     *
     * @param string|null $backup_dir Directory to store the backup
     * @param string|null $install_dir Installation directory to back up
     * @return string|false Path to the backup file on success, false on failure
     */
    public function createFileBackup($backup_dir = null, $install_dir = null)
    {
        if ($backup_dir === null) {
            $backup_dir = $this->getBackupDirectory();
        }
        if ($install_dir === null) {
            $install_dir = $this->getInstallDir();
        }

        if (!$this->ensureDirectory($backup_dir)) {
            return false;
        }

        $version = defined('BLESTA_VERSION') ? BLESTA_VERSION : 'unknown';
        $timestamp = date('Y-m-d\THis\Z');
        $filename = sprintf('blesta_files_backup_%s_%s.tar.gz', $version, $timestamp);
        $filepath = rtrim($backup_dir, '/') . '/' . $filename;

        // Build excludes as relative paths (tar -C makes all paths relative to install_dir)
        $excludes = ['./cache'];

        $real_install = realpath($install_dir);
        $real_backup = realpath($backup_dir);
        if ($real_install && $real_backup && strpos($real_backup, $real_install) === 0) {
            $excludes[] = './' . substr($real_backup, strlen($real_install) + 1);
        }

        $exclude_args = '';
        foreach ($excludes as $exclude) {
            $exclude_args .= ' --exclude=' . escapeshellarg($exclude);
        }

        $cmd = sprintf(
            'tar -czf %s %s -C %s .',
            escapeshellarg($filepath),
            $exclude_args,
            escapeshellarg($install_dir)
        );

        exec($cmd . ' 2>&1', $output, $exit_code);

        if ($exit_code !== 0 || !file_exists($filepath)) {
            return false;
        }

        if (!$this->validateFileBackup($filepath)) {
            unlink($filepath);
            return false;
        }

        return $filepath;
    }

    /**
     * Validates a file backup archive.
     *
     * @param string $file_path Path to the tar.gz file
     * @return bool True if valid, false otherwise
     */
    public function validateFileBackup($file_path)
    {
        if (!file_exists($file_path) || filesize($file_path) === 0) {
            return false;
        }

        exec(sprintf('tar -tzf %s > /dev/null 2>&1', escapeshellarg($file_path)), $output, $exit_code);

        return $exit_code === 0;
    }

    /**
     * Downloads a release zip file.
     *
     * @param object|array $release The release object with download_url
     * @param string|null $temp_dir Temporary directory for download
     * @return string|false Path to downloaded file on success, false on failure
     */
    public function downloadRelease($release, $temp_dir = null)
    {
        $release = (array) $release;

        if (empty($release['download_url'])) {
            return false;
        }

        if ($temp_dir === null) {
            $temp_dir = $this->getTempDir();
        }

        if (!$this->ensureDirectory($temp_dir)) {
            return false;
        }

        $filename = 'blesta-' . ($release['version'] ?? 'release') . '.zip';
        $filepath = rtrim($temp_dir, '/') . '/' . $filename;

        // Prefer curl for better download handling
        if ($this->commandExists('curl')) {
            $cmd = sprintf(
                'curl -sL -o %s %s',
                escapeshellarg($filepath),
                escapeshellarg($release['download_url'])
            );
            exec($cmd . ' 2>&1', $output, $exit_code);

            if ($exit_code === 0 && file_exists($filepath) && filesize($filepath) > 0) {
                return $filepath;
            }
        }

        // Fallback to file_get_contents
        $context = stream_context_create([
            'http' => [
                'timeout' => 300,
                'user_agent' => 'Blesta/' . (defined('BLESTA_VERSION') ? BLESTA_VERSION : 'unknown'),
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($release['download_url'], false, $context);

        if ($data === false || empty($data)) {
            return false;
        }

        if (file_put_contents($filepath, $data) === false) {
            return false;
        }

        return $filepath;
    }

    /**
     * Verifies the SHA-256 hash of a downloaded file.
     *
     * @param string $file_path Path to the file
     * @param string $expected_hash Expected SHA-256 hash
     * @return bool True if hash matches, false otherwise
     */
    public function verifyIntegrity($file_path, $expected_hash)
    {
        if (!file_exists($file_path)) {
            return false;
        }

        $actual_hash = hash_file('sha256', $file_path);

        return hash_equals($expected_hash, $actual_hash);
    }

    /**
     * Verifies the OpenSSL signature of a file against the pinned public key.
     *
     * @param string $file_path Path to the file to verify
     * @param string $signature Base64-encoded signature
     * @return bool True if signature is valid, false otherwise
     */
    public function verifySignature($file_path, $signature)
    {
        if (!file_exists($file_path)) {
            return false;
        }

        $public_key_path = CONFIGDIR . 'release-signing-key.pub';

        if (!file_exists($public_key_path)) {
            return false;
        }

        $signature_binary = base64_decode($signature, true);

        if ($signature_binary === false) {
            return false;
        }

        // Write signature to a temp file for openssl CLI verification.
        // We shell out rather than using openssl_verify() to avoid loading
        // the entire release zip (50-100MB+) into PHP memory.
        $temp_dir = $this->getTempDir();
        $sig_file = rtrim($temp_dir, '/') . '/blesta_upgrade_sig_' . getmypid() . '.bin';

        if (file_put_contents($sig_file, $signature_binary) === false) {
            return false;
        }

        $cmd = sprintf(
            'openssl dgst -sha256 -verify %s -signature %s %s',
            escapeshellarg($public_key_path),
            escapeshellarg($sig_file),
            escapeshellarg($file_path)
        );

        exec($cmd . ' 2>&1', $output, $exit_code);

        unlink($sig_file);

        // openssl dgst returns 0 and prints "Verified OK" on success
        return $exit_code === 0;
    }

    /**
     * Extracts a release zip to a staging directory.
     *
     * @param string $zip_path Path to the zip file
     * @param string $staging_dir Directory to extract to
     * @return bool True on success, false on failure
     */
    public function extractToStaging($zip_path, $staging_dir)
    {
        if (!$this->ensureDirectory($staging_dir)) {
            return false;
        }

        $cmd = sprintf(
            'unzip -o %s -d %s',
            escapeshellarg($zip_path),
            escapeshellarg($staging_dir)
        );

        exec($cmd . ' 2>&1', $output, $exit_code);

        if ($exit_code !== 0) {
            return false;
        }

        // Validate expected structure (should contain blesta/ directory)
        $expected_dir = rtrim($staging_dir, '/') . '/blesta';
        if (!is_dir($expected_dir)) {
            return false;
        }

        // Verify key files exist
        $key_files = ['app/app_controller.php', 'config/blesta-new.php'];
        foreach ($key_files as $file) {
            if (!file_exists($expected_dir . '/' . $file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Copies the upgrader script to a location outside the install tree.
     *
     * @param string $staging_dir The staging directory containing extracted release
     * @param string $external_dir Directory outside install tree to copy upgrader to
     * @return string|false Path to the copied upgrader on success, false on failure
     */
    public function prepareUpgrader($staging_dir, $external_dir)
    {
        $source = rtrim($staging_dir, '/') . '/blesta/upgrader.php';

        if (!file_exists($source)) {
            return false;
        }

        if (!$this->ensureDirectory($external_dir)) {
            return false;
        }

        $dest = rtrim($external_dir, '/') . '/blesta_upgrader.php';

        if (!copy($source, $dest)) {
            return false;
        }

        chmod($dest, 0755);

        return $dest;
    }

    /**
     * Writes a job file with parameters for the standalone upgrader.
     *
     * @param array $params Job parameters
     * @return string|false Path to the job file on success, false on failure
     */
    public function writeJobFile(array $params)
    {
        $temp_dir = $this->getTempDir();
        $job_file = rtrim($temp_dir, '/') . '/blesta_upgrade_job.json';

        $json = json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($job_file, $json) === false) {
            return false;
        }

        // Restrict permissions even though the job file no longer holds
        // credentials — it still points at backup paths, lock files, and
        // internal state we'd rather not expose to other local accounts.
        chmod($job_file, 0600);

        return $job_file;
    }

    /**
     * Launches the standalone upgrader as a background process.
     *
     * The command uses `setsid` to put the child in a new session and
     * redirects all three standard streams, which is what lets the process
     * survive the request finishing. On jailed hosting (CloudLinux CageFS,
     * some PHP-FPM configs) a plain `&` leaves the child in the parent
     * request's process group, and the jail kills the whole group when the
     * request ends — leaving the staging dir populated but no upgrader
     * running. `setsid` is provided by util-linux and is available on any
     * modern Linux host that passes the environment check.
     *
     * @param string $upgrader_path Path to the upgrader script
     * @param string $job_file_path Path to the job file
     * @return bool True if launched successfully, false otherwise
     */
    public function launchUpgrader($upgrader_path, $job_file_path)
    {
        if (!file_exists($upgrader_path) || !file_exists($job_file_path)) {
            return false;
        }

        $php_binary = $this->getCliPhpBinary();

        $cmd = sprintf(
            'setsid %s %s %s < /dev/null > /dev/null 2>&1 &',
            escapeshellarg($php_binary),
            escapeshellarg($upgrader_path),
            escapeshellarg($job_file_path)
        );

        // Fire and forget. The shell's return code for a backgrounded (`&`)
        // command is unreliable across platforms, and a missing `setsid`
        // binary surfaces here as an exec failure that we do NOT want to
        // treat as fatal — the admin should still be offered the manual
        // recovery command. verifyUpgraderStarted() is the authoritative
        // signal for whether the background process actually started.
        exec($cmd);

        return true;
    }

    /**
     * Builds a shell-safe command the admin can run via SSH to complete an
     * upgrade when the background launch was killed by the host environment.
     * Uses the same binary the web process would have used, except when that
     * resolves to an FPM/CGI binary — those cannot run a script as CLI, so
     * we fall back to `php` from PATH (what SSH users typically have).
     *
     * @param string $upgrader_path Absolute path to the copied upgrader script
     * @param string $job_file_path Absolute path to the job file
     * @return string Shell-escaped command string
     */
    public function getManualLaunchCommand($upgrader_path, $job_file_path)
    {
        return sprintf(
            '%s %s %s',
            escapeshellarg($this->getCliPhpBinary()),
            escapeshellarg($upgrader_path),
            escapeshellarg($job_file_path)
        );
    }

    /**
     * Waits briefly for the upgrader to write its first status update after
     * launch. The upgrader's first action is to call writeStatus(), stamped
     * with the same launch nonce the controller wrote into the job file.
     * Requiring the nonce means a stale status file from a prior completed
     * upgrade cannot mask a dead background process — it just carries the
     * wrong nonce and we keep waiting until timeout.
     *
     * @param string $expected_nonce The nonce the controller placed in the job file
     * @param int $timeout_seconds How long to wait in total (default 4)
     * @return bool True if a matching status file appears, false on timeout
     */
    public function verifyUpgraderStarted($expected_nonce, $timeout_seconds = 4)
    {
        $status_file = $this->getStatusFilePath();
        $deadline = microtime(true) + $timeout_seconds;

        while (microtime(true) < $deadline) {
            clearstatcache(true, $status_file);
            if (file_exists($status_file)) {
                $status = json_decode(file_get_contents($status_file), true);
                if (is_array($status) && ($status['launch_nonce'] ?? null) === $expected_nonce) {
                    return true;
                }
            }
            usleep(250000);
        }

        return false;
    }

    /**
     * Generates a new launch nonce. Stored in the job file and written back
     * into every status update by the upgrader so verification can confirm
     * it read the status file from *this* launch, not a prior run.
     *
     * @return string 16-char hex string
     */
    public function generateLaunchNonce()
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Reads the upgrade status file.
     *
     * If a lock exists, the status file must carry a matching launch_nonce
     * or it's treated as belonging to a prior run and ignored. That prevents
     * stale "complete" status from a previous upgrade leaking into the UI
     * after a launch_failed scenario (where polling would otherwise resume
     * and hide the manual recovery panel on a false success signal).
     *
     * @return array|null Status data array, or null if no status is available
     */
    public function getUpgradeStatus()
    {
        $status_file = $this->getStatusFilePath();

        if (!file_exists($status_file)) {
            return null;
        }

        $data = json_decode(file_get_contents($status_file), true);

        if (!is_array($data)) {
            return null;
        }

        $lock = $this->getLockStatus();

        // When a lock with a nonce is active, the status file must belong to
        // the same launch. A missing or mismatched nonce in the status means
        // the file is either from a previous completed run or pre-nonce.
        if ($lock !== null && !empty($lock['launch_nonce'])) {
            if (($data['launch_nonce'] ?? null) !== $lock['launch_nonce']) {
                return null;
            }
        }

        // Check for stale process
        if (
            ($data['status'] ?? '') === 'running'
            && $lock !== null
            && !empty($lock['stale'])
        ) {
            $data['status'] = 'stale';
            $data['error'] = Language::_('SystemUpgrade.!error.upgrader_crashed', true);
        }

        return $data;
    }

    /**
     * Enables maintenance mode and records the previous state.
     */
    public function enableMaintenanceMode()
    {
        Loader::loadModels($this, ['Settings']);

        $current = $this->Settings->getSetting('maintenance_mode');
        $was_enabled = ($current && $current->value === 'true');

        // Store previous state
        $this->Settings->setSetting('upgrade_maintenance_was_enabled', $was_enabled ? 'true' : 'false');

        if (!$was_enabled) {
            $this->Settings->setSetting('maintenance_mode', 'true');
            $this->Settings->setSetting(
                'maintenance_reason',
                Language::_('SystemUpgrade.maintenance_reason', true)
            );
        }
    }

    /**
     * Restores maintenance mode to its pre-upgrade state.
     */
    public function restoreMaintenanceMode()
    {
        Loader::loadModels($this, ['Settings']);

        $was_enabled = $this->Settings->getSetting('upgrade_maintenance_was_enabled');

        if (!$was_enabled || $was_enabled->value !== 'true') {
            $this->Settings->setSetting('maintenance_mode', 'false');
        }

        // Clean up the temporary setting
        $this->Settings->unsetSetting('upgrade_maintenance_was_enabled');
    }

    /**
     * Returns the path where backups should be stored.
     *
     * @return string The backup directory path
     */
    public function getBackupDirectory()
    {
        Loader::loadModels($this, ['Settings']);
        Loader::loadComponents($this, ['SettingsCollection']);

        // Check for configured backup directory (must be outside install tree)
        $configured = $this->Settings->getSetting('upgrade_backup_dir');
        if ($configured && !empty($configured->value)) {
            $configured_path = realpath($configured->value);
            $install_path = realpath($this->getInstallDir());

            // Reject if the configured path is inside the install directory
            if (
                $configured_path !== false
                && $install_path !== false
                && strpos($configured_path, $install_path) === 0
            ) {
                // Fall through to default
            } else {
                return $configured->value;
            }
        }

        // Default: inside uploads dir (always above document root)
        $uploads = $this->SettingsCollection->fetchSystemSetting(null, 'uploads_dir');
        $uploads_dir = $uploads['value'] ?? '';

        if (!empty($uploads_dir)) {
            return rtrim($uploads_dir, '/\\') . '/blesta_backups';
        }

        // Fallback to temp dir
        return rtrim($this->getTempDir(), '/') . '/blesta_backups';
    }

    /**
     * Lists existing upgrade backups with dates and sizes.
     *
     * @return array An array of backup info arrays with 'filename', 'path', 'size', 'date', 'type'
     */
    public function getBackups()
    {
        $backup_dir = $this->getBackupDirectory();
        $backups = [];

        if (!is_dir($backup_dir)) {
            return $backups;
        }

        $iterator = new \DirectoryIterator($backup_dir);
        foreach ($iterator as $file) {
            if ($file->isDot() || !$file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();

            if (
                strpos($filename, 'blesta_db_backup_') === 0
                || strpos($filename, 'blesta_files_backup_') === 0
            ) {
                $type = strpos($filename, '_db_') !== false ? 'database' : 'files';
                $backups[] = [
                    'filename' => $filename,
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'date' => $file->getMTime(),
                    'type' => $type,
                ];
            }
        }

        // Sort by date descending
        usort($backups, function ($a, $b) {
            return $b['date'] - $a['date'];
        });

        return $backups;
    }

    /**
     * Removes old upgrade backups, keeping the N most recent pairs.
     *
     * @param int $keep Number of backup pairs to keep
     */
    public function cleanupOldBackups($keep = 3)
    {
        $backups = $this->getBackups();

        // Group by type
        $db_backups = array_filter($backups, function ($b) {
            return $b['type'] === 'database';
        });
        $file_backups = array_filter($backups, function ($b) {
            return $b['type'] === 'files';
        });

        // Remove excess (already sorted by date desc)
        $db_backups = array_values($db_backups);
        $file_backups = array_values($file_backups);

        for ($i = $keep; $i < count($db_backups); $i++) {
            if (file_exists($db_backups[$i]['path'])) {
                unlink($db_backups[$i]['path']);
            }
        }
        for ($i = $keep; $i < count($file_backups); $i++) {
            if (file_exists($file_backups[$i]['path'])) {
                unlink($file_backups[$i]['path']);
            }
        }
    }

    /**
     * Checks if the license allows upgrade to the target version.
     * Patch upgrades are always allowed regardless of license status.
     *
     * @param string $target_version The version to upgrade to
     * @return bool True if license allows the upgrade
     */
    public function isLicenseValidForVersion($target_version)
    {
        $current_version = defined('BLESTA_VERSION') ? BLESTA_VERSION : '0.0.0';
        $current_parts = $this->parseVersion($current_version);
        $target_parts = $this->parseVersion($target_version);

        // Patch upgrades are always allowed
        if (
            $current_parts['major'] === $target_parts['major']
            && $current_parts['minor'] === $target_parts['minor']
        ) {
            return true;
        }

        // Major/minor upgrades require valid support & updates.
        // Mirrors the exact logic in Upgrades::start() to avoid a mismatch
        // where we allow the upgrade but the CLI migration refuses to run.
        Loader::loadModels($this, ['License']);

        $this->License->fetchLicense();
        $license = $this->License->getLocalData();

        if (!$license || !array_key_exists('updates', (array) $license)) {
            // No license data or no 'updates' key — Upgrades::start() skips
            // the support check in this case, so we allow the upgrade
            return true;
        }

        // If 'updates' is false or null, the license has no support restriction
        // (e.g. lifetime or legacy license) — allow the upgrade
        if ($license['updates'] === false || $license['updates'] === null) {
            return true;
        }

        // Check if support & updates has expired
        if (time() > strtotime($license['updates'])) {
            // Expired — but if max_version is set and current version is within
            // the cap, Upgrades::start() will still allow it
            if (
                isset($license['max_version'])
                && version_compare($current_version, $license['max_version'], '<=')
            ) {
                return true;
            }

            return false;
        }

        return true;
    }

    /**
     * Checks the current installation against the manifest to find modified core files.
     *
     * @return array An array with keys:
     *  - 'has_manifest' (bool) Whether a manifest.json exists in the installation
     *  - 'has_checksums' (bool) Whether the manifest includes SHA-256 checksums
     *  - 'modified' (array) List of relative file paths that have been modified
     *  - 'missing' (array) List of relative file paths that are missing from the installation
     */
    public function getModifiedCoreFiles()
    {
        $install_dir = $this->getInstallDir();
        $manifest_path = $install_dir . '/manifest.json';

        $empty_result = [
            'has_manifest' => false,
            'has_checksums' => false,
            'modified' => [],
            'missing' => [],
        ];

        if (!file_exists($manifest_path)) {
            return $empty_result;
        }

        $manifest = json_decode(file_get_contents($manifest_path), true);

        if ($manifest === null || !isset($manifest['files'])) {
            return $empty_result;
        }

        // Detect whether this manifest includes checksums (object format vs flat strings)
        $has_checksums = false;
        if (!empty($manifest['files'])) {
            $first = reset($manifest['files']);
            $has_checksums = is_array($first) && isset($first['sha256']);
        }

        $modified = [];
        $missing = [];

        // Allow sufficient time for hashing all files in large installations
        $prev_limit = (int) ini_get('max_execution_time');
        set_time_limit(300);

        foreach ($manifest['files'] as $entry) {
            if (is_array($entry)) {
                $path = $entry['path'] ?? '';
                $expected_hash = $entry['sha256'] ?? null;
            } else {
                $path = $entry;
                $expected_hash = null;
            }

            if ($path === '' || $path === 'manifest.json') {
                continue;
            }

            $full_path = $install_dir . '/' . $path;

            if (!file_exists($full_path)) {
                $missing[] = $path;
                continue;
            }

            // Only check hash if the manifest includes checksums
            if ($expected_hash !== null) {
                if (!is_readable($full_path)) {
                    // File exists but can't be read — treat as modified since we can't verify
                    $modified[] = $path;
                    continue;
                }

                $actual_hash = hash_file('sha256', $full_path);
                if ($actual_hash === false || !hash_equals($expected_hash, $actual_hash)) {
                    $modified[] = $path;
                }
            }
        }

        // Restore previous time limit
        set_time_limit($prev_limit);

        return [
            'has_manifest' => true,
            'has_checksums' => $has_checksums,
            'modified' => $modified,
            'missing' => $missing,
        ];
    }

    /**
     * Returns the Blesta installation directory.
     *
     * @return string The installation directory path
     */
    public function getInstallDir()
    {
        return defined('ROOTWEBDIR') ? rtrim(ROOTWEBDIR, DIRECTORY_SEPARATOR) : dirname(__DIR__, 2);
    }

    /**
     * Returns the temp directory path.
     *
     * @return string
     */
    public function getTempDir()
    {
        Loader::loadComponents($this, ['SettingsCollection']);

        $temp = $this->SettingsCollection->fetchSystemSetting(null, 'temp_dir');

        return $temp['value'] ?? sys_get_temp_dir();
    }

    /**
     * Gets the path to the upgrade lock file.
     *
     * @return string
     */
    public function getLockFilePath()
    {
        return rtrim($this->getTempDir(), '/') . '/blesta_upgrade.lock';
    }

    /**
     * Gets the path to the upgrade status file.
     *
     * @return string
     */
    public function getStatusFilePath()
    {
        return rtrim($this->getTempDir(), '/') . '/blesta_upgrade_status.json';
    }

    /**
     * Parses a version string into major, minor, patch components.
     *
     * @param string $version The version string (e.g., "6.0.0-b1")
     * @return array An array with 'major', 'minor', 'patch' integer keys
     */
    private function parseVersion($version)
    {
        // Strip pre-release suffix
        $version = preg_replace('/-.*$/', '', $version);
        $parts = explode('.', $version);

        return [
            'major' => (int) ($parts[0] ?? 0),
            'minor' => (int) ($parts[1] ?? 0),
            'patch' => (int) ($parts[2] ?? 0),
        ];
    }

    /**
     * Checks if a shell command is available.
     *
     * @param string $command The command name
     * @return bool True if the command exists
     */
    private function commandExists($command)
    {
        exec(sprintf('which %s 2>/dev/null', escapeshellarg($command)), $output, $exit_code);

        return $exit_code === 0;
    }

    /**
     * Checks available disk space against estimated requirements.
     *
     * @param string $install_dir The installation directory
     * @return array An array with 'sufficient' (bool), 'free' (bytes), 'needed' (bytes)
     */
    private function checkDiskSpace($install_dir)
    {
        $free = disk_free_space($install_dir);
        $free = ($free !== false) ? $free : 0;

        // Estimate: use du to get install size, need 3x
        exec(sprintf('du -sb %s 2>/dev/null', escapeshellarg($install_dir)), $output, $exit_code);

        $install_size = 0;
        if ($exit_code === 0 && !empty($output[0])) {
            $parts = preg_split('/\s+/', trim($output[0]));
            $install_size = (int) ($parts[0] ?? 0);
        }

        $needed = $install_size * 3;

        return [
            'sufficient' => $free >= $needed,
            'free' => $free,
            'needed' => $needed,
        ];
    }

    /**
     * Ensures a directory exists, creating it if necessary.
     *
     * @param string $dir The directory path
     * @return bool True if the directory exists and is writable
     */
    private function ensureDirectory($dir)
    {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return false;
            }
        }

        return is_writable($dir);
    }

    /**
     * Formats bytes into a human-readable string.
     *
     * @param int $bytes Number of bytes
     * @return string Formatted string (e.g., "1.5 GB")
     */
    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / pow(1024, $pow), 1) . ' ' . $units[$pow];
    }

    /**
     * Gets the path to the PHP binary.
     *
     * @return string Path to the PHP CLI binary
     */
    /**
     * Returns a PHP CLI binary path suitable for launching the upgrader and
     * for running DB migrations from inside it. Prefers PHP_BINARY when the
     * current SAPI is CLI-capable; falls back to `php` on PATH when
     * PHP_BINARY resolves to an FPM/CGI binary that can't execute a plain
     * script. The same value is used for the background launch, the manual
     * recovery command, and the job file's php_binary field, so all three
     * code paths behave identically.
     *
     * @return string
     */
    public function getCliPhpBinary()
    {
        if (defined('PHP_BINARY') && !empty(PHP_BINARY)) {
            $basename = basename(PHP_BINARY);
            if (stripos($basename, 'fpm') === false && stripos($basename, 'cgi') === false) {
                return PHP_BINARY;
            }
        }

        return 'php';
    }

    /**
     * Gets a setting value with a default fallback.
     *
     * @param string $key The setting key
     * @param mixed $default The default value if not set
     * @return string The setting value
     */
    private function getSettingValue($key, $default = null)
    {
        Loader::loadModels($this, ['Settings']);

        $setting = $this->Settings->getSetting($key);

        if ($setting && $setting->value !== null) {
            return $setting->value;
        }

        return $default;
    }
}
