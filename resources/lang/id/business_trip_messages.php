<?php

/*
|--------------------------------------------------------------------------
| Pesan API Perjalanan Dinas
|--------------------------------------------------------------------------
| Baru memuat tahap CRUD. Kunci untuk pengajuan, persetujuan, dan tautan ke
| FPU menyusul bersama tahapnya.
|--------------------------------------------------------------------------
*/

return [
    'user_not_authenticated' => 'Sesi Anda telah berakhir. Silakan login kembali.',
    'list_loaded' => 'Data Perjalanan Dinas berhasil dimuat.',
    'list_failed' => 'Data Perjalanan Dinas gagal dimuat.',
    'detail_loaded' => 'Rincian Perjalanan Dinas berhasil dimuat.',
    'options_loaded' => 'Data pendukung formulir berhasil dimuat.',
    'created' => 'Perjalanan Dinas berhasil dibuat.',
    'create_failed' => 'Perjalanan Dinas gagal dibuat.',
    'updated' => 'Perjalanan Dinas berhasil diperbarui.',
    'update_failed' => 'Perjalanan Dinas gagal diperbarui.',
    'deleted' => 'Perjalanan Dinas berhasil dihapus.',
    'delete_failed' => 'Perjalanan Dinas gagal dihapus.',
    'not_found' => 'Perjalanan Dinas tidak ditemukan.',
    'forbidden_view' => 'Anda tidak memiliki akses untuk melihat Perjalanan Dinas.',
    'forbidden_create' => 'Anda tidak memiliki akses untuk membuat Perjalanan Dinas.',
    'forbidden_update' => 'Anda tidak memiliki akses untuk mengubah Perjalanan Dinas.',
    'forbidden_delete' => 'Anda tidak memiliki akses untuk menghapus Perjalanan Dinas.',

    /* Alur dokumen: ajukan, setujui, tolak, batalkan. */
    'not_editable' => 'Perjalanan Dinas ini sudah diajukan dan tidak dapat diubah lagi.',
    'delete_only_draft' => 'Hanya Perjalanan Dinas berstatus draft yang dapat dihapus.',
    'forbidden_submit' => 'Anda tidak memiliki akses untuk mengajukan Perjalanan Dinas.',
    'forbidden_cancel' => 'Anda tidak memiliki akses untuk membatalkan Perjalanan Dinas.',
    'submit_only_draft' => 'Hanya Perjalanan Dinas berstatus draft atau ditolak yang dapat diajukan.',
    'submit_itinerary_missing' => 'Isi dulu rundown perjalanannya sebelum mengajukan.',
    'submit_signature_missing' => 'Anda belum memiliki tanda tangan digital. Unggah dulu pada profil Anda.',
    'submitted' => 'Perjalanan Dinas berhasil diajukan.',
    'submit_failed' => 'Perjalanan Dinas gagal diajukan.',
    'approve_not_in_progress' => 'Perjalanan Dinas ini tidak sedang dalam proses persetujuan.',
    'approved' => 'Perjalanan Dinas berhasil disetujui.',
    'approve_failed' => 'Perjalanan Dinas gagal disetujui.',
    'rejected' => 'Perjalanan Dinas berhasil ditolak.',
    'reject_failed' => 'Perjalanan Dinas gagal ditolak.',
    'cancel_not_allowed' => 'Hanya Perjalanan Dinas berstatus draft atau sedang berjalan yang dapat dibatalkan.',
    'cancelled' => 'Perjalanan Dinas berhasil dibatalkan.',
    'cancel_failed' => 'Perjalanan Dinas gagal dibatalkan.',

    /* Cetak: hanya dokumen yang sudah disetujui, dan hanya bagi pemegangnya. */
    'forbidden_print' => 'Anda tidak memiliki akses untuk mencetak Perjalanan Dinas.',
    'print_not_approved' => 'Perjalanan Dinas hanya dapat dicetak setelah disetujui seluruhnya.',
    'print_failed' => 'Cetakan Perjalanan Dinas gagal dibuat.',
    'export' => [
        'forbidden' => 'Anda tidak memiliki akses untuk export data Perjalanan Dinas.',
        'failed' => 'Export data Perjalanan Dinas gagal diproses.',
        'filename' => 'Perjalanan_Dinas',
        'sheet_title' => 'Perjalanan Dinas',

        'columns' => [
            'no' => 'No',
            'trip_number' => 'Nomor Perdin',
            'date' => 'Tanggal Dokumen',
            'branch' => 'Cabang',
            'department' => 'Department',
            'employee_name' => 'Nama Karyawan',
            'position' => 'Jabatan',
            'destination' => 'Tujuan',
            'depart' => 'Berangkat',
            'return' => 'Kembali',
            'duration' => 'Lama (hari)',
            'purpose' => 'Keperluan',
            'trip_status' => 'Status Perdin',
            'cash_advance_number' => 'Nomor FPU',
            'cash_advance_date' => 'Tanggal FPU',
            'cash_advance_amount' => 'Biaya FPU',
            'cash_advance_status' => 'Status FPU',
        ],
    ],
];
