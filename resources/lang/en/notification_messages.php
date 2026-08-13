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
];
