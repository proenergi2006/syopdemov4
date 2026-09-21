<?php

/*
|--------------------------------------------------------------------------
| Pesan API Claim
|--------------------------------------------------------------------------
| Baru memuat tahap CRUD. Kunci untuk submit, approval, pembayaran, export,
| dan cetak menyusul bersama tahapnya.
|--------------------------------------------------------------------------
*/

return [
    'user_not_authenticated' => 'Sesi Anda telah berakhir. Silakan login kembali.',

    'index' => [
        'loaded' => 'Data Claim berhasil dimuat.',
        'load_failed' => 'Data Claim gagal dimuat.',
    ],

    'show' => [
        'loaded' => 'Detail Claim berhasil dimuat.',
        'not_found' => 'Claim tidak ditemukan atau Anda tidak memiliki akses.',
        'failed' => 'Detail Claim gagal dimuat.',
    ],

    'store' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membuat Claim.',
        'success' => 'Claim berhasil disimpan sebagai draft.',
        'invalid' => 'Data Claim tidak valid.',
        'failed' => 'Claim gagal disimpan.',
    ],

    'update' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengubah Claim.',
        'only_draft' => 'Hanya Claim berstatus draft yang dapat diubah.',
        'success' => 'Claim berhasil diperbarui.',
        'failed' => 'Claim gagal diperbarui.',
    ],

    'destroy' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menghapus Claim.',
        'only_draft' => 'Hanya Claim berstatus draft yang dapat dihapus.',
        'success' => 'Claim berhasil dihapus.',
        'failed' => 'Claim gagal dihapus.',
    ],

    'submit' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengajukan Claim.',
        'only_draft' => 'Hanya Claim berstatus draft yang dapat diajukan.',
        'items_unavailable' => 'Claim belum memiliki rincian pengeluaran.',
        'signature_missing' => 'Anda belum memiliki tanda tangan digital.',
        'success' => 'Claim berhasil diajukan.',
        'failed' => 'Claim gagal diajukan.',
    ],

    'approve' => [
        'not_in_progress' => 'Claim tidak sedang dalam proses approval.',
        'final_success' => 'Claim berhasil disetujui secara final.',
        'step_success' => 'Tahap approval Claim berhasil disetujui.',
        'waiting_others' => 'Approval Anda tersimpan dan masih menunggu approver lain pada tahap yang sama.',
        'failed' => 'Approval Claim tidak dapat diproses.',
    ],

    'reject' => [
        'not_in_progress' => 'Claim tidak sedang dalam proses approval.',
        'success' => 'Claim berhasil ditolak.',
        'failed' => 'Penolakan Claim tidak dapat diproses.',
    ],

    /*
    | Penerimaan mendahului pembayaran: berkasnya diterima dulu, baru
    | uangnya dibayarkan.
    */
    'receive' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menerima Claim.',
        'only_approved' => 'Hanya Claim berstatus approved yang dapat ditandai diterima.',
        'success' => 'Claim berhasil ditandai sudah diterima.',
        'failed' => 'Penerimaan Claim gagal diproses.',
    ],

    'pay' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menandai Claim sudah dibayarkan.',
        'only_received' => 'Hanya Claim yang sudah diterima yang dapat dibayarkan.',
        'only_approved' => 'Hanya Claim berstatus approved yang dapat dibayarkan.',
        'success' => 'Claim berhasil ditandai sudah dibayarkan.',
        'failed' => 'Pembayaran Claim gagal diproses.',
    ],
    'cancel' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membatalkan Claim.',
        'only_approved' => 'Hanya Claim berstatus approved yang dapat dibatalkan.',
        'success' => 'Claim berhasil dibatalkan.',
        'failed' => 'Claim gagal dibatalkan.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Excel
    |--------------------------------------------------------------------------
    */
    'export' => [
        'forbidden' => 'Anda tidak memiliki akses untuk export data Claim.',
        'failed' => 'Export data Claim gagal diproses.',
        'filename' => 'Claim',
        'sheet_title' => 'Claim',
        'no_item' => '(tanpa rincian)',

        'columns' => [
            'no' => 'No',
            'claim_number' => 'Nomor Claim',
            'date' => 'Tanggal',
            'branch' => 'Cabang',
            'department' => 'Department',
            'transaction_category' => 'Keterangan Transaksi',
            'subject' => 'Perihal',
            'item_date' => 'Tanggal Rincian',
            'item_description' => 'Deskripsi Rincian',
            'item_amount' => 'Nominal Rincian',
            'total_amount' => 'Total Claim',
            'status' => 'Status',
            'created_by' => 'Dibuat Oleh',
        ],
    ],

    'print' => [
        'not_found' => 'Claim tidak ditemukan.',
        'not_printable' => 'Claim hanya dapat dicetak setelah disetujui.',
        'url_failed' => 'URL cetak Claim gagal dibuat.',
        'failed' => 'Cetakan Claim gagal dibuat.',
    ],
    'items' => [
        'required' => 'Rincian pengeluaran wajib diisi.',
        'description_required' => 'Deskripsi pada baris :row wajib diisi.',
        'date_required' => 'Tanggal pada baris :row wajib diisi.',
        'amount_required' => 'Nominal pada baris :row wajib diisi dan lebih besar dari 0.',
        'attachment_required' => 'Bukti pada baris :row wajib diisi minimal satu berkas.',
    ],

    'access_assignment' => [
        'branch_department_required' => 'Cabang dan department wajib dipilih.',
        'no_access_branch_department' => 'Anda tidak memiliki akses pada cabang dan department tersebut.',
        'no_access_create' => 'Anda tidak memiliki akses untuk membuat Claim pada cabang dan department tersebut.',
    ],
];
