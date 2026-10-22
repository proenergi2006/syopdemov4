<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cetakan gabungan
    |--------------------------------------------------------------------------
    | Dipakai FPU, Claim, Realisasi, dan Perjalanan Dinas sekaligus. Satu
    | berkas, bukan empat salinan: aturannya satu, dan empat salinan yang
    | berawal sama selalu berakhir berbeda.
    |
    | Alasan penolakannya ditulis lengkap, bukan sekadar "tidak bisa". Pilihan
    | yang mati tanpa sebab hanya memindahkan kebingungan ke orang yang
    | membacanya.
    */
    'combine' => [
        'no_trip' => 'Dokumen ini tidak terikat pada Perjalanan Dinas mana pun.',
        'trip_not_approved' => 'Perjalanan Dinas :number belum disetujui sepenuhnya, jadi belum bisa ikut dicetak.',

        'no_document' => 'Belum ada FPU atau Claim yang terikat pada Perjalanan Dinas ini.',
        'document_not_printable' => 'Dokumen :number belum berstatus yang boleh dicetak, jadi belum bisa ikut digabungkan.',

        'trip_attachment_label' => 'Lampiran Pemesanan',
    ],
];
