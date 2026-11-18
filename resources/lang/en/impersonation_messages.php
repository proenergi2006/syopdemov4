<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Rejections
    |--------------------------------------------------------------------------
    */
    'forbidden' => 'You do not have access to sign in as another user.',

    'nested' => 'You are currently signed in as another user. Return to your own account before signing in as a different user.',

    'target_not_found' => 'The selected account was not found.',

    'target_is_self' => 'You are already signed in as that account.',

    'target_is_privileged' => 'This account also has access to sign in as other users, so it cannot be entered. Please choose another account.',

    'not_impersonating' => 'This is not an impersonation session, so there is no account to return to. Please sign in again.',

    'admin_no_longer_allowed' => 'Your administrator access is no longer valid, so the session cannot be restored. The impersonation has been ended. Please sign in again.',

    'action_blocked' => 'This action cannot be performed while signed in as another user, because it changes that account\'s permanent credentials. Return to your own account first.',

    'session_not_found' => 'The requested impersonation record was not found.',

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
    'reason_required' => 'A reason is required. The reason is what makes the impersonation record readable later on.',

    'reason_too_short' => 'The reason is too short. Write at least five characters explaining the purpose.',

    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */
    'started' => 'You are now signed in as :name.',

    'stopped' => 'You are back on your own account.',

    'candidates_loaded' => 'Account list loaded successfully.',

    'history_loaded' => 'Impersonation history loaded successfully.',

];
