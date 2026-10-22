<?php

/*
|--------------------------------------------------------------------------
| Pengajuan dana: kalimat bersama
|--------------------------------------------------------------------------
| Kalimat yang dipakai bersama FPU, Realisasi, dan Claim.
|
| Aturan revisi nominal berlaku sama di semua modul pengajuan dana, jadi
| kalimatnya pun tinggal di satu tempat. Menaruhnya di salah satu modul
| berarti modul yang lain memanggil kalimat milik tetangganya -- dan
| kalimat itu ikut berubah saat tetangganya disunting.
|--------------------------------------------------------------------------
*/
return [
    'revision' => [
        'forbidden' => 'Anda tidak memiliki akses untuk merevisi nominal. Nominal yang diajukan hanya bisa diubah oleh pemegang izin Revisi Nominal.',
        'notes_required' => 'Alasan revisi wajib diisi. Nominal yang sudah disetujui berjenjang tidak boleh berubah tanpa penjelasan yang bisa dibaca pemohon.',
        'unknown_item' => 'Ada baris rincian yang tidak dikenali pada dokumen ini. Silakan muat ulang halamannya lalu coba lagi.',
        'invalid_amount' => 'Nominal revisi harus berupa angka dan tidak boleh kurang dari nol.',
        'amount_label' => 'nominal revisi',
    ],

    /*
    | Finance menarik kembali penerimaan berkas.
    |
    | Jalan mundur untuk dokumen yang terlanjur diterima: tanpa ini, satu-
    | satunya pintu keluar adalah mencairkan uang yang tidak jadi dipakai.
    */
    'receipt_reversal' => [
        'forbidden' => 'Anda tidak memiliki akses untuk membatalkan penerimaan berkas.',
        'only_received' => 'Hanya dokumen berstatus diterima yang bisa ditarik kembali penerimaannya. Dokumen yang sudah dibayarkan tidak bisa lagi.',
        'notes_required' => 'Alasan pembatalan penerimaan wajib diisi. Tanggal pembayaran yang sudah dijanjikan ke pemohon ikut batal, dan ia berhak tahu kenapa.',
        'success' => 'Penerimaan berkas dibatalkan. Dokumen kembali ke status disetujui dan tanggal pembayarannya dibatalkan.',
        'failed' => 'Penerimaan berkas gagal dibatalkan. Silakan coba lagi.',
        'notes_label' => 'alasan pembatalan penerimaan',
    ],

    /*
    | Mengembalikan dokumen ke pemohon untuk direvisi.
    |
    | Berlaku sama di keempat modul, termasuk Perdin -- jadi kalimatnya di
    | sini, bukan di salah satu modulnya.
    */
    'return_for_revision' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengembalikan dokumen ini ke pemohon.',
        'not_active_approver' => 'Hanya approver yang sedang memegang dokumen ini yang bisa mengembalikannya ke pemohon.',
        'only_in_progress' => 'Hanya dokumen yang sedang dalam proses approval yang bisa dikembalikan ke pemohon.',
        'only_received' => 'Hanya dokumen yang berkasnya baru diterima yang bisa dikembalikan ke pemohon. Dokumen yang sudah dibayarkan atau sudah selesai tidak bisa lagi.',
        'reason_required' => 'Alasan pengembalian wajib diisi, minimal 3 karakter. Pemohon yang menerima dokumennya kembali tanpa penjelasan tidak punya cara menebak mana yang harus diperbaiki.',
        'already_open' => 'Dokumen ini sudah berada di tangan pemohon untuk direvisi.',
        'limit_reached' => 'Dokumen ini sudah direvisi :max kali, yaitu batas yang berlaku. Yang tersisa adalah menyetujui atau menolaknya.',
        'only_need_revision' => 'Hanya dokumen berstatus butuh revisi yang bisa diajukan ulang.',
        'editable_only' => 'Hanya dokumen berstatus draft atau butuh revisi yang bisa diubah.',
        'submittable_only' => 'Hanya dokumen berstatus draft atau butuh revisi yang bisa diajukan.',
        'not_requester' => 'Hanya pemohon dokumen ini yang bisa merevisi dan mengajukannya ulang.',
        'success' => 'Dokumen dikembalikan ke pemohon untuk direvisi. Pemohon sudah dikabari beserta alasannya.',
        'success_with_schedule' => 'Dokumen dikembalikan ke pemohon untuk direvisi. Penerimaan berkas ikut ditarik dan tanggal pembayaran :date dibatalkan.',
        'resubmit_success' => 'Dokumen revisi ke-:number berhasil diajukan ulang. Approval dimulai kembali dari tahap pertama.',
        'failed' => 'Dokumen gagal dikembalikan. Silakan coba lagi.',
        'reason_label' => 'alasan pengembalian',
    ],

    /*
    | Nama kolom pada daftar perubahan revisi.
    |
    | Yang membaca riwayat revisi adalah pemohon dan approver, bukan yang
    | menulis kodenya: "total_amount" di layar mereka hanya berarti sistemnya
    | bocor ke permukaan. Kolom yang belum punya nama di sini ditampilkan apa
    | adanya -- terbaca janggal, tetapi tidak pernah hilang.
    */
    'revision_fields' => [
        /* Bersama */
        'date' => 'Tanggal',
        'subject' => 'Perihal',
        'notes' => 'Catatan',
        'branch' => 'Cabang',
        'department_id' => 'Department',
        'transaction_category_id' => 'Keterangan transaksi',
        'business_trip_id' => 'Perjalanan dinas',
        'total_amount' => 'Total nominal',
        'request_type' => 'Jenis pengajuan',

        /* Rincian */
        'description' => 'Keterangan',
        'amount' => 'Nominal',
        'qty' => 'Jumlah',
        'unit_price' => 'Harga satuan',
        'expense_category_id' => 'Kategori biaya',

        /* Realisasi */
        'cash_advance_id' => 'FPU',
        'total_advance_amount' => 'Total FPU',
        'total_realization_amount' => 'Total realisasi',
        'difference_amount' => 'Selisih',
        'difference_type' => 'Jenis selisih',

        /* Lampiran */
        'filename' => 'Berkas',
        'original_filename' => 'Nama berkas',
        'attachment_type' => 'Jenis lampiran',
        'mime_type' => 'Tipe berkas',
        'file_size' => 'Ukuran berkas',
        'filepath' => 'Lokasi berkas',

        /* Perdin */
        'employee_name' => 'Nama pegawai',
        'position_name' => 'Jabatan',
        'department_name' => 'Nama department',
        'user_id' => 'Pegawai',
        'destination' => 'Tujuan',
        'depart_date' => 'Tanggal pergi',
        'depart_time' => 'Jam pergi',
        'return_date' => 'Tanggal kembali',
        'return_time' => 'Jam kembali',
        'purpose' => 'Maksud perjalanan',

        /* Itinerary */
        'sort_no' => 'Urutan',
        'time_start' => 'Jam mulai',
        'time_end' => 'Jam selesai',
        'timezone' => 'Zona waktu',
    ],

    'revision_relations' => [
        'items' => 'Rincian',
        'attachments' => 'Lampiran',
        'itineraries' => 'Itinerary',
    ],
];
