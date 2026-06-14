<?php
// KuickpayVouchers model validation errors (Story 2.1)
$lang['KuickpayVouchers.!error.company_id.empty'] = 'Company ID is required.';
$lang['KuickpayVouchers.!error.company_id.scope'] = 'Company ID is required to update a voucher.';
$lang['KuickpayVouchers.!error.client_id.empty'] = 'Client ID is required.';
$lang['KuickpayVouchers.!error.gateway_id.empty'] = 'Gateway ID is required.';
$lang['KuickpayVouchers.!error.currency.empty'] = 'Currency is required.';
$lang['KuickpayVouchers.!error.currency.length'] = 'Currency must be 3 characters.';
$lang['KuickpayVouchers.!error.amount.empty'] = 'Amount is required.';
$lang['KuickpayVouchers.!error.amount.format'] = 'Amount must be a valid decimal value.';
$lang['KuickpayVouchers.!error.status.valid'] = 'Invalid voucher status.';
$lang['KuickpayVouchers.!error.registration_number.empty'] = 'Registration number is required.';
$lang['KuickpayVouchers.!error.consumer_number.empty'] = 'Consumer number is required.';
// Active-context concurrency guard (Story 5.2)
$lang['KuickpayVouchers.!error.context_key.empty'] = 'Context key is required.';
// Durable posting-attempt counter (Story 5.3)
$lang['KuickpayVouchers.!error.posting_attempts.format'] = 'Posting attempts must be a non-negative integer.';
