<?php

return [
    'store' => [
        'forbidden' => 'You do not have access to create a Goods Receipt.',
        'invalid_ids' => 'Invalid Purchase Order, Goods Return, or item ID.',
        'not_found' => 'Purchase Order, Goods Return, or item not found.',
        'failed' => 'Failed to create Goods Receipt.',
    ],

    'update' => [
        'forbidden' => 'You do not have access to update this Goods Receipt.',
        'only_draft' => 'A Goods Receipt can only be updated while its status is still DRAFT.',
        'branch_department_incomplete' => 'The Purchase Order\'s branch or department is incomplete.',
    ],

    'show' => [
        'loaded' => 'Goods Receipt detail loaded successfully.',
        'load_failed' => 'Failed to load Goods Receipt detail.',
    ],

    'post' => [
        'forbidden' => 'You do not have access to post this Goods Receipt.',
        'success' => 'Goods Receipt posted successfully.',
        'invalid_id' => 'Invalid Goods Receipt ID.',
        'not_found' => 'Goods Receipt not found.',
        'failed' => 'Failed to post Goods Receipt.',
    ],

    'return_history' => [
        'forbidden' => 'You do not have access to view Goods Return history.',
        'loaded' => 'Goods Return history loaded successfully.',
        'invalid_id' => 'Invalid Goods Receipt ID.',
        'not_found' => 'Goods Receipt not found.',
        'load_failed' => 'Failed to load Goods Return history.',
    ],

    'destroy' => [
        'forbidden' => 'You do not have access to delete this Goods Receipt.',
        'only_draft' => 'A Goods Receipt can only be deleted while its status is still DRAFT.',
        'failed' => 'Failed to delete Goods Receipt.',
    ],

    'cancel' => [
        'forbidden' => 'You do not have access to cancel this Goods Receipt.',
        'success' => 'Goods Receipt successfully cancelled.',
        'invalid_id' => 'Invalid Goods Receipt ID.',
        'not_found' => 'Goods Receipt not found.',
        'invalid_status' => 'A Goods Receipt can only be cancelled while its status is POSTED.',
        'failed' => 'Failed to cancel Goods Receipt.',
    ],

    'validation' => [
        'source_return_status_posted' => 'The source Goods Return must have POSTED status.',
        'po_status_approved' => 'The Purchase Order must have APPROVED status.',
        'department_not_found' => 'Your login account department was not found.',
        'po_not_from_department' => 'This Purchase Order does not belong to your department.',
        'po_return_mismatch' => 'The Purchase Order does not match the source Goods Return.',
        'return_not_from_department' => 'This Goods Return does not belong to your department.',
        'items_not_in_po' => 'Some items are not part of the Purchase Order.',
        'po_item_not_found' => 'Purchase Order item not found.',
        'qty_receive_positive' => 'Quantity received must be greater than zero.',
        'qty_replacement_exceeds_outstanding' => 'The replacement quantity for item :item_name exceeds the outstanding replacement quantity. Maximum :max_qty.',
        'qty_receive_exceeds_outstanding' => 'The received quantity for item :item_name exceeds the Purchase Order outstanding quantity. Maximum :max_qty.',
        'multiple_return_candidates' => 'There is more than one Goods Return still pending replacement. Please select a specific source Goods Return.',
        'item_qty_mismatch_replacement' => 'The item or received quantity does not match the outstanding Goods Return replacement.',
    ],
];
