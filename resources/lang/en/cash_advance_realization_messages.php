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
    /*
    | Meneruskan ke pembayaran kekurangan.
    |
    | Hanya ada pada cabang kelebihan: dua cabang lain tidak berpindah meja,
    | jadi tidak ada yang perlu diteruskan ke siapa pun.
    */
    'verify' => [
        'forbidden' => 'You do not have access to forward this realization for payment.',
        'only_received' => 'Only a realization approved by Finance can be forwarded for payment.',
        'already_forwarded' => 'This realization has already been forwarded for payment.',
        'only_reimburse' => 'Only a realization with a shortfall needs forwarding. One that matches exactly is already closed on receipt, and one with money left over closes when that money comes back.',
        'success' => 'The realization has been forwarded for payment of its shortfall.',
        'failed' => 'The realization could not be forwarded for payment.',
    ],

    'receive' => [
        'awaiting_return_proof' => 'This realization leaves a surplus to be returned, and the requester has not uploaded the transfer receipt yet. The paperwork can only be received once the receipt is there, so that it is checked once.',
        'forbidden' => 'You do not have access to receive realizations.',
        'only_approved' => 'Only approved realizations can be marked as received.',
        'success' => 'The realization was marked as received.',
        'failed' => 'Failed to receive the realization.',
    ],

    /*
    | The requester states the surplus has been returned.
    |
    | The money moves outside the system; what happens here is the marking.
    | This declaration does NOT close the document -- see confirm_return below.
    */
    'return' => [
        'only_received' => 'The surplus can only be returned once Finance has received the realization paperwork.',
        'forbidden' => 'You do not have permission to mark a fund return.',
        'only_approved' => 'Only approved realizations can be settled.',
        'only_requester' => 'Only the requester of this realization can mark the surplus as returned, since they hold the money.',
        'wrong_difference' => 'This realization has no remaining funds to return.',
        'attachment_required' => 'At least one transfer receipt must be attached.',
        'amount_mismatch' => 'The returned amount must be exactly Rp :expected, but Rp :given was entered. Partial returns cannot be recorded.',
        'success' => 'The fund return has been marked and is awaiting confirmation by Finance.',
        'failed' => 'Failed to mark the fund return.',
    ],

    /*
    | One Approve button for all three branches -- see financeApprove().
    |
    | The permission behind it still differs by branch, so "no access" here
    | can mean three different permissions. Said generically on purpose: the
    | reader does not need the permission's name, they need to know who to ask.
    */
    'finance_approve' => [
        'forbidden' => 'You do not have permission to approve this realization at the Finance stage.',
        'only_on_checking' => 'Only realizations currently being processed by Finance can be approved.',
        'success' => 'The realization has been approved.',
        'failed' => 'Failed to approve the realization.',
    ],

    /* Finance confirms the money actually arrived -- this is where it closes. */
    'confirm_return' => [
        'forbidden' => 'You do not have permission to confirm a fund return.',
        'only_declared' => 'Only realizations whose return has been declared by the requester can be confirmed.',
        'success' => 'The fund return has been confirmed and the realization is closed.',
        'failed' => 'Failed to confirm the fund return.',
    ],

    /*
    | Rejecting a return declaration. Its permission is separate from
    | confirming, mirroring receive and unreceive: one closes the document,
    | the other throws it back to the requester with a reason they will read.
    */
    'reject_return' => [
        'forbidden' => 'You do not have permission to reject a return declaration.',
        'only_declared' => 'Only realizations whose return has been declared by the requester can be rejected.',
        'success' => 'The return declaration was rejected and the document went back to the requester.',
        'failed' => 'Failed to reject the return declaration.',
        'rejected_note' => 'Return declaration rejected by Finance: :reason',
    ],

    /*
    | The third branch: a realization whose value matches exactly. No money
    | moves, so all that is left is checking the figures and closing it.
    */
    'finalize' => [
        'forbidden' => 'You do not have permission to approve a realization with no difference.',
        'only_on_checking' => 'Only realizations currently being checked can be approved here.',
        'only_no_difference' => 'This realization has a difference, so it closes through a shortfall payment or a fund return, not through this approval.',
        'success' => 'The realization has been approved and is now complete.',
        'failed' => 'Failed to approve the realization.',
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
        'no_expense_category' => '(no category)',
        'arranged_by_ga' => 'Arranged by GA (nothing spent)',
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
            'expense_category' => 'Expense Category',
            'qty' => 'Qty',
            'unit_price' => 'Unit Cost',
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
        'cancelled_reason_required' => 'Row :row is zero. State the reason -- an expense that did not happen still needs an explanation.',
    ],

    /*
    | Rincian berkategori pada dokumen perjalanan dinas.
    |
    | Nominal tiap baris dihitung dari Qty x Rincian Biaya, bukan diterima
    | dari layar -- nominal yang bisa datang dari dua tempat cepat atau
    | lambat akan datang berbeda.
    */
    'breakdown' => [
        'category_required' => 'Row :row: the expense category is not selected. Business trip lines are grouped by category.',
        'qty_required' => 'Row :row: Qty is required and must be greater than zero.',
        'unit_price_required' => 'Row :row: Unit cost is required and must be greater than zero.',
        'cancelled_reason_required' => 'Row :row is zero. State the reason -- an expense that did not happen still needs an explanation.',
        'arranged_has_items' => 'Category :category is marked as arranged by GA, so it cannot have any lines. Remove the lines, or turn the marker off.',
        'arranged_not_allowed' => 'Category :category is not handled by GA, so the marker cannot be set. Its lines are filled in by the requester. This is configured in the Business Trip Expense Category master.',
    ],
];
