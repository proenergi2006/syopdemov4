<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Idle Timeout
    |--------------------------------------------------------------------------
    | User akan auto logout jika tidak ada aktivitas selama X menit.
    |--------------------------------------------------------------------------
    */
    'idle_timeout_minutes' => env('AUTH_IDLE_TIMEOUT_MINUTES', 120),

    /*
    |--------------------------------------------------------------------------
    | Absolute Timeout
    |--------------------------------------------------------------------------
    | Token maksimal hidup X menit dari login, walaupun user aktif terus.
    | 720 menit = 12 jam.
    |--------------------------------------------------------------------------
    */
    'absolute_timeout_minutes' => env('AUTH_TOKEN_LIFETIME_MINUTES', 720),
];
