<?php
/**
 * Language definitions for the Admin System Upgrade controller/views
 *
 * @package blesta
 * @subpackage language.en_us
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Page title
$lang['AdminSystemUpgrade.index.page_title'] = 'Settings > System > Upgrade Options';

// Current status section
$lang['AdminSystemUpgrade.index.boxtitle_upgrade'] = 'Upgrade Options';
$lang['AdminSystemUpgrade.index.heading_current'] = 'Current Version';
$lang['AdminSystemUpgrade.index.current_version'] = 'You are running Blesta %1$s'; // %1$s is the version
$lang['AdminSystemUpgrade.index.last_checked'] = 'Last checked: %1$s'; // %1$s is the timestamp
$lang['AdminSystemUpgrade.index.never_checked'] = 'Never checked';
$lang['AdminSystemUpgrade.index.btn_check'] = 'Check for Updates';
$lang['AdminSystemUpgrade.index.up_to_date'] = 'Your installation is up to date.';

// Environment section
$lang['AdminSystemUpgrade.index.heading_environment'] = 'Environment Status';
$lang['AdminSystemUpgrade.index.environment_pass'] = 'All checks passed. Your system is ready for self-upgrade.';
$lang['AdminSystemUpgrade.index.environment_fail'] = 'Some checks failed. Please resolve the issues below before upgrading.';
$lang['AdminSystemUpgrade.index.environment_badge_fail'] = '%1$d failed'; // %1$d is the count
$lang['AdminSystemUpgrade.index.environment_badge_warn'] = '%1$d warning'; // %1$d is the count

// Manual launch recovery
$lang['AdminSystemUpgrade.index.launch_failed_title'] = 'Background upgrade did not start';
$lang['AdminSystemUpgrade.index.launch_failed_instruction'] = 'Run the following command via SSH (as the user that owns the Blesta installation) to complete the upgrade. Leave this page open — progress will continue updating here while the command runs.';
$lang['AdminSystemUpgrade.index.btn_copy_command'] = 'Copy';
$lang['AdminSystemUpgrade.index.command_copied'] = 'Copied';

// Available updates section
$lang['AdminSystemUpgrade.index.heading_available'] = 'Available Updates';
$lang['AdminSystemUpgrade.index.upgrade_patch'] = 'Patch Update: %1$s → %2$s'; // %1$s current, %2$s target
$lang['AdminSystemUpgrade.index.upgrade_latest'] = 'Full Upgrade: %1$s → %2$s'; // %1$s current, %2$s target
$lang['AdminSystemUpgrade.index.release_date'] = 'Released: %1$s'; // %1$s is the date
$lang['AdminSystemUpgrade.index.changelog_link'] = 'View Changelog';
$lang['AdminSystemUpgrade.index.requires_support'] = 'Requires active support & updates subscription.';
$lang['AdminSystemUpgrade.index.no_support'] = 'Your support & updates subscription is not active. Only patch updates are available.';
$lang['AdminSystemUpgrade.index.skip_integrity_check'] = 'Skip file integrity check';
$lang['AdminSystemUpgrade.index.clean_stale_files'] = 'Remove stale core files after upgrade';
$lang['AdminSystemUpgrade.index.clean_stale_files_note'] = 'Deletes files in core directories that are not present in the new release manifest. Leave unchecked unless you are certain no custom files exist in core directories.';
$lang['AdminSystemUpgrade.index.btn_upgrade'] = 'Upgrade Now';
$lang['AdminSystemUpgrade.index.upgrade_warning'] = 'This will enable maintenance mode, create backups, download and install the new version, and run database migrations. This process cannot be interrupted once started.';
$lang['AdminSystemUpgrade.index.select_version'] = 'Select a version to upgrade to:';

// Progress section
$lang['AdminSystemUpgrade.index.heading_progress'] = 'Upgrade Progress';
$lang['AdminSystemUpgrade.index.step_preflight'] = 'Pre-flight checks';
$lang['AdminSystemUpgrade.index.step_maintenance'] = 'Enable maintenance mode';
$lang['AdminSystemUpgrade.index.step_backup_db'] = 'Database backup';
$lang['AdminSystemUpgrade.index.step_backup_files'] = 'File backup';
$lang['AdminSystemUpgrade.index.step_download'] = 'Download release';
$lang['AdminSystemUpgrade.index.step_verify'] = 'Verify integrity';
$lang['AdminSystemUpgrade.index.step_extract'] = 'Extract files';
$lang['AdminSystemUpgrade.index.step_replace'] = 'Replace files';
$lang['AdminSystemUpgrade.index.step_migrate'] = 'Run database migrations';
$lang['AdminSystemUpgrade.index.step_finalize'] = 'Finalize';
$lang['AdminSystemUpgrade.index.upgrade_complete'] = 'Upgrade completed successfully!';
$lang['AdminSystemUpgrade.index.upgrade_failed'] = 'Upgrade failed.';
$lang['AdminSystemUpgrade.index.btn_dashboard'] = 'Return to Dashboard';
$lang['AdminSystemUpgrade.index.btn_retry'] = 'Retry';

// Lock messages
$lang['AdminSystemUpgrade.index.lock_active'] = 'An upgrade is currently in progress, started at %1$s.'; // %1$s is the timestamp
$lang['AdminSystemUpgrade.index.lock_stale'] = 'A previous upgrade process appears to have stopped unexpectedly.';
$lang['AdminSystemUpgrade.index.btn_clear_lock'] = 'Clear Lock';

// Backup section
$lang['AdminSystemUpgrade.index.heading_backups'] = 'Upgrade Backups';
$lang['AdminSystemUpgrade.index.no_backups'] = 'No upgrade backups found.';
$lang['AdminSystemUpgrade.index.backup_col_file'] = 'File';
$lang['AdminSystemUpgrade.index.backup_col_type'] = 'Type';
$lang['AdminSystemUpgrade.index.backup_col_size'] = 'Size';
$lang['AdminSystemUpgrade.index.backup_col_date'] = 'Date';
$lang['AdminSystemUpgrade.index.backup_database'] = 'Database';
$lang['AdminSystemUpgrade.index.backup_files'] = 'Files';
$lang['AdminSystemUpgrade.index.btn_download'] = 'Download';
$lang['AdminSystemUpgrade.index.btn_delete'] = 'Delete';
$lang['AdminSystemUpgrade.index.confirm_delete_backup'] = 'Are you sure you want to delete this backup? This cannot be undone.';

// Recovery
$lang['AdminSystemUpgrade.index.heading_recovery'] = 'Recovery Instructions';
$lang['AdminSystemUpgrade.index.recovery_db_path'] = 'Database backup: %1$s'; // %1$s is the path
$lang['AdminSystemUpgrade.index.recovery_files_path'] = 'File backup: %1$s'; // %1$s is the path
$lang['AdminSystemUpgrade.index.recovery_instructions'] = 'To restore from backup, run the following commands on your server:';

// Failure guidance shown after a failed upgrade run
$lang['AdminSystemUpgrade.index.failure_heading'] = 'What to do next';
$lang['AdminSystemUpgrade.index.failure_explanation'] = 'The upgrade did not complete successfully. Your system may be in an inconsistent state and some database changes from the failed version may already be applied. Re-running the upgrade will likely fail differently. Restoring from the database backup before retrying is recommended. Backups were taken before the upgrade started and are listed below. To recover, either restore these backups manually or open a support ticket for assistance.';
$lang['AdminSystemUpgrade.index.failure_backup_label'] = 'Available backups:';
$lang['AdminSystemUpgrade.index.failure_db_version_label'] = 'Database version:';
$lang['AdminSystemUpgrade.index.failure_db_version_before'] = 'Before upgrade: %1$s'; // %1$s is the version
$lang['AdminSystemUpgrade.index.failure_db_version_expected'] = 'Expected after upgrade: %1$s'; // %1$s is the version
$lang['AdminSystemUpgrade.index.failure_db_version_after'] = 'Current: %1$s'; // %1$s is the version
$lang['AdminSystemUpgrade.index.failure_db_version_mismatch'] = 'The database version did not advance to the expected value. The upgrade may have stopped partway through a version, leaving some schema changes applied.';

// Upgrade action
$lang['AdminSystemUpgrade.upgrade.started'] = 'Upgrade process started. You may close this page — the upgrade will continue in the background. Return to this page to check progress.';
$lang['AdminSystemUpgrade.upgrade.no_manifest'] = 'No file manifest was found for your current installation. File integrity verification will be skipped. Do you want to continue with the upgrade?';
$lang['AdminSystemUpgrade.upgrade.no_checksums'] = 'The file manifest for your current installation does not include checksums. File integrity verification will be skipped. Do you want to continue with the upgrade?';
$lang['AdminSystemUpgrade.upgrade.modified_files'] = '%1$s core file(s) have been modified from the original release. These changes will be overwritten during the upgrade. Do you want to continue?'; // %1$s is the count
$lang['AdminSystemUpgrade.upgrade.modified_files_title'] = 'Modified Core Files';
$lang['AdminSystemUpgrade.upgrade.label_modified'] = 'Modified Files';
$lang['AdminSystemUpgrade.upgrade.label_missing'] = 'Missing Files';
$lang['AdminSystemUpgrade.upgrade.btn_continue'] = 'Continue with Upgrade';
$lang['AdminSystemUpgrade.upgrade.btn_abort'] = 'Cancel';

// No results
$lang['AdminSystemUpgrade.index.no_results'] = 'Upgrade options are not available at this time.';

// Submit button (legacy, kept for compatibility)
$lang['AdminSystemUpgrade.index.field_upgradesubmit'] = 'Update Settings';
