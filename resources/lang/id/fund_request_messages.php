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
];
