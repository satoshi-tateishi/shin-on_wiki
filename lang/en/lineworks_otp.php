<?php

return [
    'title' => 'Two-Factor Authentication',
    'description' => 'Please enter the 6-digit verification code sent to your LINE WORKS.',
    'code_label' => 'Verification Code',
    'code_placeholder' => '000000',
    'expires_in' => 'Valid for: 10 minutes',
    'verify_button' => 'Verify',
    'resend_button' => 'Resend verification code',

    'error_code_required' => 'Please enter the verification code.',
    'error_code_size' => 'The verification code must be 6 digits.',
    'error_code_regex' => 'The verification code must contain only numbers.',
    'error_invalid_session' => 'Your session has expired. Please log in again.',
    'error_user_not_found' => 'User not found.',
    'error_account_locked' => 'Your account is locked. Please try again in :minutes minutes.',
    'error_code_expired' => 'The verification code has expired. Please request a new one.',
    'error_code_invalid' => 'The verification code is incorrect. You have :attempts attempts remaining.',
    'error_max_attempts' => 'Too many failed attempts. Your account has been locked for 3 minutes.',
    'error_send_failed' => 'Failed to send verification code. Please try again later.',

    'success_resent' => 'Verification code has been resent.',
    'success_verified' => 'Successfully logged in.',
];
