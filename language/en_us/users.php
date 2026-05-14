<?php
/**
 * Language definitions for the Users model
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

$lang['Users.!error.username.empty'] = 'Please enter a username.';
$lang['Users.!error.username.unique'] = 'That username has already been taken.';
$lang['Users.!error.current_password.matches'] = 'Invalid password.';
$lang['Users.!error.new_password.format'] = 'Please enter a password at least %1$s characters in length.'; // %1$s is the minimum length (legacy fallback)
$lang['Users.!error.new_password.format_any'] = 'Password must be at least %1$s characters.'; // %1$s is the minimum length
$lang['Users.!error.new_password.format_any_no_space'] = 'Password must be at least %1$s characters and cannot contain spaces.'; // %1$s is the minimum length
$lang['Users.!error.new_password.format_alpha_num'] = 'Password must be at least %1$s characters and contain only letters and numbers.'; // %1$s is the minimum length
$lang['Users.!error.new_password.format_alpha'] = 'Password must be at least %1$s characters and contain only letters.'; // %1$s is the minimum length
$lang['Users.!error.new_password.format_num'] = 'Password must be at least %1$s characters and contain only numbers.'; // %1$s is the minimum length
$lang['Users.!error.new_password.format_custom'] = 'Password does not meet requirements: %2$s'; // %1$s is the minimum length, %2$s is the human-readable description
$lang['Users.!error.new_password.matches'] = 'The passwords do not match.';

// Password requirement language definitions (used for parsing custom regex rules)
$lang['Users.!error.password_requirement.lowercase'] = 'lowercase letter';
$lang['Users.!error.password_requirement.uppercase'] = 'uppercase letter';
$lang['Users.!error.password_requirement.digit'] = 'digit';
$lang['Users.!error.password_requirement.special_char'] = 'special character';
$lang['Users.!error.password_requirement.length_between'] = 'between %1$s and %2$s characters'; // %1$s is min length, %2$s is max length
$lang['Users.!error.password_requirement.length_exact'] = 'exactly %1$s characters'; // %1$s is the exact length
$lang['Users.!error.password_requirement.length_min'] = 'at least %1$s characters'; // %1$s is the minimum length
$lang['Users.!error.password_requirement.must_contain'] = 'must contain %1$s'; // %1$s is the list of requirements
$lang['Users.!error.password_requirement.pattern_fallback'] = 'must match pattern: %1$s'; // %1$s is the regex pattern
$lang['Users.!error.recovery_email.format'] = 'Invalid recovery email address.';
$lang['Users.!error.two_factor_mode.format'] = 'Invalid two factor mode.';
$lang['Users.!error.two_factor_key.format'] = 'Invalid two factor key.';
$lang['Users.!error.username.auth'] = 'No matches found for that user/password combination.';
$lang['Users.!error.otp.auth'] = 'The one-time password entered is invalid. Maximum length is 16 characters';
$lang['Users.!error.user_id.exists'] = 'Invalid user ID.';
$lang['Users.!error.username.attempts'] = 'Too many failed login attempts detected.';
$lang['Users.!error.username.company'] = 'You are not authorized to login at this location.';

$lang['Users.!error.clients.exist'] = 'The user cannot be deleted because there is at least one client assigned to the user.';
