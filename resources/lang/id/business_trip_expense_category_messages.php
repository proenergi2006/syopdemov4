<?php

/*
|--------------------------------------------------------------------------
| Master Kategori Biaya Perdin
|--------------------------------------------------------------------------
| Transportasi, Penginapan, Uang Saku -- pengelompokan rincian FPU dan
| Realisasi berketerangan transaksi perjalanan dinas.
|
| Kategori ini hanya berlaku di sana; lihat keterangan pada migrasinya.
|--------------------------------------------------------------------------
*/
return [
    'forbidden' => 'Anda tidak memiliki akses untuk mengelola kategori biaya perjalanan dinas.',
    'not_found' => 'Kategori biaya tidak ditemukan.',

    'fields' => [
        'name' => 'nama kategori',
        'sort_order' => 'urutan tampil',
    ],

    'store' => [
        'success' => 'Kategori biaya berhasil ditambahkan.',
    ],

    'update' => [
        'success' => 'Kategori biaya berhasil diperbarui.',
    ],

    'toggle' => [
        'success' => 'Status kategori biaya berhasil diubah.',
    ],

    'destroy' => [
        'success' => 'Kategori biaya berhasil dihapus.',

        /*
        | Yang sudah dipakai tidak dihapus, melainkan dinonaktifkan. Kolom
        | penunjuknya memang nullOnDelete -- dokumennya tidak akan rusak --
        | tetapi pengelompokan pada dokumen yang sudah disetujui dan sudah
        | dicetak akan hilang, dan tidak ada yang bisa mengembalikannya.
        */
        'in_use' => 'Kategori ini sudah dipakai rincian dokumen, jadi tidak bisa dihapus. Nonaktifkan saja — ia hilang dari pilihan tanpa menyentuh dokumen lama.',

        'failed' => 'Kategori biaya gagal dihapus. Silakan coba lagi.',
    ],
];
