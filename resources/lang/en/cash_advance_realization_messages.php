<?php

/*
|--------------------------------------------------------------------------
| Cash Advance Realization API messages
|--------------------------------------------------------------------------
*/

return [
    'user_not_authenticated' => 'Your session has expired. Please sign in again.',

    'index' => [
        'loaded' => 'Realization data loaded successfully.',
        'load_failed' => 'Failed to load realization data.',
    ],

    'show' => [
        'loaded' => 'Realization detail loaded successfully.',
        'not_found' => 'Realization not found or you do not have access to it.',
        'failed' => 'Failed to load the realization detail.',
    ],

    'realizable' => [
        'loaded' => 'Cash advances ready for realization loaded successfully.',
        'failed' => 'Failed to load cash advances ready for realization.',
    ],

    'source' => [
        'not_found' => 'The selected cash advance was not found.',
        'not_disbursed' => 'A realization can only be created from a disbursed cash advance.',
        'already_realized' => 'This cash advance already has a realization document.',
    ],

    'store' => [
        'forbidden' => 'You do not have permission to create a realization.',
        'success' => 'Realization saved as draft.',
        'invalid' => 'Realization data is not valid.',
        'failed' => 'Failed to save the realization.',
    ],

    'update' => [
        'forbidden' => 'You do not have permission to update a realization.',
        'only_draft' => 'Only draft realizations can be updated.',
        'success' => 'Realization updated successfully.',
        'failed' => 'Failed to update the realization.',
    ],

    'destroy' => [
        'forbidden' => 'You do not have permission to delete a realization.',
        'only_draft' => 'Only draft realizations can be deleted.',
        'success' => 'Realization deleted successfully.',
        'failed' => 'Failed to delete the realization.',
    ],

    'submit' => [
        'forbidden' => 'You do not have permission to submit a realization.',
        'only_draft' => 'Only draft realizations can be submitted.',
        'items_unavailable' => 'The realization has no expense lines yet.',
        'signature_missing' => 'You do not have a digital signature yet.',
        'success' => 'Realization submitted successfully.',
        'failed' => 'Failed to submit the realization.',
    ],

    'approve' => [
        'not_in_progress' => 'The realization is not currently in the approval process.',
        'final_success' => 'Realization has been fully approved.',
        'step_success' => 'Approval step completed successfully.',
        'waiting_others' => 'Your approval was saved and is still waiting for other approvers on the same step.',
        'failed' => 'The approval could not be processed.',
    ],

    'reject' => [
        'not_in_progress' => 'The realization is not currently in the approval process.',
        'success' => 'Realization rejected successfully.',
        'failed' => 'The rejection could not be processed.',
    ],

    'cancel' => [
        'forbidden' => 'You do not have permission to cancel a realization.',
        'only_approved' => 'Only approved realizations can be cancelled.',
        'success' => 'Realization cancelled successfully.',
        'failed' => 'Failed to cancel the realization.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Difference settlement
    |--------------------------------------------------------------------------
    | return    : the requester returns the remaining funds, proof is required.
    | reimburse : Finance pays the shortfall, proof is optional.
    |--------------------------------------------------------------------------
    */
    'receive' => [
        'forbidden' => 'You do not have access to receive realizations.',
        'only_approved' => 'Only approved realizations can be marked as received.',
        'success' => 'The realization was marked as received.',
        'failed' => 'Failed to receive the realization.',
    ],

    'return' => [
        'only_received' => 'Only received realizations can have their difference settled.',
        'forbidden' => 'You do not have permission to record a fund return.',
        'only_approved' => 'Only approved realizations can be settled.',
        'wrong_difference' => 'This realization has no remaining funds to return.',
        'attachment_required' => 'At least one proof of return must be attached.',
        'amount_mismatch' => 'The returned amount must be exactly Rp :expected, but Rp :given was entered. Partial returns cannot be recorded.',
        'success' => 'The fund return has been recorded.',
        'failed' => 'Failed to record the fund return.',
    ],

    'reimburse' => [
        'only_received' => 'Only received realizations can have their difference settled.',
        'forbidden' => 'You do not have permission to record a reimbursement.',
        'only_approved' => 'Only approved realizations can be settled.',
        'wrong_difference' => 'This realization has no shortfall to reimburse.',
        'attachment_required' => 'At least one proof of payment must be attached.',
        'amount_mismatch' => 'The paid amount must be exactly Rp :expected, but Rp :given was entered. Partial payments cannot be recorded.',
        'success' => 'The reimbursement has been recorded.',
        'failed' => 'Failed to record the reimbursement.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excel export
    |--------------------------------------------------------------------------
    */
    'export' => [
        'forbidden' => 'You do not have permission to export realization data.',
        'failed' => 'Failed to export the realization data.',
        'filename' => 'Realization',
        'sheet_title' => 'Realization',
        'no_item' => '(no lines)',

        'difference_none' => 'No difference',
        'difference_return' => 'Returned',
        'difference_reimburse' => 'Reimbursed',

        'columns' => [
            'no' => 'No',
            'realization_number' => 'Realization No.',
            'date' => 'Date',
            'advance_number' => 'Cash Advance No.',
            'branch' => 'Branch',
            'department' => 'Department',
            'transaction_category' => 'Transaction Category',
            'subject' => 'Subject',
            'item_description' => 'Line Description',
            'item_advance_amount' => 'Requested Amount',
            'item_realization_amount' => 'Realized Amount',
            'item_difference' => 'Line Difference',
            'total_advance_amount' => 'Total Requested',
            'total_realization_amount' => 'Total Realized',
            'difference_amount' => 'Total Difference',
            'difference_type' => 'Difference Type',
            'status' => 'Status',
        ],
    ],

    'print' => [
        'not_found' => 'Realization not found.',
        'not_printable' => 'A realization can only be printed after it is approved.',
        'url_failed' => 'Failed to create the print URL.',
        'failed' => 'Failed to generate the printout.',
    ],

    'items' => [
        'required' => 'Expense lines are required.',
        'description_required' => 'Description on row :row is required.',
        'date_required' => 'Date on row :row is required.',
        'amount_invalid' => 'The realized amount on row :row cannot be negative.',
        'attachment_required' => 'Row :row requires at least one attachment.',
        'unknown_source' => 'Row :row refers to an unknown cash advance line.',
        'duplicate_source' => 'Row :row refers to a cash advance line already used by another row.',
    ],
];
