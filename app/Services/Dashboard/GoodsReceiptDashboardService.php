<?php

namespace App\Services\Dashboard;

use App\Models\GoodsReceive;
use App\Models\GoodsReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| Dashboard Goods Receipt
|--------------------------------------------------------------------------
| Sudut pandangnya sama dengan dashboard PR dan PO -- manajemen, bukan
| operator -- tetapi pertanyaannya berbeda. PR menanyakan "apa yang diminta"
| dan PO "apa yang dibeli"; Goods Receipt menanyakan APA YANG SUDAH SAMPAI.
|
| Lima hal yang dijawab:
|
| 1. Berapa banyak barang yang benar-benar diterima, dan berapa nilainya
| 2. Berapa yang sudah dibeli tetapi BELUM sampai -- ini uang yang sudah
|    terikat namun barangnya belum ada
| 3. Berapa lama vendor biasanya mengirim setelah PO disetujui
| 4. Vendor mana yang paling banyak dikembalikan barangnya
| 5. Mana penerimaan yang menggantung dan perlu ditindaklanjuti hari ini
|
| Nilai barang tidak disimpan di dokumen penerimaan; yang ada hanya qty.
| Karena itu setiap nilai di sini dihitung dari qty penerimaan dikalikan
| harga satuan pada baris PO-nya.
|
| Filter periode, penanganan scope, dan bentuk responsnya sengaja mengikuti
| dashboard PR dan PO supaya ketiganya terasa satu keluarga.
|--------------------------------------------------------------------------
*/
class GoodsReceiptDashboardService
{
    /** Ambang dokumen menggantung yang dianggap perlu ditindaklanjuti. */
    private const AGING_THRESHOLD_DAYS = 3;

    /** Banyaknya baris pada daftar "butuh perhatian". */
    private const ATTENTION_LIMIT = 8;

    /** Banyaknya vendor yang ditampilkan pada peringkat kinerja. */
    private const VENDOR_LIMIT = 8;

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
            'vendors' => $this->getVendorPerformance($filters, $startDate, $endDate),
            'return_reasons' => $this->getReturnReasons($filters, $startDate, $endDate),
            'attention' => $this->getAttentionItems($filters),

            'breakdown' => [
                'by_cabang' => $this->getBreakdown($filters, $startDate, $endDate, 'cabang'),
                'by_department' => $this->getBreakdown($filters, $startDate, $endDate, 'department'),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Ringkasan
    |--------------------------------------------------------------------------
    | Empat angka yang dibaca lebih dulu. "Belum sampai" sengaja ikut di sini
    | karena itulah satu-satunya yang mewakili uang yang sudah keluar tetapi
    | barangnya belum ada -- risiko, bukan sekadar catatan.
    |--------------------------------------------------------------------------
    */
    private function getSummary(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $base = fn (): Builder => $this->baseQuery($filters, $startDate, $endDate)
            ->leftJoinSub($this->receiptValueSub(), 'nilai', 'nilai.goods_receive_id', '=', 'goods_receives.id');

        $total = (clone $base())
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(nilai.nilai), 0) AS nilai')
            ->first();

        $posted = (clone $base())
            ->where('goods_receives.status', GoodsReceive::STATUS_POSTED)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(nilai.nilai), 0) AS nilai')
            ->first();

        $draft = (clone $base())
            ->where('goods_receives.status', GoodsReceive::STATUS_DRAFT)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(nilai.nilai), 0) AS nilai')
            ->first();

        /*
        | Penerimaan sebagian: barangnya sudah datang tetapi masih menyisakan
        | kekurangan. Dihitung dari sisa pada baris penerimaannya sendiri.
        */
        $partial = (clone $base())
            ->where('goods_receives.status', GoodsReceive::STATUS_POSTED)
            ->where('nilai.sisa', '>', 0)
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(nilai.nilai), 0) AS nilai')
            ->first();

        $outstanding = $this->getOutstandingSummary($filters, $startDate, $endDate);
        $returns = $this->getReturnSummary($filters, $startDate, $endDate);

        return [
            'total' => $this->pair($total),
            'posted' => $this->pair($posted),
            'draft' => $this->pair($draft),
            'partial' => $this->pair($partial),
            'outstanding' => $outstanding,
            'returns' => $returns,

            'average_lead_days' => $this->getAverageLeadDays($filters, $startDate, $endDate),
            'fulfillment_percent' => $outstanding['fulfillment_percent'],

            /*
            | Berapa persen nilai yang diterima berakhir dikembalikan. Angka
            | kecil pun berarti, karena tiap return adalah barang yang gagal
            | dipakai -- karena itu satu desimal, bukan dibulatkan penuh.
            */
            'return_rate_percent' => (float) ($posted->nilai ?? 0) > 0
                ? round(($returns['amount'] / (float) $posted->nilai) * 100, 1)
                : 0.0,
        ];
    }

    /**
     * Nilai PO yang sudah disetujui tetapi barangnya belum sampai.
     *
     * Dihitung dari baris PO, bukan dari dokumen penerimaan: yang dicari justru
     * yang penerimaannya BELUM ada, sehingga tidak akan ketemu bila ditelusuri
     * dari sisi goods receive.
     *
     * @return array{count: int, amount: float, item_count: int, fulfillment_percent: float}
     */
    private function getOutstandingSummary(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $row = $this->approvedPurchaseOrderQuery($filters)
            ->whereBetween('purchase_orders.tanggal_po', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->join('purchase_order_items AS poi', function ($join): void {
                $join->on('poi.purchase_order_id', '=', 'purchase_orders.id')
                    ->whereNull('poi.deleted_at');
            })
            ->selectRaw('COUNT(DISTINCT purchase_orders.id) FILTER (WHERE poi.qty_outstanding_receive > 0) AS jumlah_po')
            ->selectRaw('COUNT(*) FILTER (WHERE poi.qty_outstanding_receive > 0) AS jumlah_item')
            ->selectRaw('COALESCE(SUM(GREATEST(poi.qty_outstanding_receive, 0) * poi.harga_unit), 0) AS nilai_sisa')
            ->selectRaw('COALESCE(SUM(poi.qty), 0) AS qty_pesan')
            ->selectRaw('COALESCE(SUM(poi.qty_received), 0) AS qty_terima')
            ->first();

        $qtyPesan = (float) ($row->qty_pesan ?? 0);

        return [
            'count' => (int) ($row->jumlah_po ?? 0),
            'amount' => (float) ($row->nilai_sisa ?? 0),
            'item_count' => (int) ($row->jumlah_item ?? 0),

            'fulfillment_percent' => $qtyPesan > 0
                ? round(((float) $row->qty_terima / $qtyPesan) * 100, 1)
                : 0.0,
        ];
    }

    /**
     * Barang yang dikembalikan ke vendor pada periode itu.
     *
     * Return yang dibatalkan tidak dihitung: qty-nya sudah dikembalikan ke PO,
     * jadi memasukkannya akan menghitung barang yang sebenarnya tetap dipakai.
     *
     * @return array{count: int, amount: float, qty: float}
     */
    private function getReturnSummary(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $row = $this->returnBaseQuery($filters, $startDate, $endDate)
            ->leftJoin('goods_return_items AS gri', 'gri.goods_return_id', '=', 'goods_returns.id')
            ->leftJoin('purchase_order_items AS poi', 'poi.id', '=', 'gri.purchase_order_item_id')
            ->selectRaw('COUNT(DISTINCT goods_returns.id) AS jumlah')
            ->selectRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) AS nilai')
            ->selectRaw('COALESCE(SUM(gri.qty_return), 0) AS qty')
            ->first();

        return [
            'count' => (int) ($row->jumlah ?? 0),
            'amount' => (float) ($row->nilai ?? 0),
            'qty' => (float) ($row->qty ?? 0),
        ];
    }

    /**
     * Rata-rata jarak hari dari PO disetujui sampai barangnya diterima.
     *
     * Hanya penerimaan yang sudah di-posting yang dihitung; draft belum tentu
     * jadi, dan memasukkannya akan membuat vendor terlihat lebih cepat dari
     * kenyataan. Bila tanggal persetujuan PO tidak terisi, tanggal PO dipakai
     * sebagai gantinya supaya dokumen lama tetap punya angka.
     */
    private function getAverageLeadDays(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): ?float {
        $average = $this->baseQuery($filters, $startDate, $endDate)
            ->join('purchase_orders AS po', 'po.id', '=', 'goods_receives.purchase_order_id')
            ->where('goods_receives.status', GoodsReceive::STATUS_POSTED)
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (goods_receives.tanggal_gr::timestamp - COALESCE(po.approved_at, po.tanggal_po::timestamp))) / 86400) AS rata',
            )
            ->value('rata');

        return $average === null ? null : round((float) $average, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Sebaran status
    |--------------------------------------------------------------------------
    | Ketiga status ditampilkan, termasuk yang dibatalkan -- manajemen perlu
    | melihat berapa penerimaan yang gugur, bukan hanya yang berhasil.
    |--------------------------------------------------------------------------
    */
    private function getStatusBreakdown(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->leftJoinSub($this->receiptValueSub(), 'nilai', 'nilai.goods_receive_id', '=', 'goods_receives.id')
            ->select('goods_receives.status')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(nilai.nilai), 0) AS nilai')
            ->groupBy('goods_receives.status')
            ->get()
            ->keyBy(fn ($row): string => strtoupper(trim((string) $row->status)));

        $urutan = [
            GoodsReceive::STATUS_DRAFT,
            GoodsReceive::STATUS_POSTED,
            GoodsReceive::STATUS_CANCELLED,
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
    | Kerapatan titik menyesuaikan panjang periode, sama seperti dashboard PR,
    | supaya grafik rentang panjang tetap terbaca.
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
            'day' => "TO_CHAR(goods_receives.tanggal_gr, 'YYYY-MM-DD')",
            'week' => "TO_CHAR(goods_receives.tanggal_gr, 'IYYY-\"W\"IW')",
            default => "TO_CHAR(goods_receives.tanggal_gr, 'YYYY-MM')",
        };

        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->leftJoinSub($this->receiptValueSub(), 'nilai', 'nilai.goods_receive_id', '=', 'goods_receives.id')
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw(
                'COUNT(*) FILTER (WHERE goods_receives.status = ?) AS diposting',
                [GoodsReceive::STATUS_POSTED],
            )
            ->selectRaw('COALESCE(SUM(nilai.nilai), 0) AS nilai')
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
                    'posted_count' => (int) $row->diposting,
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
    | Kinerja vendor
    |--------------------------------------------------------------------------
    | Bagian yang paling khas Goods Receipt: dashboard PR dan PO berhenti pada
    | dokumen, sedangkan di sini yang dinilai adalah PIHAK yang mengirim.
    |
    | Tiga angka per vendor: berapa yang dikirim, seberapa cepat, dan berapa
    | yang dikembalikan. Ketiganya baru bermakna bila dibaca bersamaan -- vendor
    | tercepat belum tentu yang paling sedikit returnnya.
    |--------------------------------------------------------------------------
    */
    private function getVendorPerformance(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        /*
        | Return dikumpulkan terpisah lalu ditempelkan, bukan di-join langsung.
        | Satu penerimaan bisa punya banyak baris return, dan menggabungkannya
        | dalam satu join akan melipatgandakan nilai penerimaannya.
        */
        $returnPerVendor = $this->returnBaseQuery($filters, $startDate, $endDate)
            ->leftJoin('goods_return_items AS gri', 'gri.goods_return_id', '=', 'goods_returns.id')
            ->leftJoin('purchase_order_items AS poi', 'poi.id', '=', 'gri.purchase_order_item_id')
            ->groupBy('goods_returns.vendor_id')
            ->select('goods_returns.vendor_id')
            ->selectRaw('COUNT(DISTINCT goods_returns.id) AS jumlah_return')
            ->selectRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) AS nilai_return')
            ->get()
            ->keyBy(fn ($row): int => (int) $row->vendor_id);

        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->leftJoinSub($this->receiptValueSub(), 'nilai', 'nilai.goods_receive_id', '=', 'goods_receives.id')
            ->leftJoin('master_vendor AS mv', 'mv.id', '=', 'goods_receives.vendor_id')
            ->leftJoin('purchase_orders AS po', 'po.id', '=', 'goods_receives.purchase_order_id')
            ->groupBy('goods_receives.vendor_id', 'mv.nama_vendor')
            ->selectRaw('goods_receives.vendor_id')
            ->selectRaw("COALESCE(NULLIF(TRIM(mv.nama_vendor), ''), 'Tidak diketahui') AS nama_vendor")
            ->selectRaw('COUNT(*) AS jumlah_gr')
            ->selectRaw(
                'COUNT(*) FILTER (WHERE goods_receives.status = ?) AS jumlah_posted',
                [GoodsReceive::STATUS_POSTED],
            )
            ->selectRaw('COALESCE(SUM(nilai.nilai), 0) AS nilai')
            ->selectRaw('COALESCE(SUM(nilai.qty), 0) AS qty')
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (goods_receives.tanggal_gr::timestamp - COALESCE(po.approved_at, po.tanggal_po::timestamp))) / 86400)'
                . ' FILTER (WHERE goods_receives.status = ?) AS rata_hari',
                [GoodsReceive::STATUS_POSTED],
            )
            ->orderByRaw('COALESCE(SUM(nilai.nilai), 0) DESC')
            ->limit(self::VENDOR_LIMIT)
            ->get();

        return $rows
            ->map(function ($row) use ($returnPerVendor): array {
                $vendorId = $row->vendor_id === null ? null : (int) $row->vendor_id;
                $nilai = (float) $row->nilai;
                $return = $vendorId === null ? null : ($returnPerVendor[$vendorId] ?? null);
                $nilaiReturn = (float) ($return->nilai_return ?? 0);

                return [
                    'id' => $vendorId,
                    'name' => (string) $row->nama_vendor,
                    'receipt_count' => (int) $row->jumlah_gr,
                    'posted_count' => (int) $row->jumlah_posted,
                    'amount' => $nilai,
                    'qty' => (float) $row->qty,

                    'average_lead_days' => $row->rata_hari === null
                        ? null
                        : round((float) $row->rata_hari, 1),

                    'return_count' => (int) ($return->jumlah_return ?? 0),
                    'return_amount' => $nilaiReturn,

                    'return_rate_percent' => $nilai > 0
                        ? round(($nilaiReturn / $nilai) * 100, 1)
                        : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Alasan pengembalian
    |--------------------------------------------------------------------------
    | Menjawab "kenapa barangnya dikembalikan". Dikelompokkan per alasan, bukan
    | per vendor, supaya yang terbaca adalah pola masalahnya.
    |--------------------------------------------------------------------------
    */
    private function getReturnReasons(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $rows = $this->returnBaseQuery($filters, $startDate, $endDate)
            ->join('goods_return_items AS gri', 'gri.goods_return_id', '=', 'goods_returns.id')
            ->leftJoin('goods_return_reasons AS grr', 'grr.id', '=', 'gri.reason_id')
            ->leftJoin('purchase_order_items AS poi', 'poi.id', '=', 'gri.purchase_order_item_id')
            ->groupByRaw("COALESCE(NULLIF(TRIM(grr.name), ''), 'Tidak diisi')")
            ->selectRaw("COALESCE(NULLIF(TRIM(grr.name), ''), 'Tidak diisi') AS alasan")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(gri.qty_return), 0) AS qty')
            ->selectRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) AS nilai')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'name' => (string) $row->alasan,
                'count' => (int) $row->jumlah,
                'qty' => (float) $row->qty,
                'amount' => (float) $row->nilai,
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Butuh perhatian
    |--------------------------------------------------------------------------
    | Sengaja TIDAK dibatasi periode. PO yang barangnya belum sampai sejak
    | bulan lalu justru yang paling perlu terlihat, dan akan hilang bila ikut
    | disaring tanggalnya.
    |--------------------------------------------------------------------------
    */
    private function getAttentionItems(array $filters): array
    {
        return [
            'threshold_days' => self::AGING_THRESHOLD_DAYS,
            'outstanding_po' => $this->getOutstandingPurchaseOrders($filters),
            'draft_receipts' => $this->getDraftReceipts($filters),
        ];
    }

    private function getOutstandingPurchaseOrders(array $filters): array
    {
        $rows = $this->approvedPurchaseOrderQuery($filters)
            ->join('purchase_order_items AS poi', function ($join): void {
                $join->on('poi.purchase_order_id', '=', 'purchase_orders.id')
                    ->whereNull('poi.deleted_at');
            })
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'purchase_orders.cabang')
            ->leftJoin('departments', 'departments.id', '=', 'purchase_orders.id_department')
            ->leftJoin('master_vendor AS mv', 'mv.id', '=', 'purchase_orders.vendor_id')
            ->groupBy(
                'purchase_orders.id',
                'purchase_orders.nomor_po',
                'purchase_orders.tanggal_po',
                'purchase_orders.approved_at',
                'purchase_orders.updated_at',
                'cabang.nama_cabang',
                'departments.nama',
                'mv.nama_vendor',
            )
            ->havingRaw('SUM(GREATEST(poi.qty_outstanding_receive, 0)) > 0')
            ->selectRaw('purchase_orders.id')
            ->selectRaw('purchase_orders.nomor_po')
            ->selectRaw('purchase_orders.tanggal_po')
            ->selectRaw('purchase_orders.approved_at')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw("COALESCE(NULLIF(TRIM(mv.nama_vendor), ''), '-') AS nama_vendor")
            ->selectRaw('COALESCE(SUM(GREATEST(poi.qty_outstanding_receive, 0) * poi.harga_unit), 0) AS nilai_sisa')
            ->selectRaw('COALESCE(SUM(poi.qty), 0) AS qty_pesan')
            ->selectRaw('COALESCE(SUM(poi.qty_received), 0) AS qty_terima')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - COALESCE(purchase_orders.approved_at, purchase_orders.updated_at))) / 86400 AS lama_hari',
            )
            ->orderByRaw('COALESCE(purchase_orders.approved_at, purchase_orders.updated_at) ASC')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(function ($row): array {
                $qtyPesan = (float) $row->qty_pesan;

                return [
                    'id' => (int) $row->id,
                    'number' => (string) $row->nomor_po,
                    'date' => $row->tanggal_po,
                    'vendor' => (string) $row->nama_vendor,
                    'cabang' => $row->nama_cabang ?? '-',
                    'department' => $row->nama_department ?? '-',
                    'approved_at' => $row->approved_at,
                    'outstanding_amount' => (float) $row->nilai_sisa,

                    'received_percent' => $qtyPesan > 0
                        ? round(((float) $row->qty_terima / $qtyPesan) * 100, 1)
                        : 0.0,

                    'idle_days' => round((float) $row->lama_hari, 1),
                    'is_overdue' => (float) $row->lama_hari >= self::AGING_THRESHOLD_DAYS,
                ];
            })
            ->values()
            ->all();
    }

    private function getDraftReceipts(array $filters): array
    {
        $rows = DB::table('goods_receives')
            ->leftJoinSub($this->receiptValueSub(), 'nilai', 'nilai.goods_receive_id', '=', 'goods_receives.id')
            ->leftJoin('cabang', 'cabang.id', '=', 'goods_receives.cabang')
            ->leftJoin('departments', 'departments.id', '=', 'goods_receives.id_department')
            ->leftJoin('master_vendor AS mv', 'mv.id', '=', 'goods_receives.vendor_id')
            ->leftJoin('purchase_orders AS po', 'po.id', '=', 'goods_receives.purchase_order_id')
            ->whereNull('goods_receives.deleted_at')
            ->where('goods_receives.status', GoodsReceive::STATUS_DRAFT)
            ->when(
                isset($filters['cabang_id']),
                fn ($query) => $query->where('goods_receives.cabang', (int) $filters['cabang_id']),
            )
            ->when(
                isset($filters['department_id']),
                fn ($query) => $query->where('goods_receives.id_department', (int) $filters['department_id']),
            )
            ->when(
                isset($filters['created_by']),
                fn ($query) => $query->where('goods_receives.created_by', (int) $filters['created_by']),
            )
            ->selectRaw('goods_receives.id')
            ->selectRaw('goods_receives.nomor_gr')
            ->selectRaw('goods_receives.tanggal_gr')
            ->selectRaw('po.nomor_po')
            ->selectRaw("COALESCE(NULLIF(TRIM(mv.nama_vendor), ''), '-') AS nama_vendor")
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw('COALESCE(nilai.nilai, 0) AS nilai')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - goods_receives.created_at)) / 86400 AS lama_hari',
            )
            ->orderBy('goods_receives.created_at')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->nomor_gr,
                'date' => $row->tanggal_gr,
                'po_number' => $row->nomor_po ?? '-',
                'vendor' => (string) $row->nama_vendor,
                'cabang' => $row->nama_cabang ?? '-',
                'department' => $row->nama_department ?? '-',
                'amount' => (float) $row->nilai,
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
        $query = $this->baseQuery($filters, $startDate, $endDate)
            ->leftJoinSub($this->receiptValueSub(), 'nilai', 'nilai.goods_receive_id', '=', 'goods_receives.id');

        if ($dimension === 'cabang') {
            $query
                ->leftJoin('cabang', 'cabang.id', '=', 'goods_receives.cabang')
                ->selectRaw('cabang.id AS dimensi_id')
                ->selectRaw("COALESCE(cabang.nama_cabang, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('cabang.id', 'cabang.nama_cabang');
        } else {
            $query
                ->leftJoin('departments', 'departments.id', '=', 'goods_receives.id_department')
                ->selectRaw('departments.id AS dimensi_id')
                ->selectRaw("COALESCE(departments.nama, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('departments.id', 'departments.nama');
        }

        $rows = $query
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(nilai.nilai), 0) AS nilai')
            ->selectRaw(
                'COUNT(*) FILTER (WHERE goods_receives.status = ?) AS diposting',
                [GoodsReceive::STATUS_POSTED],
            )
            ->selectRaw(
                'COUNT(*) FILTER (WHERE goods_receives.status = ?) AS draf',
                [GoodsReceive::STATUS_DRAFT],
            )
            ->orderByRaw('COALESCE(SUM(nilai.nilai), 0) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => $row->dimensi_id === null ? null : (int) $row->dimensi_id,
                'name' => (string) $row->dimensi_nama,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->nilai,
                'posted_count' => (int) $row->diposting,
                'draft_count' => (int) $row->draf,
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Akses
    |--------------------------------------------------------------------------
    | Mengikuti pola dashboard PR dan PO: scope permission menentukan filter
    | mana yang boleh dipilih user dan mana yang dipaksakan sistem.
    |--------------------------------------------------------------------------
    */
    public function resolveAccessAndFilters(User $user, array $filters): array
    {
        $scope = $user->getPermissionScope('dashboard.gr.view');

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
        return GoodsReceive::query()
            ->whereBetween('goods_receives.tanggal_gr', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->when(
                isset($filters['cabang_id']),
                fn ($query) => $query->where('goods_receives.cabang', (int) $filters['cabang_id']),
            )
            ->when(
                isset($filters['department_id']),
                fn ($query) => $query->where('goods_receives.id_department', (int) $filters['department_id']),
            )
            ->when(
                isset($filters['created_by']),
                fn ($query) => $query->where('goods_receives.created_by', (int) $filters['created_by']),
            );
    }

    /**
     * Nilai, qty, dan sisa per dokumen penerimaan.
     *
     * Harganya diambil dari baris PO karena dokumen penerimaan hanya mencatat
     * kuantitas. Dipisah sebagai subquery, bukan join langsung, supaya satu
     * penerimaan tetap terhitung satu baris ketika dijumlahkan.
     */
    private function receiptValueSub(): QueryBuilder
    {
        return DB::table('goods_receive_items AS gri')
            ->join('purchase_order_items AS poi', 'poi.id', '=', 'gri.purchase_order_item_id')
            ->groupBy('gri.goods_receive_id')
            ->select('gri.goods_receive_id')
            ->selectRaw('COALESCE(SUM(gri.qty_receive * poi.harga_unit), 0) AS nilai')
            ->selectRaw('COALESCE(SUM(gri.qty_receive), 0) AS qty')
            ->selectRaw('COALESCE(SUM(gri.qty_outstanding), 0) AS sisa');
    }

    /**
     * PO yang sudah disetujui dan karenanya layak ditunggu barangnya.
     *
     * cabang pada purchase_orders bertipe teks, berbeda dengan goods_receives
     * yang bertipe angka -- karena itu perbandingannya dilakukan sebagai teks.
     */
    private function approvedPurchaseOrderQuery(array $filters): QueryBuilder
    {
        return DB::table('purchase_orders')
            ->whereNull('purchase_orders.deleted_at')
            ->where('purchase_orders.status', 'APPROVED')
            ->when(
                isset($filters['cabang_id']),
                fn ($query) => $query->where('purchase_orders.cabang', (string) $filters['cabang_id']),
            )
            ->when(
                isset($filters['department_id']),
                fn ($query) => $query->where('purchase_orders.id_department', (int) $filters['department_id']),
            )
            ->when(
                isset($filters['created_by']),
                fn ($query) => $query->where('purchase_orders.created_by', (int) $filters['created_by']),
            );
    }

    private function returnBaseQuery(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): QueryBuilder {
        return DB::table('goods_returns')
            ->whereNull('goods_returns.deleted_at')
            ->where('goods_returns.status', '!=', GoodsReturn::STATUS_CANCELLED)
            ->whereBetween('goods_returns.tanggal_return', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->when(
                isset($filters['cabang_id']),
                fn ($query) => $query->where('goods_returns.cabang', (int) $filters['cabang_id']),
            )
            ->when(
                isset($filters['department_id']),
                fn ($query) => $query->where('goods_returns.id_department', (int) $filters['department_id']),
            )
            ->when(
                isset($filters['created_by']),
                fn ($query) => $query->where('goods_returns.created_by', (int) $filters['created_by']),
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
