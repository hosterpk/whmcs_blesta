<?php
/**
 * Language definitions for the Admin Login controller/views
 *
 * @package blesta
 * @subpackage language.enus
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

// Index
$lang['AdminLogin.index.page_title'] = 'Log in';
$lang['AdminLogin.index.page_subtitle'] = 'Welcome back! If you don\'t have a login, contact your administrator.';
$lang['AdminLogin.index.title_adminarea'] = 'Staff Area';
$lang['AdminLogin.index.subtitle_adminarea'] = 'Enter your credentials to access the admin panel.';
$lang['AdminLogin.index.field_username'] = 'Username';
$lang['AdminLogin.index.field_password'] = 'Password';
$lang['AdminLogin.index.field_rememberme'] = 'Remember me on this computer.';
$lang['AdminLogin.index.field_loginsubmit'] = 'Log In';
$lang['AdminLogin.index.link_resetpassword'] = 'Reset My Password';


// OTP
$lang['AdminLogin.otp.page_title'] = 'Two-Factor Authentication';
$lang['AdminLogin.otp.page_subtitle'] = 'Enter your one-time password to complete the login process.';
$lang['AdminLogin.otp.title_adminarea'] = 'Two-Factor Authentication';
$lang['AdminLogin.otp.subtitle_adminarea'] = 'Enter your one-time password to verify your identity.';
$lang['AdminLogin.otp.field_password'] = 'One-time Password';
$lang['AdminLogin.otp.field_loginsubmit'] = 'Log In';
$lang['AdminLogin.otp.link_login'] = 'Cancel, Log In';


// Step Up
$lang['AdminLogin.up.page_title'] = 'Access Verification';
$lang['AdminLogin.up.page_subtitle'] = 'Welcome back! If you don\'t have a login, contact your administrator.';
$lang['AdminLogin.up.title_adminarea'] = 'Access Verification';
$lang['AdminLogin.up.subtitle_adminarea'] = 'Verify your access to continue.';
$lang['AdminLogin.up.field_password'] = 'Password';
$lang['AdminLogin.up.field_password_otp'] = 'One-time Password';
$lang['AdminLogin.up.field_loginsubmit'] = 'Verify Access';
$lang['AdminLogin.up.link_cancel'] = 'Cancel';


// Reset
$lang['AdminLogin.reset.page_title'] = 'Reset Password';
$lang['AdminLogin.reset.page_subtitle'] = 'Forgotten your password? Enter your username to begin the reset process.';
$lang['AdminLogin.reset.title_adminarea'] = 'Reset Password';
$lang['AdminLogin.reset.subtitle_adminarea'] = 'Enter your username to begin the reset process.';
$lang['AdminLogin.reset.field_username'] = 'Username';
$lang['AdminLogin.reset.field_resetsubmit'] = 'Reset Password';
$lang['AdminLogin.reset.link_login'] = 'Cancel, Log In';


// Confirm Reset
$lang['AdminLogin.confirmreset.page_title'] = 'Confirm Password Reset';
$lang['AdminLogin.confirmreset.page_subtitle'] = 'Create a new password for your account.';
$lang['AdminLogin.confirmreset.title_adminarea'] = 'Confirm Password Reset';
$lang['AdminLogin.confirmreset.subtitle_adminarea'] = 'Enter your new password below.';
$lang['AdminLogin.confirmreset.field_new_password'] = 'New Password';
$lang['AdminLogin.confirmreset.field_confirm_password'] = 'Confirm New Password';
$lang['AdminLogin.confirmreset.field_resetsubmit'] = 'Set Password';
$lang['AdminLogin.confirmreset.link_login'] = 'Cancel, Log In';


// Setup
$lang['AdminLogin.setup.page_title'] = 'Initial Setup';
$lang['AdminLogin.setup.page_subtitle'] = 'Configure your Blesta installation and create your administrator account.';
$lang['AdminLogin.setup.title_adminarea'] = 'Get Started with Blesta';
$lang['AdminLogin.setup.subtitle_adminarea'] = 'Complete the initial setup to begin managing your billing system. This will only take a few moments.';
$lang['AdminLogin.setup.field_license_key'] = 'License Key';
$lang['AdminLogin.setup.trial_newsletter'] = 'By signing up for a trial, you agree to receive emails from us during your trial. You can opt-out at any time.';
$lang['AdminLogin.setup.field_newsletter'] = 'Sign-up for our newsletter. You can opt-out at any time.';
$lang['AdminLogin.setup.heading_create_account'] = 'Create your Staff account';
$lang['AdminLogin.setup.field_first_name'] = 'First Name';
$lang['AdminLogin.setup.field_last_name'] = 'Last Name';
$lang['AdminLogin.setup.field_username'] = 'Username';
$lang['AdminLogin.setup.field_email'] = 'Email Address';
$lang['AdminLogin.setup.field_password'] = 'Password';
$lang['AdminLogin.setup.field_confirm_password'] = 'Confirm Password';
$lang['AdminLogin.setup.field_enter_key_true'] = 'I have a license key to enter';
$lang['AdminLogin.setup.field_enter_key_false'] = 'I want to start a 30-day free trial';
$lang['AdminLogin.setup.field_submit'] = 'Finish';


// Error
$lang['AdminLogin.!error.unknown_user'] = 'That username is not recognized or the password is not capable of being reset.';
$lang['AdminLogin.!error.captcha.invalid'] = 'The captcha entered was invalid. Please try again.';
$lang['AdminLogin.!error.step_up_expired'] = 'Step up session has expired.';


// Success
$lang['AdminLogin.!success.reset_sent'] = 'A confirmation email has been sent to the address on record.';
$lang['AdminLogin.!success.step_up_extended'] = 'Step up session has been extended.';


// Info
$lang['AdminLogin.!info.reset_password'] = 'Enter your username below and a time-sensitive link will be emailed to you so you can set a new password.';
$lang['AdminLogin.!info.otp'] = 'Two-Factor Authentication is required for this user. Please enter your OTP (One-time Password) below.';
$lang['AdminLogin.!info.step_up'] = 'In order to continue, it is necessary to verify your access again by entering your password below.';
$lang['AdminLogin.!info.step_up_otp'] = 'In order to continue, it is necessary to verify your access again by entering your OTP (One-time Password) below.';
