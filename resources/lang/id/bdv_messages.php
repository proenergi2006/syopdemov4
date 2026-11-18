<?php

return [
    'forbidden_view' => 'Anda tidak memiliki akses melihat Bank Disbursement Voucher.',

    'forbidden_create' => 'Anda tidak memiliki akses membuat Bank Disbursement Voucher.',

    'unknown_document_type' => 'Jenis dokumen tersebut tidak terdaftar sebagai sumber BDV.',

    'document_not_found' => 'Dokumen yang dipilih tidak ditemukan.',

    /*
    | Sengaja satu kalimat untuk tiga keadaan sekaligus -- belum diterima,
    | sudah dicairkan, atau sudah diangkat BDV lain. Membedakannya hanya
    | membocorkan keberadaan dokumen yang memang tidak boleh dilihat.
    */
    'document_not_available' => 'Dokumen itu tidak bisa diangkat menjadi BDV. Mungkin berkasnya belum diterima, sudah dicairkan, atau sudah dipakai pada BDV lain.',
];
