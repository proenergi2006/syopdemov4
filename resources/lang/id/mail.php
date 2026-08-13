<?php

return [
    'po' => [
        'subject' => [
            'final_approved' => 'Purchase Order Disetujui - :nomor_po',
            'step_approved' => 'Update Approval Purchase Order - :nomor_po',
            'rejected' => 'Purchase Order Ditolak - :nomor_po',
            'default' => 'Approval Purchase Order - :nomor_po',
        ],
        'title' => [
            'final_approved' => 'Purchase Order Telah Disetujui',
            'step_approved' => 'Update Approval Purchase Order',
            'rejected' => 'Purchase Order Ditolak',
            'default' => 'Approval Purchase Order',
        ],
        'description' => [
            'final_approved' => 'Purchase Order Anda telah mendapatkan final approval oleh :actor_name.',
            'step_approved' => 'Purchase Order Anda telah disetujui oleh :actor_name dan masih menunggu approval berikutnya.',
            'rejected' => 'Purchase Order Anda telah ditolak oleh :actor_name.',
            'default' => 'Terdapat Purchase Order yang membutuhkan approval Anda.',
        ],
        'body_heading' => 'Approval Purchase Order',
        'field_no' => 'No. PO',
        'field_date' => 'Tanggal PO',
        'field_total' => 'Total Nilai',
        'field_status' => 'Status',
        'field_rejection_notes' => 'Catatan Penolakan',
        'instruction' => 'Silakan klik tombol berikut untuk membuka halaman Purchase Order di SYOP v4.',
        'button' => 'Buka Purchase Order',
    ],

    'pr' => [
        'subject' => [
            'step_approved' => 'Tahap Approval Purchase Requisition Disetujui - :nomor_pr',
            'final_approved' => 'Purchase Requisition Disetujui - :nomor_pr',
            'rejected' => 'Purchase Requisition Ditolak - :nomor_pr',
            'default' => 'Permintaan Approval Purchase Requisition - :nomor_pr',
        ],
        'title' => [
            'final_approved' => 'Purchase Requisition Telah Disetujui',
            'step_approved' => 'Update Approval Purchase Requisition',
            'rejected' => 'Purchase Requisition Ditolak',
            'default' => 'Approval Purchase Requisition',
        ],
        'description' => [
            'final_approved' => 'Purchase Requisition Anda telah mendapatkan final approval oleh :actor_name.',
            'step_approved' => 'Purchase Requisition Anda telah disetujui oleh :actor_name dan masih menunggu approval berikutnya.',
            'rejected' => 'Purchase Requisition Anda telah ditolak oleh :actor_name.',
            'default' => 'Terdapat Purchase Requisition yang membutuhkan approval Anda.',
        ],
        'field_no' => 'No. PR',
        'field_date' => 'Tanggal PR',
        'field_total' => 'Total Nilai',
        'field_step' => 'Tahap Approval',
        'field_step_value' => 'Tahap :step_order',
        'field_status' => 'Status',
        'field_rejection_notes' => 'Catatan Penolakan',
        'instruction' => 'Silakan klik tombol berikut untuk membuka halaman Purchase Requisition di SYOP v4.',
        'button' => 'Buka Purchase Requisition',
    ],

    'greeting' => 'Dear',
    'footer_notice' => 'Email ini dikirim otomatis oleh sistem SYOP v4. Mohon tidak membalas email ini.',
    'footer_rights' => 'All Rights Reserved.',
];
