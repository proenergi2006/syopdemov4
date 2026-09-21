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

    'claim' => [
        'subject' => [
            'final_approved' => 'Claim Disetujui - :claim_number',
            'rejected' => 'Claim Ditolak - :claim_number',
            'paid' => 'Claim Sudah Dibayarkan - :claim_number',
            'received' => 'Claim Sudah Diterima - :claim_number',
            'receipt_request' => 'Claim Menunggu Diterima - :claim_number',
            'payment_request' => 'Claim Menunggu Pembayaran - :claim_number',

            'default' => 'Permintaan Approval Claim - :claim_number',
        ],
        'title' => [
            'final_approved' => 'Claim Telah Disetujui',
            'rejected' => 'Claim Ditolak',
            'paid' => 'Claim Sudah Dibayarkan',
            'received' => 'Claim Sudah Diterima',
            'receipt_request' => 'Claim Menunggu Diterima',
            'payment_request' => 'Claim Menunggu Pembayaran',

            'default' => 'Approval Claim',
        ],
        'description' => [
            'final_approved' => 'Claim Anda telah mendapatkan final approval oleh :actor_name.',
            'rejected' => 'Claim Anda telah ditolak oleh :actor_name.',
            'paid' => 'Claim Anda telah dibayarkan oleh :actor_name.',
            'received' => 'Claim Anda sudah diterima oleh :actor_name dan menunggu pembayaran.',
            'receipt_request' => 'Terdapat Claim yang sudah disetujui dan menunggu Anda terima.',
            'payment_request' => 'Terdapat Claim yang sudah disetujui dan menunggu pembayaran dari Anda.',

            'default' => 'Terdapat Claim yang membutuhkan approval Anda.',
        ],
        'field_no' => 'No. Claim',
        'field_date' => 'Tanggal Claim',
        'field_payment_date' => 'Jadwal Pembayaran',
        'field_category' => 'Keterangan Transaksi',
        'field_subject' => 'Perihal',
        'field_total' => 'Total Claim',
        'field_step' => 'Tahap Approval',
        'field_step_value' => 'Tahap :step_order',
        'field_status' => 'Status',
        'field_notes' => 'Catatan',
        'instruction' => 'Silakan klik tombol berikut untuk membuka halaman Claim di SYOP v4.',
        'button' => 'Buka Claim',
    ],
    'fpu' => [
        'subject' => [
            'final_approved' => 'FPU Disetujui - :advance_number',
            'rejected' => 'FPU Ditolak - :advance_number',
            'disbursed' => 'FPU Sudah Dibayarkan - :advance_number',
            'received' => 'FPU Sudah Diterima - :advance_number',
            'receipt_request' => 'FPU Menunggu Diterima - :advance_number',
            'disbursement_request' => 'FPU Menunggu Pencairan - :advance_number',

            'default' => 'Permintaan Approval FPU - :advance_number',
        ],
        'title' => [
            'final_approved' => 'FPU Telah Disetujui',
            'rejected' => 'FPU Ditolak',
            'disbursed' => 'FPU Sudah Dibayarkan',
            'received' => 'FPU Sudah Diterima',
            'receipt_request' => 'FPU Menunggu Diterima',
            'disbursement_request' => 'FPU Menunggu Pencairan',

            'default' => 'Approval FPU',
        ],
        'description' => [
            'final_approved' => 'FPU Anda telah mendapatkan final approval oleh :actor_name.',
            'rejected' => 'FPU Anda telah ditolak oleh :actor_name.',
            'disbursed' => 'Dana FPU Anda telah dibayarkan oleh :actor_name.',
            'received' => 'FPU Anda sudah diterima oleh :actor_name dan menunggu pencairan.',
            'receipt_request' => 'Terdapat FPU yang sudah disetujui dan menunggu Anda terima.',
            'disbursement_request' => 'Terdapat FPU yang sudah disetujui dan menunggu pencairan dari Anda.',

            'default' => 'Terdapat FPU yang membutuhkan approval Anda.',
        ],
        'field_no' => 'No. FPU',
        'field_date' => 'Tanggal FPU',
        'field_payment_date' => 'Jadwal Pembayaran',
        'field_category' => 'Keterangan Transaksi',
        'field_subject' => 'Perihal',
        'field_total' => 'Total Pengajuan',
        'field_step' => 'Tahap Approval',
        'field_step_value' => 'Tahap :step_order',
        'field_status' => 'Status',
        'field_notes' => 'Catatan',
        'instruction' => 'Silakan klik tombol berikut untuk membuka halaman FPU di SYOP v4.',
        'button' => 'Buka FPU',
    ],

    'realisasi' => [
        'subject' => [
            'final_approved' => 'Realisasi FPU Disetujui - :realization_number',
            'rejected' => 'Realisasi FPU Ditolak - :realization_number',
            'settled' => 'Selisih Realisasi FPU Selesai - :realization_number',
            'received' => 'Realisasi FPU Sudah Diterima - :realization_number',
            'receipt_request' => 'Realisasi FPU Menunggu Diterima - :realization_number',
            'return_request' => 'Selisih Realisasi Menunggu Pengembalian - :realization_number',
            'reimburse_request' => 'Kekurangan Realisasi Menunggu Pembayaran - :realization_number',

            'default' => 'Permintaan Approval Realisasi FPU - :realization_number',
        ],
        'title' => [
            'final_approved' => 'Realisasi FPU Telah Disetujui',
            'rejected' => 'Realisasi FPU Ditolak',
            'settled' => 'Selisih Realisasi FPU Telah Diselesaikan',
            'received' => 'Realisasi FPU Sudah Diterima',
            'receipt_request' => 'Realisasi FPU Menunggu Diterima',
            'return_request' => 'Selisih Realisasi Menunggu Pengembalian',
            'reimburse_request' => 'Kekurangan Realisasi Menunggu Pembayaran',

            'default' => 'Approval Realisasi FPU',
        ],
        'description' => [
            'final_approved' => 'Realisasi FPU Anda telah mendapatkan final approval oleh :actor_name.',
            'rejected' => 'Realisasi FPU Anda telah ditolak oleh :actor_name.',
            'settled' => 'Selisih Realisasi FPU Anda telah diselesaikan oleh :actor_name.',
            'received' => 'Realisasi FPU Anda sudah diterima oleh :actor_name.',
            'receipt_request' => 'Terdapat Realisasi FPU yang sudah disetujui dan menunggu Anda terima.',
            'return_request' => 'Realisasi berikut sudah disetujui dan menyisakan dana yang perlu dikembalikan pemohon ke Finance.',
            'reimburse_request' => 'Realisasi berikut sudah disetujui dan kekurangannya perlu dibayarkan kepada pemohon.',

            'default' => 'Terdapat Realisasi FPU yang membutuhkan approval Anda.',
        ],
        'field_no' => 'No. Realisasi',
        'field_advance_no' => 'No. FPU',
        'field_date' => 'Tanggal Realisasi',
        'field_payment_date' => 'Jadwal Pembayaran',
        'field_category' => 'Keterangan Transaksi',
        'field_subject' => 'Perihal',
        'field_total_advance' => 'Nilai Dicairkan',
        'field_total_realization' => 'Total Realisasi',
        'field_difference' => 'Selisih',
        'difference_return' => 'Sisa Dikembalikan',
        'difference_reimburse' => 'Kekurangan Dibayarkan',
        'difference_none' => 'Tidak Ada Selisih',
        'field_step' => 'Tahap Approval',
        'field_step_value' => 'Tahap :step_order',
        'field_status' => 'Status',
        'field_notes' => 'Catatan',
        'instruction' => 'Silakan klik tombol berikut untuk membuka halaman Realisasi FPU di SYOP v4.',
        'button' => 'Buka Realisasi FPU',
    ],

    'greeting' => 'Dear',
    'footer_notice' => 'Email ini dikirim otomatis oleh sistem SYOP v4. Mohon tidak membalas email ini.',
    'footer_rights' => 'All Rights Reserved.',
    /*
    | Perdin -- izin perjalanan dinas, mendahului FPU yang membiayainya.
    | Hanya tiga peristiwa: diminta, disetujui, ditolak. Tidak ada tahap
    | pembayaran, karena yang disetujui perjalanannya, bukan uangnya.
    */
    'business_trip' => [
        'subject' => [
            'default' => 'Persetujuan Perjalanan Dinas :trip_number',
            'final_approved' => 'Perjalanan Dinas :trip_number telah disetujui',
            'rejected' => 'Perjalanan Dinas :trip_number ditolak',
        ],

        'title' => [
            'default' => 'Permintaan Persetujuan Perdin',
            'final_approved' => 'Perdin Disetujui',
            'rejected' => 'Perdin Ditolak',
        ],

        'description' => [
            'default' => 'Ada pengajuan perjalanan dinas yang menunggu persetujuan Anda. Mohon diperiksa rencana perjalanannya di bawah ini.',
            'final_approved' => 'Pengajuan perjalanan dinas Anda telah disetujui seluruhnya oleh :actor_name. Anda dapat melanjutkan dengan mengajukan FPU untuk perjalanan ini.',
            'rejected' => 'Pengajuan perjalanan dinas Anda ditolak oleh :actor_name. Silakan periksa catatannya, perbaiki, lalu ajukan kembali.',
        ],

        'field_no' => 'Nomor Perdin',
        'field_employee' => 'Nama Karyawan',
        'field_destination' => 'Tujuan',
        'field_period' => 'Periode Perjalanan',
        'field_purpose' => 'Keperluan',
        'field_step' => 'Tahap Persetujuan',
        'field_step_value' => 'Tahap :step_order',
        'field_status' => 'Status',
        'field_notes' => 'Catatan',

        'itinerary_title' => 'Daftar Perjalanan (Itinerary)',
        'itinerary_date' => 'Hari & Tanggal',
        'itinerary_time' => 'Jam',
        'itinerary_description' => 'Keterangan',

        'instruction' => 'Klik tombol di bawah untuk membuka dokumennya di aplikasi.',
        'button' => 'Buka Perdin',
    ],

];
