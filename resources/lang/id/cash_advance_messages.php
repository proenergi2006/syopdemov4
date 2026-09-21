<?php

/*
|--------------------------------------------------------------------------
| Pesan API FPU (Form Pengajuan Uang)
|--------------------------------------------------------------------------
*/

return [
    'user_not_authenticated' => 'Sesi Anda telah berakhir. Silakan login kembali.',

    'index' => [
        'loaded' => 'Data FPU berhasil dimuat.',
        'load_failed' => 'Data FPU gagal dimuat.',
    ],

    'show' => [
        'loaded' => 'Detail FPU berhasil dimuat.',
        'not_found' => 'FPU tidak ditemukan atau Anda tidak memiliki akses.',
        'failed' => 'Detail FPU gagal dimuat.',
    ],

    'store' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membuat FPU.',
        'success' => 'FPU berhasil disimpan sebagai draft.',
        'invalid' => 'Data FPU tidak valid.',
        'failed' => 'FPU gagal disimpan.',
    ],

    'update' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengubah FPU.',
        'only_draft' => 'Hanya FPU berstatus draft yang dapat diubah.',
        'success' => 'FPU berhasil diperbarui.',
        'failed' => 'FPU gagal diperbarui.',
    ],

    'destroy' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menghapus FPU.',
        'only_draft' => 'Hanya FPU berstatus draft yang dapat dihapus.',
        'success' => 'FPU berhasil dihapus.',
        'failed' => 'FPU gagal dihapus.',
    ],

    'submit' => [
        'limit_too_many' => 'Anda sudah punya :count dari :max FPU yang masih berjalan. Realisasikan salah satunya sampai disetujui sebelum mengajukan yang baru.',
        'limit_overdue' => 'Ada FPU Anda yang sudah lewat :days hari dan belum direalisasi: :documents. Selesaikan realisasinya lebih dulu sebelum mengajukan yang baru.',
        'forbidden' => 'Anda tidak memiliki akses untuk mengajukan FPU.',
        'only_draft' => 'Hanya FPU berstatus draft yang dapat diajukan.',
        'items_unavailable' => 'FPU belum memiliki rincian pengajuan.',
        'signature_missing' => 'Anda belum memiliki tanda tangan digital.',
        'success' => 'FPU berhasil diajukan.',
        'failed' => 'FPU gagal diajukan.',
    ],

    'approve' => [
        'not_in_progress' => 'FPU tidak sedang dalam proses approval.',
        'final_success' => 'FPU berhasil disetujui secara final.',
        'step_success' => 'Tahap approval FPU berhasil disetujui.',
        'waiting_others' => 'Approval Anda tersimpan dan masih menunggu approver lain pada tahap yang sama.',
        'failed' => 'Approval FPU tidak dapat diproses.',
    ],

    'reject' => [
        'not_in_progress' => 'FPU tidak sedang dalam proses approval.',
        'success' => 'FPU berhasil ditolak.',
        'failed' => 'Penolakan FPU tidak dapat diproses.',
    ],

    'cancel' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membatalkan FPU.',
        'only_approved' => 'Hanya FPU berstatus approved yang dapat dibatalkan.',
        'has_realization' => 'FPU tidak dapat dibatalkan karena masih terikat Realisasi :realization_number yang berstatus :realization_status. Batalkan atau tolak realisasinya terlebih dahulu.',
        'success' => 'FPU berhasil dibatalkan.',
        'failed' => 'FPU gagal dibatalkan.',
    ],

    'receive' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menerima FPU.',
        'only_approved' => 'Hanya FPU berstatus approved yang dapat ditandai diterima.',
        'success' => 'FPU berhasil ditandai sudah diterima.',
        'failed' => 'Penerimaan FPU gagal diproses.',
    ],

    'disburse' => [
        'only_received' => 'Hanya FPU yang sudah diterima yang dapat dicairkan.',
        'forbidden' => 'Anda tidak memiliki akses untuk mencairkan FPU.',
        'only_approved' => 'Hanya FPU berstatus approved yang dapat dicairkan.',
        'success' => 'FPU berhasil ditandai sudah dibayarkan.',
        'failed' => 'Pencairan FPU gagal diproses.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Excel
    |--------------------------------------------------------------------------
    */
    'export' => [
        'forbidden' => 'Anda tidak memiliki akses untuk export data FPU.',
        'failed' => 'Export data FPU gagal diproses.',
        'filename' => 'FPU',
        'sheet_title' => 'FPU',
        'no_item' => '(tanpa rincian)',

        'columns' => [
            'no' => 'No',
            'advance_number' => 'Nomor FPU',
            'date' => 'Tanggal',
            'branch' => 'Cabang',
            'department' => 'Department',
            'transaction_category' => 'Keterangan Transaksi',
            'request_type' => 'Jenis Pengajuan',
            'subject' => 'Perihal',
            'item_date' => 'Tanggal Rincian',
            'item_description' => 'Deskripsi Rincian',
            'item_amount' => 'Nominal Rincian',
            'total_amount' => 'Total Pengajuan',
            'status' => 'Status',
            'realization_number' => 'Nomor Realisasi',
            'realization_status' => 'Status Realisasi',
            'created_by' => 'Dibuat Oleh',
        ],
    ],

    'items' => [
        'required' => 'Rincian pengajuan wajib diisi.',
        'description_required' => 'Deskripsi pada baris :row wajib diisi.',
        'date_required' => 'Tanggal pada baris :row wajib diisi.',
        'amount_required' => 'Nominal pada baris :row wajib diisi dan lebih besar dari 0.',
        'attachment_required' => 'Lampiran pada baris :row wajib diisi minimal satu berkas.',
    ],

    'print' => [
        'not_found' => 'FPU tidak ditemukan.',
        'not_printable' => 'FPU hanya dapat dicetak setelah disetujui.',
        'url_failed' => 'URL cetak FPU gagal dibuat.',
        'failed' => 'Cetakan FPU gagal dibuat.',
    ],

    'access_assignment' => [
        'branch_department_required' => 'Cabang dan department wajib dipilih.',
        'no_access_branch_department' => 'Anda tidak memiliki akses pada cabang dan department tersebut.',
        'no_access_create' => 'Anda tidak memiliki akses untuk membuat FPU pada cabang dan department tersebut.',
    ],

    /* Tautan ke Perdin; wajib bila keterangan transaksinya menuntutnya. */
    'business_trip' => [
        'required' => 'FPU dengan keterangan transaksi perjalanan dinas wajib menunjuk dokumen Perdin yang sudah disetujui.',
        'not_eligible' => 'Dokumen Perdin yang dipilih tidak dapat dipakai: pastikan Perdin tersebut milik Anda, sudah disetujui, dan belum dipakai FPU lain yang masih berjalan.',
        'item_date_outside_trip' => 'Baris :row: tanggalnya di luar periode Perjalanan Dinas :number (:from s/d :to). Biaya perjalanan tidak bisa bertanggal di luar perjalanannya.',
        'trip_not_approved' => 'FPU ini belum bisa dicairkan: Perjalanan Dinas :number masih menunggu persetujuan manajemen.',
        'trip_not_valid' => 'FPU ini tidak bisa dicairkan: Perjalanan Dinas :number ditolak atau dibatalkan. Batalkan FPU ini, atau ajukan ulang perdinnya.',
    ],
];
