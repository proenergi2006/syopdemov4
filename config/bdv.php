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
    |   label                nama yang dibaca manusia di layar
    |   model                model Eloquent dokumen sumbernya
    |   number_column        kolom nomor dokumen, dipakai mencari dan menampilkan
    |   eligible_status      status yang membuat dokumen layak diangkat
    |   item_attachment_key  kolom pada tabel lampiran yang menunjuk baris rincian
    |   sort_order           urutan tampil pada pilihan
    |
    | item_attachment_key berbeda antar dokumen -- claim_attachments memakai
    | claim_item_id, cash_advance_attachments memakai cash_advance_item_id --
    | dan disebutkan di sini alih-alih ditebak dari nama modelnya. Nama kolom
    | yang ditebak adalah nama kolom yang diam-diam salah: lampirannya tidak
    | muncul, dan tidak ada satu pun galat yang memberi tahu kenapa.
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
            'item_attachment_key' => 'cash_advance_item_id',
            'sort_order' => 1,
        ],

        'CLAIM' => [
            'label' => 'Claim',
            'model' => Claim::class,
            'number_column' => 'claim_number',
            'eligible_status' => Claim::STATUS_RECEIVED,
            'item_attachment_key' => 'claim_item_id',
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

    /*
    |--------------------------------------------------------------------------
    | Pencairan ditahan sampai vouchernya terbit
    |--------------------------------------------------------------------------
    | true: FPU tidak bisa ditandai "dicairkan" dan Claim tidak bisa ditandai
    | "dibayarkan" sebelum ada BDV yang mengangkatnya.
    |
    | Aturannya ditegakkan App\Support\BdvDisbursementGuard, dan alasan
    | lengkapnya ada di sana. Yang perlu diketahui di tempat ini hanya satu
    | hal: aturan ini menahan SELURUH pencairan di perusahaan. Bila BDV
    | ternyata belum dipakai Finance sehari-hari, menyalakannya berarti
    | menghentikan pembayaran sampai ada yang menerbitkan vouchernya.
    |
    | Karena itu tombol matinya diletakkan di sini -- bisa dicapai tanpa
    | menunggu rilis kode, dan tanpa menyentuh dua controller.
    |--------------------------------------------------------------------------
    */
    'require_voucher_before_disbursement' => true,

    /*
    |--------------------------------------------------------------------------
    | Mengirim bukti ke Accurate
    |--------------------------------------------------------------------------
    | Bukti yang menempel pada tiap baris rincian ikut diunggah ke dokumen
    | Other Payment di Accurate lewat attachment/attach.do. Berkasnya dikirim
    | sebagai Base64 di dalam body JSON, bukan sebagai multipart upload.
    |
    | Ketiga batas di bawah ADALAH BATAS ACCURATE, bukan batas kita. Ditulis
    | di sini supaya bisa diikuti bila Accurate mengubahnya, dan supaya
    | berkas yang pasti ditolak bisa disaring lebih dulu -- berkas 20 MB yang
    | dikirim hanya untuk ditolak membuang waktu orang yang menunggu, dan
    | menghabiskan jatah permintaan per detik untuk sesuatu yang sia-sia.
    |
    |   max_file_size      10 MB per berkas
    |   max_files          30 berkas per transaksi
    |   allowed_extensions jenis yang diterima Accurate
    |
    | transaction_type 'PAY' adalah kode Accurate untuk Pembayaran (other
    | payment) -- jenis dokumen yang dipakai BDV. Dinyatakan di sini, bukan
    | ditulis di dalam kode, karena ia pasangan dari endpoint yang dipakai
    | BdvAccuratePostingService: bila kelak BDV dikirim sebagai jenis dokumen
    | yang lain, keduanya harus berubah berbarengan.
    |--------------------------------------------------------------------------
    */
    'attachment' => [
        'transaction_type' => 'PAY',

        'max_file_size' => 10 * 1024 * 1024,
        'max_files' => 30,

        'allowed_extensions' => [
            'gif', 'png', 'jpg', 'jpeg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'heic',
        ],

        /*
        | Unggahan Base64 jauh lebih besar dan lebih lambat daripada
        | permintaan JSON biasa: 10 MB berkas menjadi sekitar 13,4 MB badan
        | permintaan. Batas waktu bawaan Laravel (30 detik) terlalu pendek
        | untuk itu pada sambungan yang biasa saja.
        */
        'timeout' => 120,
    ],
];
