<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Penolakan
    |--------------------------------------------------------------------------
    | Setiap kalimat menyebut apa yang harus dilakukan, bukan hanya bahwa
    | permintaannya gagal.
    |--------------------------------------------------------------------------
    */
    'forbidden' => 'Anda tidak memiliki akses untuk masuk sebagai user lain.',

    'nested' => 'Anda sedang masuk sebagai user lain. Kembali ke akun Anda sendiri dulu sebelum masuk sebagai user yang berbeda.',

    'target_not_found' => 'Akun yang dipilih tidak ditemukan.',

    'target_is_self' => 'Anda sudah masuk sebagai akun tersebut.',

    'target_is_privileged' => 'Akun ini juga memiliki akses untuk masuk sebagai user lain, jadi tidak bisa dimasuki. Pilih akun lain.',

    'not_impersonating' => 'Sesi ini bukan sesi penyamaran, jadi tidak ada akun yang bisa dikembalikan. Silakan login kembali.',

    'admin_no_longer_allowed' => 'Hak akses akun administrator Anda sudah tidak berlaku, jadi sesi tidak bisa dipulihkan. Penyamaran sudah dihentikan. Silakan login kembali.',

    'action_blocked' => 'Tindakan ini tidak bisa dilakukan saat masuk sebagai user lain, karena mengubah kredensial permanen milik akun tersebut. Kembali ke akun Anda sendiri terlebih dahulu.',

    'session_not_found' => 'Riwayat penyamaran yang diminta tidak ditemukan.',

    /*
    |--------------------------------------------------------------------------
    | Validasi
    |--------------------------------------------------------------------------
    */
    'reason_required' => 'Alasan wajib diisi. Alasan inilah yang membuat catatan penyamaran bisa dibaca ulang di kemudian hari.',

    'reason_too_short' => 'Alasan terlalu singkat. Tuliskan setidaknya lima karakter yang menjelaskan keperluannya.',

    /*
    |--------------------------------------------------------------------------
    | Berhasil
    |--------------------------------------------------------------------------
    */
    'started' => 'Anda sekarang masuk sebagai :name.',

    'stopped' => 'Anda sudah kembali ke akun Anda sendiri.',

    'candidates_loaded' => 'Daftar akun berhasil dimuat.',

    'history_loaded' => 'Riwayat penyamaran berhasil dimuat.',

];
