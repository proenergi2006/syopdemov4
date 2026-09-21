<?php

namespace App\Console\Commands;

use App\Mail\QueueHealthAlertMail;
use App\Services\Queue\QueueHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Pemeriksaan kesehatan antrean
|--------------------------------------------------------------------------
| Menjawab masalah yang paling berbahaya dari antrean: kesenyapannya. Worker
| yang mati tidak meninggalkan error di mana pun -- approver hanya tidak
| pernah menerima emailnya, dan tidak ada satu pun tanda di layar.
|
| Melapor lewat tiga jalur sekaligus, sesuai kesepakatan:
|
|   1. Log      -- jejak permanen, selalu ada
|   2. Email    -- dikirim LANGSUNG, tidak lewat antrean; kalau ikut antre,
|                  laporannya akan tertahan oleh masalah yang dilaporkannya
|   3. Halaman  -- ringkasan yang sama dibaca halaman Monitoring dari service
|                  yang sama, jadi angkanya tidak mungkin berbeda
|
| Dijalankan penjadwal Laravel; lihat App\Console\Kernel.
|--------------------------------------------------------------------------
*/
class CheckQueueHealth extends Command
{
    protected $signature = 'queue:health-check
                            {--force-alert : Kirim email walau sedang dalam jeda peringatan}
                            {--no-mail : Hanya catat ke log, jangan kirim email}';

    protected $description = 'Memeriksa kesehatan antrean job dan melaporkan bila bermasalah.';

    /** Penanda jeda peringatan, supaya masalah yang sama tidak dikirim berulang. */
    private const CACHE_KEY = 'queue_health.last_alert';

    public function handle(QueueHealthService $service): int
    {
        $snapshot = $service->snapshot();

        $this->tampilkanRingkasan($snapshot);

        if ($snapshot['is_healthy']) {
            /*
            | Sengaja dicatat sebagai info walau sehat: deretan baris "sehat"
            | yang tiba-tiba berhenti adalah petunjuk bahwa penjadwalnya
            | sendiri yang mati -- sesuatu yang tidak akan terdeteksi oleh
            | pemeriksaan ini.
            */
            Log::info('[Queue Health] Antrean sehat', [
                'menunggu' => $snapshot['waiting']['count'],
                'gagal' => $snapshot['failed']['total'],
            ]);

            /* Jeda peringatan dilepas supaya masalah berikutnya langsung dikabarkan. */
            Cache::forget(self::CACHE_KEY);

            return self::SUCCESS;
        }

        Log::warning('[Queue Health] Antrean perlu diperiksa', [
            'status' => $snapshot['status'],
            'alasan' => $snapshot['reasons'],
            'menunggu' => $snapshot['waiting'],
            'gagal' => [
                'total' => $snapshot['failed']['total'],
                'baru' => $snapshot['failed']['recent'],
                'per_jenis' => $snapshot['failed']['by_job'],
            ],
        ]);

        if ($this->option('no-mail')) {
            $this->comment('Email dilewati (--no-mail).');

            return self::SUCCESS;
        }

        $this->kirimPeringatan($snapshot);

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Email peringatan
    |--------------------------------------------------------------------------
    */
    private function kirimPeringatan(array $snapshot): void
    {
        $penerima = $this->resolvePenerima();

        if ($penerima === []) {
            $this->warn('Tidak ada penerima peringatan; atur queue_health.recipients.');

            Log::warning('[Queue Health] Peringatan tidak terkirim: penerima belum diatur');

            return;
        }

        if (!$this->bolehMengirim($snapshot)) {
            $this->comment('Masih dalam jeda peringatan, email tidak dikirim ulang.');

            return;
        }

        try {
            /*
            | send(), bukan queue(). Email inilah satu-satunya yang tidak boleh
            | bergantung pada antrean -- justru antrean itu yang sedang
            | dilaporkan bermasalah.
            */
            Mail::to($penerima)->send(new QueueHealthAlertMail($snapshot));

            Cache::put(
                self::CACHE_KEY,
                $this->sidikMasalah($snapshot),
                now()->addMinutes((int) config('queue_health.alert_cooldown_minutes', 60)),
            );

            $this->info('Peringatan terkirim ke: ' . implode(', ', $penerima));

            Log::info('[Queue Health] Peringatan terkirim', ['penerima' => $penerima]);
        } catch (\Throwable $e) {
            /*
            | Kegagalan mengirim laporan tidak boleh menggagalkan perintahnya.
            | Yang penting jejaknya tetap ada di log -- jalur pertama dari tiga
            | jalur pelaporan memang disiapkan untuk keadaan seperti ini.
            */
            $this->error('Gagal mengirim peringatan: ' . $e->getMessage());

            Log::error('[Queue Health] Gagal mengirim peringatan', [
                'penerima' => $penerima,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Peringatan yang sama tidak dikirim berulang selama jedanya belum lewat.
     *
     * Masalah yang BERBEDA tetap dikirim walau masih dalam jeda -- worker yang
     * mati semalaman tidak boleh menutupi munculnya kegagalan jenis baru.
     */
    private function bolehMengirim(array $snapshot): bool
    {
        if ($this->option('force-alert')) {
            return true;
        }

        $terakhir = Cache::get(self::CACHE_KEY);

        return $terakhir !== $this->sidikMasalah($snapshot);
    }

    /** Sidik ringkas dari masalah yang sedang terjadi. */
    private function sidikMasalah(array $snapshot): string
    {
        return md5($snapshot['status'] . '|' . implode('||', $snapshot['reasons']));
    }

    /**
     * @return array<int, string>
     */
    private function resolvePenerima(): array
    {
        $penerima = (array) config('queue_health.recipients', []);

        /*
        | Tanpa penerima yang diatur, alamat pengirim aplikasi dipakai sebagai
        | cadangan -- lebih baik masuk ke satu kotak masuk yang mungkin salah
        | daripada hilang sama sekali.
        */
        if ($penerima === []) {
            $cadangan = config('mail.from.address');

            if (is_string($cadangan) && $cadangan !== '') {
                $penerima = [$cadangan];
            }
        }

        return array_values(array_filter(
            $penerima,
            fn ($alamat): bool => is_string($alamat)
                && filter_var($alamat, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }

    private function tampilkanRingkasan(array $snapshot): void
    {
        $this->line("Status  : {$snapshot['status']}");
        $this->line("Menunggu: {$snapshot['waiting']['count']} job"
            . ($snapshot['waiting']['oldest_waiting_minutes'] === null
                ? ''
                : " (terlama {$snapshot['waiting']['oldest_waiting_minutes']} menit)"));
        $this->line("Gagal   : {$snapshot['failed']['total']} total, {$snapshot['failed']['recent']} baru");

        foreach ($snapshot['reasons'] as $alasan) {
            $this->warn("  - {$alasan}");
        }
    }
}
