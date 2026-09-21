<?php

/*
|--------------------------------------------------------------------------
| Cash Advance (FPU) API messages
|--------------------------------------------------------------------------
*/

return [
    'user_not_authenticated' => 'Your session has expired. Please sign in again.',

    'index' => [
        'loaded' => 'Cash advance data loaded successfully.',
        'load_failed' => 'Failed to load cash advance data.',
    ],

    'show' => [
        'loaded' => 'Cash advance detail loaded successfully.',
        'not_found' => 'Cash advance not found or you do not have access to it.',
        'failed' => 'Failed to load cash advance detail.',
    ],

    'store' => [
        'forbidden' => 'You do not have permission to create a cash advance.',
        'success' => 'Cash advance saved as draft.',
        'invalid' => 'Cash advance data is not valid.',
        'failed' => 'Failed to save the cash advance.',
    ],

    'update' => [
        'forbidden' => 'You do not have permission to update a cash advance.',
        'only_draft' => 'Only draft cash advances can be updated.',
        'success' => 'Cash advance updated successfully.',
        'failed' => 'Failed to update the cash advance.',
    ],

    'destroy' => [
        'forbidden' => 'You do not have permission to delete a cash advance.',
        'only_draft' => 'Only draft cash advances can be deleted.',
        'success' => 'Cash advance deleted successfully.',
        'failed' => 'Failed to delete the cash advance.',
    ],

    'submit' => [
        'limit_too_many' => 'You already have :count of :max cash advances still running. Realize one of them through to approval before submitting a new one.',
        'limit_overdue' => 'Some of your cash advances are past :days days without realization: :documents. Complete their realization before submitting a new one.',
        'forbidden' => 'You do not have permission to submit a cash advance.',
        'only_draft' => 'Only draft cash advances can be submitted.',
        'items_unavailable' => 'The cash advance has no request lines yet.',
        'signature_missing' => 'You do not have a digital signature yet.',
        'success' => 'Cash advance submitted successfully.',
        'failed' => 'Failed to submit the cash advance.',
    ],

    'approve' => [
        'not_in_progress' => 'The cash advance is not currently in the approval process.',
        'final_success' => 'Cash advance has been fully approved.',
        'step_success' => 'Approval step completed successfully.',
        'waiting_others' => 'Your approval was saved and is still waiting for other approvers on the same step.',
        'failed' => 'The approval could not be processed.',
    ],

    'reject' => [
        'not_in_progress' => 'The cash advance is not currently in the approval process.',
        'success' => 'Cash advance rejected successfully.',
        'failed' => 'The rejection could not be processed.',
    ],

    'cancel' => [
        'forbidden' => 'You do not have permission to cancel a cash advance.',
        'only_approved' => 'Only approved cash advances can be cancelled.',
        'has_realization' => 'This cash advance cannot be cancelled because realization :realization_number is still active with status :realization_status. Cancel or reject the realization first.',
        'success' => 'Cash advance cancelled successfully.',
        'failed' => 'Failed to cancel the cash advance.',
    ],

    'receive' => [
        'forbidden' => 'You do not have access to receive a cash advance.',
        'only_approved' => 'Only approved cash advances can be marked as received.',
        'success' => 'Cash advance marked as received.',
        'failed' => 'Failed to mark the cash advance as received.',
    ],

    'disburse' => [
        'only_received' => 'Only received cash advances can be disbursed.',
        'forbidden' => 'You do not have permission to disburse a cash advance.',
        'only_approved' => 'Only approved cash advances can be disbursed.',
        'success' => 'Cash advance marked as paid.',
        'failed' => 'Failed to process the disbursement.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excel export
    |--------------------------------------------------------------------------
    */
    'export' => [
        'forbidden' => 'You do not have permission to export cash advance data.',
        'failed' => 'Failed to export the cash advance data.',
        'filename' => 'CashAdvance',
        'sheet_title' => 'Cash Advance',
        'no_item' => '(no lines)',

        'columns' => [
            'no' => 'No',
            'advance_number' => 'Cash Advance No.',
            'date' => 'Date',
            'branch' => 'Branch',
            'department' => 'Department',
            'transaction_category' => 'Transaction Category',
            'request_type' => 'Request Type',
            'subject' => 'Subject',
            'item_date' => 'Line Date',
            'item_description' => 'Line Description',
            'item_amount' => 'Line Amount',
            'total_amount' => 'Total Requested',
            'status' => 'Status',
            'realization_number' => 'Realization No.',
            'realization_status' => 'Realization Status',
            'created_by' => 'Created By',
        ],
    ],

    'items' => [
        'required' => 'Request lines are required.',
        'description_required' => 'Description on row :row is required.',
        'date_required' => 'Date on row :row is required.',
        'amount_required' => 'Amount on row :row is required and must be greater than 0.',
        'attachment_required' => 'Row :row requires at least one attachment.',
    ],

    'print' => [
        'not_found' => 'Cash advance not found.',
        'not_printable' => 'A cash advance can only be printed after it is approved.',
        'url_failed' => 'Failed to create the print URL.',
        'failed' => 'Failed to generate the printout.',
    ],

    'access_assignment' => [
        'branch_department_required' => 'Branch and department are required.',
        'no_access_branch_department' => 'You do not have access to that branch and department.',
        'no_access_create' => 'You do not have access to create a cash advance for that branch and department.',
    ],

    /* Tautan ke Perdin; wajib bila keterangan transaksinya menuntutnya. */
    'business_trip' => [
        'required' => 'A cash advance with a business-trip transaction category must reference an approved business trip.',
        'not_eligible' => 'The selected business trip cannot be used: make sure it is yours, already approved, and not already used by another active cash advance.',
        'item_date_outside_trip' => 'Row :row: the date falls outside business trip :number (:from to :to). A trip expense cannot be dated outside the trip itself.',
        'trip_not_approved' => 'This cash advance cannot be disbursed yet: business trip :number is still awaiting management approval.',
        'trip_not_valid' => 'This cash advance cannot be disbursed: business trip :number was rejected or cancelled. Cancel this cash advance, or submit the business trip again.',
    ],
];
