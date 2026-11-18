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
| Dashboard FPU (Form Pengajuan Uang)
|--------------------------------------------------------------------------
| Sudut pandangnya manajemen, bukan operator. Bentuk filter, penanganan
| scope, dan susunan responsnya mengikuti dashboard Purchase Requisition dan
| Purchase Order supaya ketiganya terasa satu keluarga.
|
| Yang membedakannya dari PR/PO: FPU adalah UANG YANG KELUAR LEBIH DULU, lalu
| dipertanggungjawabkan belakangan. Jadi pertanyaan yang dijawab bukan
| berhenti di "berapa yang diajukan dan disetujui", melainkan sampai ke
| "berapa yang sudah keluar dan belum kembali bentuk pertanggungjawabannya".
|
| Lima pertanyaan yang dijawab dashboard ini:
|
| 1. Berapa banyak dan berapa nilainya periode ini
| 2. Berapa lama sampai disetujui, dan menumpuk di tahap siapa
| 3. Mana yang sudah disetujui tetapi uangnya belum juga dicairkan
| 4. BERAPA DANA YANG MASIH DI TANGAN ORANG dan mana yang sudah lewat batas
|    realisasi
| 5. Cabang, department, dan keperluan apa yang paling banyak menyerap dana
|
| ANGKA PERIODE DAN ANGKA POSISI SENGAJA DIBEDAKAN.
|
| "Diajukan", "disetujui", "dicairkan" dihitung dalam rentang tanggal yang
| dipilih -- itu pertanyaan tentang periode. Sedangkan "dana berjalan",
| "penumpukan approval", dan seluruh daftar butuh perhatian TIDAK dibatasi
| periode: FPU yang menggantung sejak dua bulan lalu justru yang paling perlu
| terlihat, dan akan lenyap bila ikut disaring tanggal pengajuannya.
|--------------------------------------------------------------------------
*/
class CashAdvanceDashboardService
{
    /*
    | Status yang dianggap "jadi": pengajuannya lolos dan uangnya memang akan
    | atau sudah keluar. DRAFT belum tentu jadi; REJECTED, CANCELLED, dan NEED
    | REVISION sudah gugur atau sedang kembali ke pemohon. Semuanya tetap
    | dihitung pada sebaran status, hanya tidak ikut menyusun nilai disetujui.
    */
    private const STATUS_DISETUJUI = [
        CashAdvance::STATUS_APPROVED,
        CashAdvance::STATUS_RECEIVED,
        CashAdvance::STATUS_DISBURSED,
    ];

    /** Sudah disetujui tetapi uangnya belum keluar. */
    private const STATUS_BELUM_CAIR = [
        CashAdvance::STATUS_APPROVED,
        CashAdvance::STATUS_RECEIVED,
    ];

    /** Realisasi yang dianggap sudah menggugurkan kewajiban pemohon. */
    private const REALISASI_SAH = [
        CashAdvanceRealization::STATUS_APPROVED,
        CashAdvanceRealization::STATUS_RECEIVED,
        CashAdvanceRealization::STATUS_VERIFIED,
        CashAdvanceRealization::STATUS_ON_CHECKING,
        CashAdvanceRealization::STATUS_RETURN_DECLARED,
        CashAdvanceRealization::STATUS_SETTLED,
    ];

    /** Ambang FPU menggantung yang dianggap perlu ditindaklanjuti. */
    private const AGING_THRESHOLD_DAYS = 3;

    /*
    | Banyaknya baris pada tiap daftar "butuh perhatian".
    |
    | Layar menampilkan tiga baris dulu lalu membukanya bertahap sampai habis,
    | jadi batas ini adalah "habis" yang sebenarnya. Dipatok delapan, daftar
    | yang benar-benar menunggak akan terpotong diam-diam dan yang membacanya
    | mengira hanya segitu.
    |
    | Tetap ada batasnya: tanpa itu satu cabang yang menunggak ratusan dokumen
    | akan mengirim seluruhnya dalam satu respons, dan dashboard yang dibuka
    | untuk melihat gambaran besar justru menjadi yang paling lambat dimuat.
    */
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
    | Angka yang dibaca lebih dulu. "Dana berjalan" sengaja ikut di sini
    | walaupun ia angka posisi, bukan angka periode: itulah satu-satunya angka
    | yang menyatakan berapa uang perusahaan yang sedang berada di luar kas.
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
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->first();

        $menunggu = (clone $base())
            ->where('cash_advances.status', CashAdvance::STATUS_IN_PROGRESS)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->first();

        $disetujui = (clone $base())
            ->whereIn('cash_advances.status', self::STATUS_DISETUJUI)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->first();

        $dicairkan = (clone $base())
            ->where('cash_advances.status', CashAdvance::STATUS_DISBURSED)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->first();

        $ditolak = (clone $base())
            ->whereIn('cash_advances.status', [
                CashAdvance::STATUS_REJECTED,
                CashAdvance::STATUS_CANCELLED,
            ])
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->first();

        $jumlahDisetujui = (int) ($disetujui->jumlah ?? 0);
        $jumlahDitolak = (int) ($ditolak->jumlah ?? 0);
        $selesai = $jumlahDisetujui + $jumlahDitolak;

        return [
            'submitted' => $this->pair($total),
            'waiting' => $this->pair($menunggu),
            'approved' => $this->pair($disetujui),
            'disbursed' => $this->pair($dicairkan),
            'rejected' => $this->pair($ditolak),

            /*
            | Dihitung dari yang SUDAH SELESAI saja. Memasukkan yang masih
            | berjalan ke penyebut membuat angkanya turun hanya karena ada
            | pengajuan baru hari ini -- yang belum ditolak maupun disetujui.
            */
            'approval_rate' => $selesai > 0
                ? round(($jumlahDisetujui / $selesai) * 100, 1)
                : 0.0,

            'average_approval_days' => $this->rataHari(
                $filters,
                $startDate,
                $endDate,
                akhir: 'cash_advances.final_approved_at',
                awal: 'COALESCE(cash_advances.submitted_at, cash_advances.created_at)',
                wajibAda: ['cash_advances.final_approved_at'],
            ),

            /*
            | Jarak dari disetujui sampai uangnya benar-benar keluar. Inilah
            | bagian yang tidak terlihat pada dashboard PR/PO, dan yang paling
            | sering dikeluhkan pemohon.
            */
            'average_disbursement_days' => $this->rataHari(
                $filters,
                $startDate,
                $endDate,
                akhir: 'cash_advances.disbursed_at',
                awal: 'cash_advances.final_approved_at',
                wajibAda: ['cash_advances.disbursed_at', 'cash_advances.final_approved_at'],
            ),

            /* Angka posisi, bukan angka periode -- lihat catatan kelas. */
            'outstanding' => $this->danaBerjalan($filters),
        ];
    }

    /**
     * Rata-rata hari antara dua penanda waktu, dalam periode terpilih.
     *
     * Baris yang salah satu penandanya kosong tidak ikut dihitung, bukan
     * dihitung nol: FPU yang belum dicairkan bukan FPU yang dicairkan dalam
     * nol hari. Kolom yang harus terisi disebutkan terang-terangan lewat
     * $wajibAda -- menebaknya dari rumus $akhir akan meleset begitu rumusnya
     * memakai COALESCE.
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
    | Dana berjalan
    |--------------------------------------------------------------------------
    | FPU yang uangnya sudah keluar tetapi belum ada realisasi sah atasnya.
    | Definisinya SAMA PERSIS dengan yang dipakai penjaga batas pengajuan
    | (FundRequestLimitService::outstandingQuery) -- kalau keduanya berbeda,
    | pemohon akan diblokir karena angka yang tidak pernah terlihat di layar
    | manapun.
    |
    | Tidak dibatasi periode. Uang yang keluar bulan lalu tetap uang yang belum
    | kembali hari ini.
    |--------------------------------------------------------------------------
    */
    private function danaBerjalan(array $filters): array
    {
        $row = $this->currentStateQuery($filters)
            ->where('cash_advances.status', CashAdvance::STATUS_DISBURSED)
            ->whereNotExists($this->realisasiSahSubquery())
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->first();

        return $this->pair($row);
    }

    /**
     * Ada realisasi sah untuk FPU ini.
     *
     * @return \Closure
     */
    private function realisasiSahSubquery(): callable
    {
        return function (QueryBuilder $query): void {
            $query->select(DB::raw(1))
                ->from('cash_advance_realizations AS r')
                ->whereColumn('r.cash_advance_id', 'cash_advances.id')
                ->whereNull('r.deleted_at')
                ->whereIn('r.status', self::REALISASI_SAH);
        };
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
            ->selectRaw('cash_advances.status')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->groupBy('cash_advances.status')
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
    | Butirannya mengikuti panjang periode: harian untuk rentang pendek,
    | mingguan untuk menengah, bulanan untuk setahun penuh. Tanpa ini, memilih
    | "tahun" menghasilkan 365 titik yang tidak terbaca.
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
            'day' => "TO_CHAR(cash_advances.date, 'YYYY-MM-DD')",
            'week' => "TO_CHAR(cash_advances.date, 'IYYY-\"W\"IW')",
            default => "TO_CHAR(cash_advances.date, 'YYYY-MM')",
        };

        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->selectRaw(
                'SUM(CASE WHEN cash_advances.status IN (?, ?, ?) THEN 1 ELSE 0 END) AS disetujui',
                self::STATUS_DISETUJUI,
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN cash_advances.status = ? THEN cash_advances.total_amount ELSE 0 END), 0) AS nilai_cair',
                [CashAdvance::STATUS_DISBURSED],
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
                    'approved_count' => (int) $row->disetujui,
                    'amount' => (float) $row->nilai,
                    'disbursed_amount' => (float) $row->nilai_cair,
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
    | Menjawab "prosesnya macet di mana". Dikelompokkan per label tahap, bukan
    | per orang: yang terbaca adalah masalah prosesnya. Siapa yang menggantung
    | terlihat pada daftar butuh perhatian di bawahnya.
    |--------------------------------------------------------------------------
    */
    private function getApprovalBottleneck(array $filters): array
    {
        /*
        | Karena tahapAktifQuery sudah satu baris per FPU, COUNT dan SUM biasa
        | sudah benar. SUM(DISTINCT) justru akan SALAH di sini: dua FPU yang
        | kebetulan bernilai sama akan diciutkan menjadi satu.
        */
        $rows = $this->tahapAktifQuery($filters)
            ->selectRaw('tahap.step_label AS tahap')
            ->selectRaw('MIN(tahap.step_order) AS urutan')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (NOW() - COALESCE(cash_advances.submitted_at, cash_advances.created_at))) / 86400) AS rata_hari',
            )
            ->selectRaw(
                'MAX(EXTRACT(EPOCH FROM (NOW() - COALESCE(cash_advances.submitted_at, cash_advances.created_at))) / 86400) AS maks_hari',
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
    | Tiga daftar pendek yang bisa langsung ditindaklanjuti, berurutan menurut
    | perjalanan uangnya:
    |
    | 1. Tertahan di approval  -- belum diputuskan siapa pun
    | 2. Disetujui belum cair  -- keputusannya sudah ada, uangnya belum keluar
    | 3. Lewat batas realisasi -- uangnya sudah keluar, pertanggungjawabannya
    |                             belum masuk
    |
    | Ketiganya tidak dibatasi periode: yang tertua justru yang paling perlu
    | muncul.
    |--------------------------------------------------------------------------
    */
    private function getAttentionItems(array $filters, int $batasRealisasi): array
    {
        return [
            'threshold_days' => self::AGING_THRESHOLD_DAYS,
            'realization_limit_days' => $batasRealisasi,
            'stuck_approval' => $this->getStuckApprovals($filters),
            'awaiting_disbursement' => $this->getAwaitingDisbursement($filters),
            'overdue_realization' => $this->getOverdueRealization($filters, $batasRealisasi),
        ];
    }

    private function getStuckApprovals(array $filters): array
    {
        $rows = $this->tahapAktifQuery($filters)
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'cash_advances.branch')
            ->leftJoin('departments', 'departments.id', '=', 'cash_advances.department_id')
            ->selectRaw('cash_advances.id')
            ->selectRaw('cash_advances.advance_number')
            ->selectRaw('cash_advances.date')
            ->selectRaw('cash_advances.total_amount')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw('tahap.step_label AS tahap')
            ->selectRaw('tahap.approvers AS penyetuju')
            ->selectRaw('COALESCE(cash_advances.submitted_at, cash_advances.created_at) AS menunggu_sejak')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - COALESCE(cash_advances.submitted_at, cash_advances.created_at))) / 86400 AS lama_hari',
            )
            ->orderByRaw('COALESCE(cash_advances.submitted_at, cash_advances.created_at) ASC')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->advance_number,
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

    private function getAwaitingDisbursement(array $filters): array
    {
        $rows = $this->currentStateQuery($filters)
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'cash_advances.branch')
            ->leftJoin('departments', 'departments.id', '=', 'cash_advances.department_id')
            ->whereIn('cash_advances.status', self::STATUS_BELUM_CAIR)
            ->whereNotNull('cash_advances.final_approved_at')
            ->selectRaw('cash_advances.id')
            ->selectRaw('cash_advances.advance_number')
            ->selectRaw('cash_advances.date')
            ->selectRaw('cash_advances.status')
            ->selectRaw('cash_advances.total_amount')
            ->selectRaw('cash_advances.final_approved_at')
            ->selectRaw('cash_advances.scheduled_payment_date')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - cash_advances.final_approved_at)) / 86400 AS lama_hari',
            )
            ->orderBy('cash_advances.final_approved_at')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->advance_number,
                'date' => $row->date,
                'status' => (string) $row->status,
                'amount' => (float) $row->total_amount,
                'cabang' => $row->nama_cabang ?? '-',
                'department' => $row->nama_department ?? '-',
                'approved_at' => $row->final_approved_at,
                'scheduled_payment_date' => $row->scheduled_payment_date,
                'idle_days' => round((float) $row->lama_hari, 1),
                'is_overdue' => (float) $row->lama_hari >= self::AGING_THRESHOLD_DAYS,
            ])
            ->values()
            ->all();
    }

    /*
    | FPU yang uangnya sudah keluar dan umurnya melewati batas realisasi.
    |
    | Diurutkan dari yang paling tua, bukan dari yang paling besar nilainya:
    | yang menunggak paling lama adalah yang paling mungkin tidak akan
    | dipertanggungjawabkan sama sekali.
    */
    private function getOverdueRealization(array $filters, int $batasHari): array
    {
        $rows = $this->currentStateQuery($filters)
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'cash_advances.branch')
            ->leftJoin('departments', 'departments.id', '=', 'cash_advances.department_id')
            ->leftJoin('users AS pemohon', 'pemohon.id', '=', 'cash_advances.created_by')
            ->where('cash_advances.status', CashAdvance::STATUS_DISBURSED)
            ->whereNotNull('cash_advances.disbursed_at')
            ->whereNotExists($this->realisasiSahSubquery())
            ->whereRaw(
                'EXTRACT(EPOCH FROM (NOW() - cash_advances.disbursed_at)) / 86400 > ?',
                [$batasHari],
            )
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
            ->orderBy('cash_advances.disbursed_at')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->advance_number,
                'amount' => (float) $row->total_amount,
                'cabang' => $row->nama_cabang ?? '-',
                'department' => $row->nama_department ?? '-',
                'requester' => (string) $row->nama_pemohon,
                'disbursed_at' => $row->disbursed_at,
                'age_days' => (int) floor((float) $row->umur_hari),
                'overdue_days' => (int) max(0, floor((float) $row->umur_hari) - $batasHari),
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
                ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'cash_advances.branch')
                ->selectRaw('cabang.id AS dimensi_id')
                ->selectRaw("COALESCE(cabang.nama_cabang, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('cabang.id', 'cabang.nama_cabang');
        } else {
            $query
                ->leftJoin('departments', 'departments.id', '=', 'cash_advances.department_id')
                ->selectRaw('departments.id AS dimensi_id')
                ->selectRaw("COALESCE(departments.nama, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('departments.id', 'departments.nama');
        }

        $rows = $query
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->selectRaw(
                'SUM(CASE WHEN cash_advances.status = ? THEN 1 ELSE 0 END) AS menunggu',
                [CashAdvance::STATUS_IN_PROGRESS],
            )
            ->selectRaw(
                'SUM(CASE WHEN cash_advances.status IN (?, ?, ?) THEN 1 ELSE 0 END) AS disetujui',
                self::STATUS_DISETUJUI,
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN cash_advances.status = ? THEN cash_advances.total_amount ELSE 0 END), 0) AS nilai_cair',
                [CashAdvance::STATUS_DISBURSED],
            )
            ->orderByRaw('COALESCE(SUM(cash_advances.total_amount), 0) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => $row->dimensi_id === null ? null : (int) $row->dimensi_id,
                'name' => (string) $row->dimensi_nama,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->nilai,
                'waiting_count' => (int) $row->menunggu,
                'approved_count' => (int) $row->disetujui,
                'disbursed_amount' => (float) $row->nilai_cair,
            ])
            ->values()
            ->all();
    }

    /*
    | Sebaran menurut keterangan transaksi -- untuk apa uangnya diminta.
    |
    | Ini yang tidak punya padanan di PR/PO, dan yang paling sering ditanyakan
    | manajemen: perjalanan dinas, operasional kantor, atau keperluan rutin.
    */
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
                'cash_advances.transaction_category_id',
            )
            ->selectRaw('kat.id AS dimensi_id')
            ->selectRaw("COALESCE(kat.name, 'Tidak diisi') AS dimensi_nama")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(cash_advances.total_amount), 0) AS nilai')
            ->groupBy('kat.id', 'kat.name')
            ->orderByRaw('COALESCE(SUM(cash_advances.total_amount), 0) DESC')
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
    | Scope permission menentukan filter mana yang boleh dipilih user sendiri
    | dan mana yang dipaksakan sistem:
    |
    |   ALL            -- bebas memilih cabang dan department
    |   OWN_DEPARTMENT -- department dikunci ke miliknya, cabang bebas dipilih
    |   OWN_CABANG     -- cabang DAN department dikunci, tidak ada yang bisa
    |                     dipilih
    |   OWN_DATA       -- hanya dokumen yang ia buat sendiri
    |
    | DIKUNCI DENGAN SELURUH AKSES, BUKAN YANG PERTAMA.
    |
    | Satu akun bisa punya beberapa pasangan cabang-department lewat akses
    | tambahan. Mengunci ke yang pertama saja akan menyembunyikan separuh
    | datanya sendiri -- dan angka dashboard-nya tidak akan cocok dengan daftar
    | FPU yang ia lihat di modulnya, yang memang menyaring dengan seluruh
    | aksesnya.
    |
    | SATU PERBEDAAN YANG DISENGAJA dari daftar FPU di modulnya: daftar itu
    | juga memperlihatkan dokumen yang masuk ke alur approval seseorang
    | walaupun di luar cabangnya. Dashboard tidak. Angka ringkasan harus
    | menjawab "berapa banyak di wilayah saya", dan dokumen titipan dari cabang
    | lain akan menggelembungkannya tanpa bisa dijelaskan.
    |--------------------------------------------------------------------------
    */
    public function resolveAccessAndFilters(User $user, array $filters): array
    {
        $scope = $user->getPermissionScope('dashboard.cash-advance.view');

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
                    'Scope permission Anda tidak mengizinkan akses ke dashboard FPU.',
                );
        }

        /* Filter pilihan user hanya berlaku pada kolom yang memang boleh ia pilih. */
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

                /*
                | Nama wilayah yang sedang dikunci, supaya layar bisa
                | mengatakannya terang-terangan. User yang melihat angka kecil
                | berhak tahu bahwa itu bukan angka perusahaan, melainkan angka
                | wilayahnya.
                */
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

    /** Pertanyaan tentang PERIODE: disaring rentang tanggal pengajuan. */
    private function baseQuery(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): Builder {
        $query = CashAdvance::query()
            ->whereBetween('cash_advances.date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ]);

        $this->applyScope($query, $filters);

        return $query;
    }

    /** Pertanyaan tentang POSISI SAAT INI: tanpa batas periode. */
    private function currentStateQuery(array $filters): Builder
    {
        $query = CashAdvance::query();

        $this->applyScope($query, $filters);

        return $query;
    }

    /**
     * FPU yang sedang menunggu, beserta tahap aktifnya -- SATU BARIS PER FPU.
     *
     * Dua hal harus dijaga sekaligus, dan keduanya pernah salah:
     *
     * 1. Tahap aktif = step_order TERKECIL yang masih WAITING. Tanpa itu, FPU
     *    bertingkat tiga yang tertahan di tingkat satu ikut terhitung pada
     *    tingkat dua dan tiga, dan penumpukannya terbaca tiga kali lebih parah
     *    daripada kenyataannya.
     *
     * 2. Satu tingkat bisa berisi BEBERAPA BARIS approver -- mode ANY dengan
     *    dua role sekaligus. Menyambung langsung ke tabel approval melipatkan
     *    barisnya: tujuh FPU terbaca delapan, dan nilainya ikut terhitung dua
     *    kali.
     *
     * Jalan keluarnya: approval-nya diciutkan DULU menjadi satu baris per FPU,
     * baru disambungkan. Nama penyetujunya digabung, bukan diambil salah satu
     * -- pada mode ANY memang semuanya berhak, dan menyebut satu nama saja
     * menuduh orang yang kebetulan terurut pertama.
     */
    private function tahapAktifQuery(array $filters): Builder
    {
        $tingkatTerkecil = DB::table('cash_advance_approvals')
            ->select('cash_advance_id')
            ->selectRaw('MIN(step_order) AS step_aktif')
            ->where('status', 'WAITING')
            ->groupBy('cash_advance_id');

        $tahap = DB::table('cash_advance_approvals AS caa')
            ->joinSub($tingkatTerkecil, 'm', function ($join): void {
                $join->on('m.cash_advance_id', '=', 'caa.cash_advance_id')
                    ->on('m.step_aktif', '=', 'caa.step_order');
            })
            ->where('caa.status', 'WAITING')
            ->groupBy('caa.cash_advance_id', 'caa.step_order')
            ->select('caa.cash_advance_id')
            ->selectRaw('caa.step_order AS step_order')
            ->selectRaw(
                "COALESCE(NULLIF(TRIM(MIN(caa.label)), ''), 'Tahap ' || caa.step_order) AS step_label",
            )
            ->selectRaw(
                "COALESCE(STRING_AGG(DISTINCT NULLIF(TRIM(caa.approver_name_snapshot), ''), ', '), '-') AS approvers",
            );

        $query = CashAdvance::query()
            ->joinSub($tahap, 'tahap', 'tahap.cash_advance_id', '=', 'cash_advances.id')
            ->where('cash_advances.status', CashAdvance::STATUS_IN_PROGRESS);

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
                : $query->whereIn('cash_advances.branch', $daftar);
        }

        if (isset($filters['department_ids'])) {
            $daftar = array_map('intval', (array) $filters['department_ids']);

            $daftar === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('cash_advances.department_id', $daftar);
        }

        if (isset($filters['created_by'])) {
            $query->where('cash_advances.created_by', (int) $filters['created_by']);
        }

        /* Pilihan user sendiri, dipersempit di dalam wilayah yang boleh. */
        if (isset($filters['cabang_id'])) {
            $query->where('cash_advances.branch', (string) $filters['cabang_id']);
        }

        if (isset($filters['department_id'])) {
            $query->where('cash_advances.department_id', (int) $filters['department_id']);
        }
    }

    /**
     * Batas umur realisasi yang berlaku umum.
     *
     * Master batas pengajuan bisa punya baris khusus per area dan department,
     * tetapi dashboard membaca banyak dokumen sekaligus dari berbagai
     * department -- jadi yang dipakai BATAS UMUM, yaitu baris tanpa area dan
     * tanpa department. Itu pula yang berlaku bagi sebagian besar pemohon.
     *
     * Angkanya dikirim ke layar, bukan ditulis mati di sana: begitu batasnya
     * diubah di master, kalimat di dashboard ikut benar sendiri.
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
