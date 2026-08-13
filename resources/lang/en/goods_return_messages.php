<?php

return [
    'user_not_authenticated' => 'User is not authenticated.',
    'invalid_id' => 'Invalid Goods Return ID.',
    'not_found' => 'Goods Return not found.',
    'not_found_or_no_access' => 'Goods Return not found or you do not have access to it.',
    'source_not_found' => 'Goods Return or source data not found.',
    'create_forbidden' => 'You do not have access to create a goods return.',
    'edit_forbidden' => 'You do not have access to update this Goods Return.',
    'only_draft' => 'A Goods Return can only be updated while its status is still DRAFT.',
    'index_forbidden' => 'You do not have access to view Goods Return.',

    'reasons' => [
        'forbidden' => 'You do not have access to the return reason master data.',
        'loaded' => 'Return reason data retrieved successfully.',
        'load_failed' => 'Failed to retrieve return reason data.',
    ],

    'create' => [
        'department_not_configured' => 'Your department is not configured.',
        'gr_not_found_or_fully_returned' => 'Goods Receipt not found or all item quantities have already been returned.',
    ],

    'form' => [
        'loaded' => 'Goods return form data retrieved successfully.',
        'invalid_id' => 'Invalid Goods Receipt ID.',
        'load_failed' => 'Failed to retrieve goods return form data.',
    ],

    'draft' => [
        'created' => 'Goods return draft created successfully.',
        'invalid_ids' => 'Invalid Goods Receipt or item ID.',
        'not_found' => 'Source Goods Receipt or item not found.',
        'create_failed' => 'Failed to create goods return draft.',
    ],

    'show' => [
        'loaded' => 'Goods Return detail loaded successfully.',
        'load_failed' => 'Failed to load Goods Return detail.',
    ],

    'edit' => [
        'loaded' => 'Goods Return edit data loaded successfully.',
        'load_failed' => 'Failed to load Goods Return edit data.',
    ],

    'update' => [
        'success' => 'Goods Return draft updated successfully.',
        'invalid_ids' => 'Invalid Goods Return or item ID.',
        'failed' => 'Failed to update Goods Return.',
    ],

    'destroy' => [
        'forbidden' => 'You do not have access to delete this Goods Return.',
        'only_draft' => 'A Goods Return can only be deleted while its status is still DRAFT.',
        'success' => 'Goods Return draft deleted successfully.',
        'failed' => 'Failed to delete Goods Return.',
    ],

    'post' => [
        'forbidden' => 'You do not have access to post this goods return.',
        'success' => 'Goods Return posted successfully.',
        'failed' => 'Failed to post Goods Return.',
    ],

    'cancel' => [
        'forbidden' => 'You do not have access to cancel this goods return.',
        'success' => 'Goods Return cancelled successfully.',
        'failed' => 'Failed to cancel Goods Return.',
    ],

    'replacement' => [
        'forbidden' => 'You do not have access to create a Goods Receipt.',
        'department_unavailable' => 'Your account department is not available.',
        'none_needed' => 'There is no Goods Return that needs replacement.',
    ],

    'validation' => [
        'gr_must_be_posted' => 'A return can only be created from a Goods Receipt that is already POSTED.',
        'gr_data_incomplete' => 'The Purchase Order, branch, or department data on this Goods Receipt is incomplete.',
        'po_item_mismatch' => 'The Purchase Order item does not match the Goods Receipt item.',
        'reason_not_found' => 'Return reason not found or no longer active.',
        'qty_receive_invalid' => 'Invalid received quantity for this item.',
        'qty_return_exceeds_returnable' => 'The return quantity for item :item_name exceeds the quantity still returnable. Maximum: :max_qty.',
        'unit_item_not_found' => 'Unit item :item_name was not found in either the Goods Receipt or the Purchase Order.',
        'gr_source_must_be_posted' => 'The source Goods Receipt must have POSTED status.',
        'item_reference_mismatch' => 'The return item reference does not match.',
        'qty_return_positive' => 'Return quantity must be greater than zero.',
    ],
];
