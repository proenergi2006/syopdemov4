<?php

namespace App\Services\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Kesehatan antrean
|--------------------------------------------------------------------------
| Menjawab satu pertanyaan yang selama ini tidak terjawab: apakah email yang
| diantrekan benar-benar terkirim?
|
| Tiga gejala yang dibaca, masing-masing berbeda artinya:
|
|   1. Ada job GAGAL          -- ada yang sudah menyerah, perlu dilihat manusia
|   2. Job MENUNGGU terlalu lama -- worker kemungkinan besar tidak berjalan
|   3. Antrean MENUMPUK       -- worker hidup tapi tidak sanggup mengejar
|
| Yang kedua paling berbahaya karena paling senyap: tidak ada error di mana
| pun, tidak ada baris di failed_jobs, approver hanya tidak pernah menerima
| emailnya.
|
| Dipakai bersama oleh perintah terjadwal dan halaman pemantauan, supaya
| angka pada email peringatan dan angka di layar tidak mungkin berbeda.
|--------------------------------------------------------------------------
*/
class QueueHealthService
{
    public const STATUS_SEHAT = 'SEHAT';
    public const STATUS_PERHATIAN = 'PERHATIAN';
    public const STATUS_BERMASALAH = 'BERMASALAH';

    /**
     * Potret keadaan antrean saat ini.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $sekarang = CarbonImmutable::now();

        $menunggu = $this->waitingSummary($sekarang);
        $gagal = $this->failureSummary($sekarang);

        $ambangMacet = (int) config('queue_health.stale_after_minutes', 30);
        $ambangMenumpuk = (int) config('queue_health.backlog_warning', 50);

        /*
        | Job tertua yang belum tersentuh melewati ambang: worker kemungkinan
        | tidak berjalan. Ini gejala paling gawat karena tidak meninggalkan
        | jejak error di mana pun.
        */
        $macet = $menunggu['oldest_waiting_minutes'] !== null
            && $menunggu['oldest_waiting_minutes'] >= $ambangMacet;

        $menumpuk = $menunggu['count'] >= $ambangMenumpuk;

        $alasan = [];

        if ($macet) {
            $alasan[] = sprintf(
                'Job tertua sudah menunggu %s menit (ambang %d menit) -- worker kemungkinan tidak berjalan.',
                number_format($menunggu['oldest_waiting_minutes'], 1),
                $ambangMacet,
            );
        }

        if ($gagal['recent'] > 0) {
            $alasan[] = sprintf(
                '%d job gagal dalam %d jam terakhir.',
                $gagal['recent'],
                (int) config('queue_health.failure_window_hours', 24),
            );
        }

        if ($menumpuk) {
            $alasan[] = sprintf(
                '%d job menunggu di antrean (ambang %d).',
                $menunggu['count'],
                $ambangMenumpuk,
            );
        }

        if ($gagal['total'] > 0 && $gagal['recent'] === 0) {
            $alasan[] = sprintf(
                '%d job gagal lama masih mengendap dan belum ditangani.',
                $gagal['total'],
            );
        }

        return [
            'checked_at' => $sekarang->toDateTimeString(),
            'status' => $this->resolveStatus($macet, $gagal, $menumpuk),
            'is_healthy' => !$macet && $gagal['total'] === 0 && !$menumpuk,
            'reasons' => $alasan,

            'thresholds' => [
                'stale_after_minutes' => $ambangMacet,
                'backlog_warning' => $ambangMenumpuk,
                'failure_window_hours' => (int) config('queue_health.failure_window_hours', 24),
            ],

            'waiting' => $menunggu,
            'failed' => $gagal,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Job yang masih menunggu
    |--------------------------------------------------------------------------
    | available_at menentukan kapan sebuah job BOLEH diambil. Job yang sedang
    | menunggu jeda percobaan ulang punya available_at di masa depan, dan itu
    | keadaan yang sehat -- karena itu umurnya dihitung dari available_at,
    | bukan dari created_at.
    |--------------------------------------------------------------------------
    */
    private function waitingSummary(CarbonImmutable $sekarang): array
    {
        $baris = DB::table('jobs')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COUNT(*) FILTER (WHERE reserved_at IS NOT NULL) AS sedang_dikerjakan')
            ->selectRaw('MIN(available_at) AS paling_lama')
            ->first();

        $palingLama = $baris->paling_lama === null
            ? null
            : CarbonImmutable::createFromTimestamp((int) $baris->paling_lama);

        /*
        | Hanya job yang SUDAH boleh diambil yang dihitung umurnya. Yang masih
        | menunggu jedanya belum terlambat oleh siapa pun.
        */
        $umurMenit = $palingLama === null || $palingLama->greaterThan($sekarang)
            ? null
            : round($palingLama->diffInSeconds($sekarang) / 60, 1);

        return [
            'count' => (int) $baris->jumlah,
            'reserved' => (int) $baris->sedang_dikerjakan,
            'oldest_available_at' => $palingLama?->toDateTimeString(),
            'oldest_waiting_minutes' => $umurMenit,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Job yang gagal
    |--------------------------------------------------------------------------
    */
    private function failureSummary(CarbonImmutable $sekarang): array
    {
        $batas = $sekarang->subHours((int) config('queue_health.failure_window_hours', 24));

        $total = (int) DB::table('failed_jobs')->count();
        $baru = (int) DB::table('failed_jobs')->where('failed_at', '>=', $batas)->count();

        $limit = (int) config('queue_health.detail_limit', 20);

        $rincian = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'queue' => (string) $row->queue,
                'job' => $this->resolveJobName($row->payload),
                'error' => $this->resolveErrorMessage($row->exception),
                'failed_at' => (string) $row->failed_at,
            ])
            ->values()
            ->all();

        /*
        | Dikelompokkan per jenis job supaya pola masalahnya terbaca: sepuluh
        | kegagalan dari satu jenis email berarti sesuatu yang berbeda dari
        | sepuluh kegagalan yang tersebar.
        */
        $perJenis = collect($rincian)
            ->groupBy('job')
            ->map(fn ($grup, $nama): array => [
                'job' => $nama,
                'count' => $grup->count(),
                'last_failed_at' => $grup->max('failed_at'),
                'sample_error' => $grup->first()['error'],
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'total' => $total,
            'recent' => $baru,
            'by_job' => $perJenis,
            'items' => $rincian,
            'truncated' => $total > $limit,
        ];
    }

    private function resolveStatus(bool $macet, array $gagal, bool $menumpuk): string
    {
        /*
        | Worker yang tidak berjalan dan kegagalan baru sama-sama menuntut
        | tindakan hari ini. Kegagalan lama dan antrean menumpuk cukup
        | ditandai sebagai perlu diperhatikan.
        */
        if ($macet || $gagal['recent'] > 0) {
            return self::STATUS_BERMASALAH;
        }

        if ($menumpuk || $gagal['total'] > 0) {
            return self::STATUS_PERHATIAN;
        }

        return self::STATUS_SEHAT;
    }

    /**
     * Nama job dari payload-nya.
     *
     * displayName dipakai lebih dulu karena untuk email berantre isinya nama
     * kelas email itu sendiri, bukan nama kelas pembungkusnya.
     */
    private function resolveJobName(?string $payload): string
    {
        $isi = json_decode((string) $payload, true);

        if (!is_array($isi)) {
            return 'Tidak diketahui';
        }

        return (string) ($isi['displayName'] ?? $isi['job'] ?? 'Tidak diketahui');
    }

    /**
     * Baris pertama jejak kesalahan -- bagian yang benar-benar menjelaskan.
     */
    private function resolveErrorMessage(?string $exception): string
    {
        $baris = strtok((string) $exception, "\n");

        return $baris === false ? '-' : trim($baris);
    }
}
