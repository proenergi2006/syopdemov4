<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nama hari
    |--------------------------------------------------------------------------
    | Penomoran ISO: 1 = Senin ... 7 = Minggu. Dipakai layar, email, dan
    | notifikasi, jadi semuanya menyebut hari yang sama dengan istilah yang
    | sama.
    |--------------------------------------------------------------------------
    */
    'days' => [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Minggu',
    ],

    'document_types' => [
        'FPU' => 'FPU',
        'REALISASI' => 'Realisasi FPU',
        'CLAIM' => 'Claim',
    ],

    /*
    |--------------------------------------------------------------------------
    | Kalimat satu batas setor
    |--------------------------------------------------------------------------
    | Ditulis utuh, bukan dirangkai dari potongan kata di sisi layar --
    | susunan kalimatnya berbeda antar bahasa.
    |--------------------------------------------------------------------------
    */
    'cutoff_sentence' => 'Diterima sampai :cutoff_day pukul :cutoff_time dibayar :payment_day :week',

    'week' => [
        'same' => 'minggu yang sama',
        'next' => 'minggu berikutnya',
        'after' => ':count minggu berikutnya',
    ],

    'scope' => [
        'all_modules' => 'Semua modul',
        'all_categories' => 'Semua keterangan transaksi',
        'fallback' => 'Berlaku umum',
    ],
    'and' => 'dan',

    'not_payment_day' => 'Pembayaran hanya bisa dilakukan pada hari :days. Hari ini bukan hari pembayaran, jadi dokumen ini belum bisa diproses.',

    'not_found' => 'Jadwal pembayaran tidak ditemukan.',

    /*
    |--------------------------------------------------------------------------
    | Dua jadwal dengan cakupan sama persis membuat hasilnya bergantung urutan
    | baris -- sesuatu yang tidak bisa ditebak siapa pun dari layar.
    |--------------------------------------------------------------------------
    */
    'duplicate_scope' => 'Sudah ada jadwal lain dengan cakupan yang sama persis. Ubah modul atau keterangan transaksinya, atau sunting jadwal yang sudah ada.',

    'index' => [
        'forbidden' => 'Anda tidak memiliki akses untuk melihat master jadwal pembayaran.',
    ],

    'create' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menambah jadwal pembayaran.',
        'success' => 'Jadwal pembayaran berhasil ditambahkan.',
        'failed' => 'Jadwal pembayaran gagal ditambahkan.',
    ],

    'update' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengubah jadwal pembayaran.',
        'success' => 'Jadwal pembayaran berhasil diperbarui.',
        'failed' => 'Jadwal pembayaran gagal diperbarui.',
    ],

    'destroy' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menghapus jadwal pembayaran.',
        'success' => 'Jadwal pembayaran berhasil dihapus.',
        'failed' => 'Jadwal pembayaran gagal dihapus.',

        /*
        | Jadwal umum adalah jaring pengaman terakhir. Tanpa itu, dokumen yang
        | tidak cocok ke jadwal khusus mana pun diterima tanpa tanggal bayar,
        | dan pemohonnya tidak diberi tahu apa-apa.
        */
        'last_fallback' => 'Jadwal umum tidak bisa dihapus selama belum ada penggantinya. Tanpa jadwal umum, dokumen yang tidak cocok ke jadwal khusus mana pun tidak akan punya tanggal pembayaran.',
    ],

];
