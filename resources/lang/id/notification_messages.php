<?php

return [
    'purchase_order' => [
        'approval_request' => [
            'title' => 'Approval Purchase Order',
            'message' => 'Purchase Order :nomor_po menunggu approval Anda.',
        ],
        'approval_step_pending' => [
            'title' => 'Tahap Approval PO Disetujui',
            'message' => 'Purchase Order :nomor_po telah disetujui oleh :approver_name dan masih menunggu approval berikutnya.',
        ],
        'approval_step_final' => [
            'title' => 'Purchase Order Disetujui',
            'message' => 'Purchase Order :nomor_po telah final disetujui oleh :approver_name.',
        ],
        'rejected' => [
            'title' => 'Purchase Order Ditolak',
            'message' => 'Purchase Order :nomor_po telah ditolak oleh :rejecter_name.',
        ],
    ],

    'purchase_request' => [
        'approval_request' => [
            'title' => 'Approval Purchase Requisition',
            'message_with_label' => 'Purchase Requisition :nomor_pr menunggu approval Anda pada tahap :step_order (:step_label).',
            'message_without_label' => 'Purchase Requisition :nomor_pr menunggu approval Anda pada tahap :step_order.',
        ],
        'approval_step_pending' => [
            'title' => 'Tahap Approval PR Disetujui',
            'message' => 'Purchase Requisition :nomor_pr telah disetujui oleh :approver_name pada tahap :step_order dan masih menunggu approval berikutnya.',
        ],
        'approval_step_final' => [
            'title' => 'Purchase Requisition Disetujui',
            'message' => 'Purchase Requisition :nomor_pr telah final disetujui oleh :approver_name.',
        ],
        'rejected' => [
            'title' => 'Purchase Requisition Ditolak',
            'message' => 'Purchase Requisition :nomor_pr telah ditolak oleh :rejecter_name.',
        ],
    ],
];
