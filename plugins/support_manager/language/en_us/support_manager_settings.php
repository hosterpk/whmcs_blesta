<?php
// Errors
$lang['SupportManagerSettings.!error.key.empty'] = 'Please enter a setting key.';
$lang['SupportManagerSettings.!error.key.length'] = 'The setting key may not exceed 32 characters in length.';
$lang['SupportManagerSettings.!error.company_id.exists'] = 'Invalid company ID.';

// AI Settings Validation Errors
$lang['SupportManagerSettings.!error.ai_temperature.numeric'] = 'Temperature must be a numeric value.';
$lang['SupportManagerSettings.!error.ai_temperature.range'] = 'Temperature must be between 0.0 and 2.0.';
$lang['SupportManagerSettings.!error.ai_max_tokens.integer'] = 'Max tokens must be an integer value.';
$lang['SupportManagerSettings.!error.ai_max_tokens.range'] = 'Max tokens must be between 100 and 128,000.';
$lang['SupportManagerSettings.!error.ai_model.valid'] = 'Invalid AI model selected. Please choose from the available options.';
$lang['SupportManagerSettings.!error.ai_confidence_threshold.integer'] = 'Confidence threshold must be an integer value.';
$lang['SupportManagerSettings.!error.ai_confidence_threshold.range'] = 'Confidence threshold must be between 60 and 100.';
$lang['SupportManagerSettings.!error.ai_max_queue_age_hours.integer'] = 'Max queue age must be an integer value.';
$lang['SupportManagerSettings.!error.ai_max_queue_age_hours.range'] = 'Max queue age must be between 1 and 8760 hours.';
$lang['SupportManagerSettings.!error.ai_system_prompt.length'] = 'System prompt is too long (maximum 10,000 characters).';
$lang['SupportManagerSettings.!error.ai_departments.valid'] = 'Invalid department selection. Departments must exist and belong to your company.';
