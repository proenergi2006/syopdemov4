<?php

/*
|--------------------------------------------------------------------------
| Tanda tangan digital
|--------------------------------------------------------------------------
| Kalimat kegagalan di bagian image sengaja menyebut apa yang perlu diubah
| pada fotonya, bukan sekadar "gagal". Yang mengunggah tidak bisa melihat
| hasil analisis piksel; satu-satunya petunjuk yang ia punya adalah kalimat
| ini, jadi kalimat ini harus memberitahunya langkah berikutnya.
|--------------------------------------------------------------------------
*/
return [
    'image' => [
        'unreadable_format' => 'File tidak terbaca sebagai gambar.'
            . ' Gunakan foto berformat JPG, PNG, atau WEBP.',

        'ink_not_found' => 'Tidak ada tanda tangan yang terbaca pada gambar itu.'
            . ' Pastikan tanda tangan ditulis dengan pena gelap di atas kertas putih,'
            . ' lalu foto dari atas dengan cahaya yang cukup.',

        'background_too_dark' => 'Hampir seluruh gambar terbaca sebagai tinta,'
            . ' biasanya karena fotonya gelap atau kertasnya bergaris dan bermotif.'
            . ' Foto ulang di tempat yang lebih terang, di atas kertas putih bersih.',
    ],

    'slot_full' => 'Tanda tangan Anda sudah :max. Hapus salah satunya dulu'
        . ' sebelum menambah yang baru.',

    'not_found' => 'Tanda tangan itu tidak ditemukan pada akun Anda.',

    'label_required' => 'Nama tanda tangan wajib diisi.',

    'agree_required' => 'Anda perlu menyetujui penggunaan tanda tangan digital ini.',

    'source_required' => 'Belum ada tanda tangan yang dikirim.'
        . ' Tulis di layar atau unggah foto tanda tangan Anda.',

    'file_invalid' => 'File harus berupa gambar JPG, PNG, atau WEBP.',

    'file_too_large' => 'Ukuran file melebihi 8 MB. Kecilkan dulu fotonya.',

    'loaded' => 'Daftar tanda tangan berhasil dimuat.',
    'created' => 'Tanda tangan berhasil disimpan.',
    'activated' => 'Tanda tangan itu sekarang dipakai untuk dokumen berikutnya.',
    'deleted' => 'Tanda tangan berhasil dihapus dari daftar Anda.',
    'label_updated' => 'Nama tanda tangan berhasil diubah.',

    'load_failed' => 'Gagal memuat daftar tanda tangan.',
    'create_failed' => 'Gagal menyimpan tanda tangan.',
    'activate_failed' => 'Gagal mengubah tanda tangan yang dipakai.',
    'delete_failed' => 'Gagal menghapus tanda tangan.',
    'label_update_failed' => 'Gagal mengubah nama tanda tangan.',
];
