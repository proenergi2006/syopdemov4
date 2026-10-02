<?php

namespace App\Services\Dashboard;

use App\Models\GoodsReceive;
use App\Models\GoodsReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| Dashboard Goods Return
|--------------------------------------------------------------------------
| Sudut pandangnya sama dengan dashboard PR, PO, dan GR -- manajemen, bukan
| operator -- tetapi pertanyaannya berbeda. GR menanyakan "apa yang sudah
| sampai"; Goods Return menanyakan APA YANG TERNYATA TIDAK BISA DIPAKAI.
|
| Enam hal yang dijawab:
|
| 1. Berapa banyak barang dikembalikan, berapa nilainya, dan seberapa besar
|    bagiannya dari barang yang diterima pada periode yang sama
| 2. Kenapa dikembalikan -- alasan mana yang paling sering muncul
| 3. Vendor mana yang barangnya paling banyak dikembalikan, dan seberapa
|    besar dibanding yang ia kirim
| 4. Barang apa yang paling sering bermasalah
| 5. Berapa lama cacatnya baru ketahuan setelah barang diterima
| 6. Dokumen mana yang menggantung dan perlu ditindaklanjuti hari ini
|
| Dua hal yang perlu diketahui tentang angkanya:
|
| - Dokumen return hanya mencatat kuantitas, tidak menyimpan nilai. Setiap
|   rupiah di sini dihitung dari qty return dikali harga satuan pada baris
|   PO-nya. Baris yang tidak tertaut ke PO bernilai nol, dan qty-nya tetap
|   terhitung -- itu sebabnya qty dan nilai selalu ditampilkan berdampingan,
|   bukan nilai saja.
|
| - Return yang DIBATALKAN tidak dihitung sebagai barang yang dikembalikan:
|   qty-nya sudah dikembalikan ke PO, jadi memasukkannya berarti menghitung
|   barang yang sebenarnya tetap dipakai. Ia tetap muncul pada sebaran status,
|   karena manajemen perlu melihat berapa yang gugur.
|
| Filter periode, penanganan scope, dan bentuk responsnya sengaja mengikuti
| ketiga dashboard sebelumnya supaya keempatnya terasa satu keluarga.
|--------------------------------------------------------------------------
*/
class GoodsReturnDashboardService
{
    /** Ambang draft menggantung yang dianggap perlu ditindaklanjuti. */
    private const AGING_THRESHOLD_DAYS = 3;

    /** Banyaknya baris pada daftar "butuh perhatian". */
    private const ATTENTION_LIMIT = 8;

    /** Banyaknya vendor dan item yang ditampilkan pada peringkat. */
    private const RANK_LIMIT = 8;

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
            'reasons' => $this->getReasons($filters, $startDate, $endDate),
            'vendors' => $this->getVendors($filters, $startDate, $endDate),
            'items' => $this->getItems($filters, $startDate, $endDate),
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
    | Angka yang dibaca lebih dulu. Yang paling berarti bukan jumlah returnnya,
    | melainkan BAGIANNYA dari yang diterima: sepuluh return sebulan berarti
    | lain pada seratus penerimaan dan pada lima penerimaan.
    |--------------------------------------------------------------------------
    */
    private function getSummary(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $total = $this->valueQuery($filters, $startDate, $endDate)->first();

        $posted = $this->valueQuery($filters, $startDate, $endDate)
            ->where('goods_returns.status', GoodsReturn::STATUS_POSTED)
            ->first();

        $draft = $this->valueQuery($filters, $startDate, $endDate)
            ->where('goods_returns.status', GoodsReturn::STATUS_DRAFT)
            ->first();

        /*
        | Yang dibatalkan dihitung terpisah dan TIDAK masuk hitungan barang
        | yang dikembalikan -- qty-nya sudah kembali ke PO.
        */
        $cancelled = $this->valueQuery($filters, $startDate, $endDate, true)
            ->where('goods_returns.status', GoodsReturn::STATUS_CANCELLED)
            ->first();

        $received = $this->getReceivedSummary($filters, $startDate, $endDate);

        $nilaiReturn = (float) ($posted->nilai ?? 0);
        $nilaiTerima = $received['amount'];

        $qtyReturn = (float) ($posted->qty ?? 0);
        $qtyTerima = $received['qty'];

        return [
            'total' => $this->triple($total),
            'posted' => $this->triple($posted),
            'draft' => $this->triple($draft),
            'cancelled' => $this->triple($cancelled),
            'received' => $received,

            /*
            | Dua rasio, karena keduanya bisa berbeda jauh: barang murah yang
            | sering dikembalikan menaikkan rasio qty tanpa menaikkan rasio
            | nilai, dan satu barang mahal melakukan sebaliknya.
            |
            | Satu desimal, bukan dibulatkan penuh: angka kecil pun berarti,
            | karena tiap return adalah barang yang gagal dipakai.
            */
            'return_rate_amount_percent' => $nilaiTerima > 0
                ? round(($nilaiReturn / $nilaiTerima) * 100, 1)
                : 0.0,

            'return_rate_qty_percent' => $qtyTerima > 0
                ? round(($qtyReturn / $qtyTerima) * 100, 1)
                : 0.0,

            'average_detect_days' => $this->getAverageDetectDays($filters, $startDate, $endDate),

            /*
            | Berapa baris barang yang tersangkut, bukan berapa dokumen. Satu
            | dokumen bisa memuat banyak barang, dan yang bermasalah adalah
            | barangnya.
            */
            'item_count' => (int) ($posted->baris ?? 0),
        ];
    }

    /**
     * Barang yang diterima pada periode yang sama, sebagai pembanding.
     *
     * Tanpa ini jumlah return tidak punya ukuran: sepuluh return berarti lain
     * pada seratus penerimaan dan pada lima penerimaan.
     *
     * @return array{count: int, amount: float, qty: float}
     */
    private function getReceivedSummary(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $row = DB::table('goods_receives')
            ->whereNull('goods_receives.deleted_at')
            ->where('goods_receives.status', GoodsReceive::STATUS_POSTED)
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
            )
            ->leftJoin('goods_receive_items AS gri', 'gri.goods_receive_id', '=', 'goods_receives.id')
            ->leftJoin('purchase_order_items AS poi', 'poi.id', '=', 'gri.purchase_order_item_id')
            ->selectRaw('COUNT(DISTINCT goods_receives.id) AS jumlah')
            ->selectRaw('COALESCE(SUM(gri.qty_receive * poi.harga_unit), 0) AS nilai')
            ->selectRaw('COALESCE(SUM(gri.qty_receive), 0) AS qty')
            ->first();

        return [
            'count' => (int) ($row->jumlah ?? 0),
            'amount' => (float) ($row->nilai ?? 0),
            'qty' => (float) ($row->qty ?? 0),
        ];
    }

    /**
     * Rata-rata jarak hari dari barang diterima sampai dikembalikan.
     *
     * Jawaban atas "cacatnya ketahuan setelah berapa lama". Angka yang besar
     * berarti barang sempat lama tersimpan sebelum diperiksa -- dan semakin
     * lama, semakin sulit klaimnya ke vendor.
     *
     * Hanya return yang sudah diposting yang dihitung; draft belum tentu jadi.
     */
    private function getAverageDetectDays(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): ?float {
        $average = $this->returnBaseQuery($filters, $startDate, $endDate)
            ->where('goods_returns.status', GoodsReturn::STATUS_POSTED)
            ->join('goods_receives AS gr', 'gr.id', '=', 'goods_returns.goods_receive_id')
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (goods_returns.tanggal_return::timestamp - gr.tanggal_gr::timestamp)) / 86400) AS rata',
            )
            ->value('rata');

        return $average === null ? null : round((float) $average, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Sebaran status
    |--------------------------------------------------------------------------
    | Ketiganya ditampilkan, termasuk yang dibatalkan -- manajemen perlu
    | melihat berapa return yang gugur, bukan hanya yang jadi.
    |--------------------------------------------------------------------------
    */
    private function getStatusBreakdown(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $rows = $this->valueQuery($filters, $startDate, $endDate, true)
            ->addSelect('goods_returns.status')
            ->groupBy('goods_returns.status')
            ->get()
            ->keyBy(fn ($row): string => strtoupper(trim((string) $row->status)));

        $urutan = [
            GoodsReturn::STATUS_DRAFT,
            GoodsReturn::STATUS_POSTED,
            GoodsReturn::STATUS_CANCELLED,
        ];

        return collect($urutan)
            ->map(fn (string $status): array => [
                'status' => $status,
                'count' => (int) ($rows[$status]->jumlah ?? 0),
                'amount' => (float) ($rows[$status]->nilai ?? 0),
                'qty' => (float) ($rows[$status]->qty ?? 0),
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Tren
    |--------------------------------------------------------------------------
    | Kerapatan titik menyesuaikan panjang periode, sama seperti dashboard
    | sebelumnya, supaya grafik rentang panjang tetap terbaca.
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

        $rows = $this->valueQuery($filters, $startDate, $endDate)
            ->selectRaw("DATE_TRUNC('{$granularity}', goods_returns.tanggal_return::timestamp) AS titik")
            ->groupByRaw("DATE_TRUNC('{$granularity}', goods_returns.tanggal_return::timestamp)")
            ->orderByRaw("DATE_TRUNC('{$granularity}', goods_returns.tanggal_return::timestamp)")
            ->get()
            ->keyBy(fn ($row): string => CarbonImmutable::parse($row->titik)->toDateString());

        $points = [];

        $cursor = match ($granularity) {
            'day' => $startDate->startOfDay(),
            'week' => $startDate->startOfWeek(),
            default => $startDate->startOfMonth(),
        };

        while ($cursor <= $endDate) {
            $kunci = $cursor->toDateString();
            $row = $rows[$kunci] ?? null;

            $points[] = [
                'date' => $kunci,
                'count' => (int) ($row->jumlah ?? 0),
                'amount' => (float) ($row->nilai ?? 0),
                'qty' => (float) ($row->qty ?? 0),
            ];

            $cursor = match ($granularity) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonth(),
            };
        }

        return [
            'granularity' => $granularity,
            'points' => $points,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Alasan pengembalian
    |--------------------------------------------------------------------------
    | Dimensi terpenting pada modul ini: jumlah return memberi tahu SEBERAPA
    | BANYAK, alasannya memberi tahu APA YANG HARUS DIPERBAIKI.
    |
    | Baris tanpa alasan tetap ditampilkan sebagai "Tidak diisi", bukan
    | dibuang -- justru itu yang perlu dibereskan pengisiannya.
    |--------------------------------------------------------------------------
    */
    private function getReasons(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $rows = $this->itemQuery($filters, $startDate, $endDate)
            ->leftJoin('goods_return_reasons AS grr', 'grr.id', '=', 'gri.reason_id')
            ->groupByRaw("COALESCE(NULLIF(TRIM(grr.name), ''), '-')")
            ->selectRaw("COALESCE(NULLIF(TRIM(grr.name), ''), '-') AS alasan")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COALESCE(SUM(gri.qty_return), 0) AS qty')
            ->selectRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) AS nilai')
            ->orderByRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) DESC, COUNT(*) DESC')
            ->get();

        $totalNilai = (float) $rows->sum('nilai');

        return $rows
            ->map(fn ($row): array => [
                'name' => (string) $row->alasan,
                'count' => (int) $row->jumlah,
                'qty' => (float) $row->qty,
                'amount' => (float) $row->nilai,

                'share_percent' => $totalNilai > 0
                    ? round(((float) $row->nilai / $totalNilai) * 100, 1)
                    : 0.0,
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Vendor
    |--------------------------------------------------------------------------
    | Bukan sekadar siapa yang paling banyak dikembalikan: nilai kirimnya ikut
    | dibawa supaya rasionya terbaca. Vendor besar wajar punya angka return
    | lebih besar; yang perlu dilihat adalah BAGIANNYA.
    |--------------------------------------------------------------------------
    */
    private function getVendors(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $rows = $this->itemQuery($filters, $startDate, $endDate)
            ->leftJoin('master_vendor AS mv', 'mv.id', '=', 'goods_returns.vendor_id')
            ->groupBy('goods_returns.vendor_id', 'mv.nama_vendor')
            ->selectRaw('goods_returns.vendor_id')
            ->selectRaw("COALESCE(NULLIF(TRIM(mv.nama_vendor), ''), '-') AS nama_vendor")
            ->selectRaw('COUNT(DISTINCT goods_returns.id) AS jumlah')
            ->selectRaw('COALESCE(SUM(gri.qty_return), 0) AS qty')
            ->selectRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) AS nilai')
            ->orderByRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) DESC')
            ->limit(self::RANK_LIMIT)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $nilaiKirim = $this->getVendorReceivedAmounts(
            $filters,
            $startDate,
            $endDate,
            $rows->pluck('vendor_id')->filter()->all(),
        );

        return $rows
            ->map(function ($row) use ($nilaiKirim): array {
                $kirim = (float) ($nilaiKirim[(int) $row->vendor_id] ?? 0);
                $balik = (float) $row->nilai;

                return [
                    'vendor_id' => $row->vendor_id === null ? null : (int) $row->vendor_id,
                    'name' => (string) $row->nama_vendor,
                    'count' => (int) $row->jumlah,
                    'qty' => (float) $row->qty,
                    'amount' => $balik,
                    'received_amount' => $kirim,

                    'return_rate_percent' => $kirim > 0
                        ? round(($balik / $kirim) * 100, 1)
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Nilai barang yang dikirim tiap vendor pada periode yang sama.
     *
     * @param  int[]  $vendorIds
     * @return array<int, float>
     */
    private function getVendorReceivedAmounts(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        array $vendorIds,
    ): array {
        if ($vendorIds === []) {
            return [];
        }

        return DB::table('goods_receives')
            ->whereNull('goods_receives.deleted_at')
            ->where('goods_receives.status', GoodsReceive::STATUS_POSTED)
            ->whereIn('goods_receives.vendor_id', $vendorIds)
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
            ->join('goods_receive_items AS gri', 'gri.goods_receive_id', '=', 'goods_receives.id')
            ->leftJoin('purchase_order_items AS poi', 'poi.id', '=', 'gri.purchase_order_item_id')
            ->groupBy('goods_receives.vendor_id')
            ->selectRaw('goods_receives.vendor_id')
            ->selectRaw('COALESCE(SUM(gri.qty_receive * poi.harga_unit), 0) AS nilai')
            ->pluck('nilai', 'vendor_id')
            ->map(fn ($nilai): float => (float) $nilai)
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Barang yang paling sering bermasalah
    |--------------------------------------------------------------------------
    | Dikelompokkan menurut nama barang pada dokumennya, karena itulah yang
    | disimpan baris return. Berguna untuk melihat pola yang tidak terlihat
    | per dokumen: barang yang sama dikembalikan berulang kali.
    |--------------------------------------------------------------------------
    */
    private function getItems(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        return $this->itemQuery($filters, $startDate, $endDate)
            ->groupByRaw("COALESCE(NULLIF(TRIM(gri.nama_item), ''), '-')")
            ->selectRaw("COALESCE(NULLIF(TRIM(gri.nama_item), ''), '-') AS nama_item")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw('COUNT(DISTINCT goods_returns.id) AS jumlah_dokumen')
            ->selectRaw('COALESCE(SUM(gri.qty_return), 0) AS qty')
            ->selectRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) AS nilai')
            ->orderByRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) DESC, COUNT(*) DESC')
            ->limit(self::RANK_LIMIT)
            ->get()
            ->map(fn ($row): array => [
                'name' => (string) $row->nama_item,
                'count' => (int) $row->jumlah,
                'document_count' => (int) $row->jumlah_dokumen,
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
    | Sengaja TIDAK dibatasi periode. Draft yang menggantung sejak bulan lalu
    | justru yang paling perlu terlihat, dan akan hilang bila ikut disaring
    | tanggalnya.
    |
    | Draft return berarti barangnya sudah ditolak tetapi dokumennya belum
    | jadi: stoknya belum berkurang dan klaimnya ke vendor belum berjalan.
    |--------------------------------------------------------------------------
    */
    private function getAttentionItems(array $filters): array
    {
        $rows = DB::table('goods_returns')
            ->whereNull('goods_returns.deleted_at')
            ->where('goods_returns.status', GoodsReturn::STATUS_DRAFT)
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
            )
            ->leftJoin('goods_return_items AS gri', 'gri.goods_return_id', '=', 'goods_returns.id')
            ->leftJoin('purchase_order_items AS poi', 'poi.id', '=', 'gri.purchase_order_item_id')
            ->leftJoin('cabang', 'cabang.id', '=', 'goods_returns.cabang')
            ->leftJoin('departments', 'departments.id', '=', 'goods_returns.id_department')
            ->leftJoin('master_vendor AS mv', 'mv.id', '=', 'goods_returns.vendor_id')
            ->groupBy(
                'goods_returns.id',
                'goods_returns.nomor_return',
                'goods_returns.tanggal_return',
                'goods_returns.created_at',
                'cabang.nama_cabang',
                'departments.nama',
                'mv.nama_vendor',
            )
            ->selectRaw('goods_returns.id')
            ->selectRaw('goods_returns.nomor_return')
            ->selectRaw('goods_returns.tanggal_return')
            ->selectRaw("COALESCE(cabang.nama_cabang, '-') AS nama_cabang")
            ->selectRaw("COALESCE(departments.nama, '-') AS nama_department")
            ->selectRaw("COALESCE(NULLIF(TRIM(mv.nama_vendor), ''), '-') AS nama_vendor")
            ->selectRaw('COALESCE(SUM(gri.qty_return), 0) AS qty')
            ->selectRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) AS nilai')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - goods_returns.created_at)) / 86400 AS lama_hari',
            )
            ->orderBy('goods_returns.created_at')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return [
            'threshold_days' => self::AGING_THRESHOLD_DAYS,

            'draft_returns' => $rows
                ->map(fn ($row): array => [
                    'id' => (int) $row->id,
                    'number' => (string) $row->nomor_return,
                    'date' => $row->tanggal_return,
                    'cabang_name' => (string) $row->nama_cabang,
                    'department_name' => (string) $row->nama_department,
                    'vendor_name' => (string) $row->nama_vendor,
                    'qty' => (float) $row->qty,
                    'amount' => (float) $row->nilai,
                    'age_days' => (int) floor((float) $row->lama_hari),
                ])
                ->values()
                ->all(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Sebaran cabang dan department
    |--------------------------------------------------------------------------
    */
    private function getBreakdown(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        string $dimension,
    ): array {
        $query = $this->valueQuery($filters, $startDate, $endDate, true);

        if ($dimension === 'cabang') {
            $query
                ->leftJoin('cabang', 'cabang.id', '=', 'goods_returns.cabang')
                ->selectRaw('cabang.id AS dimensi_id')
                ->selectRaw("COALESCE(cabang.nama_cabang, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('cabang.id', 'cabang.nama_cabang');
        } else {
            $query
                ->leftJoin('departments', 'departments.id', '=', 'goods_returns.id_department')
                ->selectRaw('departments.id AS dimensi_id')
                ->selectRaw("COALESCE(departments.nama, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('departments.id', 'departments.nama');
        }

        return $query
            ->selectRaw(
                'COUNT(DISTINCT goods_returns.id) FILTER (WHERE goods_returns.status = ?) AS diposting',
                [GoodsReturn::STATUS_POSTED],
            )
            ->selectRaw(
                'COUNT(DISTINCT goods_returns.id) FILTER (WHERE goods_returns.status = ?) AS draf',
                [GoodsReturn::STATUS_DRAFT],
            )
            ->orderByRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) DESC')
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->dimensi_id === null ? null : (int) $row->dimensi_id,
                'name' => (string) $row->dimensi_nama,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->nilai,
                'qty' => (float) $row->qty,
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
    | Mengikuti pola tiga dashboard sebelumnya: scope permission menentukan
    | filter mana yang boleh dipilih user dan mana yang dipaksakan sistem.
    |--------------------------------------------------------------------------
    */
    public function resolveAccessAndFilters(User $user, array $filters): array
    {
        $scope = $user->getPermissionScope('dashboard.goods-return.view');

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

    /**
     * Dokumen return pada periode itu.
     *
     * Yang dibatalkan dikecualikan kecuali diminta: qty-nya sudah kembali ke
     * PO, jadi menghitungnya berarti menghitung barang yang tetap dipakai.
     */
    private function returnBaseQuery(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        bool $sertakanBatal = false,
    ): QueryBuilder {
        return DB::table('goods_returns')
            ->whereNull('goods_returns.deleted_at')
            ->when(
                !$sertakanBatal,
                fn ($query) => $query->where('goods_returns.status', '!=', GoodsReturn::STATUS_CANCELLED),
            )
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
     * Dokumen return beserta jumlah, qty, dan nilainya.
     *
     * Harganya diambil dari baris PO karena dokumen return hanya mencatat
     * kuantitas. COUNT memakai DISTINCT id dokumen supaya satu return berisi
     * lima barang tetap terhitung satu dokumen, bukan lima.
     */
    private function valueQuery(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        bool $sertakanBatal = false,
    ): QueryBuilder {
        return $this->returnBaseQuery($filters, $startDate, $endDate, $sertakanBatal)
            ->leftJoin('goods_return_items AS gri', 'gri.goods_return_id', '=', 'goods_returns.id')
            ->leftJoin('purchase_order_items AS poi', 'poi.id', '=', 'gri.purchase_order_item_id')
            ->selectRaw('COUNT(DISTINCT goods_returns.id) AS jumlah')
            ->selectRaw('COUNT(gri.id) AS baris')
            ->selectRaw('COALESCE(SUM(gri.qty_return), 0) AS qty')
            ->selectRaw('COALESCE(SUM(gri.qty_return * poi.harga_unit), 0) AS nilai');
    }

    /**
     * Baris barang yang dikembalikan, untuk peringkat alasan, vendor, dan item.
     *
     * Berbeda dari valueQuery: di sini yang dihitung barisnya, bukan
     * dokumennya, karena satu dokumen bisa memuat banyak barang dengan alasan
     * yang berbeda-beda.
     */
    private function itemQuery(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): QueryBuilder {
        return $this->returnBaseQuery($filters, $startDate, $endDate)
            ->where('goods_returns.status', GoodsReturn::STATUS_POSTED)
            ->join('goods_return_items AS gri', 'gri.goods_return_id', '=', 'goods_returns.id')
            ->leftJoin('purchase_order_items AS poi', 'poi.id', '=', 'gri.purchase_order_item_id');
    }

    /**
     * @return array{count: int, amount: float, qty: float}
     */
    private function triple(?object $row): array
    {
        return [
            'count' => (int) ($row->jumlah ?? 0),
            'amount' => (float) ($row->nilai ?? 0),
            'qty' => (float) ($row->qty ?? 0),
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
