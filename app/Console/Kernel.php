<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('po-supplier:auto-approve-cfo')
            ->everyTenMinutes()
            ->withoutOverlapping();

        /*
        |--------------------------------------------------------------------------
        | Activity Log Cleanup
        |--------------------------------------------------------------------------
        | Hapus activity log yang lebih lama dari config('activitylog.delete_records_older_than_days')
        | supaya tabel activity_log tidak membesar tanpa batas.
        |--------------------------------------------------------------------------
        */
        $schedule->command('activitylog:clean')
            ->daily();

        /*
        |--------------------------------------------------------------------------
        | Pengawasan antrean
        |--------------------------------------------------------------------------
        | Memeriksa apakah email yang diantrekan benar-benar terkirim. Worker
        | yang mati tidak meninggalkan error di mana pun, jadi tanpa pemeriksaan
        | berkala keadaan itu hanya ketahuan dari keluhan pengguna.
        |
        | Lima belas menit dipilih supaya masalah ketahuan dalam waktu wajar
        | tanpa membanjiri log. Emailnya sendiri punya jeda peringatan
        | tersendiri, jadi frekuensi ini tidak membuat kotak masuk penuh.
        |
        | withoutOverlapping mencegah dua pemeriksaan berjalan bersamaan bila
        | satu di antaranya tertahan SMTP yang lambat.
        |--------------------------------------------------------------------------
        */
        $schedule->command('queue:health-check')
            ->everyFifteenMinutes()
            ->withoutOverlapping();

        /*
        |--------------------------------------------------------------------------
        | Peringatan saldo kas kecil
        |--------------------------------------------------------------------------
        | Memperingatkan pemegang kas kecil yang saldonya sudah di bawah ambang,
        | supaya top up diajukan SEBELUM ada claim kecil yang tidak bisa dibayar.
        |
        | Harian pada pukul 07:00, bukan tiap jam: peringatan yang muncul
        | berulang sepanjang hari akan berhenti dibaca, dan sesudah itu ia tidak
        | lagi memperingatkan apa pun. Jamnya dipilih supaya sudah ada di layar
        | ketika ADH membuka aplikasi pagi-pagi, bukan menyusul di tengah hari
        | saat claim-nya sudah menumpuk.
        |--------------------------------------------------------------------------
        */
        $schedule->command('petty-cash:warn-low-balance')
            ->dailyAt('07:00')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
    
}
