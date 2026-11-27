<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cakupan
    |--------------------------------------------------------------------------
    | Sumbu "area" (HO/Cabang) DICABUT 26 Nov 2026. Sebuah batas kini menyebut
    | cabang satu per satu, dan boleh menyebut beberapa sekaligus.
    |--------------------------------------------------------------------------
    */
    'scope' => [
        'all_branches' => 'Semua cabang',
        'all_departments' => 'Semua department',
        'fallback' => 'Berlaku umum',
    ],

    'not_found' => 'Batas pengajuan tidak ditemukan.',

    /*
    |--------------------------------------------------------------------------
    | Dua batas dengan cakupan sama persis membuat hasilnya bergantung urutan
    | baris -- sesuatu yang tidak bisa ditebak siapa pun dari layar.
    |--------------------------------------------------------------------------
    */
    'duplicate_scope' => 'Sudah ada batas lain dengan cakupan yang sama persis. Ubah pilihan cabang atau department-nya, atau sunting batas yang sudah ada.',

    'index' => [
        'forbidden' => 'Anda tidak memiliki akses untuk melihat master batas pengajuan.',
    ],

    'create' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menambah batas pengajuan.',
        'success' => 'Batas pengajuan berhasil ditambahkan.',
        'failed' => 'Batas pengajuan gagal ditambahkan.',
    ],

    'update' => [
        'forbidden' => 'Anda tidak memiliki akses untuk mengubah batas pengajuan.',
        'success' => 'Batas pengajuan berhasil diperbarui.',
        'failed' => 'Batas pengajuan gagal diperbarui.',
    ],

    'destroy' => [
        'forbidden' => 'Anda tidak memiliki akses untuk menghapus batas pengajuan.',
        'success' => 'Batas pengajuan berhasil dihapus.',
        'failed' => 'Batas pengajuan gagal dihapus.',

        /*
        | Batas umum adalah jaring pengaman terakhir. Tanpa itu, pemohon yang
        | tidak cocok ke batas khusus mana pun tidak terbatasi sama sekali --
        | dan seluruh aturan ini kehilangan gunanya.
        */
        'last_fallback' => 'Batas umum tidak bisa dihapus selama belum ada penggantinya. Tanpa batas umum, pemohon yang tidak cocok ke batas khusus mana pun tidak akan terbatasi sama sekali.',
    ],

];
