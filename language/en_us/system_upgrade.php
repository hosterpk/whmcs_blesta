<?php
/**
 * Language definitions for the SystemUpgrade model
 *
 * @package blesta
 * @subpackage language.en_us
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Environment check messages
$lang['SystemUpgrade.environment.os_pass'] = 'Operating system: Linux detected.';
$lang['SystemUpgrade.environment.os_fail'] = 'Self-upgrade is only supported on Linux environments.';
$lang['SystemUpgrade.environment.exec_pass'] = 'Shell access: exec() function is available.';
$lang['SystemUpgrade.environment.exec_fail'] = 'Shell access: exec() function is disabled or not available.';
$lang['SystemUpgrade.environment.unzip_pass'] = 'Archive extraction: unzip command is available.';
$lang['SystemUpgrade.environment.unzip_fail'] = 'Archive extraction: unzip command is not available. Please install unzip.';
$lang['SystemUpgrade.environment.mysqldump_pass'] = 'Database backup: mysqldump command is available.';
$lang['SystemUpgrade.environment.mysqldump_fail'] = 'Database backup: mysqldump command is not available. Please install mysql-client tools.';
$lang['SystemUpgrade.environment.tar_pass'] = 'File backup: tar command is available.';
$lang['SystemUpgrade.environment.tar_fail'] = 'File backup: tar command is not available. Please install tar.';
$lang['SystemUpgrade.environment.rsync_pass'] = 'File synchronization: rsync command is available.';
$lang['SystemUpgrade.environment.rsync_fail'] = 'File synchronization: rsync command is not available. Please install rsync.';
$lang['SystemUpgrade.environment.setsid_pass'] = 'Process detachment: setsid command is available.';
$lang['SystemUpgrade.environment.setsid_warn'] = 'Process detachment: setsid command is not available. The upgrade may require a manual command via SSH to complete on jailed hosting environments.';
$lang['SystemUpgrade.environment.writable_pass'] = 'Filesystem: Installation directory is writable.';
$lang['SystemUpgrade.environment.writable_fail'] = 'Filesystem: Installation directory is not writable by the web server.';
$lang['SystemUpgrade.environment.ownership_pass'] = 'File ownership: All key directories are owned by the web server user.';
$lang['SystemUpgrade.environment.ownership_fail'] = 'File ownership: %1$s key directories are not owned by the web server user.'; // %1$s is the count
$lang['SystemUpgrade.environment.disk_pass'] = 'Disk space: %1$s available.'; // %1$s is the free space
$lang['SystemUpgrade.environment.disk_warn'] = 'Disk space: Only %1$s available, estimated %2$s needed.'; // %1$s is free, %2$s is needed
$lang['SystemUpgrade.environment.config_pass'] = 'Configuration: blesta.php is writable.';
$lang['SystemUpgrade.environment.config_fail'] = 'Configuration: blesta.php is not writable.';

// Maintenance mode
$lang['SystemUpgrade.maintenance_reason'] = 'The system is currently being upgraded. Please check back shortly.';

// Errors
$lang['SystemUpgrade.!error.upgrade_locked'] = 'An upgrade is already in progress (started by staff ID %1$s at %2$s).'; // %1$s is staff_id, %2$s is timestamp
$lang['SystemUpgrade.!error.lock_stale'] = 'A previous upgrade process appears to have stopped unexpectedly. You may clear the lock to try again.';
$lang['SystemUpgrade.!error.backup_db_failed'] = 'Database backup failed. The upgrade cannot proceed without a reliable backup.';
$lang['SystemUpgrade.!error.backup_files_failed'] = 'File backup failed. The upgrade cannot proceed without a reliable backup.';
$lang['SystemUpgrade.!error.download_failed'] = 'Failed to download the release file.';
$lang['SystemUpgrade.!error.hash_mismatch'] = 'Downloaded file integrity check failed. The file may be corrupted.';
$lang['SystemUpgrade.!error.signature_missing'] = 'The release does not include a cryptographic signature. Cannot verify authenticity.';
$lang['SystemUpgrade.!error.signature_invalid'] = 'Release signature verification failed. The file may have been tampered with.';
$lang['SystemUpgrade.!error.extraction_failed'] = 'Failed to extract the release archive.';
$lang['SystemUpgrade.!error.upgrader_failed'] = 'Failed to prepare or launch the upgrade process.';
$lang['SystemUpgrade.!error.launch_not_detected'] = 'The background upgrade process did not start. This can happen on jailed hosting environments (CloudLinux/CageFS, some PHP-FPM configurations) where the web server is not permitted to detach long-running child processes. The upgrade can be completed by running the command below via SSH.';
$lang['SystemUpgrade.!error.upgrader_crashed'] = 'The upgrade process appears to have stopped unexpectedly. Check the backup paths below for recovery.';
$lang['SystemUpgrade.!error.license_invalid'] = 'Your support and updates subscription must be active for major or minor version upgrades.';
$lang['SystemUpgrade.!error.php_version'] = 'The target release requires PHP %1$s or newer. You are running PHP %2$s.'; // %1$s min version, %2$s current
$lang['SystemUpgrade.!error.environment_fail'] = 'One or more environment checks failed. Please resolve the issues before upgrading.';
