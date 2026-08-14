<?php

return [
    'user_not_authenticated' => 'User is not authenticated.',
    'not_found' => 'Purchase Requisition not found.',
    'minimum_amount' => 'The minimum Purchase Requisition amount is Rp 1,000,000.',

    'access_assignment' => [
        'branch_department_required' => 'Branch and department must be selected.',
        'no_access_branch_department' => 'You do not have access to that branch and department.',
        'no_access_create' => 'You do not have access to create a Purchase Requisition for that branch and department.',
    ],

    'index' => [
        'loaded' => 'Purchase Requisition data loaded successfully.',
        'load_failed' => 'Failed to load Purchase Requisition data.',
    ],

    'store' => [
        'forbidden' => 'You do not have access to create a Purchase Requisition.',
        'success' => 'Purchase Requisition saved successfully.',
        'failed' => 'Failed to save Purchase Requisition. Please check the data or contact IT.',
    ],

    'show' => [
        'loaded' => 'Purchase Requisition detail loaded successfully.',
        'load_failed' => 'Failed to load Purchase Requisition detail.',
    ],

    'update' => [
        'forbidden' => 'You do not have access to update this Purchase Requisition.',
        'already_approved' => 'This Purchase Requisition has already been approved and cannot be updated.',
        'success' => 'Purchase Requisition updated successfully.',
        'failed' => 'Failed to update Purchase Requisition. Please check the data or contact IT.',
    ],

    'destroy' => [
        'forbidden' => 'You do not have access to delete this Purchase Requisition.',
        'only_draft' => 'A Purchase Requisition can only be deleted while its status is still Draft.',
        'success' => 'Purchase Requisition deleted successfully.',
        'failed' => 'Failed to delete Purchase Requisition.',
    ],

    'edit' => [
        'forbidden' => 'You do not have access to update this Purchase Requisition.',
        'loaded' => 'Purchase Requisition edit data loaded successfully.',
        'load_failed' => 'Failed to load Purchase Requisition edit data.',
    ],

    'attachment' => [
        'already_approved' => 'This PR has already been approved or is in the approval process. Attachments cannot be deleted.',
        'deleted' => 'Attachment deleted successfully.',
        'not_found' => 'Attachment not found.',
        'delete_failed' => 'Failed to delete attachment.',
    ],

    'approve' => [
        'not_in_progress' => 'This Purchase Requisition is not currently in the approval process.',
        'failed' => 'Failed to approve Purchase Requisition.',
    ],

    'reject' => [
        'not_in_progress' => 'This Purchase Requisition is not currently in the approval process.',
        'success' => 'Purchase Requisition rejected successfully.',
        'failed' => 'Failed to reject Purchase Requisition.',
    ],

    'cancel' => [
        'forbidden' => 'You do not have access to cancel this Purchase Requisition.',
        'success' => 'Purchase Requisition cancelled successfully.',
        'invalid_id' => 'Invalid Purchase Requisition ID.',
        'not_found' => 'Purchase Requisition not found.',
        'failed' => 'Failed to cancel Purchase Requisition.',
    ],

    'submit' => [
        'only_draft' => 'A Purchase Requisition can only be submitted from Draft status.',
        'items_unavailable' => 'This Purchase Requisition cannot be submitted because its items are not yet available.',
        'signature_missing' => 'Requester signature is not available yet. Please complete your signature first.',
        'success' => 'Purchase Requisition submitted successfully.',
        'failed' => 'Failed to submit Purchase Requisition.',
    ],

    'dropdown_approved' => [
        'forbidden' => 'You do not have access to create a Purchase Order.',
        'department_forbidden' => 'You do not have access to create a Purchase Order for that department.',
        'loaded' => 'Purchase Requisitions loaded successfully.',
        'load_failed' => 'Failed to load Purchase Requisitions.',
    ],

    'special_document_type' => [
        'not_allowed' => 'That special document type is not available for your department.',
    ],

    'export' => [
        'failed' => 'Failed to export Purchase Requisition data.',
        'forbidden' => 'You do not have access to export Purchase Requisitions.',

        'filename' => 'purchase_requisition',
        'sheet_title' => 'Purchase Requisition',

        'columns' => [
            'no' => 'No',
            'nomor_pr' => 'PR Number',
            'tanggal_pr' => 'PR Date',
            'cabang' => 'Branch',
            'department' => 'Department',
            'item' => 'Item',
            'harga_satuan' => 'Unit Price',
            'qty' => 'Qty',
            'satuan' => 'Unit',
            'subtotal_item' => 'Item Total',
            'ppn' => 'VAT',
            'total_pr' => 'PR Total',
            'status' => 'Status',
            'status_po' => 'PO Fulfillment Status',
            'nomor_po' => 'PO Number',
        ],

        'status_po_open' => 'Open',
        'status_po_partial' => 'Partial',
        'status_po_completed' => 'Completed',
        'status_po_empty' => 'No PO yet',

        'no_item' => 'No items',
        'no_po' => 'No PO yet',
    ],

    'print' => [
        'not_found' => 'Purchase Requisition was not found.',
        'failed' => 'Failed to generate Purchase Requisition print.',
        'url_failed' => 'Failed to generate the Purchase Requisition print link.',
    ],

    'vendor' => [
        'load_failed' => 'Failed to load Purchase Requisitions for the vendor.',
    ],
];
