<?php

/**
 * Admin System Upgrade Settings
 *
 * @package blesta
 * @subpackage app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminSystemUpgrade extends AdminController
{
    /**
     * Pre-action setup method that is called before the index method, or the set controller action
     */
    public function preAction()
    {
        parent::preAction();
        $this->uses(['SystemUpgrade', 'Settings']);
    }

    /**
     * Upgrade options page
     */
    public function index()
    {
        $current_version = defined('BLESTA_VERSION') ? BLESTA_VERSION : '0.0.0';
        $environment_status = $this->SystemUpgrade->getEnvironmentStatus();
        $available_upgrades = $this->SystemUpgrade->getAvailableUpgrades();

        $lock_status = $this->SystemUpgrade->getLockStatus();
        $upgrade_status = $this->SystemUpgrade->getUpgradeStatus();
        $backups = $this->SystemUpgrade->getBackups();

        // Determine overall environment health
        $env_all_pass = true;
        $env_has_fail = false;
        $env_fail_count = 0;
        $env_warn_count = 0;
        foreach ($environment_status as $check) {
            if ($check['status'] === 'fail') {
                $env_all_pass = false;
                $env_has_fail = true;
                $env_fail_count++;
            } elseif ($check['status'] === 'warn') {
                $env_all_pass = false;
                $env_warn_count++;
            }
        }

        // Get last check timestamp
        $last_check_setting = $this->Settings->getSetting('upgrade_last_check');
        $last_check = $last_check_setting ? (int) $last_check_setting->value : null;

        // Check if support & updates is NOT active (disables major/minor upgrade radio)
        $no_support = false;
        foreach ($available_upgrades as $upgrade) {
            if (!empty($upgrade['requires_support']) && !$this->SystemUpgrade->isLicenseValidForVersion($upgrade['version'])) {
                $no_support = true;
                break;
            }
        }

        $this->set('current_version', $current_version);
        $this->set('environment_status', $environment_status);
        $this->set('env_all_pass', $env_all_pass);
        $this->set('env_has_fail', $env_has_fail);
        $this->set('env_fail_count', $env_fail_count);
        $this->set('env_warn_count', $env_warn_count);

        // If the lock carries a launch_failed marker with persisted recovery
        // paths, reconstruct the manual command so the stale-lock UI can
        // surface the same guidance the original AJAX response showed.
        $launch_failed_command = null;
        if (
            !empty($lock_status['launch_failed'])
            && !empty($lock_status['launch_failed_upgrader_path'])
            && !empty($lock_status['launch_failed_job_file'])
        ) {
            $launch_failed_command = $this->SystemUpgrade->getManualLaunchCommand(
                $lock_status['launch_failed_upgrader_path'],
                $lock_status['launch_failed_job_file']
            );
        }
        $this->set('launch_failed_command', $launch_failed_command);
        $this->set('available_upgrades', $available_upgrades);
        $this->set('no_support', $no_support);
        $this->set('lock_status', $lock_status);
        $this->set('upgrade_status', $upgrade_status);
        $this->set('backups', $backups);
        $this->set('last_check', $last_check);
    }

    /**
     * Check for updates (AJAX)
     */
    public function check()
    {
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri . 'settings/system/upgrade/');
        }

        $release_data = $this->SystemUpgrade->checkForUpdates(true);
        $available_upgrades = $this->SystemUpgrade->getAvailableUpgrades();

        $this->outputAsJson([
            'success' => !empty($release_data),
            'upgrades' => $available_upgrades,
            'checked_at' => time(),
        ]);

        return false;
    }

    /**
     * Start the upgrade process (AJAX)
     */
    public function upgrade()
    {
        if (!$this->isAjax() || empty($this->post)) {
            $this->redirect($this->base_uri . 'settings/system/upgrade/');
        }

        $target_version = $this->post['target_version'] ?? '';

        if (empty($target_version)) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.environment_fail', true),
            ]);
            return false;
        }

        // Get the matching release info
        $available = $this->SystemUpgrade->getAvailableUpgrades();

        $release = null;

        foreach ($available as $upgrade) {
            if ($upgrade['version'] === $target_version) {
                $release = $upgrade;
                break;
            }
        }

        if ($release === null) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.environment_fail', true),
            ]);
            return false;
        }

        // Pre-flight: environment checks
        $env_status = $this->SystemUpgrade->getEnvironmentStatus();
        foreach ($env_status as $check) {
            if ($check['status'] === 'fail') {
                $this->outputAsJson([
                    'success' => false,
                    'error' => Language::_('SystemUpgrade.!error.environment_fail', true),
                ]);
                return false;
            }
        }

        // Pre-flight: license check (method handles patch versions internally)
        if (!$this->SystemUpgrade->isLicenseValidForVersion($target_version)) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.license_invalid', true),
            ]);
            return false;
        }

        // Pre-flight: PHP version check
        if (!empty($release['min_php_version']) && version_compare(PHP_VERSION, $release['min_php_version'], '<')) {
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_(
                    'SystemUpgrade.!error.php_version',
                    true,
                    $release['min_php_version'],
                    PHP_VERSION
                ),
            ]);
            return false;
        }

        // Check for modified core files (unless user confirmed overwrite)
        $confirm_overwrite = !empty($this->post['confirm_overwrite']);
        if (!$confirm_overwrite) {
            $file_check = $this->SystemUpgrade->getModifiedCoreFiles();

            if (!$file_check['has_manifest']) {
                // No manifest at all — warn but allow continuing
                $this->outputAsJson([
                    'success' => false,
                    'needs_confirm' => 'no_manifest',
                    'message' => Language::_('AdminSystemUpgrade.upgrade.no_manifest', true),
                ]);
                return false;
            }

            if (!$file_check['has_checksums']) {
                // Manifest exists but lacks checksums (old format) — warn but allow continuing
                $this->outputAsJson([
                    'success' => false,
                    'needs_confirm' => 'no_manifest',
                    'message' => Language::_('AdminSystemUpgrade.upgrade.no_checksums', true),
                ]);
                return false;
            }

            $changed_files = array_merge($file_check['modified'], $file_check['missing']);
            if (!empty($changed_files)) {
                $this->outputAsJson([
                    'success' => false,
                    'needs_confirm' => 'modified_files',
                    'message' => Language::_(
                        'AdminSystemUpgrade.upgrade.modified_files',
                        true,
                        count($changed_files)
                    ),
                    'modified_files' => $file_check['modified'],
                    'missing_files' => $file_check['missing'],
                ]);
                return false;
            }
        }

        // Acquire lock. The launch nonce is generated here and stored in the
        // lock so every downstream check — initial verifyUpgraderStarted(),
        // status-polling validation, stale-status rejection — can confirm it
        // is looking at the status file produced by *this* upgrade attempt.
        $staff_id = $this->Session->read('blesta_staff_id');
        $launch_nonce = $this->SystemUpgrade->generateLaunchNonce();
        if (!$this->SystemUpgrade->acquireLock($staff_id, $target_version, $launch_nonce)) {
            $lock = $this->SystemUpgrade->getLockStatus();
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_(
                    'SystemUpgrade.!error.upgrade_locked',
                    true,
                    $lock['staff_id'] ?? '?',
                    $lock['started_at'] ?? '?'
                ),
            ]);
            return false;
        }

        // Enable maintenance mode
        $this->SystemUpgrade->enableMaintenanceMode();

        // Database backup
        $db_backup = $this->SystemUpgrade->createDatabaseBackup();
        if ($db_backup === false) {
            $this->SystemUpgrade->restoreMaintenanceMode();
            $this->SystemUpgrade->releaseLock();
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.backup_db_failed', true),
            ]);
            return false;
        }

        // File backup
        $file_backup = $this->SystemUpgrade->createFileBackup();
        if ($file_backup === false) {
            $this->SystemUpgrade->restoreMaintenanceMode();
            $this->SystemUpgrade->releaseLock();
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.backup_files_failed', true),
            ]);
            return false;
        }

        // Download release
        $zip_path = $this->SystemUpgrade->downloadRelease($release);
        if ($zip_path === false) {
            $this->SystemUpgrade->restoreMaintenanceMode();
            $this->SystemUpgrade->releaseLock();
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.download_failed', true),
            ]);
            return false;
        }

        // Verify integrity
        if (!empty($release['sha256']) && !$this->SystemUpgrade->verifyIntegrity($zip_path, $release['sha256'])) {
            unlink($zip_path);
            $this->SystemUpgrade->restoreMaintenanceMode();
            $this->SystemUpgrade->releaseLock();
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.hash_mismatch', true),
            ]);
            return false;
        }

        // Verify signature (mandatory — reject releases without a signature)
        if (empty($release['signature'])) {
            unlink($zip_path);
            $this->SystemUpgrade->restoreMaintenanceMode();
            $this->SystemUpgrade->releaseLock();
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.signature_missing', true),
            ]);
            return false;
        }

        if (!$this->SystemUpgrade->verifySignature($zip_path, $release['signature'])) {
            unlink($zip_path);
            $this->SystemUpgrade->restoreMaintenanceMode();
            $this->SystemUpgrade->releaseLock();
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.signature_invalid', true),
            ]);
            return false;
        }

        // Extract to staging
        $temp_dir = $this->SystemUpgrade->getTempDir();
        $staging_dir = rtrim($temp_dir, '/') . '/blesta_upgrade_staging_' . time();

        if (!$this->SystemUpgrade->extractToStaging($zip_path, $staging_dir)) {
            unlink($zip_path);
            $this->SystemUpgrade->restoreMaintenanceMode();
            $this->SystemUpgrade->releaseLock();
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.extraction_failed', true),
            ]);
            return false;
        }

        // Prepare upgrader — copy to temp dir (outside install tree)
        $upgrader_dir = $this->SystemUpgrade->getTempDir();
        $upgrader_path = $this->SystemUpgrade->prepareUpgrader($staging_dir, $upgrader_dir);

        if ($upgrader_path === false) {
            exec('rm -rf ' . escapeshellarg($staging_dir));
            unlink($zip_path);
            $this->SystemUpgrade->restoreMaintenanceMode();
            $this->SystemUpgrade->releaseLock();
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.upgrader_failed', true),
            ]);
            return false;
        }

        // Write job file
        $install_dir = $this->SystemUpgrade->getInstallDir();
        $manifest_path = $staging_dir . '/blesta/manifest.json';
        $status_file = $this->SystemUpgrade->getStatusFilePath();

        // Paths rsync must not overwrite when overlaying the release onto
        // the install directory. The rsync step intentionally runs without
        // --delete (see upgrader.php step 1), so this list only needs to
        // protect files from being *replaced*, not from being deleted.
        $preserve_paths = [
            'config/blesta.php',
            '.htaccess',
            'cache/',
            'logs/',
        ];

        // Discover third-party components not in the release manifest
        $manifest_content = file_exists($manifest_path) ? json_decode(file_get_contents($manifest_path), true) : null;
        if ($manifest_content && isset($manifest_content['files'])) {
            // Build path lookup — supports both object and flat string formats
            $manifest_files = [];
            foreach ($manifest_content['files'] as $entry) {
                $path = is_array($entry) ? ($entry['path'] ?? '') : $entry;
                if ($path !== '') {
                    $manifest_files[$path] = true;
                }
            }

            // Directories that may contain third-party additions alongside core items.
            // Each entry maps a filesystem directory (relative to install_dir) to
            // the prefix used in the manifest for files within that directory.
            $extensible_dirs = [
                'plugins' => 'plugins/',
                'components/modules' => 'components/modules/',
                'components/gateways/merchant' => 'components/gateways/merchant/',
                'components/gateways/nonmerchant' => 'components/gateways/nonmerchant/',
                'components/messengers' => 'components/messengers/',
                'components/reports' => 'components/reports/',
                'components/invoice_templates' => 'components/invoice_templates/',
            ];

            foreach ($extensible_dirs as $rel_dir => $manifest_prefix) {
                $full_dir = $install_dir . '/' . $rel_dir;
                if (!is_dir($full_dir)) {
                    continue;
                }

                foreach (new \DirectoryIterator($full_dir) as $item) {
                    if ($item->isDot() || !$item->isDir()) {
                        continue;
                    }

                    $item_prefix = $manifest_prefix . $item->getFilename() . '/';
                    $is_core = false;
                    foreach ($manifest_files as $file => $v) {
                        if (strpos($file, $item_prefix) === 0) {
                            $is_core = true;
                            break;
                        }
                    }

                    if (!$is_core) {
                        $preserve_paths[] = $item_prefix;
                    }
                }
            }

            // Preserve custom (non-core) themes in app/views/
            $views_dirs = ['app/views/admin', 'app/views/client'];
            foreach ($views_dirs as $views_rel) {
                $views_full = $install_dir . '/' . $views_rel;
                if (!is_dir($views_full)) {
                    continue;
                }

                foreach (new \DirectoryIterator($views_full) as $item) {
                    if ($item->isDot() || !$item->isDir()) {
                        continue;
                    }

                    $theme_prefix = $views_rel . '/' . $item->getFilename() . '/';
                    $is_core = false;
                    foreach ($manifest_files as $file => $v) {
                        if (strpos($file, $theme_prefix) === 0) {
                            $is_core = true;
                            break;
                        }
                    }

                    if (!$is_core) {
                        $preserve_paths[] = $theme_prefix;
                    }
                }
            }
        }

        // Preserve uploads and backup directories if inside install tree
        Loader::loadComponents($this, ['SettingsCollection']);
        $real_install = realpath($install_dir);

        $uploads_setting = $this->SettingsCollection->fetchSystemSetting(null, 'uploads_dir');
        $uploads_dir = $uploads_setting['value'] ?? '';
        if (!empty($uploads_dir)) {
            $real_uploads = realpath($uploads_dir);
            if ($real_install && $real_uploads && strpos($real_uploads, $real_install) === 0) {
                $relative = substr($real_uploads, strlen($real_install) + 1);
                $preserve_paths[] = $relative . '/';
            }
        }

        $backup_dir = $this->SystemUpgrade->getBackupDirectory();
        $real_backup = realpath($backup_dir);
        if ($real_install && $real_backup && strpos($real_backup, $real_install) === 0) {
            $relative = substr($real_backup, strlen($real_install) + 1);
            $preserve_paths[] = $relative . '/';
        }

        // Compute the expected post-upgrade database_version using the STAGED
        // mappings file. The staged copy is used (not the in-tree one) so any
        // mapping entries added by the new release are visible. The upgrader
        // will compare this against the actual database_version after running
        // the migration command and flag mismatches as failures.
        $staged_mappings = $staging_dir . '/blesta/components/upgrades/tasks/mappings.php';
        if (!file_exists($staged_mappings)) {
            $staged_mappings = $staging_dir . '/components/upgrades/tasks/mappings.php';
        }
        $db_version_before = $this->SystemUpgrade->getCurrentDatabaseVersion();
        $db_version_expected = $this->SystemUpgrade->getExpectedDatabaseVersion(
            $db_version_before,
            $target_version,
            $staged_mappings
        );

        $job_params = [
            'staging_dir' => $staging_dir,
            'install_dir' => $install_dir,
            'manifest_path' => $manifest_path,
            'preserve_paths' => $preserve_paths,
            'clean_stale_files' => !empty($this->post['clean_stale_files']),
            'php_binary' => $this->SystemUpgrade->getCliPhpBinary(),
            'status_file' => $status_file,
            'lock_file' => $this->SystemUpgrade->getLockFilePath(),
            'launch_nonce' => $launch_nonce,
            'maintenance_was_enabled' => $this->Settings->getSetting('upgrade_maintenance_was_enabled')
                ? ($this->Settings->getSetting('upgrade_maintenance_was_enabled')->value === 'true')
                : false,
            // Forwarded into every status write so the failure UI can point
            // the user at the backups taken before the upgrade started.
            'backup_db_path' => $db_backup,
            'backup_files_path' => $file_backup,
            // Used by the upgrader to verify the database actually advanced
            // after the migration runs. Either may be null if mappings can't
            // be parsed; the upgrader skips the check in that case.
            'db_version_before' => $db_version_before,
            'db_version_expected' => $db_version_expected,
        ];

        $job_file = $this->SystemUpgrade->writeJobFile($job_params);

        if ($job_file === false) {
            exec('rm -rf ' . escapeshellarg($staging_dir));
            unlink($zip_path);
            $this->SystemUpgrade->restoreMaintenanceMode();
            $this->SystemUpgrade->releaseLock();
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.upgrader_failed', true),
            ]);
            return false;
        }

        // Launch upgrader
        if (!$this->SystemUpgrade->launchUpgrader($upgrader_path, $job_file)) {
            exec('rm -rf ' . escapeshellarg($staging_dir));
            unlink($zip_path);
            $this->SystemUpgrade->restoreMaintenanceMode();
            $this->SystemUpgrade->releaseLock();
            $this->outputAsJson([
                'success' => false,
                'error' => Language::_('SystemUpgrade.!error.upgrader_failed', true),
            ]);
            return false;
        }

        // Clean up the downloaded zip (staging still needed by upgrader)
        unlink($zip_path);

        // Confirm the background process actually detached and is running.
        // Some hosting environments (CageFS/CloudLinux, certain PHP-FPM configs)
        // kill children of the web request when the request finishes. The
        // staging dir + job file are kept so the admin can recover manually.
        if (!$this->SystemUpgrade->verifyUpgraderStarted($launch_nonce)) {
            // Flag the lock so the UI treats it as stale even when the
            // recorded web worker PID is still in the FPM pool, and persist
            // the paths needed to reconstruct the manual recovery command on
            // reload. The upgrader clears all three fields when it starts.
            $this->SystemUpgrade->markLockLaunchFailed($upgrader_path, $job_file);
            $this->outputAsJson([
                'success' => true,
                'launch_failed' => true,
                'error' => Language::_('SystemUpgrade.!error.launch_not_detected', true),
                'manual_command' => $this->SystemUpgrade->getManualLaunchCommand($upgrader_path, $job_file),
                'backup_db_path' => $db_backup,
                'backup_files_path' => $file_backup,
            ]);
            return false;
        }

        $this->outputAsJson([
            'success' => true,
            'message' => Language::_('AdminSystemUpgrade.upgrade.started', true),
            'backup_db_path' => $db_backup,
            'backup_files_path' => $file_backup,
        ]);

        return false;
    }

    /**
     * Poll upgrade progress (AJAX)
     */
    public function status()
    {
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri . 'settings/system/upgrade/');
        }

        $status = $this->SystemUpgrade->getUpgradeStatus();

        $this->outputAsJson([
            'success' => $status !== null,
            'status' => $status,
        ]);

        return false;
    }

    /**
     * Stream a backup file download
     */
    public function backups()
    {
        $filename = $this->get[0] ?? '';

        if (empty($filename)) {
            $this->redirect($this->base_uri . 'settings/system/upgrade/');
        }

        // Sanitize filename to prevent directory traversal
        $filename = basename($filename);
        $backup_dir = $this->SystemUpgrade->getBackupDirectory();
        $filepath = rtrim($backup_dir, '/') . '/' . $filename;

        // Validate the file exists and is within the backup directory
        $real_path = realpath($filepath);
        $real_backup_dir = realpath($backup_dir);

        if (
            $real_path === false
            || $real_backup_dir === false
            || strpos($real_path, $real_backup_dir) !== 0
            || !file_exists($real_path)
        ) {
            $this->redirect($this->base_uri . 'settings/system/upgrade/');
        }

        // Stream the file
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($real_path));
        header('Cache-Control: no-cache, no-store, must-revalidate');

        readfile($real_path);
        exit;
    }

    /**
     * Clear a stale upgrade lock (AJAX)
     */
    public function clearlock()
    {
        if (!$this->isAjax()) {
            $this->redirect($this->base_uri . 'settings/system/upgrade/');
        }

        $lock = $this->SystemUpgrade->getLockStatus();

        if ($lock !== null && !empty($lock['stale'])) {
            $this->SystemUpgrade->releaseLock();

            // Also clean up any stale status file
            $status_file = $this->SystemUpgrade->getStatusFilePath();
            if (file_exists($status_file)) {
                unlink($status_file);
            }
        }

        $this->outputAsJson(['success' => true]);

        return false;
    }

    /**
     * Delete a backup file (AJAX)
     */
    public function deletebackup()
    {
        if (!$this->isAjax() || empty($this->post)) {
            $this->redirect($this->base_uri . 'settings/system/upgrade/');
        }

        $filename = basename($this->post['filename'] ?? '');

        if (empty($filename)) {
            $this->outputAsJson(['success' => false]);
            return false;
        }

        $backup_dir = $this->SystemUpgrade->getBackupDirectory();
        $filepath = rtrim($backup_dir, '/') . '/' . $filename;

        // Validate the file exists and is within the backup directory
        $real_path = realpath($filepath);
        $real_backup_dir = realpath($backup_dir);

        if (
            $real_path === false
            || $real_backup_dir === false
            || strpos($real_path, $real_backup_dir) !== 0
            || !file_exists($real_path)
        ) {
            $this->outputAsJson(['success' => false]);
            return false;
        }

        // Only allow deleting recognized backup files
        if (
            strpos($filename, 'blesta_db_backup_') !== 0
            && strpos($filename, 'blesta_files_backup_') !== 0
        ) {
            $this->outputAsJson(['success' => false]);
            return false;
        }

        unlink($real_path);

        $this->outputAsJson(['success' => true]);

        return false;
    }
}
