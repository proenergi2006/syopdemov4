<?php

return [
    'purchase_order' => [
        'approval_request' => [
            'title' => 'Purchase Order Approval',
            'message' => 'Purchase Order :nomor_po is awaiting your approval.',
        ],
        'approval_step_pending' => [
            'title' => 'PO Approval Step Approved',
            'message' => 'Purchase Order :nomor_po has been approved by :approver_name and is still awaiting further approval.',
        ],
        'approval_step_final' => [
            'title' => 'Purchase Order Approved',
            'message' => 'Purchase Order :nomor_po has been fully approved by :approver_name.',
        ],
        'rejected' => [
            'title' => 'Purchase Order Rejected',
            'message' => 'Purchase Order :nomor_po has been rejected by :rejecter_name.',
        ],
    ],

    'purchase_request' => [
        'approval_request' => [
            'title' => 'Purchase Requisition Approval',
            'message_with_label' => 'Purchase Requisition :nomor_pr is awaiting your approval at step :step_order (:step_label).',
            'message_without_label' => 'Purchase Requisition :nomor_pr is awaiting your approval at step :step_order.',
        ],
        'approval_step_pending' => [
            'title' => 'PR Approval Step Approved',
            'message' => 'Purchase Requisition :nomor_pr has been approved by :approver_name at step :step_order and is still awaiting further approval.',
        ],
        'approval_step_final' => [
            'title' => 'Purchase Requisition Approved',
            'message' => 'Purchase Requisition :nomor_pr has been fully approved by :approver_name.',
        ],
        'rejected' => [
            'title' => 'Purchase Requisition Rejected',
            'message' => 'Purchase Requisition :nomor_pr has been rejected by :rejecter_name.',
        ],
    ],

    /*
    | FPU -- Cash Advance, part of the Fund Request module.
    */
    /*
    |--------------------------------------------------------------------------
    | Claim
    |--------------------------------------------------------------------------
    */
    'claim' => [
        'receipt_request' => [
            'title' => 'Claim Awaiting Receipt',
            'message' => 'Claim :claim_number worth Rp :total_amount is waiting for you to receive it.',
        ],

        'received' => [
            'title' => 'Claim Received',
            'message' => 'Your claim :claim_number was received by :receiver_name and is awaiting payment.',
            'message_scheduled' => 'Your claim :claim_number was received by :receiver_name and is scheduled for payment on :payment_date.',
        ],

        /*
        | Outside the approval flow: recipients are the pay permission holders.
        */
        'payment_request' => [
            'title' => 'Claim Awaiting Reimbursement',
            'message' => 'Claim :claim_number has been approved and is awaiting reimbursement of Rp :total_amount.',
        ],

        'paid' => [
            'title' => 'Claim Reimbursed',
            'message' => 'Your claim :claim_number has been reimbursed by :payer_name.',
        ],
        'approval_request' => [
            'title' => 'Claim Approval Request',
            'message_with_label' => 'Claim :claim_number is waiting for your approval on step :step_order (:step_label).',
            'message_without_label' => 'Claim :claim_number is waiting for your approval on step :step_order.',
        ],

        'approval_step_pending' => [
            'title' => 'Claim Approval Step Completed',
            'message' => 'Claim :claim_number was approved by :approver_name on step :step_order and is waiting for the next step.',
        ],

        'approval_step_final' => [
            'title' => 'Claim Approved',
            'message' => 'Claim :claim_number has been fully approved by :approver_name.',
        ],

        'rejected' => [
            'title' => 'Claim Rejected',
            'message' => 'Claim :claim_number was rejected by :rejecter_name.',
        ],
    ],
    'cash_advance' => [
        /*
        | Outside the approval flow: recipients are the disburse permission
        | holders, not this document's approvers.
        */
        'receipt_request' => [
            'title' => 'Cash Advance Awaiting Receipt',
            'message' => 'Cash advance :advance_number worth Rp :total_amount is awaiting your receipt.',
        ],

        'received' => [
            'title' => 'Cash Advance Received',
            'message' => 'Cash advance :advance_number was received by :receiver_name and is awaiting disbursement.',
            'message_scheduled' => 'Cash advance :advance_number was received by :receiver_name and is scheduled for payment on :payment_date.',
        ],

        'disbursement_request' => [
            'title' => 'Cash Advance Awaiting Disbursement',
            'message' => 'Cash advance :advance_number has been approved and is awaiting disbursement of Rp :total_amount.',
        ],
        'approval_request' => [
            'title' => 'Cash Advance Approval',
            'message_with_label' => 'Cash Advance :advance_number is awaiting your approval at step :step_order (:step_label).',
            'message_without_label' => 'Cash Advance :advance_number is awaiting your approval at step :step_order.',
        ],
        'approval_step_pending' => [
            'title' => 'Cash Advance Approval Step Approved',
            'message' => 'Cash Advance :advance_number has been approved by :approver_name at step :step_order and is still awaiting further approval.',
        ],
        'approval_step_final' => [
            'title' => 'Cash Advance Approved',
            'message' => 'Cash Advance :advance_number has been fully approved by :approver_name.',
        ],
        'rejected' => [
            'title' => 'Cash Advance Rejected',
            'message' => 'Cash Advance :advance_number has been rejected by :rejecter_name.',
        ],
        'disbursed' => [
            'title' => 'Cash Advance Disbursed',
            'message' => 'Cash Advance :advance_number has been disbursed by :disburser_name.',
        ],
    ],

    /*
    | Cash Advance Realization -- accountability for money already disbursed.
    */
    'cash_advance_realization' => [
        'receipt_request' => [
            'title' => 'Realization Awaiting Receipt',
            'message' => 'Realization :realization_number worth Rp :total_amount is waiting for you to receive it.',
        ],

        'received' => [
            'title' => 'Realization Received',
            'message' => 'Your realization :realization_number was received by :receiver_name.',
            'message_scheduled' => 'Your realization :realization_number was received by :receiver_name and its shortfall is scheduled for payment on :payment_date.',
        ],

        /*
        | Outside the approval flow: recipients are the settlement permission
        | holders, picked according to which way the money moves.
        */
        'return_request' => [
            'title' => 'Realization Surplus Awaiting Return',
            'message' => 'Realization :realization_number has been approved and leaves Rp :difference_amount for the requester to return.',
        ],

        'reimburse_request' => [
            'title' => 'Realization Shortfall Awaiting Payment',
            'message' => 'Realization :realization_number has been approved and its shortfall of Rp :difference_amount must be paid to the requester.',
        ],
        'approval_request' => [
            'title' => 'Realization Approval',
            'message_with_label' => 'Realization :realization_number is awaiting your approval at step :step_order (:step_label).',
            'message_without_label' => 'Realization :realization_number is awaiting your approval at step :step_order.',
        ],
        'approval_step_pending' => [
            'title' => 'Realization Approval Step Approved',
            'message' => 'Realization :realization_number has been approved by :approver_name at step :step_order and is still awaiting further approval.',
        ],
        'approval_step_final' => [
            'title' => 'Realization Approved',
            'message' => 'Realization :realization_number has been fully approved by :approver_name.',
        ],
        'rejected' => [
            'title' => 'Realization Rejected',
            'message' => 'Realization :realization_number has been rejected by :rejecter_name.',
        ],
        'settled' => [
            'title' => 'Realization Difference Settled',
            'message' => 'The difference on realization :realization_number has been settled by :settler_name.',
        ],
    ],
    'business_trip' => [
        'approval_request' => [
            'title' => 'Business Trip Awaiting Approval',
            'message_with_label' => 'Business trip :trip_number is waiting for your approval at step :step_order (:step_label).',
            'message_without_label' => 'Business trip :trip_number is waiting for your approval at step :step_order.',
        ],

        'approval_step_pending' => [
            'title' => 'One Business Trip Step Approved',
            'message' => 'Business trip :trip_number was approved by :approver_name at step :step_order. Waiting for the next step.',
        ],

        'approval_step_final' => [
            'title' => 'Business Trip Approved',
            'message' => 'Business trip :trip_number has been fully approved by :approver_name. You may now submit the cash advance.',
        ],

        'rejected' => [
            'title' => 'Business Trip Rejected',
            'message' => 'Business trip :trip_number was rejected by :approver_name. Notes: :notes',
        ],
    ],
];
