<?php

return [
    'forbidden_view' => 'You do not have access to view Bank Disbursement Vouchers.',

    'forbidden_create' => 'You do not have access to create Bank Disbursement Vouchers.',

    'not_found' => 'That Bank Disbursement Voucher was not found.',

    'unknown_document_type' => 'That document type is not registered as a BDV source.',

    'document_not_found' => 'The selected document was not found.',

    'document_not_available' => 'That document cannot be raised into a BDV. Its paperwork may not have been received yet, it may already be disbursed, or it may already be used on another BDV.',

    'forbidden_update' => 'You do not have access to edit Bank Disbursement Vouchers.',

    'not_draft' => 'Only draft BDVs can be edited.',
    'not_editable' => 'Only BDVs that are draft or need revision can be edited.',

    'number_taken' => 'That BDV number is already used by another document.',

    'duplicate_source' => 'That document has already been raised into another BDV.',

    'item_line_unknown' => 'Detail line :line does not exist on the source document.',

    'sources_required' => 'Choose at least one document to disburse.',

    'duplicate_document_in_voucher' => 'One document was chosen twice on this voucher.',

    'mixed_document_type' => 'One BDV may only disburse a single document type. The chosen ones mix :types.',

    'mixed_department' => 'Every document on one BDV must come from the same department. Chosen: :documents.',

    'mixed_branch' => 'Every document on one BDV must come from the same branch. Chosen: :documents.',

    'item_line_unknown_on' => 'Detail line :line does not exist on document :document.',

    'items_required' => 'A BDV cannot be saved without details.',

    'created' => 'Bank Disbursement Voucher saved.',

    'updated' => 'Bank Disbursement Voucher changes saved.',

    'save_failed' => 'The Bank Disbursement Voucher could not be saved.',

    /*
    |--------------------------------------------------------------------------
    | Deleting
    |--------------------------------------------------------------------------
    */
    'forbidden_delete' => 'You do not have access to delete Bank Disbursement Vouchers.',

    'delete_draft_only' => 'Only draft BDVs can be deleted.',

    'delete_accurate_failed' => 'This document could not be deleted in Accurate, so the deletion was cancelled. The BDV is still intact. Accurate said: :reason',

    'delete_local_failed' => 'The document was deleted in Accurate, but the BDV could not be deleted here. Do not press Save on this voucher -- that would raise a new document in Accurate. Try deleting it once more, and report it if it fails again.',

    'deleted' => 'Bank Disbursement Voucher deleted.',

    'accurate_no_cash_bank' => 'No cash/bank account was chosen, so there is no source of funds to record in Accurate.',

    'accurate_no_payee' => 'The payee has not been filled in. Accurate requires it on every Other Payment.',

    'accurate_no_items' => 'A BDV without details cannot be sent to Accurate.',

    'accurate_item_no_account' => 'The Accurate account on line :lines has not been chosen.',

    'accurate_rejected' => 'Accurate rejected this document: :reason',

    'accurate_failed' => 'Sending to Accurate failed, and Accurate gave no reason.',

    'accurate_no_id' => 'Accurate reported success but returned no document id. Check Accurate directly before retrying.',

    'accurate_sent_not_recorded' => 'The document reached Accurate with id :id, but could not be recorded here. Do not save again before it is checked -- contact IT and quote that id.',

    'saved_accurate_failed' => 'The BDV was saved as a draft, but it has not reached Accurate. :reason',

    /*
    |--------------------------------------------------------------------------
    | Sending evidence files to Accurate
    |--------------------------------------------------------------------------
    */
    'attachment_file_missing' => 'The file was not found in storage. Re-upload that evidence on the source document, then save this BDV again.',

    'attachment_unreadable' => 'The file exists but could not be read. Re-upload that evidence on the source document.',

    'attachment_bad_type' => 'Accurate does not accept :type files. Only :allowed are accepted.',

    'attachment_too_big' => 'It is :size, over Accurate\'s :max limit per file.',

    'attachment_too_many' => 'The Accurate document has reached its maximum of :max files.',

    'attachment_no_answer' => 'Accurate gave no readable answer. Try saving once more.',

    'attachment_rejected_unknown' => 'Accurate rejected it without giving a reason.',



    'attachment_delete_failed' => 'could not be deleted in Accurate -- :reason. Save once more to retry',



    'attachment_no_remote_id' => 'cannot be deleted from here because its attachment id was never recorded -- it must be removed by hand in Accurate',

    'attachment_partial' => ':failed of :total evidence files have not reached Accurate. :detail Save once more to retry the ones that failed.',

    'attachment_leftover' => 'Old evidence still attached in Accurate although this voucher no longer uses it (:count). :detail',



    'attachment_detail' => ':name (:reason)',

    'attachment_and_more' => 'and :count more.',

    /*
    |--------------------------------------------------------------------------
    | Submitting and approval
    |--------------------------------------------------------------------------
    */
    'forbidden_submit' => 'You do not have access to submit Bank Disbursement Vouchers.',

    'forbidden_approve' => 'You do not have access to approve Bank Disbursement Vouchers.',

    'submit_draft_only' => 'Only BDVs that are a draft or need revision can be submitted.',

    'submitted' => 'Bank Disbursement Voucher submitted.',

    'submit_needs_resync' => 'The latest changes to this BDV have not reached Accurate, so the figures here differ from the books. Open its edit page and press Save to send them again before submitting.',

    'submit_needs_accurate' => 'This BDV has not reached Accurate, so it cannot be submitted yet. Open its edit page and press Save to send it again.',

    'approved' => 'Your approval has been saved.',

    'approved_final' => 'Bank Disbursement Voucher fully approved.',

    'rejected' => 'Bank Disbursement Voucher rejected.',

    'approval_not_in_progress' => 'This BDV is not in approval right now.',

    'approval_not_yours' => 'You are not the active approver for this BDV.',

    'approval_no_signature' => 'You do not have a digital signature yet, so you cannot approve.',

    'approval_reject_needs_notes' => 'A reason for the rejection is required.',

    'approval_already_built' => 'The approval chain for this BDV has already been built.',

    'approval_amount_zero' => 'The BDV amount must be greater than 0 before it can be submitted.',

    'approval_no_department' => 'The BDV has no department, so no approval flow can be found.',

    'approval_no_branch' => 'The BDV has no branch yet.',

    /*
    | The criteria are assembled in BdvApprovalGeneratorService from the
    | module switches: only the ones actually used are named.
    */
    'approval_flow_missing' => 'No BDV approval flow was found for :criteria. Complete the BDV approval matrix for that combination.',

    /*
    | The BDV module is set to global: telling the admin to complete "the
    | matrix" here would be misleading, since the matrix is not consulted.
    */
    'approval_flow_missing_global' => 'No active BDV approval flow covers Rp :amount yet. The BDV module is set to apply globally -- no matrix condition, no transaction category -- so a single active BDV flow that covers that amount is enough. Create or activate one under Approval Flow.',

    'approval_criteria_area' => 'area :value',
    'approval_criteria_department' => 'department :value',
    'approval_criteria_category' => 'transaction category :value',
    'approval_criteria_amount' => 'amount Rp :value',
    'approval_criteria_join' => 'and',

    'approval_flow_no_steps' => 'A BDV approval flow was found, but it has no approvers yet.',

    'approval_no_approver_left' => 'No approver is left on this BDV approval flow for you. Ask an administrator to check the approval matrix.',

    'approval_step_empty' => 'No approver was found for step ":step" on this BDV\'s branch.',

    'approval_step_no_approver' => 'The approver on step ":step" has not been configured.',

    'approval_step_no_branches' => 'The branches handled by the approver on step ":step" have not been configured.',

    'approval_step_scope_must_global' => 'Step ":step" uses a department or the peer-approval rule, so its scope must be Global.',

    'approval_scope_unsupported' => 'Approver scope ":scope" on step ":step" is not supported.',

    'approval_type_unsupported' => 'Approver type ":type" on step ":step" is not supported.',

    'approval_skipped_any' => 'Skipped because approval mode ANY was already satisfied by :name.',

    'approval_cancelled_by_reject' => 'Cancelled because the BDV was rejected.',
];
