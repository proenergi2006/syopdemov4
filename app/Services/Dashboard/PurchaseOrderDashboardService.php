<?php

namespace App\Services\Dashboard;

use App\Models\GoodsReceive;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PurchaseOrderDashboardService
{
    private const PO_STATUS_DRAFT = 'DRAFT';
    private const PO_STATUS_IN_PROGRESS = 'IN PROGRESS';
    private const PO_STATUS_APPROVED = 'APPROVED';
    private const PO_STATUS_REJECTED = 'REJECTED';
    private const PO_STATUS_CANCELLED = 'CANCELLED';

    private const EXCLUDED_DASHBOARD_STATUSES = [
        self::PO_STATUS_REJECTED,
        self::PO_STATUS_CANCELLED,
    ];

    /**
     * Dashboard tahap kedua:
     *
     * Query 1:
     * Ringkasan Purchase Request.
     *
     * Query 2:
     * Ringkasan PO, status, dan outstanding receipt.
     */
    public function getDashboard(array $filters): array
    {
        [
            $startDate,
            $endDate,
        ] = $this->resolveDateRange($filters);

        $purchaseRequestSummary
            = $this->getPurchaseRequestSummary(
                filters: $filters,
                startDate: $startDate,
                endDate: $endDate,
            );

        $purchaseOrderSummary
            = $this->getPurchaseOrderSummary(
                filters: $filters,
                startDate: $startDate,
                endDate: $endDate,
            );

        $trend = $this->getTrend(
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $breakdownByCabang = $this->getBreakdownByCabang(
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $breakdownByDepartment = $this->getBreakdownByDepartment(
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $itemPriceComparison = $this->getItemPriceComparison(
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $valueComparison = $this->getValueComparison(
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $approvedPr = (int) (
            $purchaseRequestSummary->approved_pr ?? 0
        );

        $convertedPr = (int) (
            $purchaseRequestSummary->converted_pr ?? 0
        );

        $conversionRate = $approvedPr > 0
            ? round(
                ($convertedPr / $approvedPr) * 100,
                1,
            )
            : 0;

        $draftPo = (int) (
            $purchaseOrderSummary->draft_po ?? 0
        );

        $inProgressPo = (int) (
            $purchaseOrderSummary->in_progress_po ?? 0
        );

        $approvedPo = (int) (
            $purchaseOrderSummary->approved_po ?? 0
        );

        $rejectedPo = (int) (
            $purchaseOrderSummary->rejected_po ?? 0
        );

        return [
            'filters' => [
                'period' => $filters['period'],

                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),

                'cabang_id' => isset($filters['cabang_id'])
                    ? (int) $filters['cabang_id']
                    : null,

                'department_id' => isset(
                    $filters['department_id'],
                )
                    ? (int) $filters['department_id']
                    : null,
            ],

            'summary' => [
                'total_pr' => (int) (
                    $purchaseRequestSummary->total_pr ?? 0
                ),

                'total_pr_amount' => (float) (
                    $purchaseRequestSummary->total_pr_amount ?? 0
                ),

                'total_po' => (int) (
                    $purchaseOrderSummary->total_po ?? 0
                ),

                'total_po_amount' => (float) (
                    $purchaseOrderSummary->total_po_amount ?? 0
                ),

                'approved_pr' => $approvedPr,

                'pr_not_ordered' => (int) (
                    $purchaseRequestSummary->pr_not_ordered ?? 0
                ),

                'pending_po_approval' => $inProgressPo,

                'outstanding_receipt' => (int) (
                    $purchaseOrderSummary->outstanding_receipt ?? 0
                ),

                'rejected_po' => $rejectedPo,

                'conversion_rate' => $conversionRate,
            ],

            'trend' => $trend,
            'statuses' => [
                [
                    'status' => self::PO_STATUS_IN_PROGRESS,
                    'label' => 'In Progress',
                    'total' => $inProgressPo,
                ],
                [
                    'status' => self::PO_STATUS_APPROVED,
                    'label' => 'Approved',
                    'total' => $approvedPo,
                ],
            ],

            'attention_items' => [],

            'breakdown' => [
                'by_cabang' => $breakdownByCabang,
                'by_department' => $breakdownByDepartment,
            ],

            'item_price_comparison' => $itemPriceComparison,
            'value_comparison' => $valueComparison,
        ];
    }

    /**
     * Menentukan akses dan filter efektif berdasarkan scope permission.
     *
     * Filter dari frontend tidak langsung dipercaya.
     * Untuk scope terbatas, backend akan menimpa filter menggunakan
     * cabang dan departemen user login.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function resolveAccessAndFilters(
        User $user,
        array $filters,
    ): array {
        $scope = $user->getPermissionScope(
            'dashboard.po.view',
        );

        $user->loadMissing([
            'cabang:id,nama_cabang',
            'departemen:id,nama',
        ]);

        $userCabangId = $user->cabang_id !== null
            ? (int) $user->cabang_id
            : null;

        $userDepartmentId = $user->departemen_id !== null
            ? (int) $user->departemen_id
            : null;

        $effectiveFilters = $filters;

        $canFilterCabang = false;
        $canFilterDepartment = false;

        switch ($scope) {
            case 'ALL':
                /*
             * User boleh memilih semua cabang
             * dan semua departemen.
             */
                $canFilterCabang = true;
                $canFilterDepartment = true;

                break;

            case 'OWN_DEPARTMENT':
                /*
             * Departemen selalu mengikuti user login.
             * Cabang masih dapat dipilih.
             */
                if ($userDepartmentId === null) {
                    throw ValidationException::withMessages([
                        'department_id' => [
                            'Departemen user belum ditentukan.',
                        ],
                    ]);
                }

                $effectiveFilters['department_id']
                    = $userDepartmentId;

                $canFilterCabang = true;
                $canFilterDepartment = false;

                break;

            case 'OWN_CABANG':
                /*
             * Sesuai konsep dashboard:
             * cabang dan departemen sama-sama mengikuti user login.
             */
                if ($userCabangId === null) {
                    throw ValidationException::withMessages([
                        'cabang_id' => [
                            'Cabang user belum ditentukan.',
                        ],
                    ]);
                }

                if ($userDepartmentId === null) {
                    throw ValidationException::withMessages([
                        'department_id' => [
                            'Departemen user belum ditentukan.',
                        ],
                    ]);
                }

                $effectiveFilters['cabang_id']
                    = $userCabangId;

                $effectiveFilters['department_id']
                    = $userDepartmentId;

                $canFilterCabang = false;
                $canFilterDepartment = false;

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

                'cabang_id' => isset(
                    $effectiveFilters['cabang_id'],
                )
                    ? (int) $effectiveFilters['cabang_id']
                    : null,

                'cabang_name' => $scope === 'OWN_CABANG'
                    ? $user->cabang?->nama_cabang
                    : null,

                'department_id' => isset(
                    $effectiveFilters['department_id'],
                )
                    ? (int) $effectiveFilters['department_id']
                    : null,

                'department_name' => in_array(
                    $scope,
                    [
                        'OWN_CABANG',
                        'OWN_DEPARTMENT',
                    ],
                    true,
                )
                    ? $user->departemen?->nama
                    : null,

                'can_filter_cabang' => $canFilterCabang,

                'can_filter_department'
                => $canFilterDepartment,
            ],
        ];
    }

    /**
     * Ringkasan Purchase Request.
     *
     * Hanya menghasilkan satu row agregasi.
     */
    private function getPurchaseRequestSummary(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): object {
        $query = PurchaseRequest::query();

        $this->applyPurchaseRequestFilters(
            query: $query,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        return $query
            ->selectRaw(
                '
                COUNT(purchase_requests.id)
                    AS total_pr,

                COALESCE(
                    SUM(purchase_requests.total_amount),
                    0
                ) AS total_pr_amount,

                SUM(
                    CASE
                        WHEN UPPER(TRIM(purchase_requests.status)) = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS approved_pr,

                SUM(
                    CASE
                        WHEN UPPER(TRIM(purchase_requests.status)) = ?
                        AND (
                            purchase_requests.status_po = ?
                            OR purchase_requests.status_po IS NULL
                        )
                        THEN 1
                        ELSE 0
                    END
                ) AS pr_not_ordered,

                SUM(
                    CASE
                        WHEN UPPER(TRIM(purchase_requests.status)) = ?
                        AND purchase_requests.status_po IN (?, ?)
                        THEN 1
                        ELSE 0
                    END
                ) AS converted_pr
                ',
                [
                    PurchaseRequest::STATUS_APPROVED,

                    PurchaseRequest::STATUS_APPROVED,
                    PurchaseRequest::STATUS_PO_OPEN,

                    PurchaseRequest::STATUS_APPROVED,
                    PurchaseRequest::STATUS_PO_PARTIAL,
                    PurchaseRequest::STATUS_PO_COMPLETED,
                ],
            )
            ->first();
    }

    /**
     * Ringkasan PO, status, dan outstanding receipt.
     *
     * Tetap hanya satu query utama ke purchase_orders.
     *
     * Quantity GR sudah diagregasi dahulu per PO item,
     * sehingga tidak terjadi query per PO atau per item.
     */
    private function getPurchaseOrderSummary(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): object {
        $receiptStateByPurchaseOrder
            = $this->buildReceiptStateByPurchaseOrderSubquery();

        $query = PurchaseOrder::query()
            ->leftJoinSub(
                $receiptStateByPurchaseOrder,
                'receipt_state',
                function ($join): void {
                    $join->on(
                        'receipt_state.purchase_order_id',
                        '=',
                        'purchase_orders.id',
                    );
                },
            );

        $this->applyPurchaseOrderFilters(
            query: $query,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        return $query
            ->selectRaw(
                '
                COUNT(purchase_orders.id)
                    AS total_po,

                COALESCE(
                    SUM(purchase_orders.total_nilai),
                    0
                ) AS total_po_amount,

                SUM(
                    CASE
                        WHEN UPPER(TRIM(purchase_orders.status)) = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS draft_po,

                SUM(
                    CASE
                        WHEN UPPER(TRIM(purchase_orders.status)) = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS in_progress_po,

                SUM(
                    CASE
                        WHEN UPPER(TRIM(purchase_orders.status)) = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS approved_po,

                SUM(
                    CASE
                        WHEN UPPER(TRIM(purchase_orders.status)) = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS rejected_po,

                SUM(
                    CASE
                        WHEN UPPER(TRIM(purchase_orders.status)) = ?
                        AND COALESCE(
                            receipt_state.has_outstanding,
                            0
                        ) = 1
                        THEN 1
                        ELSE 0
                    END
                ) AS outstanding_receipt
                ',
                [
                    self::PO_STATUS_DRAFT,
                    self::PO_STATUS_IN_PROGRESS,
                    self::PO_STATUS_APPROVED,
                    self::PO_STATUS_REJECTED,
                    self::PO_STATUS_APPROVED,
                ],
            )
            ->first();
    }

    /**
     * Total qty GR posted per Purchase Order Item.
     *
     * GR Draft dan Cancelled tidak dihitung.
     */
    private function buildPostedReceivedQuantityByItemSubquery(): QueryBuilder
    {
        return DB::table(
            'goods_receive_items as gri',
        )
            ->join(
                'goods_receives as gr',
                'gr.id',
                '=',
                'gri.goods_receive_id',
            )
            ->where(
                'gr.status',
                GoodsReceive::STATUS_POSTED,
            )
            ->whereNull('gr.deleted_at')
            ->groupBy(
                'gri.purchase_order_item_id',
            )
            ->selectRaw(
                '
                gri.purchase_order_item_id,
                COALESCE(
                    SUM(gri.qty_receive),
                    0
                ) AS received_qty
                ',
            );
    }

    /**
     * Menentukan apakah sebuah PO masih mempunyai
     * minimal satu item outstanding.
     *
     * has_outstanding:
     * 1 = masih ada qty belum diterima
     * 0 = seluruh item sudah diterima
     */
    private function buildReceiptStateByPurchaseOrderSubquery(): QueryBuilder
    {
        $receivedQuantityByItem
            = $this
            ->buildPostedReceivedQuantityByItemSubquery();

        return DB::table(
            'purchase_order_items as poi',
        )
            ->leftJoinSub(
                $receivedQuantityByItem,
                'received',
                function ($join): void {
                    $join->on(
                        'received.purchase_order_item_id',
                        '=',
                        'poi.id',
                    );
                },
            )
            ->whereNull('poi.deleted_at')
            ->groupBy('poi.purchase_order_id')
            ->selectRaw(
                '
                poi.purchase_order_id,

                MAX(
                    CASE
                        WHEN COALESCE(poi.qty, 0)
                            > COALESCE(
                                received.received_qty,
                                0
                            )
                        THEN 1
                        ELSE 0
                    END
                ) AS has_outstanding
                ',
            );
    }
    /**
     * Membatasi dokumen yang dihitung dashboard hanya dokumen resmi.
     *
     * DRAFT belum dianggap kebutuhan/realisasi resmi, sedangkan REJECTED dan
     * CANCELLED tidak valid untuk pengambilan keputusan management.
     */
    private function applyAllowedStatusFilter(
        Builder $query,
        string $qualifiedStatusColumn,
        array $allowedStatuses,
    ): void {
        $statuses = collect($allowedStatuses)
            ->map(fn($status): string => strtoupper(trim((string) $status)))
            ->filter(fn(string $status): bool => $status !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($statuses)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($statuses), '?'),
        );

        $query->whereRaw(
            "UPPER(TRIM({$qualifiedStatusColumn})) IN ({$placeholders})",
            $statuses,
        );
    }

    private function applyOfficialPurchaseRequestDashboardStatusFilter(
        Builder $query,
    ): void {
        $this->applyAllowedStatusFilter(
            query: $query,
            qualifiedStatusColumn: 'purchase_requests.status',
            allowedStatuses: [
                'IN PROGRESS',
                PurchaseRequest::STATUS_APPROVED,
            ],
        );
    }

    private function applyOfficialPurchaseOrderDashboardStatusFilter(
        Builder $query,
    ): void {
        $this->applyAllowedStatusFilter(
            query: $query,
            qualifiedStatusColumn: 'purchase_orders.status',
            allowedStatuses: [
                self::PO_STATUS_IN_PROGRESS,
                self::PO_STATUS_APPROVED,
            ],
        );
    }

    /**
     * Filter tanggal, cabang, dan departemen PR.
     */
    private function applyPurchaseRequestFilters(
        Builder $query,
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): void {
        $query->whereBetween(
            'purchase_requests.tanggal_pr',
            [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ],
        );
        $this->applyOfficialPurchaseRequestDashboardStatusFilter(
            query: $query,
        );

        if (isset($filters['cabang_id'])) {
            $query->where(
                'purchase_requests.cabang',
                (int) $filters['cabang_id'],
            );
        }

        if (isset($filters['department_id'])) {
            $query->where(
                'purchase_requests.id_department',
                (int) $filters['department_id'],
            );
        }
    }

    /**
     * Filter tanggal, cabang, dan departemen PO.
     */
    private function applyPurchaseOrderFilters(
        Builder $query,
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): void {
        $query->whereBetween(
            'purchase_orders.tanggal_po',
            [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ],
        );
        $this->applyOfficialPurchaseOrderDashboardStatusFilter(
            query: $query,
        );

        if (isset($filters['cabang_id'])) {
            $query->where(
                'purchase_orders.cabang',
                (int) $filters['cabang_id'],
            );
        }

        if (isset($filters['department_id'])) {
            $query->where(
                'purchase_orders.id_department',
                (int) $filters['department_id'],
            );
        }
    }

    /**
     * Mengambil tren nilai PR dan PO.
     *
     * PR dan PO digabung menggunakan UNION ALL, kemudian
     * dijumlahkan kembali berdasarkan bucket tanggal.
     *
     * Hanya menghasilkan satu query database.
     */
    private function getTrend(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $granularity = $this->resolveTrendGranularity(
            period: $filters['period'],
            startDate: $startDate,
            endDate: $endDate,
        );

        $purchaseRequestBucketExpression
            = $this->getTrendBucketExpression(
                column: 'purchase_requests.tanggal_pr',
                granularity: $granularity,
            );

        $purchaseOrderBucketExpression
            = $this->getTrendBucketExpression(
                column: 'purchase_orders.tanggal_po',
                granularity: $granularity,
            );

        /*
     * Query agregasi nilai PR.
     */
        $purchaseRequestTrend = PurchaseRequest::query();

        $this->applyPurchaseRequestFilters(
            query: $purchaseRequestTrend,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $purchaseRequestTrend
            ->selectRaw(
                "
            {$purchaseRequestBucketExpression} AS bucket,

            COALESCE(
                SUM(purchase_requests.total_amount),
                0
            ) AS pr_amount,

            0 AS po_amount
            ",
            )
            ->groupByRaw(
                $purchaseRequestBucketExpression,
            );

        /*
     * Query agregasi nilai PO.
     */
        $purchaseOrderTrend = PurchaseOrder::query();

        $this->applyPurchaseOrderFilters(
            query: $purchaseOrderTrend,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $purchaseOrderTrend
            ->selectRaw(
                "
            {$purchaseOrderBucketExpression} AS bucket,

            0 AS pr_amount,

            COALESCE(
                SUM(purchase_orders.total_nilai),
                0
            ) AS po_amount
            ",
            )
            ->groupByRaw(
                $purchaseOrderBucketExpression,
            );

        /*
     * UNION ALL lebih ringan daripada UNION karena database
     * tidak perlu melakukan proses penghapusan duplicate row.
     */
        $unionQuery = $purchaseRequestTrend
            ->toBase()
            ->unionAll(
                $purchaseOrderTrend->toBase(),
            );

        $rows = DB::query()
            ->fromSub(
                $unionQuery,
                'trend_rows',
            )
            ->selectRaw(
                '
            bucket,

            COALESCE(
                SUM(pr_amount),
                0
            ) AS pr_amount,

            COALESCE(
                SUM(po_amount),
                0
            ) AS po_amount
            ',
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows
            ->map(function ($row) use ($granularity): array {
                $bucket = CarbonImmutable::parse(
                    (string) $row->bucket,
                );

                return [
                    /*
                 * Nilai bucket mentah berguna untuk debugging
                 * atau pengembangan berikutnya.
                 */
                    'date' => $bucket->toDateString(),

                    /*
                 * Label langsung dibaca grafik frontend.
                 */
                    'label' => $this->formatTrendLabel(
                        date: $bucket,
                        granularity: $granularity,
                    ),

                    'pr_amount' => (float) (
                        $row->pr_amount ?? 0
                    ),

                    'po_amount' => (float) (
                        $row->po_amount ?? 0
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Menentukan interval grafik berdasarkan periode.
     */
    private function resolveTrendGranularity(
        string $period,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): string {
        /*
     * Grafik satu tahun lebih mudah dibaca per bulan.
     */
        if ($period === 'year') {
            return 'month';
        }

        /*
     * Rentang tanggal menggunakan interval adaptif
     * agar jumlah titik grafik tidak terlalu banyak.
     */
        if ($period === 'range') {
            $totalDays = $startDate->diffInDays(
                $endDate,
            ) + 1;

            if ($totalDays <= 31) {
                return 'day';
            }

            if ($totalDays <= 180) {
                return 'week';
            }

            return 'month';
        }

        /*
     * Day, week, dan month ditampilkan per tanggal.
     */
        return 'day';
    }

    /**
     * Menghasilkan ekspresi SQL tanggal berdasarkan database driver.
     *
     * Hanya menerima granularity dari sistem, bukan input langsung
     * dari user, sehingga aman digunakan pada selectRaw.
     */
    private function getTrendBucketExpression(
        string $column,
        string $granularity,
    ): string {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return match ($granularity) {
                'week' => "DATE_TRUNC('week', {$column})::date",
                'month' => "DATE_TRUNC('month', {$column})::date",
                default => "DATE({$column})",
            };
        }

        if ($driver === 'mysql') {
            return match ($granularity) {
                /*
             * Menghasilkan hari Senin sebagai awal minggu.
             */
                'week' => "
                DATE_SUB(
                    DATE({$column}),
                    INTERVAL WEEKDAY({$column}) DAY
                )
            ",

                /*
             * Menghasilkan tanggal pertama bulan.
             */
                'month' => "
                STR_TO_DATE(
                    DATE_FORMAT({$column}, '%Y-%m-01'),
                    '%Y-%m-%d'
                )
            ",

                default => "DATE({$column})",
            };
        }

        /*
     * Fallback untuk database lain.
     */
        return "DATE({$column})";
    }

    /**
     * Membentuk label grafik tanpa bergantung pada locale server.
     */
    private function formatTrendLabel(
        CarbonImmutable $date,
        string $granularity,
    ): string {
        $monthNames = [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'Mei',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Agu',
            9 => 'Sep',
            10 => 'Okt',
            11 => 'Nov',
            12 => 'Des',
        ];

        $month = $monthNames[(int) $date->format('n')];

        return match ($granularity) {
            'week' => sprintf(
                'Minggu %s %s',
                $date->format('d'),
                $month,
            ),

            'month' => sprintf(
                '%s %s',
                $month,
                $date->format('Y'),
            ),

            default => sprintf(
                '%s %s',
                $date->format('d'),
                $month,
            ),
        };
    }

    /**
     * Breakdown jumlah dan nilai PR/PO per cabang.
     *
     * Hanya menjalankan satu query database:
     * - agregasi PR per cabang
     * - UNION ALL
     * - agregasi PO per cabang
     * - digabung kembali berdasarkan cabang
     */
    private function getBreakdownByCabang(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $purchaseRequestQuery = PurchaseRequest::query();

        $this->applyPurchaseRequestFilters(
            query: $purchaseRequestQuery,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $purchaseRequestQuery
            ->selectRaw(
                "
        CASE
            WHEN TRIM(
                purchase_requests.cabang::text
            ) ~ '^[0-9]+$'
            THEN TRIM(
                purchase_requests.cabang::text
            )::bigint
            ELSE NULL
        END AS dimension_id,

        COUNT(purchase_requests.id)
            AS pr_count,

        COALESCE(
            SUM(purchase_requests.total_amount),
            0
        ) AS pr_amount,

        0 AS po_count,
        0 AS po_amount
        ",
            )
            ->groupBy('purchase_requests.cabang');

        $purchaseOrderQuery = PurchaseOrder::query();

        $this->applyPurchaseOrderFilters(
            query: $purchaseOrderQuery,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $purchaseOrderQuery
            ->selectRaw(
                "
        CASE
            WHEN TRIM(
                purchase_orders.cabang::text
            ) ~ '^[0-9]+$'
            THEN TRIM(
                purchase_orders.cabang::text
            )::bigint
            ELSE NULL
        END AS dimension_id,

        0 AS pr_count,
        0 AS pr_amount,

        COUNT(purchase_orders.id)
            AS po_count,

        COALESCE(
            SUM(purchase_orders.total_nilai),
            0
        ) AS po_amount
        ",
            )
            ->groupBy('purchase_orders.cabang');

        $unionQuery = $purchaseRequestQuery
            ->toBase()
            ->unionAll(
                $purchaseOrderQuery->toBase(),
            );

        $rows = DB::query()
            ->fromSub(
                $unionQuery,
                'cabang_breakdown_rows',
            )
            ->leftJoin(
                'cabang',
                'cabang.id',
                '=',
                'cabang_breakdown_rows.dimension_id',
            )
            ->selectRaw(
                "
            cabang_breakdown_rows.dimension_id AS id,

            COALESCE(
                cabang.nama_cabang,
                'Belum Ditentukan'
            ) AS name,

            COALESCE(
                SUM(cabang_breakdown_rows.pr_count),
                0
            ) AS pr_count,

            COALESCE(
                SUM(cabang_breakdown_rows.pr_amount),
                0
            ) AS pr_amount,

            COALESCE(
                SUM(cabang_breakdown_rows.po_count),
                0
            ) AS po_count,

            COALESCE(
                SUM(cabang_breakdown_rows.po_amount),
                0
            ) AS po_amount
            ",
            )
            ->groupBy(
                'cabang_breakdown_rows.dimension_id',
                'cabang.nama_cabang',
            )
            ->orderByRaw(
                '
            (
                COALESCE(
                    SUM(cabang_breakdown_rows.pr_amount),
                    0
                )
                +
                COALESCE(
                    SUM(cabang_breakdown_rows.po_amount),
                    0
                )
            ) DESC
            ',
            )
            ->get();

        return $rows
            ->map(function ($row): array {
                return [
                    'id' => $row->id !== null
                        ? (int) $row->id
                        : null,

                    'name' => (string) $row->name,

                    'pr_count' => (int) (
                        $row->pr_count ?? 0
                    ),

                    'pr_amount' => (float) (
                        $row->pr_amount ?? 0
                    ),

                    'po_count' => (int) (
                        $row->po_count ?? 0
                    ),

                    'po_amount' => (float) (
                        $row->po_amount ?? 0
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Breakdown jumlah dan nilai PR/PO per departemen.
     */
    private function getBreakdownByDepartment(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $purchaseRequestQuery = PurchaseRequest::query();

        $this->applyPurchaseRequestFilters(
            query: $purchaseRequestQuery,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $purchaseRequestQuery
            ->selectRaw(
                '
            purchase_requests.id_department
                AS dimension_id,

            COUNT(purchase_requests.id)
                AS pr_count,

            COALESCE(
                SUM(purchase_requests.total_amount),
                0
            ) AS pr_amount,

            0 AS po_count,
            0 AS po_amount
            ',
            )
            ->groupBy(
                'purchase_requests.id_department',
            );

        $purchaseOrderQuery = PurchaseOrder::query();

        $this->applyPurchaseOrderFilters(
            query: $purchaseOrderQuery,
            filters: $filters,
            startDate: $startDate,
            endDate: $endDate,
        );

        $purchaseOrderQuery
            ->selectRaw(
                '
            purchase_orders.id_department
                AS dimension_id,

            0 AS pr_count,
            0 AS pr_amount,

            COUNT(purchase_orders.id)
                AS po_count,

            COALESCE(
                SUM(purchase_orders.total_nilai),
                0
            ) AS po_amount
            ',
            )
            ->groupBy(
                'purchase_orders.id_department',
            );

        $unionQuery = $purchaseRequestQuery
            ->toBase()
            ->unionAll(
                $purchaseOrderQuery->toBase(),
            );

        $rows = DB::query()
            ->fromSub(
                $unionQuery,
                'department_breakdown_rows',
            )
            ->leftJoin(
                'departments',
                'departments.id',
                '=',
                'department_breakdown_rows.dimension_id',
            )
            ->selectRaw(
                "
            department_breakdown_rows.dimension_id AS id,

            COALESCE(
                departments.nama,
                'Belum Ditentukan'
            ) AS name,

            COALESCE(
                SUM(department_breakdown_rows.pr_count),
                0
            ) AS pr_count,

            COALESCE(
                SUM(department_breakdown_rows.pr_amount),
                0
            ) AS pr_amount,

            COALESCE(
                SUM(department_breakdown_rows.po_count),
                0
            ) AS po_count,

            COALESCE(
                SUM(department_breakdown_rows.po_amount),
                0
            ) AS po_amount
            ",
            )
            ->groupBy(
                'department_breakdown_rows.dimension_id',
                'departments.nama',
            )
            ->orderByRaw(
                '
            (
                COALESCE(
                    SUM(department_breakdown_rows.pr_amount),
                    0
                )
                +
                COALESCE(
                    SUM(department_breakdown_rows.po_amount),
                    0
                )
            ) DESC
            ',
            )
            ->get();

        return $rows
            ->map(function ($row): array {
                return [
                    'id' => $row->id !== null
                        ? (int) $row->id
                        : null,

                    'name' => (string) $row->name,

                    'pr_count' => (int) (
                        $row->pr_count ?? 0
                    ),

                    'pr_amount' => (float) (
                        $row->pr_amount ?? 0
                    ),

                    'po_count' => (int) (
                        $row->po_count ?? 0
                    ),

                    'po_amount' => (float) (
                        $row->po_amount ?? 0
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Membandingkan harga satuan item PR dengan harga satuan rata-rata PO.
     *
     * Data ini membantu management melihat item mana yang mengalami kenaikan,
     * penurunan, atau tetap sama saat kebutuhan PR direalisasikan menjadi PO.
     */
    private function getItemPriceComparison(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $driver = DB::connection()->getDriverName();

        $poNumbersExpression = $driver === 'mysql'
            ? "GROUP_CONCAT(DISTINCT po.nomor_po ORDER BY po.nomor_po SEPARATOR ', ')"
            : "STRING_AGG(DISTINCT po.nomor_po, ', ' ORDER BY po.nomor_po)";

        /*
        |--------------------------------------------------------------------------
        | PR Unit Price Expression
        |--------------------------------------------------------------------------
        | Data PR lama kadang harga_unit bernilai 0/null, tetapi subtotal dan qty
        | tetap terisi. Agar dashboard tetap muncul, harga PR dihitung dengan
        | fallback:
        | 1. purchase_request_items.harga_unit
        | 2. purchase_request_items.subtotal / purchase_request_items.qty
        | 3. 0 jika keduanya tidak valid
        |--------------------------------------------------------------------------
        */
        $prUnitPriceExpression = "
            CASE
                WHEN COALESCE(pri.harga_unit, 0) > 0
                    THEN COALESCE(pri.harga_unit, 0)
                WHEN COALESCE(pri.qty, 0) > 0
                    AND COALESCE(pri.subtotal, 0) > 0
                    THEN COALESCE(pri.subtotal, 0) / NULLIF(COALESCE(pri.qty, 0), 0)
                ELSE 0
            END
        ";

        /*
        |--------------------------------------------------------------------------
        | PO Unit Price Expression
        |--------------------------------------------------------------------------
        | Harga PO menggunakan weighted average berdasarkan qty agar lebih akurat
        | ketika satu item PR dibuat menjadi beberapa PO dengan harga berbeda.
        |--------------------------------------------------------------------------
        */
        $poUnitPriceExpression = "
            CASE
                WHEN SUM(COALESCE(poi.qty, 0)) > 0
                    THEN SUM(COALESCE(poi.harga_unit, 0) * COALESCE(poi.qty, 0))
                        / NULLIF(SUM(COALESCE(poi.qty, 0)), 0)
                ELSE AVG(COALESCE(poi.harga_unit, 0))
            END
        ";

        $query = DB::table('purchase_order_items as poi')
            ->join(
                'purchase_orders as po',
                'po.id',
                '=',
                'poi.purchase_order_id',
            )
            ->join(
                'purchase_request_items as pri',
                'pri.id',
                '=',
                'poi.purchase_request_item_id',
            )
            ->leftJoin(
                'purchase_requests as pr',
                'pr.id',
                '=',
                'pri.purchase_request_id',
            )
            ->whereNull('poi.deleted_at')
            ->whereBetween(
                'po.tanggal_po',
                [
                    $startDate->toDateString(),
                    $endDate->toDateString(),
                ],
            )
            ->whereRaw(
                'UPPER(TRIM(po.status)) IN (?, ?)',
                [
                    self::PO_STATUS_IN_PROGRESS,
                    self::PO_STATUS_APPROVED,
                ],
            )
            ->whereRaw('COALESCE(poi.harga_unit, 0) > 0')
            ->whereRaw("({$prUnitPriceExpression}) > 0");

        if (isset($filters['cabang_id'])) {
            $query->where(
                'po.cabang',
                (int) $filters['cabang_id'],
            );
        }

        if (isset($filters['department_id'])) {
            $query->where(
                'po.id_department',
                (int) $filters['department_id'],
            );
        }

        $rows = $query
            ->selectRaw(
                "
                pri.id AS purchase_request_item_id,
                COALESCE(MAX(pr.nomor_pr), '-') AS pr_number,
                {$poNumbersExpression} AS po_numbers,
                COALESCE(MAX(NULLIF(TRIM(poi.nama_item), '')), MAX(NULLIF(TRIM(pri.nama_item), '')), 'Item tanpa nama') AS item_name,
                MAX({$prUnitPriceExpression}) AS pr_unit_price,
                {$poUnitPriceExpression} AS po_unit_price,
                MIN(COALESCE(poi.harga_unit, 0)) AS min_po_unit_price,
                MAX(COALESCE(poi.harga_unit, 0)) AS max_po_unit_price,
                COUNT(DISTINCT po.id) AS po_count,
                COALESCE(SUM(COALESCE(poi.qty, 0)), 0) AS po_qty,
                COALESCE(SUM(COALESCE(poi.subtotal, 0)), 0) AS po_amount
                ",
            )
            ->groupBy('pri.id')
            ->havingRaw('COALESCE(SUM(COALESCE(poi.qty, 0)), 0) > 0')
            ->get();

        $items = $rows
            ->map(function ($row): array {
                $prUnitPrice = (float) (
                    $row->pr_unit_price ?? 0
                );

                $poUnitPrice = (float) (
                    $row->po_unit_price ?? 0
                );

                $priceDifference = $poUnitPrice - $prUnitPrice;

                $priceDifferencePercent = $prUnitPrice > 0
                    ? round(
                        ($priceDifference / $prUnitPrice) * 100,
                        1,
                    )
                    : 0.0;

                $varianceType = 'same';

                if ($priceDifference > 0) {
                    $varianceType = 'increase';
                } elseif ($priceDifference < 0) {
                    $varianceType = 'decrease';
                }

                return [
                    'purchase_request_item_id' => $row->purchase_request_item_id !== null
                        ? (int) $row->purchase_request_item_id
                        : null,

                    'pr_number' => (string) (
                        $row->pr_number ?? '-'
                    ),

                    'po_numbers' => (string) (
                        $row->po_numbers ?? '-'
                    ),

                    'item_name' => (string) (
                        $row->item_name ?? 'Item tanpa nama'
                    ),

                    'pr_unit_price' => round($prUnitPrice, 2),
                    'po_unit_price' => round($poUnitPrice, 2),
                    'min_po_unit_price' => (float) (
                        $row->min_po_unit_price ?? 0
                    ),
                    'max_po_unit_price' => (float) (
                        $row->max_po_unit_price ?? 0
                    ),
                    'price_difference' => round($priceDifference, 2),
                    'price_difference_percent' => $priceDifferencePercent,
                    'variance_type' => $varianceType,
                    'po_count' => (int) (
                        $row->po_count ?? 0
                    ),
                    'po_qty' => (float) (
                        $row->po_qty ?? 0
                    ),
                    'po_amount' => (float) (
                        $row->po_amount ?? 0
                    ),
                ];
            })
            ->sortByDesc(function (array $item): float {
                return abs(
                    (float) $item['price_difference_percent'],
                );
            })
            ->values();

        $allItems = $items->all();
        $totalItems = count($allItems);

        $increasedItems = collect($allItems)
            ->where('variance_type', 'increase')
            ->count();

        $decreasedItems = collect($allItems)
            ->where('variance_type', 'decrease')
            ->count();

        $unchangedItems = collect($allItems)
            ->where('variance_type', 'same')
            ->count();

        $averageDifferencePercent = $totalItems > 0
            ? round(
                collect($allItems)->avg('price_difference_percent'),
                1,
            )
            : 0.0;

        $totalDifferenceAmount = collect($allItems)
            ->sum('price_difference');

        return [
            'summary' => [
                'total_items' => $totalItems,
                'increased_items' => $increasedItems,
                'decreased_items' => $decreasedItems,
                'unchanged_items' => $unchangedItems,
                'average_difference_percent' => $averageDifferencePercent,
                'total_difference_amount' => round(
                    (float) $totalDifferenceAmount,
                    2,
                ),
            ],

            'items' => $items
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * Membandingkan nilai final PR dengan nilai PO terkait.
     *
     * Perbandingan ini hanya memakai PR yang sudah COMPLETED secara status PO,
     * karena efisiensi / kenaikan nilai baru final ketika seluruh qty PR sudah
     * terealisasi menjadi PO. Outstanding qty tidak dihitung sebagai efisiensi.
     */
    private function getValueComparison(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $driver = DB::connection()->getDriverName();

        $poNumbersExpression = $driver === 'mysql'
            ? "GROUP_CONCAT(DISTINCT po.nomor_po ORDER BY po.nomor_po SEPARATOR ', ')"
            : "STRING_AGG(DISTINCT po.nomor_po, ', ' ORDER BY po.nomor_po)";

        $poSubtotalByPurchaseOrder = DB::table(
            'purchase_order_items as poi_all',
        )
            ->whereNull('poi_all.deleted_at')
            ->groupBy('poi_all.purchase_order_id')
            ->selectRaw(
                '
                poi_all.purchase_order_id,
                COALESCE(
                    SUM(COALESCE(poi_all.subtotal, 0)),
                    0
                ) AS po_item_subtotal
                ',
            );

        /*
        |--------------------------------------------------------------------------
        | Alokasi nilai PO ke masing-masing PR
        |--------------------------------------------------------------------------
        | Satu PO dapat berisi beberapa PR. Karena itu total_nilai header PO tidak
        | langsung dijumlah per PR agar tidak double count. Nilai PO dialokasikan
        | proporsional berdasarkan subtotal item PO yang berasal dari item PR.
        |--------------------------------------------------------------------------
        */
        $allocatedPoAmountExpression = '
            CASE
                WHEN COALESCE(po_subtotals.po_item_subtotal, 0) > 0
                    THEN COALESCE(poi.subtotal, 0)
                        * COALESCE(po.total_nilai, 0)
                        / NULLIF(COALESCE(po_subtotals.po_item_subtotal, 0), 0)
                ELSE COALESCE(poi.subtotal, 0)
            END
        ';

        $query = DB::table('purchase_requests as pr')
            ->join(
                'purchase_request_items as pri',
                'pri.purchase_request_id',
                '=',
                'pr.id',
            )
            ->join(
                'purchase_order_items as poi',
                'poi.purchase_request_item_id',
                '=',
                'pri.id',
            )
            ->join(
                'purchase_orders as po',
                'po.id',
                '=',
                'poi.purchase_order_id',
            )
            ->leftJoinSub(
                $poSubtotalByPurchaseOrder,
                'po_subtotals',
                function ($join): void {
                    $join->on(
                        'po_subtotals.purchase_order_id',
                        '=',
                        'po.id',
                    );
                },
            )
            ->whereNull('pri.deleted_at')
            ->whereNull('poi.deleted_at')
            ->whereNull('po.deleted_at')
            ->whereBetween(
                'pr.tanggal_pr',
                [
                    $startDate->toDateString(),
                    $endDate->toDateString(),
                ],
            )
            ->whereRaw(
                'UPPER(TRIM(pr.status)) = ?',
                [PurchaseRequest::STATUS_APPROVED],
            )
            ->whereRaw(
                'UPPER(TRIM(COALESCE(pr.status_po, \'\'))) = ?',
                [PurchaseRequest::STATUS_PO_COMPLETED],
            )
            /*
            |--------------------------------------------------------------------------
            | Status PO yang dihitung untuk perbandingan nilai
            |--------------------------------------------------------------------------
            | Sesuai rule proses PO yang sudah disepakati:
            | PO DRAFT sudah mengunci / mengurangi qty outstanding PR.
            | Karena itu untuk perbandingan nilai PR vs PO, DRAFT juga harus
            | dihitung selama PR sudah COMPLETED secara status_po.
            |
            | REJECTED dan CANCELLED tetap tidak dihitung karena tidak valid.
            |--------------------------------------------------------------------------
            */
            ->whereRaw(
                'UPPER(TRIM(po.status)) IN (?, ?, ?)',
                [
                    self::PO_STATUS_DRAFT,
                    self::PO_STATUS_IN_PROGRESS,
                    self::PO_STATUS_APPROVED,
                ],
            );

        if (isset($filters['cabang_id'])) {
            $query->where(
                'pr.cabang',
                (int) $filters['cabang_id'],
            );
        }

        if (isset($filters['department_id'])) {
            $query->where(
                'pr.id_department',
                (int) $filters['department_id'],
            );
        }

        $rows = $query
            ->selectRaw(
                "
                pr.id AS purchase_request_id,
                COALESCE(MAX(pr.nomor_pr), '-') AS pr_number,
                MAX(pr.tanggal_pr) AS pr_date,
                MAX(pr.status_po) AS status_po,
                MAX(COALESCE(pr.total_amount, 0)) AS pr_amount,
                {$poNumbersExpression} AS po_numbers,
                COALESCE(
                    SUM({$allocatedPoAmountExpression}),
                    0
                ) AS po_amount
                ",
            )
            ->groupBy('pr.id')
            ->havingRaw('COALESCE(SUM(COALESCE(poi.qty, 0)), 0) > 0')
            ->get();

        $items = $rows
            ->map(function ($row): array {
                $prAmount = round(
                    (float) ($row->pr_amount ?? 0),
                    2,
                );

                $poAmount = round(
                    (float) ($row->po_amount ?? 0),
                    2,
                );

                $differenceRaw = round(
                    $prAmount - $poAmount,
                    2,
                );

                $differenceAmount = round(
                    abs($differenceRaw),
                    2,
                );

                $differencePercent = $prAmount > 0
                    ? round(
                        ($differenceRaw / $prAmount) * 100,
                        1,
                    )
                    : 0.0;

                $varianceType = 'same';
                $varianceLabel = 'Tidak Ada Selisih';

                if ($differenceAmount > 0.009 && $differenceRaw > 0) {
                    $varianceType = 'efficiency';
                    $varianceLabel = 'Efisiensi Nilai';
                } elseif ($differenceAmount > 0.009 && $differenceRaw < 0) {
                    $varianceType = 'increase';
                    $varianceLabel = 'Kenaikan Nilai';
                } else {
                    $differenceAmount = 0.0;
                    $differenceRaw = 0.0;
                    $differencePercent = 0.0;
                }

                return [
                    'purchase_request_id' => $row->purchase_request_id !== null
                        ? (int) $row->purchase_request_id
                        : null,

                    'pr_number' => (string) (
                        $row->pr_number ?? '-'
                    ),

                    'pr_date' => $row->pr_date !== null
                        ? (string) $row->pr_date
                        : null,

                    'status_po' => (string) (
                        $row->status_po ?? PurchaseRequest::STATUS_PO_COMPLETED
                    ),

                    'po_numbers' => (string) (
                        $row->po_numbers ?? '-'
                    ),

                    'pr_amount' => $prAmount,
                    'po_amount' => $poAmount,
                    'difference_amount' => $differenceAmount,
                    'difference_raw' => $differenceRaw,
                    'difference_percent' => $differencePercent,
                    'variance_type' => $varianceType,
                    'variance_label' => $varianceLabel,
                ];
            })
            ->sortByDesc(function (array $item): float {
                return abs(
                    (float) $item['difference_raw'],
                );
            })
            ->values();

        $allItems = $items->all();
        $totalCompletedPr = count($allItems);

        $efficiencyItems = collect($allItems)
            ->where('variance_type', 'efficiency');

        $increaseItems = collect($allItems)
            ->where('variance_type', 'increase');

        $sameItems = collect($allItems)
            ->where('variance_type', 'same');

        $totalPrAmount = collect($allItems)
            ->sum('pr_amount');

        $totalPoAmount = collect($allItems)
            ->sum('po_amount');

        $efficiencyAmount = $efficiencyItems
            ->sum('difference_amount');

        $increaseAmount = $increaseItems
            ->sum('difference_amount');

        $netDifferenceAmount = round(
            (float) $efficiencyAmount - (float) $increaseAmount,
            2,
        );

        $averageDifferencePercent = $totalCompletedPr > 0
            ? round(
                collect($allItems)->avg('difference_percent'),
                1,
            )
            : 0.0;

        return [
            'summary' => [
                'completed_pr_count' => $totalCompletedPr,
                'efficiency_pr_count' => $efficiencyItems->count(),
                'increase_pr_count' => $increaseItems->count(),
                'same_pr_count' => $sameItems->count(),
                'total_pr_amount' => round((float) $totalPrAmount, 2),
                'total_po_amount' => round((float) $totalPoAmount, 2),
                'efficiency_amount' => round((float) $efficiencyAmount, 2),
                'increase_amount' => round((float) $increaseAmount, 2),
                'net_difference_amount' => $netDifferenceAmount,
                'average_difference_percent' => $averageDifferencePercent,
            ],

            'items' => $items
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * Mengubah pilihan periode menjadi
     * start date dan end date.
     */
    private function resolveDateRange(
        array $filters,
    ): array {
        return match ($filters['period']) {
            'day' => $this->resolveDayPeriod(
                $filters['date'],
            ),

            'week' => $this->resolveWeekPeriod(
                $filters['week'],
            ),

            'month' => $this->resolveMonthPeriod(
                $filters['month'],
            ),

            'year' => $this->resolveYearPeriod(
                (int) $filters['year'],
            ),

            'range' => $this->resolveCustomRange(
                $filters['start_date'],
                $filters['end_date'],
            ),

            default => throw new InvalidArgumentException(
                'Periode dashboard tidak valid.',
            ),
        };
    }

    private function resolveDayPeriod(
        string $date,
    ): array {
        $selectedDate = CarbonImmutable::parse(
            $date,
        );

        return [
            $selectedDate->startOfDay(),
            $selectedDate->endOfDay(),
        ];
    }

    private function resolveWeekPeriod(
        string $week,
    ): array {
        if (
            !preg_match(
                '/^(\d{4})-W(\d{2})$/',
                $week,
                $matches,
            )
        ) {
            throw new InvalidArgumentException(
                'Format minggu tidak valid.',
            );
        }

        $year = (int) $matches[1];
        $weekNumber = (int) $matches[2];

        $startDate = CarbonImmutable::now()
            ->setISODate(
                $year,
                $weekNumber,
            )
            ->startOfWeek();

        return [
            $startDate,
            $startDate->endOfWeek(),
        ];
    }

    private function resolveMonthPeriod(
        string $month,
    ): array {
        $selectedMonth
            = CarbonImmutable::createFromFormat(
                'Y-m-d',
                "{$month}-01",
            );

        return [
            $selectedMonth->startOfMonth(),
            $selectedMonth->endOfMonth(),
        ];
    }

    private function resolveYearPeriod(
        int $year,
    ): array {
        $selectedYear = CarbonImmutable::create(
            year: $year,
            month: 1,
            day: 1,
        );

        return [
            $selectedYear->startOfYear(),
            $selectedYear->endOfYear(),
        ];
    }

    private function resolveCustomRange(
        string $startDate,
        string $endDate,
    ): array {
        return [
            CarbonImmutable::parse(
                $startDate,
            )->startOfDay(),

            CarbonImmutable::parse(
                $endDate,
            )->endOfDay(),
        ];
    }

    private function applyOfficialPurchaseRequestStatusFilter(Builder $query): void
    {
        $query->whereIn('purchase_requests.status', [
            PurchaseRequest::STATUS_IN_PROGRESS,
            PurchaseRequest::STATUS_APPROVED,
        ]);
    }

    private function applyOfficialPurchaseOrderStatusFilter(Builder $query): void
    {
        $query->whereIn('purchase_orders.status', [
            self::PO_STATUS_IN_PROGRESS,
            self::PO_STATUS_APPROVED,
        ]);
    }
}
