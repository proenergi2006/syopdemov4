<?php

return [
    'login' => [
        'username_required' => 'Username wajib diisi.',
        'password_required' => 'Password wajib diisi.',
        'username_not_found' => 'Username tidak ditemukan.',
        'password_incorrect' => 'Password salah.',
        'account_inactive' => 'User nonaktif.',
        'success' => 'Login berhasil.',
        'validation_failed' => 'Validasi gagal.',
        'generic_error' => 'Terjadi kesalahan saat login.',
    ],

    'permissions' => [
        'user_not_authenticated' => 'User tidak terautentikasi.',
        'loaded' => 'Permission user berhasil dimuat.',
        'load_failed' => 'Gagal memuat permission user.',
    ],

    'logout' => [
        'user_not_found' => 'User tidak ditemukan.',
        'success' => 'Logout berhasil.',
        'failed' => 'Gagal logout.',
    ],

    'forgot_password' => [
        'email_required' => 'Email wajib diisi.',
        'email_invalid' => 'Format email tidak valid.',
        'email_not_registered' => 'Email tidak terdaftar.',
        'throttled' => 'Silakan tunggu beberapa saat sebelum mengirim ulang link reset password.',
        'send_failed' => 'Gagal mengirim link reset password. Silakan coba lagi.',
        'sent' => 'Link reset password telah dikirim ke email Anda.',
        'generic_error' => 'Terjadi kesalahan saat memproses permintaan.',
    ],

    'verify_reset_token' => [
        'token_invalid' => 'Token reset password tidak valid.',
        'email_required' => 'Email wajib diisi.',
        'email_invalid' => 'Format email tidak valid.',
        'generic_error' => 'Terjadi kesalahan saat memeriksa link reset password.',
    ],

    'reset_password' => [
        'token_invalid' => 'Token reset password tidak valid.',
        'email_required' => 'Email wajib diisi.',
        'email_invalid' => 'Format email tidak valid.',
        'password_required' => 'Password baru wajib diisi.',
        'password_min' => 'Password baru minimal 8 karakter.',
        'password_confirmed' => 'Konfirmasi password baru tidak sesuai.',
        'password_regex' => 'Password baru wajib memiliki huruf besar, huruf kecil, angka, dan simbol.',
        'link_invalid' => 'Link reset password tidak valid atau sudah kedaluwarsa.',
        'failed' => 'Gagal mereset password. Silakan coba lagi.',
        'success' => 'Password berhasil direset. Silakan login dengan password baru Anda.',
        'generic_error' => 'Terjadi kesalahan saat mereset password.',
    ],

    'sso' => [
        'token_invalid' => 'Token SSO tidak valid.',
        'payload_incomplete' => 'Payload SSO tidak lengkap.',
        'token_expired' => 'Token SSO sudah kedaluwarsa.',
        'token_reused' => 'Token SSO sudah pernah digunakan.',
        'branch_not_found' => 'Cabang tidak ditemukan.',
        'duplicate_user_same_branch' => 'Terdapat user duplikat pada email dan cabang yang sama.',
        'user_not_registered' => 'User belum terdaftar di SYOP v4.',
        'duplicate_user_same_email' => 'Terdapat lebih dari satu akun SYOP v4 dengan email yang sama. Hubungi tim IT.',
        'key_config_invalid' => 'Konfigurasi SSO key tidak valid.',
        'token_format_invalid' => 'Format token SSO tidak valid.',
        'auth_tag_invalid' => 'Authentication tag tidak valid.',
        'decrypt_failed' => 'Token SSO gagal didekripsi.',
        'payload_invalid' => 'Payload SSO tidak valid.',
        'encoding_invalid' => 'Encoding token SSO tidak valid.',
    ],
];
