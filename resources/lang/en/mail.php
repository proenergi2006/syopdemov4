<?php

return [
    'po' => [
        'subject' => [
            'final_approved' => 'Purchase Order Approved - :nomor_po',
            'step_approved' => 'Purchase Order Approval Update - :nomor_po',
            'rejected' => 'Purchase Order Rejected - :nomor_po',
            'default' => 'Purchase Order Approval - :nomor_po',
        ],
        'title' => [
            'final_approved' => 'Purchase Order Has Been Approved',
            'step_approved' => 'Purchase Order Approval Update',
            'rejected' => 'Purchase Order Rejected',
            'default' => 'Purchase Order Approval',
        ],
        'description' => [
            'final_approved' => 'Your Purchase Order has received final approval from :actor_name.',
            'step_approved' => 'Your Purchase Order has been approved by :actor_name and is still awaiting further approval.',
            'rejected' => 'Your Purchase Order has been rejected by :actor_name.',
            'default' => 'There is a Purchase Order awaiting your approval.',
        ],
        'body_heading' => 'Purchase Order Approval',
        'field_no' => 'PO No.',
        'field_date' => 'PO Date',
        'field_total' => 'Total Amount',
        'field_status' => 'Status',
        'field_rejection_notes' => 'Rejection Notes',
        'instruction' => 'Please click the button below to open the Purchase Order page in SYOP v4.',
        'button' => 'Open Purchase Order',
    ],

    'pr' => [
        'subject' => [
            'step_approved' => 'Purchase Requisition Approval Step Approved - :nomor_pr',
            'final_approved' => 'Purchase Requisition Approved - :nomor_pr',
            'rejected' => 'Purchase Requisition Rejected - :nomor_pr',
            'default' => 'Purchase Requisition Approval Request - :nomor_pr',
        ],
        'title' => [
            'final_approved' => 'Purchase Requisition Has Been Approved',
            'step_approved' => 'Purchase Requisition Approval Update',
            'rejected' => 'Purchase Requisition Rejected',
            'default' => 'Purchase Requisition Approval',
        ],
        'description' => [
            'final_approved' => 'Your Purchase Requisition has received final approval from :actor_name.',
            'step_approved' => 'Your Purchase Requisition has been approved by :actor_name and is still awaiting further approval.',
            'rejected' => 'Your Purchase Requisition has been rejected by :actor_name.',
            'default' => 'There is a Purchase Requisition awaiting your approval.',
        ],
        'field_no' => 'PR No.',
        'field_date' => 'PR Date',
        'field_total' => 'Total Amount',
        'field_step' => 'Approval Step',
        'field_step_value' => 'Step :step_order',
        'field_status' => 'Status',
        'field_rejection_notes' => 'Rejection Notes',
        'instruction' => 'Please click the button below to open the Purchase Requisition page in SYOP v4.',
        'button' => 'Open Purchase Requisition',
    ],

    'greeting' => 'Dear',
    'footer_notice' => 'This email was sent automatically by the SYOP v4 system. Please do not reply to this email.',
    'footer_rights' => 'All Rights Reserved.',
];
