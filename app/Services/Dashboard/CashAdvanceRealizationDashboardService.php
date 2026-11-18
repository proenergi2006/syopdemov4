<?php

namespace App\Services\Dashboard;

use App\Models\CashAdvance;
use App\Models\CashAdvanceRealization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| Dashboard Realisasi FPU
|--------------------------------------------------------------------------
| Sudut pandangnya manajemen. Bentuk filter, penanganan scope, dan susunan
| responsnya mengikuti dashboard FPU supaya keduanya terasa satu keluarga --
| dan supaya angkanya bisa dibandingkan langsung.
|
| YANG DIJAWAB DASHBOARD INI BERBEDA DARI FPU.
|
| FPU menjawab "berapa uang yang keluar". Realisasi menjawab "berapa yang
| kembali bentuk pertanggungjawabannya, dan berapa yang belum". Jadi dua hal
| ini yang ditonjolkan, dan keduanya tidak punya padanan di dashboard FPU:
|
| 1. SELISIH. Realisasi hampir tidak pernah sama persis dengan uang mukanya.
|    Yang lebih kecil menyisakan uang yang harus dikembalikan pemohon
|    (RETURN); yang lebih besar menimbulkan kewajiban perusahaan membayar
|    kekurangannya (REIMBURSE). Keduanya uang nyata, dan keduanya menggantung
|    sampai dokumennya SETTLED.
|
| 2. YANG BELUM MASUK SAMA SEKALI. FPU yang sudah cair tetapi belum
|    dibuatkan realisasinya tidak muncul di tabel realisasi mana pun --
|    justru itulah yang paling perlu terlihat. Dihitung dari sisi FPU,
|    bukan dari sisi realisasi.
|
| ANGKA PERIODE DAN ANGKA POSISI DIBEDAKAN.
|
| "Masuk", "disetujui", "selesai" dihitung dalam rentang tanggal terpilih.
| Sedangkan selisih yang menggantung, penumpukan approval, dan seluruh daftar
| butuh perhatian TIDAK dibatasi periode: yang tertunggak sejak dua bulan lalu
| justru yang paling perlu terlihat.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationDashboardService
{
    /*
    | Status yang dianggap "jadi": pertanggungjawabannya diterima. DRAFT belum
    | tentu jadi; REJECTED, CANCELLED, dan NEED REVISION sudah gugur atau
    | sedang kembali ke pemohon.
    */
    private const STATUS_DISETUJUI = [
        CashAdvanceRealization::STATUS_APPROVED,
        CashAdvanceRealization::STATUS_RECEIVED,
        CashAdvanceRealization::STATUS_VERIFIED,
        CashAdvanceRealization::STATUS_ON_CHECKING,
        CashAdvanceRealization::STATUS_RETURN_DECLARED,
        CashAdvanceRealization::STATUS_SETTLED,
    ];

    /*
    | Sudah disetujui tetapi belum ditutup. Selama di sini, selisihnya -- uang
    | yang harus dikembalikan atau dibayar -- masih menggantung.
    */
    private const STATUS_BELUM_TUNTAS = [
        CashAdvanceRealization::STATUS_APPROVED,
        CashAdvanceRealization::STATUS_RECEIVED,
        CashAdvanceRealization::STATUS_VERIFIED,

        /*
        | Pengakuan pemohon belum menutup apa pun. Uangnya baru dianggap
        | kembali setelah Finance mengonfirmasinya, jadi selama di sini
        | selisihnya masih menggantung persis seperti sebelum dinyatakan.
        */
        CashAdvanceRealization::STATUS_ON_CHECKING,
        CashAdvanceRealization::STATUS_RETURN_DECLARED,
    ];

    /** Ambang dokumen menggantung yang dianggap perlu ditindaklanjuti. */
    private const AGING_THRESHOLD_DAYS = 3;

    /** Banyaknya baris pada tiap daftar "butuh perhatian". */
    private const ATTENTION_LIMIT = 50;

    /** Dipakai bila master batas pengajuan belum punya satu baris pun. */
    private const DEFAULT_REALIZATION_DAYS = 14;

    public function getDashboard(array $filters): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($filters);

        $batasRealisasi = $this->batasRealisasiHari();

        return [
            'filters' => [
                'period' => $filters['period'],
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'cabang_id' => $filters['cabang_id'] ?? null,
                'department_id' => $filters['department_id'] ?? null,
                'aging_threshold_days' => self::AGING_THRESHOLD_DAYS,
                'realization_limit_days' => $batasRealisasi,
            ],

            'summary' => $this->getSummary($filters, $startDate, $endDate),
            'difference' => $this->getDifference($filters, $startDate, $endDate),
            'statuses' => $this->getStatusBreakdown($filters, $startDate, $endDate),
            'trend' => $this->getTrend($filters, $startDate, $endDate),
            'approval_bottleneck' => $this->getApprovalBottleneck($filters),
            'attention' => $this->getAttentionItems($filters, $batasRealisasi),

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
    | Nilai yang dipakai di sini adalah NILAI REALISASINYA, bukan nilai uang
    | mukanya -- itulah yang benar-benar dibelanjakan. Uang mukanya muncul
    | terpisah pada bagian selisih, tempat keduanya memang harus dibandingkan.
    |--------------------------------------------------------------------------
    */
    private function getSummary(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $base = fn (): Builder => $this->baseQuery($filters, $startDate, $endDate);

        $total = (clone $base())
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) AS nilai')
            ->first();

        $menunggu = (clone $base())
            ->where('cash_advance_realizations.status', CashAdvanceRealization::STATUS_IN_PROGRESS)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) AS nilai')
            ->first();

        $disetujui = (clone $base())
            ->whereIn('cash_advance_realizations.status', self::STATUS_DISETUJUI)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) AS nilai')
            ->first();

        $selesai = (clone $base())
            ->where('cash_advance_realizations.status', CashAdvanceRealization::STATUS_SETTLED)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) AS nilai')
            ->first();

        $ditolak = (clone $base())
            ->whereIn('cash_advance_realizations.status', [
                CashAdvanceRealization::STATUS_REJECTED,
                CashAdvanceRealization::STATUS_CANCELLED,
            ])
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) AS nilai')
            ->first();

        $jumlahDisetujui = (int) ($disetujui->jumlah ?? 0);
        $jumlahDitolak = (int) ($ditolak->jumlah ?? 0);
        $diputuskan = $jumlahDisetujui + $jumlahDitolak;

        return [
            'submitted' => $this->pair($total),
            'waiting' => $this->pair($menunggu),
            'approved' => $this->pair($disetujui),
            'settled' => $this->pair($selesai),
            'rejected' => $this->pair($ditolak),

            /* Dihitung dari yang SUDAH SELESAI saja -- lihat dashboard FPU. */
            'approval_rate' => $diputuskan > 0
                ? round(($jumlahDisetujui / $diputuskan) * 100, 1)
                : 0.0,

            'average_approval_days' => $this->rataHari(
                $filters,
                $startDate,
                $endDate,
                akhir: 'cash_advance_realizations.final_approved_at',
                awal: 'COALESCE(cash_advance_realizations.submitted_at, cash_advance_realizations.created_at)',
                wajibAda: ['cash_advance_realizations.final_approved_at'],
            ),

            /*
            | Jarak dari disetujui sampai dokumennya benar-benar ditutup.
            | Di sinilah selisih yang menggantung bersembunyi: disetujui cepat
            | tetapi lama tidak ditutup berarti uangnya belum kembali.
            */
            'average_settlement_days' => $this->rataHari(
                $filters,
                $startDate,
                $endDate,
                akhir: 'cash_advance_realizations.settled_at',
                awal: 'cash_advance_realizations.final_approved_at',
                wajibAda: [
                    'cash_advance_realizations.settled_at',
                    'cash_advance_realizations.final_approved_at',
                ],
            ),

            /* Angka posisi, bukan angka periode -- lihat catatan kelas. */
            'not_submitted' => $this->belumDirealisasi($filters),
        ];
    }

    /**
     * Rata-rata hari antara dua penanda waktu, dalam periode terpilih.
     *
     * Baris yang salah satu penandanya kosong tidak ikut dihitung, bukan
     * dihitung nol: realisasi yang belum ditutup bukan realisasi yang ditutup
     * dalam nol hari.
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
    | Selisih
    |--------------------------------------------------------------------------
    | Bagian yang tidak punya padanan di dashboard FPU, dan alasan utama
    | dashboard ini ada.
    |
    | difference_amount POSITIF berarti realisasinya lebih kecil daripada uang
    | mukanya -- sisanya dikembalikan pemohon (RETURN). NEGATIF berarti
    | sebaliknya: perusahaan masih berutang kekurangannya (REIMBURSE).
    |
    | Nilai REIMBURSE dilaporkan sebagai angka POSITIF, karena yang ditanyakan
    | orang adalah "berapa yang harus kita bayar", bukan "minus berapa".
    | Tandanya sudah dibawa oleh namanya sendiri.
    |--------------------------------------------------------------------------
    */
    private function getDifference(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $base = fn (): Builder => $this->baseQuery($filters, $startDate, $endDate)
            ->whereIn('cash_advance_realizations.status', self::STATUS_DISETUJUI);

        $nilai = (clone $base())
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_advance_amount), 0) AS uang_muka')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) AS realisasi')
            ->selectRaw('COUNT(*) AS jumlah')
            ->first();

        $kembali = (clone $base())
            ->where('cash_advance_realizations.difference_type', CashAdvanceRealization::DIFFERENCE_RETURN)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.difference_amount), 0) AS nilai')
            ->first();

        $tambahan = (clone $base())
            ->where('cash_advance_realizations.difference_type', CashAdvanceRealization::DIFFERENCE_REIMBURSE)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(ABS(cash_advance_realizations.difference_amount)), 0) AS nilai')
            ->first();

        $pas = (clone $base())
            ->where(function ($query): void {
                $query
                    ->where(
                        'cash_advance_realizations.difference_type',
                        CashAdvanceRealization::DIFFERENCE_NONE,
                    )
                    ->orWhereNull('cash_advance_realizations.difference_type');
            })
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('0 AS nilai')
            ->first();

        $jumlahDinilai = (int) ($nilai->jumlah ?? 0);

        return [
            'advance_amount' => (float) ($nilai->uang_muka ?? 0),
            'realization_amount' => (float) ($nilai->realisasi ?? 0),

            'returned' => $this->pair($kembali),
            'reimbursed' => $this->pair($tambahan),
            'exact' => $this->pair($pas),

            /*
            | Berapa persen realisasi yang nilainya pas dengan uang mukanya.
            | Dibaca sebagai mutu perencanaan, bukan mutu pertanggungjawaban:
            | angka rendah berarti permintaan uang mukanya sering meleset.
            */
            'exact_rate' => $jumlahDinilai > 0
                ? round(((int) ($pas->jumlah ?? 0) / $jumlahDinilai) * 100, 1)
                : 0.0,

            /* Posisi saat ini: selisih yang belum ditutup, lepas dari periode. */
            'outstanding' => $this->selisihMenggantung($filters),
        ];
    }

    /*
    | Selisih yang belum ditutup.
    |
    | Realisasinya sudah disetujui tetapi dokumennya belum SETTLED, padahal
    | ada selisih. Selama itu, uang yang harus dikembalikan pemohon belum
    | kembali, dan kekurangan yang harus dibayar perusahaan belum dibayar.
    |
    | Tidak dibatasi periode.
    */
    private function selisihMenggantung(array $filters): array
    {
        $row = $this->currentStateQuery($filters)
            ->whereIn('cash_advance_realizations.status', self::STATUS_BELUM_TUNTAS)
            ->whereIn('cash_advance_realizations.difference_type', [
                CashAdvanceRealization::DIFFERENCE_RETURN,
                CashAdvanceRealization::DIFFERENCE_REIMBURSE,
            ])
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(ABS(cash_advance_realizations.difference_amount)), 0) AS nilai')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN cash_advance_realizations.difference_type = ?'
                . ' THEN cash_advance_realizations.difference_amount ELSE 0 END), 0) AS nilai_kembali',
                [CashAdvanceRealization::DIFFERENCE_RETURN],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN cash_advance_realizations.difference_type = ?'
                . ' THEN ABS(cash_advance_realizations.difference_amount) ELSE 0 END), 0) AS nilai_tambahan',
                [CashAdvanceRealization::DIFFERENCE_REIMBURSE],
            )
            ->first();

        return [
            'count' => (int) ($row->jumlah ?? 0),
            'amount' => (float) ($row->nilai ?? 0),
            'return_amount' => (float) ($row->nilai_kembali ?? 0),
            'reimburse_amount' => (float) ($row->nilai_tambahan ?? 0),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | FPU yang belum direalisasikan
    |--------------------------------------------------------------------------
    | DIHITUNG DARI SISI FPU, BUKAN DARI SISI REALISASI.
    |
    | Yang dicari justru dokumen yang TIDAK ADA di tabel realisasi. Menghitung
    | dari tabel realisasi tidak mungkin menemukannya -- dan itulah sebabnya
    | angka ini gampang terlewat.
    |
    | Definisinya sama persis dengan yang dipakai penjaga batas pengajuan
    | (FundRequestLimitService::outstandingQuery) dan dashboard FPU. Ketiganya
    | harus berbunyi sama: kalau berbeda, pemohon akan diblokir karena angka
    | yang tidak pernah terlihat di layar mana pun.
    |--------------------------------------------------------------------------
    */
    private function belumDirealisasi(array $filters): array
    {
        $row = $this->fpuBelumDirealisasiQuery($filters)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->first();

        return $this->pair($row);
    }

    private function fpuBelumDirealisasiQuery(array $filters): Builder
    {
        $query = CashAdvance::query()
            ->where('cash_advances.status', CashAdvance::STATUS_DISBURSED)
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->select(DB::raw(1))
                    ->from('cash_advance_realizations AS r')
                    ->whereColumn('r.cash_advance_id', 'cash_advances.id')
                    ->whereNull('r.deleted_at')
                    ->whereIn('r.status', self::STATUS_DISETUJUI);
            });

        $this->applyScope($query, $filters, 'cash_advances');

        return $query;
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
            ->selectRaw('cash_advance_realizations.status')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) AS nilai')
            ->groupBy('cash_advance_realizations.status')
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
    | Uang muka dibanding realisasinya, berdampingan. Jarak antara keduanya
    | ITULAH selisihnya -- terbaca langsung tanpa perlu menghitung.
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
            'day' => "TO_CHAR(cash_advance_realizations.date, 'YYYY-MM-DD')",
            'week' => "TO_CHAR(cash_advance_realizations.date, 'IYYY-\"W\"IW')",
            default => "TO_CHAR(cash_advance_realizations.date, 'YYYY-MM')",
        };

        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_advance_amount), 0) AS uang_muka')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) AS realisasi')
            ->selectRaw(
                'SUM(CASE WHEN cash_advance_realizations.status = ? THEN 1 ELSE 0 END) AS selesai',
                [CashAdvanceRealization::STATUS_SETTLED],
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
                    'settled_count' => (int) $row->selesai,
                    'advance_amount' => (float) $row->uang_muka,
                    'realization_amount' => (float) $row->realisasi,
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
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) AS nilai')
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (NOW() - COALESCE(cash_advance_realizations.submitted_at,'
                . ' cash_advance_realizations.created_at))) / 86400) AS rata_hari',
            )
            ->selectRaw(
                'MAX(EXTRACT(EPOCH FROM (NOW() - COALESCE(cash_advance_realizations.submitted_at,'
                . ' cash_advance_realizations.created_at))) / 86400) AS maks_hari',
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
    | Tiga daftar, berurutan menurut perjalanan pertanggungjawabannya:
    |
    | 1. Belum dibuat    -- FPU sudah cair, realisasinya belum ada sama sekali
    | 2. Tertahan        -- realisasinya ada, menunggu diputuskan
    | 3. Selisih belum tuntas -- sudah disetujui, uangnya belum kembali/dibayar
    |
    | Yang pertama sengaja diletakkan paling depan: ia satu-satunya yang tidak
    | muncul di layar modul Realisasi mana pun, karena dokumennya memang belum
    | ada. Tanpa dashboard ini, tidak ada tempat lain untuk melihatnya.
    |--------------------------------------------------------------------------
    */
    private function getAttentionItems(array $filters, int $batasRealisasi): array
    {
        return [
            'threshold_days' => self::AGING_THRESHOLD_DAYS,
            'realization_limit_days' => $batasRealisasi,
            'not_submitted' => $this->getNotSubmitted($filters, $batasRealisasi),
            'stuck_approval' => $this->getStuckApprovals($filters),
            'unsettled_difference' => $this->getUnsettledDifference($filters),
        ];
    }

    /*
    | FPU cair yang realisasinya belum dibuat, diurutkan dari yang paling tua.
    |
    | Yang menunggak paling lama adalah yang paling mungkin tidak akan
    | dipertanggungjawabkan sama sekali.
    */
    private function getNotSubmitted(array $filters, int $batasHari): array
    {
        $rows = $this->fpuBelumDirealisasiQuery($filters)
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'cash_advances.branch')
            ->leftJoin('departments', 'departments.id', '=', 'cash_advances.department_id')
            ->leftJoin('users AS pemohon', 'pemohon.id', '=', 'cash_advances.created_by')
            ->whereNotNull('cash_advances.disbursed_at')
            ->selectRaw('cash_advances.id')
            ->selectRaw('cash_advances.advance_number')
            ->selectRaw('cash_advances.total_amount')
            ->selectRaw('cash_advances.disbursed_at')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw("COALESCE(NULLIF(TRIM(pemohon.name), ''), '-') AS nama_pemohon")
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - cash_advances.disbursed_at)) / 86400 AS umur_hari',
            )

            /*
            | SUDAH DI MEJA PENYETUJU, ATAU MASIH DI TANGAN PEMOHON?
            |
            | Keduanya sama-sama "belum dipertanggungjawabkan" -- itulah yang
            | dihitung penjaga batas pengajuan, dan angkanya harus tetap sama.
            | Tetapi tindak lanjutnya berbeda jauh: yang satu kejar
            | penyetujunya, yang satu kejar pemohonnya. Tanpa pembeda ini
            | daftarnya menyuruh orang menagih dokumen yang sebenarnya sudah
            | di meja atasannya sendiri.
            |
            | HANYA IN PROGRESS yang dihitung "sedang diproses". DRAFT belum
            | dikirim, NEED REVISION dikembalikan, REJECTED dan CANCELLED
            | harus dibuat ulang -- keempatnya masih menunggu pemohonnya
            | bertindak, bukan penyetujunya.
            */
            ->selectRaw(
                'EXISTS (SELECT 1 FROM cash_advance_realizations AS rp'
                . ' WHERE rp.cash_advance_id = cash_advances.id'
                . ' AND rp.deleted_at IS NULL'
                . ' AND rp.status = ?) AS sedang_diproses',
                [CashAdvanceRealization::STATUS_IN_PROGRESS],
            )
            ->orderBy('cash_advances.disbursed_at')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(function ($row) use ($batasHari): array {
                $umur = (int) floor((float) $row->umur_hari);

                return [
                    'id' => (int) $row->id,
                    'number' => (string) $row->advance_number,
                    'amount' => (float) $row->total_amount,
                    'cabang' => $row->nama_cabang ?? '-',
                    'department' => $row->nama_department ?? '-',
                    'requester' => (string) $row->nama_pemohon,
                    'disbursed_at' => $row->disbursed_at,
                    'age_days' => $umur,
                    'overdue_days' => max(0, $umur - $batasHari),
                    'is_overdue' => $umur > $batasHari,
                    'in_progress' => (bool) $row->sedang_diproses,
                ];
            })
            ->values()
            ->all();
    }

    private function getStuckApprovals(array $filters): array
    {
        $rows = $this->tahapAktifQuery($filters)
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'cash_advance_realizations.branch')
            ->leftJoin('departments', 'departments.id', '=', 'cash_advance_realizations.department_id')
            ->selectRaw('cash_advance_realizations.id')
            ->selectRaw('cash_advance_realizations.realization_number')
            ->selectRaw('cash_advance_realizations.date')
            ->selectRaw('cash_advance_realizations.total_realization_amount')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw('tahap.step_label AS tahap')
            ->selectRaw('tahap.approvers AS penyetuju')
            ->selectRaw(
                'COALESCE(cash_advance_realizations.submitted_at, cash_advance_realizations.created_at)'
                . ' AS menunggu_sejak',
            )
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - COALESCE(cash_advance_realizations.submitted_at,'
                . ' cash_advance_realizations.created_at))) / 86400 AS lama_hari',
            )
            ->orderByRaw(
                'COALESCE(cash_advance_realizations.submitted_at, cash_advance_realizations.created_at) ASC',
            )
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->realization_number,
                'date' => $row->date,
                'amount' => (float) $row->total_realization_amount,
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

    private function getUnsettledDifference(array $filters): array
    {
        $rows = $this->currentStateQuery($filters)
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'cash_advance_realizations.branch')
            ->leftJoin('departments', 'departments.id', '=', 'cash_advance_realizations.department_id')
            ->whereIn('cash_advance_realizations.status', self::STATUS_BELUM_TUNTAS)
            ->whereIn('cash_advance_realizations.difference_type', [
                CashAdvanceRealization::DIFFERENCE_RETURN,
                CashAdvanceRealization::DIFFERENCE_REIMBURSE,
            ])
            ->selectRaw('cash_advance_realizations.id')
            ->selectRaw('cash_advance_realizations.realization_number')
            ->selectRaw('cash_advance_realizations.status')
            ->selectRaw('cash_advance_realizations.difference_type')
            ->selectRaw('ABS(cash_advance_realizations.difference_amount) AS selisih')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw('cash_advance_realizations.final_approved_at')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - COALESCE(cash_advance_realizations.final_approved_at,'
                . ' cash_advance_realizations.created_at))) / 86400 AS lama_hari',
            )
            ->orderByRaw(
                'COALESCE(cash_advance_realizations.final_approved_at,'
                . ' cash_advance_realizations.created_at) ASC',
            )
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->realization_number,
                'status' => (string) $row->status,
                'difference_type' => (string) $row->difference_type,
                'amount' => (float) $row->selisih,
                'cabang' => $row->nama_cabang ?? '-',
                'department' => $row->nama_department ?? '-',
                'approved_at' => $row->final_approved_at,
                'idle_days' => round((float) $row->lama_hari, 1),
                'is_overdue' => (float) $row->lama_hari >= self::AGING_THRESHOLD_DAYS,
            ])
            ->values()
            ->all();
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
                ->leftJoin(
                    'cabang',
                    DB::raw('CAST(cabang.id AS VARCHAR)'),
                    '=',
                    'cash_advance_realizations.branch',
                )
                ->selectRaw('cabang.id AS dimensi_id')
                ->selectRaw("COALESCE(cabang.nama_cabang, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('cabang.id', 'cabang.nama_cabang');
        } else {
            $query
                ->leftJoin('departments', 'departments.id', '=', 'cash_advance_realizations.department_id')
                ->selectRaw('departments.id AS dimensi_id')
                ->selectRaw("COALESCE(departments.nama, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('departments.id', 'departments.nama');
        }

        $rows = $query
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) AS nilai')
            ->selectRaw(
                'SUM(CASE WHEN cash_advance_realizations.status = ? THEN 1 ELSE 0 END) AS menunggu',
                [CashAdvanceRealization::STATUS_IN_PROGRESS],
            )
            ->selectRaw(
                'SUM(CASE WHEN cash_advance_realizations.status = ? THEN 1 ELSE 0 END) AS selesai',
                [CashAdvanceRealization::STATUS_SETTLED],
            )
            ->selectRaw(
                'COALESCE(SUM(ABS(cash_advance_realizations.difference_amount)), 0) AS selisih',
            )
            ->orderByRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => $row->dimensi_id === null ? null : (int) $row->dimensi_id,
                'name' => (string) $row->dimensi_nama,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->nilai,
                'waiting_count' => (int) $row->menunggu,
                'settled_count' => (int) $row->selesai,
                'difference_amount' => (float) $row->selisih,
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
                'cash_advance_realizations.transaction_category_id',
            )
            ->selectRaw('kat.id AS dimensi_id')
            ->selectRaw("COALESCE(kat.name, 'Tidak diisi') AS dimensi_nama")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) AS nilai')
            ->groupBy('kat.id', 'kat.name')
            ->orderByRaw('COALESCE(SUM(cash_advance_realizations.total_realization_amount), 0) DESC')
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
    | Sama persis dengan dashboard FPU:
    |
    |   ALL            -- bebas memilih cabang dan department
    |   OWN_DEPARTMENT -- department dikunci, cabang bebas dipilih
    |   OWN_CABANG     -- cabang DAN department dikunci
    |   OWN_DATA       -- hanya dokumen yang ia buat sendiri
    |
    | Dikunci dengan SELURUH akses, bukan yang pertama -- satu akun bisa punya
    | beberapa pasangan cabang-department lewat akses tambahan.
    |--------------------------------------------------------------------------
    */
    public function resolveAccessAndFilters(User $user, array $filters): array
    {
        $scope = $user->getPermissionScope('dashboard.cash-advance-realization.view');

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
                    'Scope permission Anda tidak mengizinkan akses ke dashboard Realisasi FPU.',
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

    /** Pertanyaan tentang PERIODE: disaring rentang tanggal realisasinya. */
    private function baseQuery(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): Builder {
        $query = CashAdvanceRealization::query()
            ->whereBetween('cash_advance_realizations.date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ]);

        $this->applyScope($query, $filters);

        return $query;
    }

    /** Pertanyaan tentang POSISI SAAT INI: tanpa batas periode. */
    private function currentStateQuery(array $filters): Builder
    {
        $query = CashAdvanceRealization::query();

        $this->applyScope($query, $filters);

        return $query;
    }

    /**
     * Realisasi yang sedang menunggu, beserta tahap aktifnya -- SATU BARIS
     * PER DOKUMEN.
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
        $tingkatTerkecil = DB::table('cash_advance_realization_approvals')
            ->select('cash_advance_realization_id')
            ->selectRaw('MIN(step_order) AS step_aktif')
            ->where('status', 'WAITING')
            ->groupBy('cash_advance_realization_id');

        $tahap = DB::table('cash_advance_realization_approvals AS cara')
            ->joinSub($tingkatTerkecil, 'm', function ($join): void {
                $join
                    ->on('m.cash_advance_realization_id', '=', 'cara.cash_advance_realization_id')
                    ->on('m.step_aktif', '=', 'cara.step_order');
            })
            ->where('cara.status', 'WAITING')
            ->groupBy('cara.cash_advance_realization_id', 'cara.step_order')
            ->select('cara.cash_advance_realization_id')
            ->selectRaw('cara.step_order AS step_order')
            ->selectRaw(
                "COALESCE(NULLIF(TRIM(MIN(cara.label)), ''), 'Tahap ' || cara.step_order) AS step_label",
            )
            ->selectRaw(
                "COALESCE(STRING_AGG(DISTINCT NULLIF(TRIM(cara.approver_name_snapshot), ''), ', '), '-')"
                . ' AS approvers',
            );

        $query = CashAdvanceRealization::query()
            ->joinSub(
                $tahap,
                'tahap',
                'tahap.cash_advance_realization_id',
                '=',
                'cash_advance_realizations.id',
            )
            ->where('cash_advance_realizations.status', CashAdvanceRealization::STATUS_IN_PROGRESS);

        $this->applyScope($query, $filters);

        return $query;
    }

    /**
     * Pembatasan wilayah, dipakai SETIAP query tanpa kecuali.
     *
     * Tabelnya ikut menjadi parameter karena satu perhitungan -- FPU yang
     * belum direalisasikan -- bertanya ke cash_advances, bukan ke tabel
     * realisasi. Kolom wilayahnya bernama sama di kedua tabel, tetapi
     * prefiksnya harus benar atau PostgreSQL menolak querinya.
     */
    private function applyScope(
        Builder $query,
        array $filters,
        string $tabel = 'cash_advance_realizations',
    ): void {
        if (isset($filters['cabang_ids'])) {
            $daftar = array_map(fn ($id): string => (string) $id, (array) $filters['cabang_ids']);

            $daftar === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn("{$tabel}.branch", $daftar);
        }

        if (isset($filters['department_ids'])) {
            $daftar = array_map('intval', (array) $filters['department_ids']);

            $daftar === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn("{$tabel}.department_id", $daftar);
        }

        if (isset($filters['created_by'])) {
            $query->where("{$tabel}.created_by", (int) $filters['created_by']);
        }

        if (isset($filters['cabang_id'])) {
            $query->where("{$tabel}.branch", (string) $filters['cabang_id']);
        }

        if (isset($filters['department_id'])) {
            $query->where("{$tabel}.department_id", (int) $filters['department_id']);
        }
    }

    /**
     * Batas umur realisasi yang berlaku umum.
     *
     * Master batas pengajuan bisa punya baris khusus per area dan department,
     * tetapi dashboard membaca banyak dokumen sekaligus dari berbagai
     * department -- jadi yang dipakai BATAS UMUM. Angkanya dikirim ke layar,
     * bukan ditulis mati di sana.
     */
    private function batasRealisasiHari(): int
    {
        $umum = DB::table('fund_request_limits')
            ->where('is_active', true)
            ->whereNull('area_type')
            ->whereNull('department_id')
            ->orderBy('id')
            ->value('max_realization_days');

        return (int) ($umum ?: self::DEFAULT_REALIZATION_DAYS);
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
