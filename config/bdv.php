<?php

use App\Models\CashAdvance;
use App\Models\Claim;

return [
    /*
    |--------------------------------------------------------------------------
    | Jenis dokumen yang boleh diangkat menjadi BDV
    |--------------------------------------------------------------------------
    | Untuk sekarang FPU dan Claim. Daftar ini sengaja dijadikan SATU sumber
    | kebenaran -- dipakai bersama oleh pilihan jenis dokumen di formulir,
    | penyaring di daftar, dan pencarian dokumen yang layak diangkat.
    |
    | Rencananya daftar ini kelak dipindahkan ke tabel supaya bisa diatur dari
    | layar Master tanpa menyentuh kode. Ketika saat itu tiba, yang berubah
    | hanya isi BdvSourceRegistry -- seluruh pemanggilnya tidak perlu diubah,
    | karena mereka sudah bertanya ke registry, bukan membaca config ini
    | langsung. Bentuk tiap barisnya pun sudah disusun menyerupai baris tabel.
    |
    | Arti tiap kunci:
    |
    |   label            nama yang dibaca manusia di layar
    |   model            model Eloquent dokumen sumbernya
    |   number_column    kolom nomor dokumen, dipakai mencari dan menampilkan
    |   eligible_status  status yang membuat dokumen layak diangkat
    |   sort_order       urutan tampil pada pilihan
    |
    | eligible_status untuk keduanya adalah RECEIVED, dan itu bukan kebetulan:
    | RECEIVED berarti dokumen sudah disetujui penuh DAN berkas fisiknya sudah
    | diterima Finance. Sebelum titik itu belum ada yang pantas dicairkan;
    | sesudah dicairkan (DISBURSED/PAID) sudah tidak perlu lagi.
    |--------------------------------------------------------------------------
    */
    'document_types' => [
        'FPU' => [
            'label' => 'FPU (Formulir Pengajuan Uang)',
            'model' => CashAdvance::class,
            'number_column' => 'advance_number',
            'eligible_status' => CashAdvance::STATUS_RECEIVED,
            'sort_order' => 1,
        ],

        'CLAIM' => [
            'label' => 'Claim',
            'model' => Claim::class,
            'number_column' => 'claim_number',
            'eligible_status' => Claim::STATUS_RECEIVED,
            'sort_order' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Banyaknya dokumen yang ditawarkan pada pencarian
    |--------------------------------------------------------------------------
    | Pilihan nomor dokumen memakai pencarian ketik, bukan daftar penuh:
    | dokumen yang layak diangkat bisa ratusan, dan menurunkan semuanya ke
    | layar hanya memperlambat tanpa membantu siapa pun menemukan nomornya.
    |--------------------------------------------------------------------------
    */
    'search_limit' => 30,

    /*
    |--------------------------------------------------------------------------
    | Jenis akun Accurate untuk kolom Kas / Bank
    |--------------------------------------------------------------------------
    | Kolom Kas/Bank tidak menawarkan seluruh bagan akun, hanya akun bertipe
    | kas dan bank -- voucher ini mencairkan uang, dan uang hanya keluar dari
    | akun semacam itu. Menawarkan seluruhnya membuat akun pendapatan dan
    | persediaan ikut muncul sebagai pilihan yang mustahil benar.
    |
    | Nilainya kode enum milik Accurate, bukan tulisan yang dibaca manusia.
    | Ditaruh di sini, bukan dituliskan di dalam kode, supaya bisa dikoreksi
    | tanpa menyentuh controller maupun halaman -- termasuk bila Accurate
    | memakai kode yang berbeda dari dugaan awal.
    |--------------------------------------------------------------------------
    */
    'cash_bank_account_type' => 'CASH_BANK',
];
