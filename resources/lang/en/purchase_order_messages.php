<?php

return [
    'store' => [
        'forbidden' => 'You do not have access to create a Purchase Order.',
        'department_forbidden' => 'You do not have access to create a Purchase Order for that department.',
        'success' => 'Purchase Order saved successfully.',
        'failed' => 'Failed to save Purchase Order.',
    ],

    'show' => [
        'loaded' => 'Purchase Order detail loaded successfully.',
        'load_failed' => 'Failed to load Purchase Order detail.',
    ],

    'update' => [
        'forbidden' => 'You do not have access to update this Purchase Order.',
        'invalid_id' => 'Invalid Purchase Order ID.',
        'department_forbidden' => 'You do not have access to update a Purchase Order for that department.',
        'success' => 'Purchase Order updated successfully.',
        'not_found' => 'Purchase Order not found.',
        'failed' => 'Failed to update Purchase Order.',
        'only_draft_status' => 'A Purchase Order can only be updated while its status is still Draft.',
    ],

    'destroy' => [
        'forbidden' => 'You do not have access to delete this Purchase Order.',
        'only_draft' => 'A Purchase Order can only be deleted while its status is still Draft.',
        'success' => 'Purchase Order deleted successfully.',
        'failed' => 'Failed to delete Purchase Order.',
    ],

    'cancel' => [
        'forbidden' => 'You do not have access to cancel this Purchase Order.',
        'success' => 'Purchase Order successfully cancelled.',
        'invalid_id' => 'Invalid Purchase Order ID.',
        'not_found' => 'Purchase Order not found.',
        'invalid_status' => 'A Purchase Order can only be cancelled while its status is APPROVED.',
        'has_active_gr' => 'This Purchase Order cannot be cancelled because a Goods Receipt already exists.',
        'failed' => 'Failed to cancel Purchase Order.',
    ],

    'submit' => [
        'signature_missing' => 'You do not have a digital signature yet. Please register your signature first.',
        'success' => 'Purchase Order submitted successfully.',
        'failed' => 'Failed to submit Purchase Order.',
        'only_draft_status' => 'A Purchase Order can only be submitted while its status is still Draft.',
        'items_unavailable' => 'This Purchase Order cannot be submitted because its items are not yet available.',
        'zero_total' => 'This Purchase Order cannot be submitted while its total amount is still 0.',
    ],

    'approve' => [
        'not_current_approver' => 'You are not the approver for the current Purchase Order approval step.',
        'failed' => 'Failed to approve Purchase Order.',
        'only_in_progress_status' => 'A Purchase Order can only be approved while its status is In Progress.',
        'no_pending_approval' => 'There is no pending approval for this Purchase Order.',
    ],

    'reject' => [
        'not_current_approver' => 'You are not the approver for the current Purchase Order approval step.',
        'success' => 'Purchase Order rejected successfully.',
        'failed' => 'Failed to reject Purchase Order.',
        'only_in_progress_status' => 'A Purchase Order can only be rejected while its status is In Progress.',
        'no_pending_approval' => 'There is no pending approval for this Purchase Order.',
    ],

    'department_unavailable' => 'Your account department is not available.',

    'index' => [
        'loaded' => 'Purchase Orders loaded successfully.',
        'load_failed' => 'Failed to load Purchase Orders.',
    ],

    'receivable' => [
        'invalid_id' => 'Invalid Purchase Order ID.',
        'not_found_or_unavailable' => 'Purchase Order not found or not available for Goods Receipt.',
        'items_loaded' => 'Purchase Order items loaded successfully.',
        'items_load_failed' => 'Failed to load Purchase Order items.',
    ],

    'item' => [
        'unit_price_negative' => 'Unit price cannot be less than 0.',
        'unit_price_required' => 'Unit price is required.',
        'unit_price_numeric' => 'Unit price must be a number.',
        'qty_exceeds_outstanding' => 'The PO quantity for item :item_name exceeds the outstanding quantity.',
        'qty_exceeds_max_editable' => 'The PO quantity for item :item_name may not exceed :max_qty.',
        'unit_invalid' => 'The unit for item :item_name is invalid.',
    ],

    'purchase_request' => [
        'mismatch_with_items' => 'The list of Purchase Requests does not match the selected Purchase Order items.',
        'not_found' => 'Some Purchase Requests could not be found.',
        'not_approved' => 'Purchase Request :nomor_pr is not yet APPROVED.',
        'no_longer_processable_create' => 'Purchase Request :nomor_pr can no longer be converted into a PO.',
        'no_longer_processable_update' => 'Purchase Request :nomor_pr can no longer be used to update this PO.',
        'department_mismatch' => 'All Purchase Requests within a single Purchase Order must belong to the same department.',
        'cabang_mismatch' => 'All Purchase Requests within a single Purchase Order must belong to the same branch.',
        'items_not_found' => 'Some Purchase Request items could not be found or have been deleted.',
        'item_not_found' => 'Purchase Request item not found.',
        'item_mismatch' => 'This item does not match the selected Purchase Request.',
        'item_not_in_pr_list' => 'This Purchase Request item is not included in the PO\'s Purchase Request list.',
    ],

    'id_department_mismatch' => 'The Purchase Order department does not match the Purchase Request department.',
    'cabang_mismatch' => 'The Purchase Order branch does not match the Purchase Request branch.',

    'vendor' => [
        'master_table_not_found' => 'The master vendor table was not found.',
        'pkp_column_not_found' => 'The vendor PKP status column was not found.',
        'not_found' => 'Vendor not found.',
        'column_not_found' => 'The vendor column on Purchase Order was not found.',
    ],

    'lampiran' => [
        'min_required' => 'At least 1 Purchase Order attachment is required.',
    ],


    'export' => [
        'failed' => 'Failed to export Purchase Order data.',
        'forbidden' => 'You do not have access to export Purchase Orders.',

        'filename' => 'purchase_order',
        'sheet_title' => 'Purchase Order',

        'columns' => [
            'no' => 'No',
            'nomor_po' => 'PO Number',
            'tanggal_po' => 'PO Date',
            'vendor' => 'Vendor',
            'cabang' => 'Branch',
            'department' => 'Department',
            'item' => 'Item',
            'harga_satuan' => 'Unit Price',
            'qty' => 'Qty',
            'satuan' => 'Unit',
            'subtotal_item' => 'Item Total',
            'ppn' => 'VAT',
            'total_po' => 'PO Total',
            'status' => 'Status',
            'status_gr' => 'GR Status',
            'nomor_pr' => 'PR Number',
        ],

        'status_gr_open' => 'Open',
        'status_gr_partial' => 'Partial',
        'status_gr_completed' => 'Completed',
        'status_gr_empty' => 'No GR yet',

        'no_item' => 'No items',
        'no_pr' => 'No PR',
    ],

    'print' => [
        'not_found' => 'Purchase Order was not found.',
        'failed' => 'Failed to generate Purchase Order print.',
        'url_failed' => 'Failed to generate the Purchase Order print link.',
    ],
];
