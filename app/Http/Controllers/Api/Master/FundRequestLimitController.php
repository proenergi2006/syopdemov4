<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\FundRequestLimit;
use App\Services\FundRequest\FundRequestLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Master batas pengajuan FPU
|--------------------------------------------------------------------------
| Dua angka per baris: berapa FPU boleh berjalan bersamaan, dan berapa lama
| sebuah FPU boleh menunggu direalisasi.
|
| Cakupan ganda (area + department) membuat dua batas bisa sama-sama cocok
| pada satu pemohon. Yang paling khusus menang; aturannya ada di
| FundRequestLimitService agar layar dan penjagaan tidak berbeda pendapat.
|--------------------------------------------------------------------------
*/
class FundRequestLimitController extends Controller
{
    private const PERMISSION_PREFIX = 'master_fund_request_limit';

    /**
     * Batas atas yang masuk akal.
     *
     * Bukan aturan bisnis, melainkan penjaga salah ketik: 999 FPU berjalan
     * atau 3650 hari menunggu realisasi sama saja dengan tidak ada batas, dan
     * itu lebih baik dinyatakan dengan menonaktifkan barisnya.
     */
    private const MAX_OUTSTANDING = 50;

    private const MAX_DAYS = 365;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.view')) {
            return response()->json([
                'success' => false,
                'message' => __('fund_request_limit_messages.index.forbidden'),
            ], 403);
        }

        $batas = FundRequestLimit::query()
            ->with(['branches:id,nama_cabang', 'departments:id,nama'])
            ->withCount(['branches', 'departments'])

            /*
            | Yang paling khusus di atas, batas umum di bawah. Urutannya
            | mengikuti bobot yang sama dengan pencocokan -- daftar yang
            | tersusun berbeda dari urutan kemenangannya membuat orang
            | menyimpulkan aturan yang salah hanya dari melihat tabelnya.
            */
            ->orderByDesc('departments_count')
            ->orderByDesc('branches_count')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $batas->map(fn (FundRequestLimit $b): array => $this->transform($b))->all(),

            'abilities' => [
                'can_view' => true,
                'can_create' => $user->hasPermission(self::PERMISSION_PREFIX . '.create'),
                'can_update' => $user->hasPermission(self::PERMISSION_PREFIX . '.update'),
                'can_delete' => $user->hasPermission(self::PERMISSION_PREFIX . '.delete'),
            ],

            'options' => [
                /*
                | Seluruh cabang yang masih aktif, bukan lagi dua pilihan
                | "HO" dan "Cabang". Yang nonaktif tidak ditawarkan: memilih
                | cabang yang sudah tutup hanya menghasilkan batas yang tidak
                | pernah cocok dengan dokumen mana pun.
                */
                'branches' => DB::table('cabang')
                    ->where('is_active', true)
                    ->orderBy('nama_cabang')
                    ->get(['id', 'nama_cabang as name'])
                    ->all(),

                'departments' => DB::table('departments')
                    ->where('is_active', true)
                    ->orderBy('nama')
                    ->get(['id', 'nama as name'])
                    ->all(),

                'max_outstanding' => self::MAX_OUTSTANDING,
                'max_days' => self::MAX_DAYS,
            ],
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->simpan($request, null);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->simpan($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.delete')) {
            return response()->json([
                'success' => false,
                'message' => __('fund_request_limit_messages.destroy.forbidden'),
            ], 403);
        }

        $batas = FundRequestLimit::find($id);

        if (!$batas) {
            return response()->json([
                'success' => false,
                'message' => __('fund_request_limit_messages.not_found'),
            ], 404);
        }

        /*
        | Batas umum adalah jaring pengaman: tanpa itu, pemohon yang tidak
        | cocok ke batas khusus mana pun tidak terbatasi sama sekali -- dan
        | seluruh aturan ini kehilangan gunanya.
        */
        if ($batas->is_fallback) {
            $lain = FundRequestLimit::query()
                ->whereDoesntHave('branches')
                ->whereDoesntHave('departments')
                ->where('id', '!=', $batas->id)
                ->exists();

            if (!$lain) {
                return response()->json([
                    'success' => false,
                    'message' => __('fund_request_limit_messages.destroy.last_fallback'),
                ], 422);
            }
        }

        $batas->delete();

        return response()->json([
            'success' => true,
            'message' => __('fund_request_limit_messages.destroy.success'),
        ], 200);
    }

    /**
     * Contoh penerapan: batas mana yang akan dipakai untuk sebuah keadaan.
     *
     * Cakupan bertingkat mudah disalahpahami saat diatur. Contoh ini membuat
     * akibatnya terlihat sebelum ada pemohon sungguhan yang terlanjur
     * terblokir olehnya.
     */
    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.view')) {
            return response()->json([
                'success' => false,
                'message' => __('fund_request_limit_messages.index.forbidden'),
            ], 403);
        }

        $validated = $request->validate([
            'department_id' => ['nullable', 'integer'],
        ]);

        $service = app(FundRequestLimitService::class);

        $departmentId = isset($validated['department_id'])
            ? (int) $validated['department_id']
            : null;

        /*
        | Satu baris per cabang aktif. Dahulu dua baris saja (HO dan Cabang);
        | sejak cakupannya dipilih per cabang, dua baris tidak lagi
        | menggambarkan apa pun -- justru perbedaan ANTAR-CABANG yang perlu
        | terlihat sebelum batasnya disimpan.
        */
        $hasil = [];

        $cabang = DB::table('cabang')
            ->where('is_active', true)
            ->orderBy('nama_cabang')
            ->get(['id', 'nama_cabang']);

        foreach ($cabang as $satu) {
            $batas = $service->resolve((int) $satu->id, $departmentId);

            $hasil[] = [
                'branch_id' => (int) $satu->id,
                'branch_name' => (string) $satu->nama_cabang,
                'limit_name' => $batas?->name,
                'max_outstanding' => $batas?->max_outstanding,
                'max_realization_days' => $batas?->max_realization_days,
                'is_unlimited' => $batas ? (bool) $batas->is_unlimited : false,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => ['rows' => $hasil],
        ], 200);
    }

    private function simpan(Request $request, ?int $id): JsonResponse
    {
        $user = $request->user();
        $aksi = $id === null ? 'create' : 'update';

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.' . $aksi)) {
            return response()->json([
                'success' => false,
                'message' => __("fund_request_limit_messages.{$aksi}.forbidden"),
            ], 403);
        }

        $batas = $id === null ? null : FundRequestLimit::find($id);

        if ($id !== null && !$batas) {
            return response()->json([
                'success' => false,
                'message' => __('fund_request_limit_messages.not_found'),
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],

            /*
            | Keduanya BOLEH KOSONG, dan kosong di sini berarti "berlaku untuk
            | semua" -- bukan "tidak berlaku untuk siapa pun". Itulah yang
            | membuat batas umum menjadi batas umum.
            */
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['integer', 'exists:cabang,id'],

            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],

            /*
            | Minimal satu: batas nol berarti tidak ada yang boleh mengajukan
            | sama sekali, dan itu lebih jujur dinyatakan dengan menonaktifkan
            | barisnya daripada lewat angka yang mudah terketik tanpa sengaja.
            */
            'max_outstanding' => ['required', 'integer', 'min:1', 'max:' . self::MAX_OUTSTANDING],
            'max_realization_days' => ['required', 'integer', 'min:1', 'max:' . self::MAX_DAYS],

            /*
            | Batas yang tidak membatasi apa pun.
            |
            | Angka di atas tetap wajib diisi walau penanda ini menyala:
            | mematikannya kembali harus mengembalikan batas yang masuk akal,
            | bukan kolom kosong yang harus ditebak isinya.
            */
            'is_unlimited' => ['sometimes', 'boolean'],

            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $idCabang = $this->daftarId($validated['branch_ids'] ?? []);
        $idDepartment = $this->daftarId($validated['department_ids'] ?? []);

        /*
        | Cakupan kembar dicari dengan MEMBANDINGKAN HIMPUNAN, bukan kolom.
        | Dua batas yang menyebut cabang yang sama dalam urutan berbeda adalah
        | cakupan yang sama; yang membandingkan teks apa adanya akan
        | meloloskannya, dan sejak itu mana yang menang ditentukan oleh id --
        | yaitu kebetulan.
        */
        $sidikJari = $this->sidikCakupan($idCabang, $idDepartment);

        $kembar = FundRequestLimit::query()
            ->with(['branches:id', 'departments:id'])
            ->when($id !== null, fn ($q) => $q->where('id', '!=', $id))
            ->get()
            ->contains(fn (FundRequestLimit $lain): bool => $this->sidikCakupan(
                $lain->branches->pluck('id')->all(),
                $lain->departments->pluck('id')->all(),
            ) === $sidikJari);

        if ($kembar) {
            return response()->json([
                'success' => false,
                'message' => __('fund_request_limit_messages.duplicate_scope'),
            ], 422);
        }

        try {
            $hasil = DB::transaction(function () use ($validated, $batas, $user, $idCabang, $idDepartment): FundRequestLimit {
                $isi = [
                    'name' => trim($validated['name']),
                    'max_outstanding' => (int) $validated['max_outstanding'],
                    'max_realization_days' => (int) $validated['max_realization_days'],
                    'is_unlimited' => (bool) ($validated['is_unlimited'] ?? false),
                    'is_active' => (bool) $validated['is_active'],
                    'notes' => $validated['notes'] ?? null,
                    'updated_by' => $user->id,
                ];

                if ($batas) {
                    $batas->update($isi);
                } else {
                    $isi['created_by'] = $user->id;

                    $batas = FundRequestLimit::create($isi);
                }

                /*
                | sync() DI DALAM transaksi yang sama dengan barisnya.
                | Terpisah, sebuah kegagalan di antara keduanya meninggalkan
                | batas dengan cakupan separuh -- dan cakupan separuh adalah
                | cakupan yang salah, bukan cakupan yang kurang.
                */
                $batas->branches()->sync($idCabang);
                $batas->departments()->sync($idDepartment);

                return $batas;
            });
        } catch (\Throwable $e) {
            Log::error('[Batas FPU] Simpan gagal', [
                'fund_request_limit_id' => $id,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __("fund_request_limit_messages.{$aksi}.failed"),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => __("fund_request_limit_messages.{$aksi}.success"),
            'data' => $this->transform(
                $hasil->fresh()->loadCount(['branches', 'departments'])
                    ->load(['branches:id,nama_cabang', 'departments:id,nama']),
            ),
        ], $id === null ? 201 : 200);
    }

    private function transform(FundRequestLimit $batas): array
    {
        return [
            'id' => $batas->id,
            'name' => $batas->name,

            'branches' => $batas->branches
                ->map(fn ($c): array => ['id' => (int) $c->id, 'name' => (string) $c->nama_cabang])
                ->values()
                ->all(),

            'departments' => $batas->departments
                ->map(fn ($d): array => ['id' => (int) $d->id, 'name' => (string) $d->nama])
                ->values()
                ->all(),

            'max_outstanding' => $batas->max_outstanding,
            'max_realization_days' => $batas->max_realization_days,
            'is_unlimited' => (bool) $batas->is_unlimited,

            'is_active' => (bool) $batas->is_active,
            'notes' => $batas->notes,

            /* Batas umum tidak boleh dihapus, dan layar perlu tahu itu. */
            'is_fallback' => $batas->is_fallback,
        ];
    }

    /**
     * Daftar id yang bersih: bilangan bulat positif, tanpa kembar, terurut.
     *
     * @param  array<int, mixed>  $nilai
     * @return array<int, int>
     */
    private function daftarId(array $nilai): array
    {
        return collect($nilai)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Sidik jari sebuah cakupan, untuk membandingkan dua batas.
     *
     * @param  array<int, mixed>  $cabang
     * @param  array<int, mixed>  $department
     */
    private function sidikCakupan(array $cabang, array $department): string
    {
        return 'c:' . implode(',', $this->daftarId($cabang))
            . '|d:' . implode(',', $this->daftarId($department));
    }
}
