<?php

return [
    'login' => [
        'username_required' => 'Username is required.',
        'password_required' => 'Password is required.',
        'username_not_found' => 'Username not found.',
        'password_incorrect' => 'Incorrect password.',
        'account_inactive' => 'This account is inactive.',
        'success' => 'Login successful.',
        'validation_failed' => 'Validation failed.',
        'generic_error' => 'An error occurred while logging in.',
    ],

    'permissions' => [
        'user_not_authenticated' => 'User is not authenticated.',
        'loaded' => 'User permissions loaded successfully.',
        'load_failed' => 'Failed to load user permissions.',
    ],

    'logout' => [
        'user_not_found' => 'User not found.',
        'success' => 'Logout successful.',
        'failed' => 'Failed to log out.',
    ],

    'forgot_password' => [
        'email_required' => 'Email is required.',
        'email_invalid' => 'Invalid email format.',
        'email_not_registered' => 'Email not registered.',
        'throttled' => 'Please wait a moment before resending the password reset link.',
        'send_failed' => 'Failed to send the password reset link. Please try again.',
        'sent' => 'A password reset link has been sent to your email.',
        'generic_error' => 'An error occurred while processing your request.',
    ],

    'verify_reset_token' => [
        'token_invalid' => 'Invalid password reset token.',
        'email_required' => 'Email is required.',
        'email_invalid' => 'Invalid email format.',
        'generic_error' => 'An error occurred while checking the password reset link.',
    ],

    'reset_password' => [
        'token_invalid' => 'Invalid password reset token.',
        'email_required' => 'Email is required.',
        'email_invalid' => 'Invalid email format.',
        'password_required' => 'New password is required.',
        'password_min' => 'New password must be at least 8 characters.',
        'password_confirmed' => 'New password confirmation does not match.',
        'password_regex' => 'New password must contain an uppercase letter, a lowercase letter, a number, and a symbol.',
        'link_invalid' => 'This password reset link is invalid or has expired.',
        'failed' => 'Failed to reset password. Please try again.',
        'success' => 'Password reset successfully. Please log in with your new password.',
        'generic_error' => 'An error occurred while resetting your password.',
    ],

    'sso' => [
        'token_invalid' => 'Invalid SSO token.',
        'payload_incomplete' => 'Incomplete SSO payload.',
        'token_expired' => 'SSO token has expired.',
        'token_reused' => 'This SSO token has already been used.',
        'branch_not_found' => 'Branch not found.',
        'duplicate_user_same_branch' => 'Duplicate user found for the same email and branch.',
        'user_not_registered' => 'User is not registered in SYOP v4.',
        'duplicate_user_same_email' => 'More than one SYOP v4 account shares this email. Please contact IT.',
        'key_config_invalid' => 'Invalid SSO key configuration.',
        'token_format_invalid' => 'Invalid SSO token format.',
        'auth_tag_invalid' => 'Invalid authentication tag.',
        'decrypt_failed' => 'Failed to decrypt SSO token.',
        'payload_invalid' => 'Invalid SSO payload.',
        'encoding_invalid' => 'Invalid SSO token encoding.',
    ],
];
