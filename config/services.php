<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'accurate' => [
        'token' => env('ACCURATE_API_TOKEN'),
        'secret' => env('ACCURATE_API_SECRET'),
        'base_url' => env('ACCURATE_BASE_URL'),

        /*
        |----------------------------------------------------------------------
        | Batas permintaan per detik
        |----------------------------------------------------------------------
        | Accurate Online membatasi sekitar 8 permintaan per detik, dan
        | melewatinya berisiko memblokir seluruh sambungan aplikasi ini --
        | bukan hanya permintaan yang kelebihan itu.
        |
        | Dipasang 6, bukan 8, dengan sengaja. Hitungannya memakai cache file
        | yang penambahannya tidak dijamin atomik antar proses, jadi angkanya
        | bisa meleset sedikit saat ramai; selisih dua itulah ruang amannya.
        |
        | Yang menjaganya AccurateApiService, sehingga berlaku untuk seluruh
        | pemanggil -- termasuk proses latar yang tidak ada orang menunggui.
        |----------------------------------------------------------------------
        */
        'max_per_second' => env('ACCURATE_MAX_PER_SECOND', 6),
    ],

    'legacy_sso' => [
        'key' => env('SYOP_SSO_KEY'),
        'aad' => env(
            'SYOP_SSO_AAD',
            'syop-v3-to-v4'
        ),
    ],
];
