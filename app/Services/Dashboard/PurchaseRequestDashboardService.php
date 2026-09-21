<?php

namespace App\Services\Dashboard;

use App\Models\PurchaseRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| Dashboard Purchase Requisition
|--------------------------------------------------------------------------
| Sudut pandangnya manajemen, bukan operator: yang ditonjolkan bukan sekadar
| "berapa PR masuk", melainkan DI MANA prosesnya tertahan dan APA yang perlu
| ditindaklanjuti.
|
| Empat pertanyaan yang dijawab dashboard ini:
|
| 1. Berapa banyak dan berapa nilainya periode ini
| 2. Berapa lama PR biasanya sampai disetujui, dan menumpuk di tahap mana
| 3. Mana PR yang sudah disetujui tetapi belum ditindaklanjuti jadi PO
| 4. Departemen dan cabang mana yang paling banyak meminta
|
| Filter periode, akses, dan bentuk responsnya mengikuti dashboard Purchase
| Order supaya keduanya terasa satu keluarga.
|--------------------------------------------------------------------------
*/
class PurchaseRequestDashboardService
{
    /*
    | PR yang dianggap "resmi" untuk perhitungan nilai: draft belum tentu jadi,
    | sedangkan ditolak dan dibatalkan sudah gugur. Keduanya tetap dihitung
    | pada sebaran status, hanya tidak ikut menyusun nilai pengajuan.
    */
    private const OFFICIAL_STATUSES = [
        PurchaseRequest::STATUS_IN_PROGRESS,
        PurchaseRequest::STATUS_APPROVED,
    ];

    /** Ambang PR menggantung yang dianggap perlu ditindaklanjuti. */
    private const AGING_THRESHOLD_DAYS = 3;

    /** Banyaknya baris pada daftar "butuh perhatian". */
    private const ATTENTION_LIMIT = 8;

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
            'statuses' => $this->getStatusBreakdown($filters, $startDate, $endDate),
            'trend' => $this->getTrend($filters, $startDate, $endDate),
            'approval_bottleneck' => $this->getApprovalBottleneck($filters),
            'attention' => $this->getAttentionItems($filters),
            'breakdown' => [
                'by_cabang' => $this->getBreakdown($filters, $startDate, $endDate, 'cabang'),
                'by_department' => $this->getBreakdown($filters, $startDate, $endDate, 'department'),
                'by_type' => $this->getBreakdownByType($filters, $startDate, $endDate),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Ringkasan
    |--------------------------------------------------------------------------
    | Lima angka yang dibaca lebih dulu oleh manajemen. "Belum jadi PO" sengaja
    | ikut di sini karena itulah yang paling sering butuh tindakan.
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
            ->selectRaw('COALESCE(SUM(purchase_requests.total_amount), 0) AS nilai')
            ->first();

        $official = (clone $base())
            ->whereIn('purchase_requests.status', self::OFFICIAL_STATUSES)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(purchase_requests.total_amount), 0) AS nilai')
            ->first();

        $waiting = (clone $base())
            ->where('purchase_requests.status', PurchaseRequest::STATUS_IN_PROGRESS)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(purchase_requests.total_amount), 0) AS nilai')
            ->first();

        $approved = (clone $base())
            ->where('purchase_requests.status', PurchaseRequest::STATUS_APPROVED)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(purchase_requests.total_amount), 0) AS nilai')
            ->first();

        /*
        | Sudah disetujui tetapi belum ada PO-nya sama sekali. status_po OPEN
        | berarti PO sudah dibuat namun belum tuntas, jadi tidak dihitung di
        | sini -- yang dicari adalah yang benar-benar belum ditindaklanjuti.
        */
        $notFollowedUp = (clone $base())
            ->where('purchase_requests.status', PurchaseRequest::STATUS_APPROVED)
            ->whereNull('purchase_requests.status_po')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(purchase_requests.total_amount), 0) AS nilai')
            ->first();

        return [
            'total' => $this->pair($total),
            'official' => $this->pair($official),
            'waiting_approval' => $this->pair($waiting),
            'approved' => $this->pair($approved),
            'not_followed_up' => $this->pair($notFollowedUp),

            'average_approval_days' => $this->getAverageApprovalDays($filters, $startDate, $endDate),

            /*
            | Berapa persen PR resmi yang berhasil sampai disetujui pada
            | periode itu. Dipakai sebagai penanda kelancaran proses.
            */
            'approval_rate_percent' => (int) $official->jumlah > 0
                ? round(((int) $approved->jumlah / (int) $official->jumlah) * 100, 1)
                : 0.0,
        ];
    }

    /**
     * Rata-rata jarak hari dari pengajuan sampai disetujui final.
     *
     * Hanya PR yang benar-benar sudah disetujui pada periode itu yang dihitung;
     * yang masih berjalan belum punya angka akhir, dan memasukkannya hanya akan
     * membuat rata-ratanya terlihat lebih cepat dari kenyataan.
     *
     * Waktu selesainya diambil dari approval terakhir, bukan dari kolom
     * final_approved_at. Kolom itu tidak selalu terisi pada dokumen lama,
     * sedangkan baris approval-nya selalu ada -- memakai kolomnya saja membuat
     * angka ini kosong padahal datanya sebenarnya tersedia.
     */
    private function getAverageApprovalDays(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): ?float {
        $selesai = DB::table('purchase_request_approvals')
            ->select('purchase_request_id')
            ->selectRaw('MAX(approved_at) AS selesai_pada')
            ->where('status', 'APPROVED')
            ->whereNotNull('approved_at')
            ->groupBy('purchase_request_id');

        $average = $this->baseQuery($filters, $startDate, $endDate)
            ->joinSub($selesai, 'selesai', 'selesai.purchase_request_id', '=', 'purchase_requests.id')
            ->where('purchase_requests.status', PurchaseRequest::STATUS_APPROVED)
            ->whereNotNull('purchase_requests.submitted_at')
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (COALESCE(purchase_requests.final_approved_at, selesai.selesai_pada) - purchase_requests.submitted_at)) / 86400) AS rata',
            )
            ->value('rata');

        return $average === null ? null : round((float) $average, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Sebaran status
    |--------------------------------------------------------------------------
    | Seluruh status ditampilkan, termasuk draft, ditolak, dan dibatalkan --
    | manajemen perlu melihat berapa banyak yang gugur, bukan hanya yang jalan.
    |--------------------------------------------------------------------------
    */
    private function getStatusBreakdown(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->select('purchase_requests.status')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(purchase_requests.total_amount), 0) AS nilai')
            ->groupBy('purchase_requests.status')
            ->get()
            ->keyBy(fn ($row): string => strtoupper(trim((string) $row->status)));

        $urutan = [
            PurchaseRequest::STATUS_DRAFT,
            PurchaseRequest::STATUS_IN_PROGRESS,
            PurchaseRequest::STATUS_APPROVED,
            PurchaseRequest::STATUS_REJECTED,
            PurchaseRequest::STATUS_CANCELLED,
        ];

        return collect($urutan)
            ->map(fn (string $status): array => [
                'status' => $status,
                'count' => (int) ($rows[$status]->jumlah ?? 0),
                'amount' => (float) ($rows[$status]->nilai ?? 0),
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Tren
    |--------------------------------------------------------------------------
    | Kerapatan titik menyesuaikan panjang periode: rentang pendek dipecah per
    | hari, rentang panjang per bulan, supaya grafiknya tetap terbaca.
    |--------------------------------------------------------------------------
    */
    private function getTrend(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $days = $startDate->diffInDays($endDate) + 1;

        [$granularity, $format, $label] = match (true) {
            $days <= 31 => ['day', 'YYYY-MM-DD', 'd M'],
            $days <= 180 => ['week', 'IYYY-"W"IW', '\\W W'],
            default => ['month', 'YYYY-MM', 'M Y'],
        };

        $bucket = match ($granularity) {
            'day' => "TO_CHAR(purchase_requests.tanggal_pr, 'YYYY-MM-DD')",
            'week' => "TO_CHAR(purchase_requests.tanggal_pr, 'IYYY-\"W\"IW')",
            default => "TO_CHAR(purchase_requests.tanggal_pr, 'YYYY-MM')",
        };

        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(purchase_requests.total_amount), 0) AS nilai')
            ->selectRaw(
                'SUM(CASE WHEN purchase_requests.status = ? THEN 1 ELSE 0 END) AS disetujui',
                [PurchaseRequest::STATUS_APPROVED],
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
    | per orang, supaya yang terbaca adalah masalah prosesnya -- siapa yang
    | menggantung dapat dilihat pada daftar butuh perhatian di bawahnya.
    |
    | Sengaja TIDAK dibatasi periode: PR yang menggantung sejak bulan lalu
    | justru yang paling perlu terlihat, dan akan hilang bila ikut disaring
    | tanggal pengajuannya.
    |--------------------------------------------------------------------------
    */
    private function getApprovalBottleneck(array $filters): array
    {
        $waiting = DB::table('purchase_request_approvals')
            ->select('purchase_request_id')
            ->selectRaw('MIN(step_order) AS step_aktif')
            ->where('status', 'WAITING')
            ->groupBy('purchase_request_id');

        $rows = DB::table('purchase_requests')
            ->joinSub($waiting, 'aktif', 'aktif.purchase_request_id', '=', 'purchase_requests.id')
            ->join('purchase_request_approvals AS pra', function ($join) {
                $join->on('pra.purchase_request_id', '=', 'purchase_requests.id')
                    ->on('pra.step_order', '=', 'aktif.step_aktif')
                    ->where('pra.status', '=', 'WAITING');
            })
            ->whereNull('purchase_requests.deleted_at')
            ->where('purchase_requests.status', PurchaseRequest::STATUS_IN_PROGRESS)
            ->when(
                isset($filters['cabang_id']),
                fn ($query) => $query->where('purchase_requests.cabang', (string) $filters['cabang_id']),
            )
            ->when(
                isset($filters['department_id']),
                fn ($query) => $query->where('purchase_requests.id_department', (int) $filters['department_id']),
            )
            ->when(
                isset($filters['created_by']),
                fn ($query) => $query->where('purchase_requests.created_by', (int) $filters['created_by']),
            )
            ->selectRaw("COALESCE(NULLIF(TRIM(pra.label), ''), 'Tahap ' || pra.step_order) AS tahap")
            ->selectRaw('MIN(pra.step_order) AS urutan')
            ->selectRaw('COUNT(DISTINCT purchase_requests.id) AS jumlah')
            ->selectRaw('COALESCE(SUM(DISTINCT purchase_requests.total_amount), 0) AS nilai')
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (NOW() - COALESCE(purchase_requests.submitted_at, purchase_requests.created_at))) / 86400) AS rata_hari',
            )
            ->selectRaw(
                'MAX(EXTRACT(EPOCH FROM (NOW() - COALESCE(purchase_requests.submitted_at, purchase_requests.created_at))) / 86400) AS maks_hari',
            )
            ->groupByRaw("COALESCE(NULLIF(TRIM(pra.label), ''), 'Tahap ' || pra.step_order)")
            ->orderByRaw('COUNT(DISTINCT purchase_requests.id) DESC')
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
    | Dua daftar pendek yang bisa langsung ditindaklanjuti manajemen:
    |
    | - PR yang menggantung di approval melewati ambang hari
    | - PR yang sudah disetujui tetapi belum dibuatkan PO sama sekali
    |
    | Keduanya tidak dibatasi periode, dengan alasan yang sama seperti
    | penumpukan approval: yang tertua justru yang paling perlu muncul.
    |--------------------------------------------------------------------------
    */
    private function getAttentionItems(array $filters): array
    {
        return [
            'threshold_days' => self::AGING_THRESHOLD_DAYS,
            'stuck_approval' => $this->getStuckApprovals($filters),
            'awaiting_po' => $this->getAwaitingPurchaseOrder($filters),
        ];
    }

    private function getStuckApprovals(array $filters): array
    {
        $waiting = DB::table('purchase_request_approvals')
            ->select('purchase_request_id')
            ->selectRaw('MIN(step_order) AS step_aktif')
            ->where('status', 'WAITING')
            ->groupBy('purchase_request_id');

        $rows = DB::table('purchase_requests')
            ->joinSub($waiting, 'aktif', 'aktif.purchase_request_id', '=', 'purchase_requests.id')
            ->join('purchase_request_approvals AS pra', function ($join) {
                $join->on('pra.purchase_request_id', '=', 'purchase_requests.id')
                    ->on('pra.step_order', '=', 'aktif.step_aktif')
                    ->where('pra.status', '=', 'WAITING');
            })
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'purchase_requests.cabang')
            ->leftJoin('departments', 'departments.id', '=', 'purchase_requests.id_department')
            ->whereNull('purchase_requests.deleted_at')
            ->where('purchase_requests.status', PurchaseRequest::STATUS_IN_PROGRESS)
            ->when(
                isset($filters['cabang_id']),
                fn ($query) => $query->where('purchase_requests.cabang', (string) $filters['cabang_id']),
            )
            ->when(
                isset($filters['department_id']),
                fn ($query) => $query->where('purchase_requests.id_department', (int) $filters['department_id']),
            )
            ->when(
                isset($filters['created_by']),
                fn ($query) => $query->where('purchase_requests.created_by', (int) $filters['created_by']),
            )
            ->selectRaw('purchase_requests.id')
            ->selectRaw('purchase_requests.nomor_pr')
            ->selectRaw('purchase_requests.tanggal_pr')
            ->selectRaw('purchase_requests.total_amount')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw("COALESCE(NULLIF(TRIM(pra.label), ''), 'Tahap ' || pra.step_order) AS tahap")
            ->selectRaw('COALESCE(purchase_requests.submitted_at, purchase_requests.created_at) AS menunggu_sejak')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - COALESCE(purchase_requests.submitted_at, purchase_requests.created_at))) / 86400 AS lama_hari',
            )
            ->orderByRaw('COALESCE(purchase_requests.submitted_at, purchase_requests.created_at) ASC')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->nomor_pr,
                'date' => $row->tanggal_pr,
                'amount' => (float) $row->total_amount,
                'cabang' => $row->nama_cabang ?? '-',
                'department' => $row->nama_department ?? '-',
                'step_label' => (string) $row->tahap,
                'waiting_since' => $row->menunggu_sejak,
                'waiting_days' => round((float) $row->lama_hari, 1),
                'is_overdue' => (float) $row->lama_hari >= self::AGING_THRESHOLD_DAYS,
            ])
            ->values()
            ->all();
    }

    private function getAwaitingPurchaseOrder(array $filters): array
    {
        $rows = DB::table('purchase_requests')
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'purchase_requests.cabang')
            ->leftJoin('departments', 'departments.id', '=', 'purchase_requests.id_department')
            ->whereNull('purchase_requests.deleted_at')
            ->where('purchase_requests.status', PurchaseRequest::STATUS_APPROVED)
            ->whereNull('purchase_requests.status_po')
            ->when(
                isset($filters['cabang_id']),
                fn ($query) => $query->where('purchase_requests.cabang', (string) $filters['cabang_id']),
            )
            ->when(
                isset($filters['department_id']),
                fn ($query) => $query->where('purchase_requests.id_department', (int) $filters['department_id']),
            )
            ->when(
                isset($filters['created_by']),
                fn ($query) => $query->where('purchase_requests.created_by', (int) $filters['created_by']),
            )
            ->selectRaw('purchase_requests.id')
            ->selectRaw('purchase_requests.nomor_pr')
            ->selectRaw('purchase_requests.tanggal_pr')
            ->selectRaw('purchase_requests.total_amount')
            ->selectRaw('purchase_requests.final_approved_at')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - COALESCE(purchase_requests.final_approved_at, purchase_requests.updated_at))) / 86400 AS lama_hari',
            )
            ->orderByRaw('COALESCE(purchase_requests.final_approved_at, purchase_requests.updated_at) ASC')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->nomor_pr,
                'date' => $row->tanggal_pr,
                'amount' => (float) $row->total_amount,
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
                ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'purchase_requests.cabang')
                ->selectRaw('cabang.id AS dimensi_id')
                ->selectRaw("COALESCE(cabang.nama_cabang, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('cabang.id', 'cabang.nama_cabang');
        } else {
            $query
                ->leftJoin('departments', 'departments.id', '=', 'purchase_requests.id_department')
                ->selectRaw('departments.id AS dimensi_id')
                ->selectRaw("COALESCE(departments.nama, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('departments.id', 'departments.nama');
        }

        $rows = $query
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(purchase_requests.total_amount), 0) AS nilai')
            ->selectRaw(
                'SUM(CASE WHEN purchase_requests.status = ? THEN 1 ELSE 0 END) AS menunggu',
                [PurchaseRequest::STATUS_IN_PROGRESS],
            )
            ->selectRaw(
                'SUM(CASE WHEN purchase_requests.status = ? THEN 1 ELSE 0 END) AS disetujui',
                [PurchaseRequest::STATUS_APPROVED],
            )
            ->orderByRaw('COALESCE(SUM(purchase_requests.total_amount), 0) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => $row->dimensi_id === null ? null : (int) $row->dimensi_id,
                'name' => (string) $row->dimensi_nama,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->nilai,
                'waiting_count' => (int) $row->menunggu,
                'approved_count' => (int) $row->disetujui,
            ])
            ->values()
            ->all();
    }

    private function getBreakdownByType(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->selectRaw("COALESCE(NULLIF(TRIM(purchase_requests.pr_type), ''), 'Tidak diisi') AS jenis")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(purchase_requests.total_amount), 0) AS nilai')
            ->groupByRaw("COALESCE(NULLIF(TRIM(purchase_requests.pr_type), ''), 'Tidak diisi')")
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'name' => (string) $row->jenis,
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
    | Mengikuti pola dashboard Purchase Order: scope permission menentukan
    | filter mana yang boleh dipilih user dan mana yang dipaksakan sistem.
    |--------------------------------------------------------------------------
    */
    public function resolveAccessAndFilters(User $user, array $filters): array
    {
        $scope = $user->getPermissionScope('dashboard.pr.view');

        $user->loadMissing(['cabang:id,nama_cabang', 'departemen:id,nama']);

        $userCabangId = $user->accessibleBranchIds()->first();
        $userDepartmentId = $user->accessibleDepartmentIds()->first();

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
                if ($userDepartmentId === null) {
                    throw ValidationException::withMessages([
                        'department_id' => ['Departemen user belum ditentukan.'],
                    ]);
                }

                $effectiveFilters['department_id'] = $userDepartmentId;

                $canFilterCabang = true;

                break;

            case 'OWN_CABANG':
                if ($userCabangId === null) {
                    throw ValidationException::withMessages([
                        'cabang_id' => ['Cabang user belum ditentukan.'],
                    ]);
                }

                if ($userDepartmentId === null) {
                    throw ValidationException::withMessages([
                        'department_id' => ['Departemen user belum ditentukan.'],
                    ]);
                }

                $effectiveFilters['cabang_id'] = $userCabangId;
                $effectiveFilters['department_id'] = $userDepartmentId;

                break;

            default:
                throw new AuthorizationException(
                    'Scope permission tidak mengizinkan akses ke dashboard management.',
                );
        }

        return [
            'filters' => $effectiveFilters,

            'access' => [
                'scope_view' => $scope,

                'cabang_id' => isset($effectiveFilters['cabang_id'])
                    ? (int) $effectiveFilters['cabang_id']
                    : null,

                'cabang_name' => $scope === 'OWN_CABANG'
                    ? $user->cabang?->nama_cabang
                    : null,

                'department_id' => isset($effectiveFilters['department_id'])
                    ? (int) $effectiveFilters['department_id']
                    : null,

                'department_name' => in_array($scope, ['OWN_CABANG', 'OWN_DEPARTMENT'], true)
                    ? $user->departemen?->nama
                    : null,

                'can_filter_cabang' => $canFilterCabang,
                'can_filter_department' => $canFilterDepartment,
                'own_data_only' => isset($effectiveFilters['created_by']),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    private function baseQuery(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): Builder {
        return PurchaseRequest::query()
            ->whereBetween('purchase_requests.tanggal_pr', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->when(
                isset($filters['cabang_id']),
                fn ($query) => $query->where('purchase_requests.cabang', (string) $filters['cabang_id']),
            )
            ->when(
                isset($filters['department_id']),
                fn ($query) => $query->where('purchase_requests.id_department', (int) $filters['department_id']),
            )
            ->when(
                isset($filters['created_by']),
                fn ($query) => $query->where('purchase_requests.created_by', (int) $filters['created_by']),
            );
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
