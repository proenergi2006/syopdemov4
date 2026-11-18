<?php

namespace App\Services\Dashboard;

use App\Models\BusinessTrip;
use App\Models\BusinessTripArrangement;
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
| Dashboard Perjalanan Dinas
|--------------------------------------------------------------------------
| Sudut pandangnya manajemen. Penanganan scope, bentuk filter, dan susunan
| responsnya mengikuti dashboard FPU, Realisasi, dan Claim.
|
| YANG MEMBEDAKANNYA: PERDIN PUNYA TENGGAT YANG TIDAK BISA DIGESER.
|
| Tiga dashboard sebelumnya semuanya soal uang, dan uang bisa menunggu. Perdin
| tidak: ada TANGGAL BERANGKAT, dan begitu tanggal itu lewat, approval yang
| baru keluar tidak ada gunanya lagi, tiket yang baru dipesan percuma, dan
| orangnya sudah terlanjur berangkat tanpa bekal atau justru batal berangkat.
|
| Karena itu hampir setiap angka di sini diukur TERHADAP TANGGAL BERANGKAT,
| bukan terhadap umur dokumen:
|
| 1. BALAPAN DENGAN KEBERANGKATAN. Perdin yang masih menunggu approval
|    sementara tanggal berangkatnya makin dekat -- atau sudah lewat.
| 2. PEMESANAN BELUM DIURUS. Sudah disetujui, tetapi GA belum memesan tiket
|    atau penginapan apa pun, dan orangnya segera berangkat.
| 3. BEKAL BELUM DIURUS. Sudah disetujui, tetapi tidak ada FPU maupun Claim
|    yang menempel -- orangnya berangkat tanpa uang saku, atau pulang tanpa
|    pernah diganti.
|
| Satu lagi yang khas: YANG DIHITUNG ADALAH ORANG, BUKAN HANYA DOKUMEN. Satu
| perdin bisa membawa beberapa peserta, dan biaya maupun pemesanannya
| mengikuti jumlah orangnya.
|--------------------------------------------------------------------------
*/
class BusinessTripDashboardService
{
    /** Status yang dianggap "jadi": perjalanannya memang akan berlangsung. */
    private const STATUS_DISETUJUI = [
        BusinessTrip::STATUS_APPROVED,
    ];

    /** Ambang dokumen menggantung yang dianggap perlu ditindaklanjuti. */
    private const AGING_THRESHOLD_DAYS = 3;

    /*
    | Berangkat dalam berapa hari sudah dianggap mendesak.
    |
    | Dipakai untuk menandai perdin yang belum disetujui, belum dipesankan
    | tiketnya, atau belum punya bekal. Di bawah angka ini, tidak ada lagi
    | ruang untuk menunda.
    */
    private const URGENT_WITHIN_DAYS = 3;

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
                'urgent_within_days' => self::URGENT_WITHIN_DAYS,
            ],

            'summary' => $this->getSummary($filters, $startDate, $endDate),
            'readiness' => $this->getReadiness($filters),
            'statuses' => $this->getStatusBreakdown($filters, $startDate, $endDate),
            'trend' => $this->getTrend($filters, $startDate, $endDate),
            'approval_bottleneck' => $this->getApprovalBottleneck($filters),
            'attention' => $this->getAttentionItems($filters),

            'breakdown' => [
                'by_cabang' => $this->getBreakdown($filters, $startDate, $endDate, 'cabang'),
                'by_department' => $this->getBreakdown($filters, $startDate, $endDate, 'department'),
                'by_destination' => $this->getBreakdownByDestination($filters, $startDate, $endDate),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Ringkasan
    |--------------------------------------------------------------------------
    | Perdin tidak punya nilai rupiah sendiri -- uangnya ada di FPU dan Claim
    | yang menempel padanya. Jadi yang dihitung di sini JUMLAH ORANG, bukan
    | nilai: itulah ukuran besar-kecilnya sebuah perjalanan.
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
            ->selectRaw($this->sumPelancongSql() . ' AS nilai')
            ->first();

        $total = $hitung($base());

        $menunggu = $hitung(
            $base()->where('business_trips.status', BusinessTrip::STATUS_IN_PROGRESS),
        );

        $disetujui = $hitung(
            $base()->whereIn('business_trips.status', self::STATUS_DISETUJUI),
        );

        $ditolak = $hitung(
            $base()->whereIn('business_trips.status', [
                BusinessTrip::STATUS_REJECTED,
                BusinessTrip::STATUS_CANCELLED,
            ]),
        );

        $jumlahDisetujui = (int) ($disetujui->jumlah ?? 0);
        $jumlahDitolak = (int) ($ditolak->jumlah ?? 0);
        $diputuskan = $jumlahDisetujui + $jumlahDitolak;

        return [
            /*
            | "amount" di sini berisi JUMLAH ORANG, bukan rupiah. Namanya
            | dipertahankan supaya bentuk responsnya sama dengan tiga
            | dashboard lain, dan layar menuliskannya sebagai orang.
            */
            'submitted' => $this->pair($total),
            'waiting' => $this->pair($menunggu),
            'approved' => $this->pair($disetujui),
            'rejected' => $this->pair($ditolak),

            'approval_rate' => $diputuskan > 0
                ? round(($jumlahDisetujui / $diputuskan) * 100, 1)
                : 0.0,

            'average_approval_days' => $this->rataHari(
                $filters,
                $startDate,
                $endDate,
                akhir: 'business_trips.final_approved_at',
                awal: 'COALESCE(business_trips.submitted_at, business_trips.created_at)',
                wajibAda: ['business_trips.final_approved_at'],
            ),

            /*
            | Berapa hari sebelum berangkat approval-nya keluar.
            |
            | Angka kecil berarti pengajuannya selalu mepet, dan GA tidak
            | punya waktu memesan apa pun. Angka negatif berarti approval
            | sering baru keluar SESUDAH orangnya berangkat -- dan itu kabar
            | yang jauh lebih buruk daripada sekadar "lama".
            */
            'average_lead_days' => $this->rataHari(
                $filters,
                $startDate,
                $endDate,
                akhir: 'business_trips.depart_date::timestamp',
                awal: 'business_trips.final_approved_at',
                wajibAda: ['business_trips.final_approved_at', 'business_trips.depart_date'],
            ),

            /* Angka posisi, bukan angka periode. */
            'upcoming' => $this->akanBerangkat($filters),
        ];
    }

    /**
     * Jumlah orang yang ikut, dijumlahkan untuk sekelompok perdin.
     *
     * Pemohon TIDAK tercatat di tabel peserta -- yang di sana hanya rekan yang
     * ikut bersamanya. Jadi setiap perdin dihitung satu orang plus pesertanya;
     * menghitung barisnya saja akan menganggap rombongan berlima sebagai satu
     * orang.
     */
    private function sumPelancongSql(): string
    {
        return 'COALESCE(SUM(1 + (SELECT COUNT(*) FROM business_trip_participants AS p'
            . ' WHERE p.business_trip_id = business_trips.id)), 0)';
    }

    /**
     * Rata-rata hari antara dua penanda waktu, dalam periode terpilih.
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
    | Perjalanan yang sudah disetujui dan belum berangkat.
    |
    | Tidak dibatasi periode: yang berangkat minggu depan tetap perlu terlihat
    | walaupun pengajuannya dibuat bulan lalu.
    */
    private function akanBerangkat(array $filters): array
    {
        $row = $this->currentStateQuery($filters)
            ->whereIn('business_trips.status', self::STATUS_DISETUJUI)
            ->whereDate('business_trips.depart_date', '>=', CarbonImmutable::now()->toDateString())
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw($this->sumPelancongSql() . ' AS nilai')
            ->first();

        return $this->pair($row);
    }

    /*
    |--------------------------------------------------------------------------
    | Kesiapan berangkat
    |--------------------------------------------------------------------------
    | Dua hal yang harus sudah beres sebelum seseorang berangkat, dan keduanya
    | diurus pihak yang berbeda:
    |
    |   PEMESANAN -- tiket dan penginapan, diurus GA.
    |   BEKAL     -- FPU atau Claim yang menempel, diurus pemohon dan Finance.
    |
    | Keduanya dihitung HANYA untuk perjalanan yang belum berangkat. Yang sudah
    | terlanjur jalan tanpa tiket bukan lagi sesuatu yang bisa ditindaklanjuti
    | -- dan memasukkannya hanya membuat angkanya terus membengkak oleh hal
    | yang tidak bisa diperbaiki siapa pun.
    |
    | Angka posisi, tidak dibatasi periode.
    |--------------------------------------------------------------------------
    */
    private function getReadiness(array $filters): array
    {
        $tanpaPemesanan = $this->perdinAkanBerangkatQuery($filters)
            ->whereNotExists($this->pemesananAktifSubquery())
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw($this->sumPelancongSql() . ' AS nilai')
            ->first();

        $tanpaBekal = $this->tanpaDokumenKeuangan($this->perdinAkanBerangkatQuery($filters))
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw($this->sumPelancongSql() . ' AS nilai')
            ->first();

        return [
            'without_arrangement' => $this->pair($tanpaPemesanan),
            'without_funding' => $this->pair($tanpaBekal),
            'arrangement_types' => $this->sebaranJenisPemesanan($filters),
        ];
    }

    /**
     * Sebaran jenis pemesanan yang aktif untuk perjalanan yang akan datang.
     *
     * Menjawab "GA sedang mengurus apa saja" -- tiket, penginapan, transport.
     */
    private function sebaranJenisPemesanan(array $filters): array
    {
        $rows = $this->perdinAkanBerangkatQuery($filters)
            ->join(
                'business_trip_arrangements AS arr',
                'arr.business_trip_id',
                '=',
                'business_trips.id',
            )
            ->where('arr.status', BusinessTripArrangement::STATUS_ACTIVE)
            ->selectRaw("COALESCE(NULLIF(TRIM(arr.type), ''), 'LAINNYA') AS jenis")
            ->selectRaw('COUNT(*) AS jumlah')
            ->groupByRaw("COALESCE(NULLIF(TRIM(arr.type), ''), 'LAINNYA')")
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'type' => (string) $row->jenis,
                'count' => (int) $row->jumlah,
            ])
            ->values()
            ->all();
    }

    /** Ada pemesanan yang masih aktif untuk perdin ini. */
    private function pemesananAktifSubquery(): callable
    {
        return function (QueryBuilder $query): void {
            $query->select(DB::raw(1))
                ->from('business_trip_arrangements AS a')
                ->whereColumn('a.business_trip_id', 'business_trips.id')
                ->where('a.status', BusinessTripArrangement::STATUS_ACTIVE);
        };
    }

    /**
     * Tidak ada FPU maupun Claim yang menempel pada perdin ini.
     *
     * Disaring sebagai DUA "NOT EXISTS" terpisah, bukan satu subquery
     * ber-UNION. Keduanya setara -- "tidak ada A dan tidak ada B" sama dengan
     * "tidak ada (A atau B)" -- tetapi yang ini querinya sederhana, bisa
     * memakai index masing-masing tabel, dan tidak bergantung pada perilaku
     * UNION di dalam subquery berkorelasi.
     *
     * Yang sudah dibatalkan atau ditolak tidak dihitung: dokumen yang gugur
     * tidak membekali siapa pun.
     */
    private function tanpaDokumenKeuangan(Builder $query): Builder
    {
        return $query
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->select(DB::raw(1))
                    ->from('cash_advances AS ca')
                    ->whereColumn('ca.business_trip_id', 'business_trips.id')
                    ->whereNull('ca.deleted_at')
                    ->whereNotIn('ca.status', ['REJECTED', 'CANCELLED']);
            })
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->select(DB::raw(1))
                    ->from('claims AS cl')
                    ->whereColumn('cl.business_trip_id', 'business_trips.id')
                    ->whereNull('cl.deleted_at')
                    ->whereNotIn('cl.status', ['REJECTED', 'CANCELLED']);
            });
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
            ->selectRaw('business_trips.status')
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw($this->sumPelancongSql() . ' AS nilai')
            ->groupBy('business_trips.status')
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
    | Dikelompokkan menurut TANGGAL BERANGKAT, bukan tanggal pengajuan.
    |
    | Yang ingin dilihat manajemen adalah kapan orang-orangnya sedang di luar
    | kantor -- itu yang menentukan beban kerja yang ditinggalkan dan biaya
    | yang akan muncul. Tanggal pengajuannya sendiri tidak menyatakan apa pun
    | tentang itu.
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
            'day' => "TO_CHAR(business_trips.depart_date, 'YYYY-MM-DD')",
            'week' => "TO_CHAR(business_trips.depart_date, 'IYYY-\"W\"IW')",
            default => "TO_CHAR(business_trips.depart_date, 'YYYY-MM')",
        };

        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->whereNotNull('business_trips.depart_date')
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw($this->sumPelancongSql() . ' AS pelancong')
            ->selectRaw(
                'SUM(CASE WHEN business_trips.status = ? THEN 1 ELSE 0 END) AS disetujui',
                [BusinessTrip::STATUS_APPROVED],
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
                    'traveler_count' => (int) $row->pelancong,
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
            ->selectRaw($this->sumPelancongSql() . ' AS pelancong')
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (NOW() - COALESCE(business_trips.submitted_at,'
                . ' business_trips.created_at))) / 86400) AS rata_hari',
            )
            ->selectRaw(
                'MAX(EXTRACT(EPOCH FROM (NOW() - COALESCE(business_trips.submitted_at,'
                . ' business_trips.created_at))) / 86400) AS maks_hari',
            )
            ->groupBy('tahap.step_label')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'step_label' => (string) $row->tahap,
                'step_order' => (int) $row->urutan,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->pelancong,
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
    | Tiga daftar, dan ketiganya diurutkan menurut TANGGAL BERANGKAT -- bukan
    | menurut umur dokumen seperti tiga dashboard sebelumnya.
    |
    | Yang menentukan mendesak atau tidak bukan sudah berapa lama dokumennya
    | menggantung, melainkan berapa hari lagi orangnya berangkat.
    |--------------------------------------------------------------------------
    */
    private function getAttentionItems(array $filters): array
    {
        return [
            'threshold_days' => self::AGING_THRESHOLD_DAYS,
            'urgent_within_days' => self::URGENT_WITHIN_DAYS,
            'stuck_approval' => $this->getStuckApprovals($filters),
            'without_arrangement' => $this->getWithoutArrangement($filters),
            'without_funding' => $this->getWithoutFunding($filters),
        ];
    }

    private function getStuckApprovals(array $filters): array
    {
        $rows = $this->tahapAktifQuery($filters)
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'business_trips.branch')
            ->leftJoin('departments', 'departments.id', '=', 'business_trips.department_id')
            ->selectRaw('business_trips.id')
            ->selectRaw('business_trips.trip_number')
            ->selectRaw('business_trips.employee_name')
            ->selectRaw('business_trips.destination')
            ->selectRaw('business_trips.depart_date')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw('tahap.step_label AS tahap')
            ->selectRaw('tahap.approvers AS penyetuju')
            ->selectRaw($this->pelancongSql() . ' AS pelancong')
            ->selectRaw($this->hariKeBerangkatSql() . ' AS hari_lagi')
            ->selectRaw(
                'EXTRACT(EPOCH FROM (NOW() - COALESCE(business_trips.submitted_at,'
                . ' business_trips.created_at))) / 86400 AS lama_hari',
            )
            ->orderByRaw('business_trips.depart_date ASC NULLS LAST')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => $this->barisPerdin($row) + [
                'step_label' => (string) $row->tahap,
                'approver' => (string) $row->penyetuju,
                'waiting_days' => round((float) $row->lama_hari, 1),
            ])
            ->values()
            ->all();
    }

    private function getWithoutArrangement(array $filters): array
    {
        $rows = $this->perdinAkanBerangkatQuery($filters)
            ->whereNotExists($this->pemesananAktifSubquery())
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'business_trips.branch')
            ->leftJoin('departments', 'departments.id', '=', 'business_trips.department_id')
            ->selectRaw('business_trips.id')
            ->selectRaw('business_trips.trip_number')
            ->selectRaw('business_trips.employee_name')
            ->selectRaw('business_trips.destination')
            ->selectRaw('business_trips.depart_date')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw($this->pelancongSql() . ' AS pelancong')
            ->selectRaw($this->hariKeBerangkatSql() . ' AS hari_lagi')
            ->orderBy('business_trips.depart_date')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => $this->barisPerdin($row))
            ->values()
            ->all();
    }

    private function getWithoutFunding(array $filters): array
    {
        $rows = $this->tanpaDokumenKeuangan($this->perdinAkanBerangkatQuery($filters))
            ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'business_trips.branch')
            ->leftJoin('departments', 'departments.id', '=', 'business_trips.department_id')
            ->selectRaw('business_trips.id')
            ->selectRaw('business_trips.trip_number')
            ->selectRaw('business_trips.employee_name')
            ->selectRaw('business_trips.destination')
            ->selectRaw('business_trips.depart_date')
            ->selectRaw('cabang.nama_cabang')
            ->selectRaw('departments.nama AS nama_department')
            ->selectRaw($this->pelancongSql() . ' AS pelancong')
            ->selectRaw($this->hariKeBerangkatSql() . ' AS hari_lagi')
            ->orderBy('business_trips.depart_date')
            ->limit(self::ATTENTION_LIMIT)
            ->get();

        return $rows
            ->map(fn ($row): array => $this->barisPerdin($row))
            ->values()
            ->all();
    }

    /**
     * Bentuk baris yang sama untuk ketiga daftar.
     *
     * Ketiganya menyebut perjalanan yang sama dengan kolom yang sama; ditulis
     * tiga kali, salah satunya pasti ketinggalan saat ada kolom baru.
     *
     * @return array<string, mixed>
     */
    private function barisPerdin(object $row): array
    {
        $hariLagi = $row->hari_lagi === null ? null : (int) $row->hari_lagi;

        return [
            'id' => (int) $row->id,
            'number' => (string) $row->trip_number,
            'employee' => (string) ($row->employee_name ?: '-'),
            'destination' => (string) ($row->destination ?: '-'),
            'cabang' => $row->nama_cabang ?? '-',
            'department' => $row->nama_department ?? '-',
            'depart_date' => $row->depart_date,
            'traveler_count' => (int) $row->pelancong,
            'days_to_depart' => $hariLagi,

            /*
            | Mendesak bila sudah lewat tanggalnya, atau tinggal beberapa hari
            | lagi. Perdin tanpa tanggal berangkat tidak dianggap mendesak --
            | tidak ada tenggat untuk dilanggar.
            */
            'is_urgent' => $hariLagi !== null && $hariLagi <= self::URGENT_WITHIN_DAYS,
            'is_departed' => $hariLagi !== null && $hariLagi < 0,
        ];
    }

    /** Berapa hari lagi sampai berangkat; negatif berarti sudah lewat. */
    private function hariKeBerangkatSql(): string
    {
        return 'CASE WHEN business_trips.depart_date IS NULL THEN NULL'
            . ' ELSE (business_trips.depart_date - CURRENT_DATE) END';
    }

    /** Jumlah orang pada satu perdin: pemohon ditambah pesertanya. */
    private function pelancongSql(): string
    {
        return '1 + (SELECT COUNT(*) FROM business_trip_participants AS p'
            . ' WHERE p.business_trip_id = business_trips.id)';
    }

    /**
     * Perjalanan yang sudah disetujui dan belum berangkat.
     *
     * Rangka bersama seluruh perhitungan kesiapan. Batas "belum berangkat"
     * ditulis sekali di sini: yang sudah terlanjur jalan tanpa tiket atau
     * tanpa bekal bukan lagi sesuatu yang bisa ditindaklanjuti.
     */
    private function perdinAkanBerangkatQuery(array $filters): Builder
    {
        return $this->currentStateQuery($filters)
            ->whereIn('business_trips.status', self::STATUS_DISETUJUI)
            ->whereNotNull('business_trips.depart_date')
            ->whereDate('business_trips.depart_date', '>=', CarbonImmutable::now()->toDateString());
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
                ->leftJoin('cabang', DB::raw('CAST(cabang.id AS VARCHAR)'), '=', 'business_trips.branch')
                ->selectRaw('cabang.id AS dimensi_id')
                ->selectRaw("COALESCE(cabang.nama_cabang, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('cabang.id', 'cabang.nama_cabang');
        } else {
            $query
                ->leftJoin('departments', 'departments.id', '=', 'business_trips.department_id')
                ->selectRaw('departments.id AS dimensi_id')
                ->selectRaw("COALESCE(departments.nama, 'Tidak diketahui') AS dimensi_nama")
                ->groupBy('departments.id', 'departments.nama');
        }

        $rows = $query
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw($this->sumPelancongSql() . ' AS pelancong')
            ->selectRaw(
                'SUM(CASE WHEN business_trips.status = ? THEN 1 ELSE 0 END) AS menunggu',
                [BusinessTrip::STATUS_IN_PROGRESS],
            )
            ->selectRaw(
                'SUM(CASE WHEN business_trips.status = ? THEN 1 ELSE 0 END) AS disetujui',
                [BusinessTrip::STATUS_APPROVED],
            )
            ->orderByRaw($this->sumPelancongSql() . ' DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => $row->dimensi_id === null ? null : (int) $row->dimensi_id,
                'name' => (string) $row->dimensi_nama,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->pelancong,
                'waiting_count' => (int) $row->menunggu,
                'approved_count' => (int) $row->disetujui,
            ])
            ->values()
            ->all();
    }

    /*
    | Sebaran menurut tujuan.
    |
    | Ini yang menggantikan "keterangan transaksi" pada tiga dashboard lain --
    | dimensi yang khas Perdin, dan yang paling sering ditanyakan: ke mana saja
    | orang-orang ini pergi.
    |
    | Tujuannya diketik bebas, jadi dirapikan dulu: dipangkas spasinya dan
    | disamakan huruf besar-kecilnya. Tanpa itu "Jakarta", "jakarta", dan
    | "Jakarta " terhitung sebagai tiga tujuan berbeda.
    */
    private function getBreakdownByDestination(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array {
        $kunci = "UPPER(COALESCE(NULLIF(TRIM(business_trips.destination), ''), 'TIDAK DIISI'))";

        $rows = $this->baseQuery($filters, $startDate, $endDate)
            ->selectRaw("{$kunci} AS tujuan")
            ->selectRaw('COUNT(*) AS jumlah')
            ->selectRaw($this->sumPelancongSql() . ' AS pelancong')
            ->groupByRaw($kunci)
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'id' => null,
                'name' => (string) $row->tujuan,
                'count' => (int) $row->jumlah,
                'amount' => (float) $row->pelancong,
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Akses
    |--------------------------------------------------------------------------
    | Sama persis dengan ketiga dashboard pengajuan dana lainnya.
    |--------------------------------------------------------------------------
    */
    public function resolveAccessAndFilters(User $user, array $filters): array
    {
        $scope = $user->getPermissionScope('dashboard.business-trip.view');

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
                    'Scope permission Anda tidak mengizinkan akses ke dashboard Perjalanan Dinas.',
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

    /**
     * Pertanyaan tentang PERIODE.
     *
     * Disaring menurut TANGGAL BERANGKAT, bukan tanggal pengajuan: yang ingin
     | dilihat adalah perjalanan yang berlangsung pada periode itu. Perdin yang
     * diajukan Januari untuk berangkat Maret adalah perjalanan bulan Maret.
     *
     * Perdin tanpa tanggal berangkat jatuh kembali ke tanggal dokumennya,
     * supaya tidak lenyap dari hitungan sama sekali.
     */
    private function baseQuery(
        array $filters,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): Builder {
        $query = BusinessTrip::query()
            ->whereBetween(
                DB::raw('COALESCE(business_trips.depart_date, business_trips.date)'),
                [$startDate->toDateString(), $endDate->toDateString()],
            );

        $this->applyScope($query, $filters);

        return $query;
    }

    /** Pertanyaan tentang POSISI SAAT INI: tanpa batas periode. */
    private function currentStateQuery(array $filters): Builder
    {
        $query = BusinessTrip::query();

        $this->applyScope($query, $filters);

        return $query;
    }

    /**
     * Perdin yang sedang menunggu, beserta tahap aktifnya -- SATU BARIS PER
     * DOKUMEN.
     *
     * Approval diciutkan dulu menjadi satu baris per dokumen, baru
     * disambungkan. Satu tingkat bisa berisi beberapa baris approver (mode ANY
     * dengan dua role); menyambung langsung ke tabel approval melipatkan
     * barisnya, dan jumlahnya ikut terhitung berkali-kali.
     */
    private function tahapAktifQuery(array $filters): Builder
    {
        $tingkatTerkecil = DB::table('business_trip_approvals')
            ->select('business_trip_id')
            ->selectRaw('MIN(step_order) AS step_aktif')
            ->where('status', 'WAITING')
            ->groupBy('business_trip_id');

        $tahap = DB::table('business_trip_approvals AS bta')
            ->joinSub($tingkatTerkecil, 'm', function ($join): void {
                $join
                    ->on('m.business_trip_id', '=', 'bta.business_trip_id')
                    ->on('m.step_aktif', '=', 'bta.step_order');
            })
            ->where('bta.status', 'WAITING')
            ->groupBy('bta.business_trip_id', 'bta.step_order')
            ->select('bta.business_trip_id')
            ->selectRaw('bta.step_order AS step_order')
            ->selectRaw(
                "COALESCE(NULLIF(TRIM(MIN(bta.label)), ''), 'Tahap ' || bta.step_order) AS step_label",
            )
            ->selectRaw(
                "COALESCE(STRING_AGG(DISTINCT NULLIF(TRIM(bta.approver_name_snapshot), ''), ', '), '-')"
                . ' AS approvers',
            );

        $query = BusinessTrip::query()
            ->joinSub($tahap, 'tahap', 'tahap.business_trip_id', '=', 'business_trips.id')
            ->where('business_trips.status', BusinessTrip::STATUS_IN_PROGRESS);

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
        if (isset($filters['cabang_ids'])) {
            $daftar = array_map(fn ($id): string => (string) $id, (array) $filters['cabang_ids']);

            $daftar === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('business_trips.branch', $daftar);
        }

        if (isset($filters['department_ids'])) {
            $daftar = array_map('intval', (array) $filters['department_ids']);

            $daftar === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('business_trips.department_id', $daftar);
        }

        if (isset($filters['created_by'])) {
            $query->where('business_trips.created_by', (int) $filters['created_by']);
        }

        if (isset($filters['cabang_id'])) {
            $query->where('business_trips.branch', (string) $filters['cabang_id']);
        }

        if (isset($filters['department_id'])) {
            $query->where('business_trips.department_id', (int) $filters['department_id']);
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
