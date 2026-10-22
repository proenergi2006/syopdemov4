<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Revisi dokumen
    |--------------------------------------------------------------------------
    | Berlaku untuk FPU, Realisasi, Claim, dan Perdin.
    */

    'revisions' => [

        /*
        | Berapa kali satu dokumen boleh dikembalikan untuk direvisi.
        |
        | null = tanpa batas, dan itu keadaan sekarang. Diisi angka, misalnya
        | 3, pengembalian ke-4 ditolak dengan menyebut batasnya; dokumennya
        | tetap di tangan approver, yang tersisa baginya menyetujui atau
        | menolak.
        |
        | Penjaganya sengaja sudah terpasang walau batasnya belum dipakai:
        | aturan yang baru ditulis pada hari ia dibutuhkan selalu ditulis
        | dengan tergesa, dan yang diputuskan tergesa di sini adalah dokumen
        | orang yang tertahan.
        */
        'max' => null,

    ],

];
