<?php

/*
|--------------------------------------------------------------------------
| Kegagalan unggah di tingkat PHP
|--------------------------------------------------------------------------
| Menggantikan "gagal diunggah" bawaan Laravel, yang tidak menyebut file
| mana, tidak menyebut sebabnya, dan tidak memberi tahu apa langkah
| berikutnya. Lihat App\Http\Middleware\ExplainUploadFailure.
|
| Setiap kalimat menyebut NAMA FILE dan SATU TINDAKAN. Yang bisa diselesaikan
| sendiri oleh pengaju disebutkan caranya; yang memang urusan server
| mengarahkannya ke IT, supaya ia tidak mencoba-coba hal yang tidak akan
| pernah berhasil.
|--------------------------------------------------------------------------
*/
return [
    'failed' => [
        'ini_size' => 'File ":file" melebihi batas unggah server, yaitu :limit per file.'
            . ' Kompres atau pecah filenya lebih dulu, atau minta IT menaikkan batas tersebut.',

        'form_size' => 'File ":file" melebihi batas ukuran yang ditetapkan formulir ini.',

        'partial' => 'Unggahan file ":file" terputus sebelum selesai.'
            . ' Periksa koneksi Anda, lalu unggah ulang.',

        'no_tmp_dir' => 'Server tidak memiliki direktori sementara untuk menampung unggahan,'
            . ' sehingga file ":file" tidak bisa diterima. Hubungi IT -- ini tidak bisa diperbaiki dari sini.',

        'cant_write' => 'Server gagal menyimpan file ":file" ke disk, kemungkinan ruang penyimpanannya penuh.'
            . ' Hubungi IT -- ini tidak bisa diperbaiki dari sini.',

        'extension' => 'Unggahan file ":file" dihentikan oleh ekstensi PHP di server.'
            . ' Hubungi IT -- ini tidak bisa diperbaiki dari sini.',

        'unknown' => 'File ":file" gagal diunggah di server (kode galat :code).'
            . ' Sebutkan kode tersebut saat menghubungi IT.',
    ],

    'post_too_large' => 'Total ukuran seluruh lampiran melebihi batas satu kali pengiriman di server, yaitu :limit.'
        . ' Kurangi jumlah lampiran, atau kirim sebagian dulu lalu sisanya menyusul lewat Ubah.',

    'unlimited' => 'tanpa batas',

    /*
    |--------------------------------------------------------------------------
    | Lampiran yang DIAM-DIAM tidak sampai
    |--------------------------------------------------------------------------
    | PHP membatasi jumlah file per pengiriman lewat max_file_uploads. Ketika
    | batas itu terlewat, file selebihnya TIDAK ditolak -- mereka hanya tidak
    | ada. Tidak ada kode galat, tidak ada entri di $_FILES, tidak ada apa pun
    | yang bisa dibaca sesudahnya.
    |
    | Akibatnya pernah terjadi dan terukur: dua puluh lima lampiran dikirim,
    | dua puluh tersimpan, dokumennya berstatus "berhasil disimpan", dan tidak
    | seorang pun diberi tahu bahwa lima bukti pengeluaran hilang.
    |
    | Satu-satunya cara mengetahuinya adalah membandingkan dengan jumlah yang
    | DINYATAKAN pengirim. Layar menyertakan angka itu; kalimat di bawah
    | dipakai saat angkanya tidak cocok.
    |--------------------------------------------------------------------------
    */
    'truncated' => [
        'server_limit' => 'Server hanya menerima :limit lampiran dalam satu kali pengiriman,'
            . ' sedangkan yang dikirim :sent -- sebanyak :lost lampiran tidak sampai.'
            . ' Dokumen TIDAK disimpan, supaya tidak ada bukti yang hilang diam-diam.'
            . ' Kurangi lampirannya, atau simpan sebagian dulu lalu tambahkan sisanya lewat Ubah.',

        'unknown' => 'Dari :sent lampiran yang dikirim, hanya :received yang sampai di server.'
            . ' Dokumen TIDAK disimpan, supaya tidak ada bukti yang hilang diam-diam.'
            . ' Periksa koneksi Anda, lalu kirim ulang.',
    ],

    /*
    | Berkas yang lolos validasi tetapi ternyata tidak utuh saat hendak
    | dituliskan ke disk. Sebelumnya dilewati begitu saja -- dokumennya
    | tersimpan, lampirannya tidak, dan tidak ada yang menyebutkannya.
    */
    'not_received' => 'Lampiran ":file" tidak sampai di server dalam keadaan utuh.'
        . ' Penyimpanan dibatalkan supaya dokumennya tidak tersimpan tanpa bukti itu.'
        . ' Unggah ulang berkasnya.',

    /*
    | Lampiran yang menunjuk baris rincian yang tidak ada. Dulu dibuang
    | diam-diam, sehingga berkasnya lenyap tanpa jejak.
    */
    'orphan_row' => 'Ada lampiran untuk baris :row, padahal rincian yang dikirim tidak memuat baris itu.'
        . ' Muat ulang halaman, lalu susun kembali lampirannya.',

    /*
    |--------------------------------------------------------------------------
    | Kalimat aturan validasi lampiran per baris
    |--------------------------------------------------------------------------
    | Bawaan Laravel menyebut nama atribut mentah -- "line_attachments.0.0" --
    | yang tidak berarti apa pun bagi orang yang mengisi formulirnya. Kalimat
    | di bawah menyebut nama berkas dan nomor barisnya.
    |--------------------------------------------------------------------------
    */
    'rule' => [
        'attribute' => 'Lampiran ":file" pada baris :row',

        'file' => ':attribute tidak diterima server sebagai berkas yang utuh. Unggah ulang berkasnya.',

        'uploaded' => ':attribute gagal diunggah ke server. Unggah ulang berkasnya.',

        'mimes' => ':attribute bukan gambar maupun PDF.'
            . ' Yang bisa dilampirkan hanya JPG, JPEG, PNG, dan PDF.',

        'max' => ':attribute melebihi batas :max KB per lampiran.'
            . ' Kompres gambarnya, atau pecah PDF-nya, lalu unggah ulang.',
    ],
];
