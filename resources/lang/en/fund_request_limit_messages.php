<?php

return [

    /*
    | The "area" axis (HO/Branch) was REMOVED on 26 Nov 2026. A limit now
    | names branches one by one, and may name several at once.
    */
    'scope' => [
        'all_branches' => 'All branches',
        'all_departments' => 'All departments',
        'fallback' => 'Applies generally',
    ],

    'not_found' => 'Submission limit not found.',

    'duplicate_scope' => 'Another limit already covers exactly the same scope. Change its branches or departments, or edit the existing limit instead.',

    'index' => [
        'forbidden' => 'You do not have access to view submission limits.',
    ],

    'create' => [
        'forbidden' => 'You do not have access to add submission limits.',
        'success' => 'The submission limit was added.',
        'failed' => 'Failed to add the submission limit.',
    ],

    'update' => [
        'forbidden' => 'You do not have access to change submission limits.',
        'success' => 'The submission limit was updated.',
        'failed' => 'Failed to update the submission limit.',
    ],

    'destroy' => [
        'forbidden' => 'You do not have access to delete submission limits.',
        'success' => 'The submission limit was deleted.',
        'failed' => 'Failed to delete the submission limit.',
        'last_fallback' => 'The general limit cannot be deleted while no replacement exists. Without it, requesters matching no specific limit would not be limited at all.',
    ],

];
