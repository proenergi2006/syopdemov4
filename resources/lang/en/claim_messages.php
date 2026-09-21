<?php

/*
|--------------------------------------------------------------------------
| Claim API messages
|--------------------------------------------------------------------------
| Covers the CRUD stage only. Keys for submit, approval, payment, export and
| printing follow along with those stages.
|--------------------------------------------------------------------------
*/

return [
    'user_not_authenticated' => 'Your session has expired. Please sign in again.',

    'index' => [
        'loaded' => 'Claim data loaded successfully.',
        'load_failed' => 'Failed to load claim data.',
    ],

    'show' => [
        'loaded' => 'Claim detail loaded successfully.',
        'not_found' => 'Claim not found or you do not have access to it.',
        'failed' => 'Failed to load claim detail.',
    ],

    'store' => [
        'forbidden' => 'You do not have permission to create a claim.',
        'success' => 'Claim saved as draft.',
        'invalid' => 'Claim data is not valid.',
        'failed' => 'Failed to save the claim.',
    ],

    'update' => [
        'forbidden' => 'You do not have permission to update a claim.',
        'only_draft' => 'Only draft claims can be updated.',
        'success' => 'Claim updated successfully.',
        'failed' => 'Failed to update the claim.',
    ],

    'destroy' => [
        'forbidden' => 'You do not have permission to delete a claim.',
        'only_draft' => 'Only draft claims can be deleted.',
        'success' => 'Claim deleted successfully.',
        'failed' => 'Failed to delete the claim.',
    ],

    'submit' => [
        'forbidden' => 'You do not have permission to submit a claim.',
        'only_draft' => 'Only draft claims can be submitted.',
        'items_unavailable' => 'The claim has no expense lines yet.',
        'signature_missing' => 'You do not have a digital signature yet.',
        'success' => 'Claim submitted successfully.',
        'failed' => 'Failed to submit the claim.',
    ],

    'approve' => [
        'not_in_progress' => 'The claim is not currently in the approval process.',
        'final_success' => 'Claim has been fully approved.',
        'step_success' => 'Approval step completed successfully.',
        'waiting_others' => 'Your approval was saved and is still waiting for other approvers on the same step.',
        'failed' => 'The approval could not be processed.',
    ],

    'reject' => [
        'not_in_progress' => 'The claim is not currently in the approval process.',
        'success' => 'Claim rejected successfully.',
        'failed' => 'The rejection could not be processed.',
    ],

    'receive' => [
        'forbidden' => 'You do not have access to receive claims.',
        'only_approved' => 'Only approved claims can be marked as received.',
        'success' => 'The claim was marked as received.',
        'failed' => 'Failed to receive the claim.',
    ],

    'pay' => [
        'only_received' => 'Only received claims can be reimbursed.',
        'forbidden' => 'You do not have permission to mark a claim as reimbursed.',
        'only_approved' => 'Only approved claims can be reimbursed.',
        'success' => 'Claim marked as reimbursed.',
        'failed' => 'Failed to process the reimbursement.',
    ],
    'cancel' => [
        'forbidden' => 'You do not have permission to cancel a claim.',
        'only_approved' => 'Only approved claims can be cancelled.',
        'success' => 'Claim cancelled successfully.',
        'failed' => 'Failed to cancel the claim.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excel export
    |--------------------------------------------------------------------------
    */
    'export' => [
        'forbidden' => 'You do not have permission to export claim data.',
        'failed' => 'Failed to export the claim data.',
        'filename' => 'Claim',
        'sheet_title' => 'Claim',
        'no_item' => '(no lines)',

        'columns' => [
            'no' => 'No',
            'claim_number' => 'Claim No.',
            'date' => 'Date',
            'branch' => 'Branch',
            'department' => 'Department',
            'transaction_category' => 'Transaction Category',
            'subject' => 'Subject',
            'item_date' => 'Line Date',
            'item_description' => 'Line Description',
            'item_amount' => 'Line Amount',
            'total_amount' => 'Claim Total',
            'status' => 'Status',
            'created_by' => 'Created By',
        ],
    ],

    'print' => [
        'not_found' => 'Claim not found.',
        'not_printable' => 'A claim can only be printed after it is approved.',
        'url_failed' => 'Failed to create the print URL.',
        'failed' => 'Failed to generate the printout.',
    ],
    'items' => [
        'required' => 'Expense lines are required.',
        'description_required' => 'Description on row :row is required.',
        'date_required' => 'Date on row :row is required.',
        'amount_required' => 'Amount on row :row is required and must be greater than 0.',
        'attachment_required' => 'Row :row requires at least one attachment.',
    ],

    'access_assignment' => [
        'branch_department_required' => 'Branch and department are required.',
        'no_access_branch_department' => 'You do not have access to that branch and department.',
        'no_access_create' => 'You do not have access to create a claim for that branch and department.',
    ],
];
