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

    /*
    | FPU -- Form Pengajuan Uang, modul Pengajuan Dana.
    */
    /*
    |--------------------------------------------------------------------------
    | Claim
    |--------------------------------------------------------------------------
    */
    'claim' => [
        'receipt_request' => [
            'title' => 'Claim Menunggu Diterima',
            'message' => 'Claim :claim_number senilai Rp :total_amount menunggu Anda terima.',
        ],

        'received' => [
            'title' => 'Claim Sudah Diterima',
            'message' => 'Claim :claim_number Anda sudah diterima oleh :receiver_name dan menunggu pembayaran.',
            'message_scheduled' => 'Claim :claim_number Anda sudah diterima oleh :receiver_name dan dijadwalkan dibayarkan pada :payment_date.',
        ],

        /*
        | Di luar approval flow: penerimanya pemegang permission pembayaran.
        */
        'payment_request' => [
            'title' => 'Claim Menunggu Pembayaran',
            'message' => 'Claim :claim_number sudah disetujui dan menunggu penggantian sebesar Rp :total_amount.',
        ],

        'paid' => [
            'title' => 'Claim Sudah Dibayarkan',
            'message' => 'Claim :claim_number Anda telah dibayarkan oleh :payer_name.',
        ],
        'approval_request' => [
            'title' => 'Permintaan Approval Claim',
            'message_with_label' => 'Claim :claim_number menunggu approval Anda pada tahap :step_order (:step_label).',
            'message_without_label' => 'Claim :claim_number menunggu approval Anda pada tahap :step_order.',
        ],

        'approval_step_pending' => [
            'title' => 'Tahap Approval Claim Disetujui',
            'message' => 'Claim :claim_number telah disetujui :approver_name pada tahap :step_order dan masih menunggu tahap berikutnya.',
        ],

        'approval_step_final' => [
            'title' => 'Claim Disetujui',
            'message' => 'Claim :claim_number telah disetujui secara final oleh :approver_name.',
        ],

        'rejected' => [
            'title' => 'Claim Ditolak',
            'message' => 'Claim :claim_number ditolak oleh :rejecter_name.',
        ],
    ],
    'cash_advance' => [
        /*
        | Di luar approval flow: penerimanya pemegang permission pencairan,
        | bukan approver dokumen ini.
        */
        'receipt_request' => [
            'title' => 'FPU Menunggu Diterima',
            'message' => 'FPU :advance_number senilai Rp :total_amount menunggu Anda terima.',
        ],

        'received' => [
            'title' => 'FPU Sudah Diterima',
            'message' => 'FPU :advance_number sudah diterima oleh :receiver_name dan menunggu pencairan.',
            'message_scheduled' => 'FPU :advance_number sudah diterima oleh :receiver_name dan dijadwalkan dibayarkan pada :payment_date.',
        ],

        'disbursement_request' => [
            'title' => 'FPU Menunggu Pencairan',
            'message' => 'FPU :advance_number sudah disetujui dan menunggu pencairan sebesar Rp :total_amount.',
        ],
        'approval_request' => [
            'title' => 'Approval FPU',
            'message_with_label' => 'FPU :advance_number menunggu approval Anda pada tahap :step_order (:step_label).',
            'message_without_label' => 'FPU :advance_number menunggu approval Anda pada tahap :step_order.',
        ],
        'approval_step_pending' => [
            'title' => 'Tahap Approval FPU Disetujui',
            'message' => 'FPU :advance_number telah disetujui oleh :approver_name pada tahap :step_order dan masih menunggu approval berikutnya.',
        ],
        'approval_step_final' => [
            'title' => 'FPU Disetujui',
            'message' => 'FPU :advance_number telah final disetujui oleh :approver_name.',
        ],
        'rejected' => [
            'title' => 'FPU Ditolak',
            'message' => 'FPU :advance_number telah ditolak oleh :rejecter_name.',
        ],
        'disbursed' => [
            'title' => 'FPU Sudah Dibayarkan',
            'message' => 'FPU :advance_number telah dibayarkan oleh :disburser_name.',
        ],
    ],

    /*
    | Realisasi FPU -- pertanggungjawaban atas uang yang sudah dicairkan.
    */
    'cash_advance_realization' => [
        'receipt_request' => [
            'title' => 'Realisasi FPU Menunggu Diterima',
            'message' => 'Realisasi FPU :realization_number senilai Rp :total_amount menunggu Anda terima.',
        ],

        'received' => [
            'title' => 'Realisasi FPU Sudah Diterima',
            'message' => 'Realisasi FPU :realization_number Anda sudah diterima oleh :receiver_name.',
            'message_scheduled' => 'Realisasi FPU :realization_number Anda sudah diterima oleh :receiver_name dan kekurangannya dijadwalkan dibayarkan pada :payment_date.',
        ],

        /*
        | Di luar approval flow: penerimanya pemegang permission penyelesaian
        | selisih, dipilih mengikuti arah uangnya.
        */
        'return_request' => [
            'title' => 'Selisih Realisasi Menunggu Pengembalian',
            'message' => 'Realisasi :realization_number sudah disetujui dan menyisakan dana Rp :difference_amount yang perlu dikembalikan pemohon.',
        ],

        'reimburse_request' => [
            'title' => 'Kekurangan Realisasi Menunggu Pembayaran',
            'message' => 'Realisasi :realization_number sudah disetujui dan kekurangannya sebesar Rp :difference_amount perlu dibayarkan kepada pemohon.',
        ],
        'approval_request' => [
            'title' => 'Approval Realisasi FPU',
            'message_with_label' => 'Realisasi FPU :realization_number menunggu approval Anda pada tahap :step_order (:step_label).',
            'message_without_label' => 'Realisasi FPU :realization_number menunggu approval Anda pada tahap :step_order.',
        ],
        'approval_step_pending' => [
            'title' => 'Tahap Approval Realisasi FPU Disetujui',
            'message' => 'Realisasi FPU :realization_number telah disetujui oleh :approver_name pada tahap :step_order dan masih menunggu approval berikutnya.',
        ],
        'approval_step_final' => [
            'title' => 'Realisasi FPU Disetujui',
            'message' => 'Realisasi FPU :realization_number telah final disetujui oleh :approver_name.',
        ],
        'rejected' => [
            'title' => 'Realisasi FPU Ditolak',
            'message' => 'Realisasi FPU :realization_number telah ditolak oleh :rejecter_name.',
        ],
        'settled' => [
            'title' => 'Selisih Realisasi FPU Selesai',
            'message' => 'Selisih Realisasi FPU :realization_number telah diselesaikan oleh :settler_name.',
        ],
    ],
    'business_trip' => [
        'approval_request' => [
            'title' => 'Perdin Menunggu Persetujuan',
            'message_with_label' => 'Perdin :trip_number menunggu persetujuan Anda pada tahap :step_order (:step_label).',
            'message_without_label' => 'Perdin :trip_number menunggu persetujuan Anda pada tahap :step_order.',
        ],

        'approval_step_pending' => [
            'title' => 'Satu Tahap Perdin Disetujui',
            'message' => 'Perdin :trip_number disetujui :approver_name pada tahap :step_order. Menunggu tahap berikutnya.',
        ],

        'approval_step_final' => [
            'title' => 'Perdin Disetujui',
            'message' => 'Perdin :trip_number telah disetujui seluruhnya oleh :approver_name. Anda dapat melanjutkan mengajukan FPU.',
        ],

        'rejected' => [
            'title' => 'Perdin Ditolak',
            'message' => 'Perdin :trip_number ditolak oleh :approver_name. Catatan: :notes',
        ],
    ],
];
