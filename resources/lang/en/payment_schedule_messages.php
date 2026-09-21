<?php

return [

    'days' => [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ],

    'document_types' => [
        'FPU' => 'Cash Advance',
        'REALISASI' => 'Realization',
        'CLAIM' => 'Claim',
    ],

    'cutoff_sentence' => 'Received by :cutoff_day :cutoff_time is paid on :payment_day :week',

    'week' => [
        'same' => 'the same week',
        'next' => 'the following week',
        'after' => ':count weeks later',
    ],

    'scope' => [
        'all_modules' => 'All modules',
        'all_categories' => 'All transaction categories',
        'fallback' => 'Applies generally',
    ],
    'and' => 'and',

    'not_payment_day' => 'Payments can only be made on :days. Today is not a payment day, so this document cannot be processed yet.',

    'not_found' => 'Payment schedule not found.',

    'duplicate_scope' => 'Another schedule already covers exactly the same scope. Change the module or transaction category, or edit the existing schedule instead.',

    'index' => [
        'forbidden' => 'You do not have access to view payment schedules.',
    ],

    'create' => [
        'forbidden' => 'You do not have access to add payment schedules.',
        'success' => 'The payment schedule was added.',
        'failed' => 'Failed to add the payment schedule.',
    ],

    'update' => [
        'forbidden' => 'You do not have access to change payment schedules.',
        'success' => 'The payment schedule was updated.',
        'failed' => 'Failed to update the payment schedule.',
    ],

    'destroy' => [
        'forbidden' => 'You do not have access to delete payment schedules.',
        'success' => 'The payment schedule was deleted.',
        'failed' => 'Failed to delete the payment schedule.',
        'last_fallback' => 'The general schedule cannot be deleted while no replacement exists. Without it, documents that match no specific schedule would have no payment date at all.',
    ],

];
