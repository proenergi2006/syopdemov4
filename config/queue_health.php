<?php

/*
|--------------------------------------------------------------------------
| Pengawasan antrean
|--------------------------------------------------------------------------
| Ambang di bawah ini menentukan kapan antrean dianggap bermasalah, dan ke
| mana laporannya dikirim. Semuanya bisa ditimpa lewat environment tanpa
| mengubah kode.
|--------------------------------------------------------------------------
*/
return [

    /*
    | Job yang menunggu lebih lama dari ini menandakan worker kemungkinan
    | tidak berjalan. Nilainya sengaja jauh di atas jeda percobaan ulang
    | terpanjang (15 menit) supaya email yang sedang menunggu percobaan
    | berikutnya tidak salah dibaca sebagai worker mati.
    */
    'stale_after_minutes' => (int) env('QUEUE_HEALTH_STALE_MINUTES', 30),

    /*
    | Banyaknya job menunggu yang masih dianggap wajar. Di atas ini antrean
    | dianggap menumpuk -- worker mungkin hidup tetapi tidak sanggup mengejar.
    */
    'backlog_warning' => (int) env('QUEUE_HEALTH_BACKLOG_WARNING', 50),

    /*
    | Rentang waktu yang dibaca sebagai "kegagalan baru" pada laporan.
    */
    'failure_window_hours' => (int) env('QUEUE_HEALTH_FAILURE_WINDOW_HOURS', 24),

    /*
    | Jeda minimum antar email peringatan untuk masalah yang sama. Tanpa ini,
    | worker yang mati semalaman akan mengirim puluhan email yang isinya sama.
    */
    'alert_cooldown_minutes' => (int) env('QUEUE_HEALTH_COOLDOWN_MINUTES', 60),

    /*
    | Penerima laporan. Dipisah koma bila lebih dari satu. Bila dikosongkan,
    | dipakai alamat pengirim aplikasi sebagai cadangan supaya laporannya
    | tidak hilang begitu saja.
    */
    'recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('QUEUE_HEALTH_RECIPIENTS', '')),
    ))),

    /*
    | Banyaknya baris kegagalan yang ikut dirinci pada email dan halaman.
    */
    'detail_limit' => (int) env('QUEUE_HEALTH_DETAIL_LIMIT', 20),

];
