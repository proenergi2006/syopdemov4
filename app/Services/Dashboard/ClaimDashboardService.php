<?php

namespace App\Services\Dashboard;

use App\Models\Claim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| Dashboard Claim
|--------------------------------------------------------------------------
| Sudut pandangnya manajemen. Penanganan scope, bentuk filter, dan susunan
| responsnya mengikuti dashboard FPU dan Realisasi supaya ketiganya terasa
| satu keluarga.
|
| YANG DIJAWAB BERBEDA DARI KEDUANYA.
|
| FPU: uang perusahaan keluar lebih dulu, dipertanggungjawabkan belakangan.
| Claim KEBALIKANNYA -- karyawan membayar lebih dulu dengan uangnya sendiri,
| perusahaan mengganti belakangan. Jadi yang menggantung di sini bukan uang
| perusahaan di tangan orang, melainkan UANG ORANG YANG DITAHAN PERUSAHAAN.
|
| Tiga hal yang ditonjolkan, dan ketiganya tidak punya padanan di dashboard
| FPU maupun Realisasi:
|
| 1. BELUM DIGANTI. Claim yang sudah disetujui tetapi belum dibayar. Setiap
|    rupiah di angka ini adalah uang karyawan yang sedang dipegang
|    perusahaan.
|
| 2. JADWAL PEMBAYARAN. Claim punya scheduled_payment_date. Yang sudah lewat
|    tanggalnya tetapi belum dibayar adalah janji yang dilanggar; yang belum
|    punya tanggal sama sekali bahkan belum masuk antrean.
|
| 3. REVISI NOMINAL. Finance boleh memotong nilai yang diajukan.
|    original_total_amount menyimpan angka sebelum dipotong -- selisihnya
|    menunjukkan seberapa sering dan seberapa besar pengajuan dikoreksi.
|
| ANGKA PERIODE DAN ANGKA POSISI DIBEDAKAN, sama seperti dua dashboard
| sebelumnya.
|--------------------------------------------------------------------------
*/
class ClaimDashboardService
{
    /*
    | Status yang dianggap "jadi": klaimnya lolos dan perusahaan memang
    | berutang atau sudah membayarnya.
    */
    private const STATUS_DISETUJUI = [
        Claim::STATUS_APPROVED,
        Claim::STATUS_RECEIVED,
        Claim::STATUS_PAID,
    ];

    /** Sudah disetujui tetapi uangnya belum sampai ke karyawan. */
    private const STATUS_BELUM_DIBAYAR = [
        Claim::STATUS_APPROVED,
        Claim::STATUS_RECEIVED,
    ];

    /** Ambang dokumen menggantung yang dianggap perlu ditindaklanjuti. */
    private const AGING_THRESHOLD_DAYS = 3;

    /** Banyaknya baris pada tiap daftar "butuh perhatian". */
    private const ATTENTION_LIMIT = 50;

    public function getDashboard(array $filters): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($filters);

        return [
            'filters' => [
                'period' => $filters['period'],
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'cabang_id' => $filters['cabang_id'] ?? null,
                'department_id' => $filters['department_id'] ?? null,
                'aging_threshold_days' => self::AGING_THRESHOLD_DAYS,
            ],

            'summary' => $this->getSummary($filters, $startDate, $endDate),
            'revision' => $this->getRevision($filters, $startDate, $endDate),
            'schedule' => $this->getSchedule($filters),
            'statuses' => $this->getStatusBreakdown($filters, $startDate, $endDate),
            'trend' => $this->getTrend($filters, $startDate, $endDate),
            'approval_bottleneck' => $this->getApprovalBottleneck($filters),
            'attention' => $this->getAttentionItems($filters),

            'breakdown' => [
                'by_cabang' => $this->getBreakdown($filters, $startDate, $endDate, 'cabang'),
                'by_department' => $this->getBreakdown($filters, $startDate, $endDate, 'department'),
                'by_category' => $this->getBreakdownByCategory($filters, $startDate, $endDate),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Ringkasan
    |--------------------------------------------------------------------------
    */
    private function getSummary(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $base = fn (): Builder => $this->baseQuery($filters, $startDate, $endDate);

        $hitung = fn (Builder $query): ?object => $query
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(claims.total_amount), 0) AS nilai')
            ->first();

        $total = $hitung($base());

        $menunggu = $hitung(
            $base()->where('claims.status', Claim::STATUS_IN_PROGRESS),
        );

        $disetujui = $hitung(
            $base()->whereIn('claims.status', self::STATUS_DISETUJUI),
        );

        $dibayar = $hitung(
            $base()->where('claims.status', Claim::STATUS_PAID),
        );

        $ditolak = $hitung(
            $base()->whereIn('claims.status', [Claim::STATUS_REJECTED, Claim::STATUS_CANCELLED]),
        );

        $jumlahDisetujui = (int) ($disetujui->jumlah ?? 0);
        $jumlahDitolak = (int) ($ditolak->jumlah ?? 0);
        $diputuskan = $jumlahDisetujui + $jumlahDitolak;

        return [
            'submitted' => $this->pair($total),
            'waiting' => $this->pair($menunggu),
            'approved' => $this->pair($disetujui),
            'paid' => $this->pair($dibayar),
            'rejected' => $this->pair($ditolak),

            /* Dihitung dari yang SUDAH SELESAI saja -- lihat dashboard FPU. */
            'approval_rate' => $diputuskan > 0
                ? round(($jumlahDisetujui / $diputuskan) * 100, 1)
                : 0.0,

            'average_approval_days' => $this->rataHari(
                $filters,
                $startDate,
                $endDate,
                akhir: 'claims.final_approved_at',
                awal: 'COALESCE(claims.submitted_at, claims.created_at)',
                wajibAda: ['claims.final_approved_at'],
            ),

            /*
            | Jarak dari disetujui sampai uangnya sampai ke karyawan. Inilah
            | angka yang paling dirasakan pengaju: ia sudah membayar dengan
            | uangnya sendiri, dan ini lamanya ia menunggu diganti.
            */
            'average_payment_days' => $this->rataHari(
                $filters,
                $startDate,
                $endDate,
                akhir: 'claims.paid_at',
                awal: 'claims.final_approved_at',
                wajibAda: ['claims.paid_at', 'claims.final_approved_at'],
            ),

            /* Angka posisi, bukan angka periode -- lihat catatan kelas. */
            'unpaid' => $this->belumDiganti($filters),
        ];
    }

    /**
     * Rata-rata hari antara dua penanda waktu, dalam periode terpilih.
     *
     * Baris yang salah satu penandanya kosong tidak ikut dihitung, bukan
     * dihitung nol: claim yang belum dibayar bukan claim yang dibayar dalam
     * nol hari.
     *
     * @param  array<int, string>  $wajibAda
     */
    private function rataHari(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        string $akhir,
        string $awal,
        array $wajibAda,
    ): float {
        $query = $this->baseQuery($filters, $startDate, $endDate);

        foreach ($wajibAda as $kolom) {
            $query->whereNotNull($kolom);
        }

        $row = $query
            ->selectRaw("AVG(EXTRACT(EPOCH FROM ({$akhir} - {$awal})) / 86400) AS rata")
            ->first();

        return round((float) ($row->rata ?? 0), 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Belum diganti
    |--------------------------------------------------------------------------
    | Claim yang sudah disetujui tetapi belum dibayar. Setiap rupiah di sini
    | adalah uang karyawan yang sedang dipegang perusahaan -- ia sudah
    | membayar dengan uangnya sendiri dan belum menerima gantinya.
    |
    | Tidak dibatasi periode: yang disetujui bulan lalu dan belum dibayar
    | justru yang paling perlu terlihat.
    |--------------------------------------------------------------------------
    */
    private function belumDiganti(array $filters): array
    {
        $row = $this->currentStateQuery($filters)
            ->whereIn('claims.status', self::STATUS_BELUM_DIBAYAR)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(claims.total_amount), 0) AS nilai')
            ->first();

        return $this->pair($row);
    }

    /*
    |--------------------------------------------------------------------------
    | Revisi nominal
    |--------------------------------------------------------------------------
    | Finance boleh memotong nilai yang diajukan. original_total_amount
    | menyimpan angka sebelum dipotong, dan NULL berarti tidak ada revisi --
    | bukan nol, dan bukan salinan.
    |
    | Yang dihitung hanya baris yang benar-benar direvisi. Memasukkan yang
    | tidak direvisi ke dalam penjumlahan akan menenggelamkan potongannya di
    | antara ratusan nol.
    |--------------------------------------------------------------------------
    */
    private function getRevision(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $disetujui = fn (): Builder => $this->baseQuery($filters, $startDate, $endDate)
            ->whereIn('claims.status', self::STATUS_DISETUJUI);

        $semua = (clone $disetujui())
            ->selectRaw('COUNT(*) AS jumlah')
            ->first();

        $direvisi = (clone $disetujui())
            ->whereNotNull('claims.original_total_amount')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(claims.original_total_amount), 0) AS diajukan')
            ->selectRaw('COALESCE(SUM(claims.total_amount), 0) AS disetujui')
            ->first();

        $jumlahSemua = (int) ($semua->jumlah ?? 0);
        $jumlahDirevisi = (int) ($direvisi->jumlah ?? 0);

        $diajukan = (float) ($direvisi->diajukan ?? 0);
        $nilaiDisetujui = (float) ($direvisi->disetujui ?? 0);

        return [
            'count' => $jumlahDirevisi,
            'requested_amount' => $diajukan,
            'approved_amount' => $nilaiDisetujui,

            /*
            | Positif berarti dipotong. Bisa negatif bila justru dinaikkan --
            | jarang, tetapi tidak dilarang, dan menyembunyikan tandanya akan
            | membuat angkanya berbohong.
            */
            'trimmed_amount' => $diajukan - $nilaiDisetujui,

            'revision_rate' => $jumlahSemua > 0
                ? round(($jumlahDirevisi / $jumlahSemua) * 100, 1)
                : 0.0,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Jadwal pembayaran
    |--------------------------------------------------------------------------
    | Dua keadaan yang berbeda, dan keduanya perlu dibedakan:
    |
    |   LEWAT JADWAL    -- tanggalnya sudah ditetapkan dan sudah lewat. Ini
    |                      janji yang dilanggar.
    |   BELUM DIJADWAL  -- tanggalnya belum ada sama sekali. Ini bahkan belum
    |                      masuk antrean pembayaran.
    |
    | Yang kedua lebih mudah terlewat justru karena tidak pernah muncul di
    | daftar mana pun yang diurutkan menurut tanggal bayar.
    |
    | Keduanya angka posisi, tidak dibatasi periode.
    |--------------------------------------------------------------------------
    */
    private function getSchedule(array $filters): array
    {
        $hariIni = CarbonImmutable::now()->toDateString();

        $lewat = $this->currentStateQuery($filters)
            ->whereIn('claims.status', self::STATUS_BELUM_DIBAYAR)
            ->whereNotNull('claims.scheduled_payment_date')
            ->whereDate('claims.scheduled_payment_date', '<', $hariIni)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(claims.total_amount), 0) AS nilai')
            ->first();

        $belum = $this->currentStateQuery($filters)
            ->whereIn('claims.status', self::STATUS_BELUM_DIBAYAR)
            ->whereNull('claims.scheduled_payment_date')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(claims.total_amount), 0) AS nilai')
            ->first();

        return [
            'overdue' => $this->pair($lewat),
            'unscheduled' => $this->pair($belum),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Sebaran status
    |--------------------------------------------------------------------------
    */
    private function getStatusBreakdown(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->selectRaw('claims.status')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(claims.total_amount), 0) AS nilai')
            ->groupBy('claims.status')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'status' => (string) $row->status,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->nilai,
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Tren
    |--------------------------------------------------------------------------
    | Nilai diajukan dibanding nilai yang benar-benar dibayarkan. Jarak antara
    | keduanya adalah uang yang masih tertahan pada periode itu.
    |--------------------------------------------------------------------------
    */
    private function getTrend(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $days = $startDate->diffInDays($endDate) + 1;

        $granularity = match (true) {
            $days <= 31 => 'day',
            $days <= 180 => 'week',
            default => 'month',
        };

        $bucket = match ($granularity) {
            'day' => "TO_CHAR(claims.date, 'YYYY-MM-DD')",
            'week' => "TO_CHAR(claims.date, 'IYYY-\"W\"IW')",
            default => "TO_CHAR(claims.date, 'YYYY-MM')",
        };

        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(claims.total_amount), 0) AS nilai')
            ->selectRaw(
                'SUM(CASE WHEN claims.status = ? THEN 1 ELSE 0 END) AS dibayar',
                [Claim::STATUS_PAID],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN claims.status = ? THEN claims.total_amount ELSE 0 END), 0)'
                . ' AS nilai_dibayar',
                [Claim::STATUS_PAID],
            )
            ->groupByRaw($bucket)
            ->orderByRaw($bucket)
            ->get();

        return [
            'granularity' => $granularity,

            'points' => $rows
                ->map(fn ($row): array => [
                    'bucket' => (string) $row->bucket,
                    'label' => $this->formatTrendLabel((string) $row->bucket, $granularity),
                    'count' => (int) $row->jumlah,
                    'paid_count' => (int) $row->dibayar,
                    'amount' => (float) $row->nilai,
                    'paid_amount' => (float) $row->nilai_dibayar,
                ])
                ->values()
                ->all(),
        ];
    }

    private function formatTrendLabel(string $bucket, string $granularity): string
    {
        return match ($granularity) {
            'day' => CarbonImmutable::parse($bucket)->translatedFormat('d M'),
            'week' => str_replace('-W', ' Minggu ', $bucket),
            default => CarbonImmutable::parse($bucket . '-01')->translatedFormat('M Y'),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Penumpukan approval
    |--------------------------------------------------------------------------
    */
    private function getApprovalBottleneck(array $filters): array
    {
        $rows = $this->tahapAktifQuery($filters)
            ->selectRaw('tahap.step_label AS tahap')
            ->selectRaw('MIN(tahap.step_order) AS urutan')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(claims.total_amount), 0) AS nilai')
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (NOW() - COALESCE(claims.submitted_at, claims.created_at)))'
                . ' / 86400) AS rata_hari',
            )
            ->selectRaw(
                'MAX(EXTRACT(EPOCH FROM (NOW() - COALESCE(claims.submitted_at, claims.created_at)))'
                . ' / 86400) AS maks_hari',
            )
            ->groupBy('tahap.step_label')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'step_label' => (string) $row->tahap,
                'step_order' => (int) $row->urutan,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->nilai,
                'average_waiting_days' => round((float) $row->rata_hari, 1),
                'longest_waiting_days' => round((float) $row->maks_hari, 1),
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Butuh perhatian
    |--------------------------------------------------------------------------
    | Tiga daftar, berurutan menurut perjalanan uangnya:
    |
    | 1. Tertahan di approval -- belum diputuskan siapa pun
    | 2. Belum dijadwalkan    -- disetujui, tetapi belum masuk antrean bayar
    | 3. Lewat jadwal bayar   -- sudah dijanjikan tanggalnya, dan terlewat
    |
    | Ketiganya saling lepas, tidak ada dokumen yang muncul di dua daftar
    | sekaligus -- jadi menjumlahkannya tidak akan menghitung ganda.
    |--------------------------------------------------------------------------
    */
    private function getAttentionItems(array $filters): array
    {
        return [
            'threshold_days' => self::AGING_THRESHOLD_DAYS,
            'stuck_approval' => $this->getStuckApprovals($filters),
            'unscheduled' => $this->getUnscheduled($filters),
            'overdue_payment' => $this->getOverduePayment($filters),
        ];
    }

    private function getStuckApprovals(array $filters): array
    {
        $rows = $this->tahapAktifQuery($filters)
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'claims.branch')
            ->leftJoin('departments', 'departments.id', '=', 'claims.department_id')
            ->selectRaw('claims.id')
            ->selectRaw('claims.claim_number')
            ->selectRaw('claims.date')
            ->selectRaw('claims.total_amount')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw('tahap.step_label AS tahap')
            ->selectRaw('tahap.approvers AS penyetuju')
            ->selectRaw('COALESCE(claims.submitted_at, claims.created_at) AS menunggu_sejak')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - COALESCE(claims.submitted_at, claims.created_at)))'
                . ' / 86400 AS lama_hari',
            )
            ->orderByRaw('COALESCE(claims.submitted_at, claims.created_at) ASC')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->claim_number,
                'date' => $row->date,
                'amount' => (float) $row->total_amount,
                'cabang' => $row->nama_cabang ?? '-',
                'department' => $row->nama_department ?? '-',
                'step_label' => (string) $row->tahap,
                'approver' => (string) $row->penyetuju,
                'waiting_since' => $row->menunggu_sejak,
                'waiting_days' => round((float) $row->lama_hari, 1),
                'is_overdue' => (float) $row->lama_hari >= self::AGING_THRESHOLD_DAYS,
            ])
            ->values()
            ->all();
    }

    /*
    | Disetujui tetapi belum punya tanggal bayar sama sekali.
    |
    | Diurutkan dari yang paling lama disetujui: makin lama menggantung tanpa
    | jadwal, makin besar kemungkinan ia memang terlupakan.
    */
    private function getUnscheduled(array $filters): array
    {
        $rows = $this->claimBelumDibayarQuery($filters)
            ->whereNull('claims.scheduled_payment_date')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - COALESCE(claims.final_approved_at, claims.created_at)))'
                . ' / 86400 AS lama_hari',
            )
            ->orderByRaw('COALESCE(claims.final_approved_at, claims.created_at) ASC')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->claim_number,
                'status' => (string) $row->status,
                'amount' => (float) $row->total_amount,
                'cabang' => $row->nama_cabang ?? '-',
                'department' => $row->nama_department ?? '-',
                'requester' => (string) $row->nama_pemohon,
                'approved_at' => $row->final_approved_at,
                'idle_days' => round((float) $row->lama_hari, 1),
                'is_overdue' => (float) $row->lama_hari >= self::AGING_THRESHOLD_DAYS,
            ])
            ->values()
            ->all();
    }

    /*
    | Tanggal bayarnya sudah ditetapkan dan sudah lewat, tetapi belum dibayar.
    |
    | Diurutkan dari yang tanggalnya paling tua -- yang paling lama dilanggar
    | janjinya.
    */
    private function getOverduePayment(array $filters): array
    {
        $hariIni = CarbonImmutable::now()->toDateString();

        $rows = $this->claimBelumDibayarQuery($filters)
            ->whereNotNull('claims.scheduled_payment_date')
            ->whereDate('claims.scheduled_payment_date', '<', $hariIni)
            ->selectRaw('claims.scheduled_payment_date')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - claims.scheduled_payment_date::timestamp))'
                . ' / 86400 AS telat_hari',
            )
            ->orderBy('claims.scheduled_payment_date')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->claim_number,
                'status' => (string) $row->status,
                'amount' => (float) $row->total_amount,
                'cabang' => $row->nama_cabang ?? '-',
                'department' => $row->nama_department ?? '-',
                'requester' => (string) $row->nama_pemohon,
                'approved_at' => $row->final_approved_at,
                'scheduled_payment_date' => $row->scheduled_payment_date,
                'overdue_days' => (int) floor((float) $row->telat_hari),
            ])
            ->values()
            ->all();
    }

    /**
     * Rangka bersama kedua daftar pembayaran.
     *
     * Keduanya menanyakan hal yang sama -- claim disetujui yang belum dibayar,
     * berikut nama cabang, department, dan pemohonnya -- dan hanya berbeda
     * pada syarat jadwalnya. Ditulis dua kali, perbedaan yang tidak disengaja
     * akan menyelinap masuk cepat atau lambat.
     */
    private function claimBelumDibayarQuery(array $filters): Builder
    {
        return $this->currentStateQuery($filters)
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'claims.branch')
            ->leftJoin('departments', 'departments.id', '=', 'claims.department_id')
            ->leftJoin('users AS pemohon', 'pemohon.id', '=', 'claims.created_by')
            ->whereIn('claims.status', self::STATUS_BELUM_DIBAYAR)
            ->selectRaw('claims.id')
            ->selectRaw('claims.claim_number')
            ->selectRaw('claims.status')
            ->selectRaw('claims.total_amount')
            ->selectRaw('claims.final_approved_at')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw("COALESCE(NULLIF(TRIM(pemohon.name), ''), '-') AS nama_pemohon");
    }

    /*
    |--------------------------------------------------------------------------
    | Sebaran
    |--------------------------------------------------------------------------
    */
    private function getBreakdown(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        string $dimension,
    ): array {
        $query = $this->baseQuery($filters, $startDate, $endDate);

        if ($dimension === 'cabang') {
            $query
                ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'claims.branch')
                ->selectRaw('cabang.id AS dimensi_id')
                ->selectRaw("COALESCE(cabang.nama_cabang, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('cabang.id', 'cabang.nama_cabang');
        } else {
            $query
                ->leftJoin('departments', 'departments.id', '=', 'claims.department_id')
                ->selectRaw('departments.id AS dimensi_id')
                ->selectRaw("COALESCE(departments.nama, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('departments.id', 'departments.nama');
        }

        $rows = $query
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(claims.total_amount), 0) AS nilai')
            ->selectRaw(
                'SUM(CASE WHEN claims.status = ? THEN 1 ELSE 0 END) AS menunggu',
                [Claim::STATUS_IN_PROGRESS],
            )
            ->selectRaw(
                'SUM(CASE WHEN claims.status = ? THEN 1 ELSE 0 END) AS dibayar',
                [Claim::STATUS_PAID],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN claims.status IN (?, ?) THEN claims.total_amount ELSE 0 END), 0)'
                . ' AS nilai_belum_dibayar',
                self::STATUS_BELUM_DIBAYAR,
            )
            ->orderByRaw('COALESCE(SUM(claims.total_amount), 0) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => $row->dimensi_id === null ? null : (int) $row->dimensi_id,
                'name' => (string) $row->dimensi_nama,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->nilai,
                'waiting_count' => (int) $row->menunggu,
                'paid_count' => (int) $row->dibayar,
                'unpaid_amount' => (float) $row->nilai_belum_dibayar,
            ])
            ->values()
            ->all();
    }

    private function getBreakdownByCategory(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->leftJoin(
                'fund_request_transaction_categories AS kat',
                'kat.id',
                '=',
                'claims.transaction_category_id',
            )
            ->selectRaw('kat.id AS dimensi_id')
            ->selectRaw("COALESCE(kat.name, 'Tidak diisi') AS dimensi_nama")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(claims.total_amount), 0) AS nilai')
            ->groupBy('kat.id', 'kat.name')
            ->orderByRaw('COALESCE(SUM(claims.total_amount), 0) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => $row->dimensi_id === null ? null : (int) $row->dimensi_id,
                'name' => (string) $row->dimensi_nama,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->nilai,
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Akses
    |--------------------------------------------------------------------------
    | Sama persis dengan dashboard FPU dan Realisasi:
    |
    |   ALL            -- bebas memilih cabang dan department
    |   OWN_DEPARTMENT -- department dikunci, cabang bebas dipilih
    |   OWN_CABANG     -- cabang DAN department dikunci
    |   OWN_DATA       -- hanya dokumen yang ia buat sendiri
    |
    | Dikunci dengan SELURUH akses, bukan yang pertama.
    |--------------------------------------------------------------------------
    */
    public function resolveAccessAndFilters(User $user, array $filters): array
    {
        $scope = $user->getPermissionScope('dashboard.claim.view');

        $user->loadMissing(['cabang:id,nama_cabang', 'departemen:id,nama']);

        $branchIds = $user->accessibleBranchIds();
        $departmentIds = $user->accessibleDepartmentIds();

        $effectiveFilters = $filters;

        $canFilterCabang = false;
        $canFilterDepartment = false;

        switch ($scope) {
            case 'ALL':
                $canFilterCabang = true;
                $canFilterDepartment = true;

                break;

            case 'OWN_DATA':
                $effectiveFilters['created_by'] = $user->id;

                break;

            case 'OWN_DEPARTMENT':
                if ($departmentIds->isEmpty()) {
                    throw ValidationException::withMessages([
                        'department_id' => ['Department akun Anda belum ditentukan.'],
                    ]);
                }

                $effectiveFilters['department_ids'] = $departmentIds->all();

                $canFilterCabang = true;

                break;

            case 'OWN_CABANG':
                if ($branchIds->isEmpty() || $departmentIds->isEmpty()) {
                    throw ValidationException::withMessages([
                        'cabang_id' => ['Cabang atau department akun Anda belum ditentukan.'],
                    ]);
                }

                $effectiveFilters['cabang_ids'] = $branchIds->all();
                $effectiveFilters['department_ids'] = $departmentIds->all();

                break;

            default:
                throw new AuthorizationException(
                    'Scope permission Anda tidak mengizinkan akses ke dashboard Claim.',
                );
        }

        if (!$canFilterCabang) {
            unset($effectiveFilters['cabang_id']);
        }

        if (!$canFilterDepartment) {
            unset($effectiveFilters['department_id']);
        }

        return [
            'filters' => $effectiveFilters,

            'access' => [
                'scope_view' => $scope,
                'can_filter_cabang' => $canFilterCabang,
                'can_filter_department' => $canFilterDepartment,
                'own_data_only' => isset($effectiveFilters['created_by']),

                'cabang_id' => isset($effectiveFilters['cabang_id'])
                    ? (int) $effectiveFilters['cabang_id']
                    : null,

                'department_id' => isset($effectiveFilters['department_id'])
                    ? (int) $effectiveFilters['department_id']
                    : null,

                'locked_cabang_names' => $scope === 'OWN_CABANG'
                    ? $this->namaCabang($branchIds)
                    : [],

                'locked_department_names' => in_array($scope, ['OWN_CABANG', 'OWN_DEPARTMENT'], true)
                    ? $this->namaDepartment($departmentIds)
                    : [],
            ],
        ];
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return array<int, string>
     */
    private function namaCabang(Collection $ids): array
    {
        return $ids->isEmpty()
            ? []
            : DB::table('cabang')
                ->whereIn('id', $ids->all())
                ->orderBy('nama_cabang')
                ->pluck('nama_cabang')
                ->map(fn ($nama): string => (string) $nama)
                ->all();
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return array<int, string>
     */
    private function namaDepartment(Collection $ids): array
    {
        return $ids->isEmpty()
            ? []
            : DB::table('departments')
                ->whereIn('id', $ids->all())
                ->orderBy('nama')
                ->pluck('nama')
                ->map(fn ($nama): string => (string) $nama)
                ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    /** Pertanyaan tentang PERIODE: disaring rentang tanggal claim. */
    private function baseQuery(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): Builder {
        $query = Claim::query()
            ->whereBetween('claims.date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ]);

        $this->applyScope($query, $filters);

        return $query;
    }

    /** Pertanyaan tentang POSISI SAAT INI: tanpa batas periode. */
    private function currentStateQuery(array $filters): Builder
    {
        $query = Claim::query();

        $this->applyScope($query, $filters);

        return $query;
    }

    /**
     * Claim yang sedang menunggu, beserta tahap aktifnya -- SATU BARIS PER
     * DOKUMEN.
     *
     * Approval diciutkan dulu menjadi satu baris per dokumen, baru
     * disambungkan. Satu tingkat bisa berisi beberapa baris approver (mode ANY
     * dengan dua role); menyambung langsung ke tabel approval melipatkan
     * barisnya, dan jumlah maupun nilainya ikut terhitung berkali-kali.
     *
     * Tahap aktif = step_order TERKECIL yang masih WAITING.
     */
    private function tahapAktifQuery(array $filters): Builder
    {
        $tingkatTerkecil = DB::table('claim_approvals')
            ->select('claim_id')
            ->selectRaw('MIN(step_order) AS step_aktif')
            ->where('status', 'WAITING')
            ->groupBy('claim_id');

        $tahap = DB::table('claim_approvals AS ca')
            ->joinSub($tingkatTerkecil, 'm', function ($join): void {
                $join
                    ->on('m.claim_id', '=', 'ca.claim_id')
                    ->on('m.step_aktif', '=', 'ca.step_order');
            })
            ->where('ca.status', 'WAITING')
            ->groupBy('ca.claim_id', 'ca.step_order')
            ->select('ca.claim_id')
            ->selectRaw('ca.step_order AS step_order')
            ->selectRaw(
                "COALESCE(NULLIF(TRIM(MIN(ca.label)), ''), 'Tahap ' || ca.step_order) AS step_label",
            )
            ->selectRaw(
                "COALESCE(STRING_AGG(DISTINCT NULLIF(TRIM(ca.approver_name_snapshot), ''), ', '), '-')"
                . ' AS approvers',
            );

        $query = Claim::query()
            ->joinSub($tahap, 'tahap', 'tahap.claim_id', '=', 'claims.id')
            ->where('claims.status', Claim::STATUS_IN_PROGRESS);

        $this->applyScope($query, $filters);

        return $query;
    }

    /**
     * Pembatasan wilayah, dipakai SETIAP query tanpa kecuali.
     *
     * Ditulis sekali di sini, bukan diulang di tiap perhitungan: satu
     * perhitungan yang lupa menerapkannya akan membocorkan angka cabang lain
     * ke dalam ringkasan, dan kebocoran seperti itu tidak terlihat sampai ada
     * yang menghitung ulang secara manual.
     */
    private function applyScope(Builder $query, array $filters): void
    {
        /* Penguncian dari scope permission -- tidak bisa ditawar user. */
        if (isset($filters['cabang_ids'])) {
            $daftar = array_map(fn ($id): string => (string) $id, (array) $filters['cabang_ids']);

            $daftar === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('claims.branch', $daftar);
        }

        if (isset($filters['department_ids'])) {
            $daftar = array_map('intval', (array) $filters['department_ids']);

            $daftar === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('claims.department_id', $daftar);
        }

        if (isset($filters['created_by'])) {
            $query->where('claims.created_by', (int) $filters['created_by']);
        }

        /* Pilihan user sendiri, dipersempit di dalam wilayah yang boleh. */
        if (isset($filters['cabang_id'])) {
            $query->where('claims.branch', (string) $filters['cabang_id']);
        }

        if (isset($filters['department_id'])) {
            $query->where('claims.department_id', (int) $filters['department_id']);
        }
    }

    /**
     * @return array{count: int, amount: float}
     */
    private function pair(?object $row): array
    {
        return [
            'count' => (int) ($row->jumlah ?? 0),
            'amount' => (float) ($row->nilai ?? 0),
        ];
    }

    private function resolveDateRange(array $filters): array
    {
        return match ($filters['period']) {
            'day' => $this->resolveDayPeriod($filters['date']),
            'week' => $this->resolveWeekPeriod($filters['week']),
            'month' => $this->resolveMonthPeriod($filters['month']),
            'year' => $this->resolveYearPeriod((int) $filters['year']),
            'range' => $this->resolveCustomRange($filters['start_date'], $filters['end_date']),
            default => throw new InvalidArgumentException('Periode dashboard tidak valid.'),
        };
    }

    private function resolveDayPeriod(string $date): array
    {
        $selected = CarbonImmutable::parse($date);

        return [$selected->startOfDay(), $selected->endOfDay()];
    }

    private function resolveWeekPeriod(string $week): array
    {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $week, $matches)) {
            throw new InvalidArgumentException('Format minggu tidak valid.');
        }

        $start = CarbonImmutable::now()
            ->setISODate((int) $matches[1], (int) $matches[2])
            ->startOfWeek();

        return [$start, $start->endOfWeek()];
    }

    private function resolveMonthPeriod(string $month): array
    {
        $selected = CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01");

        return [$selected->startOfMonth(), $selected->endOfMonth()];
    }

    private function resolveYearPeriod(int $year): array
    {
        $selected = CarbonImmutable::create(year: $year, month: 1, day: 1);

        return [$selected->startOfYear(), $selected->endOfYear()];
    }

    private function resolveCustomRange(string $startDate, string $endDate): array
    {
        return [
            CarbonImmutable::parse($startDate)->startOfDay(),
            CarbonImmutable::parse($endDate)->endOfDay(),
        ];
    }
}
