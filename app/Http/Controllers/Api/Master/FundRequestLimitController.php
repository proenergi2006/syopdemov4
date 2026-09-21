<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\FundRequestLimit;
use App\Services\FundRequest\FundRequestLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
            ->with('department:id,nama')
            ->orderByRaw('CASE WHEN department_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN area_type IS NOT NULL THEN 0 ELSE 1 END')
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
                'area_types' => collect(FundRequestLimit::areaTypeOptions())
                    ->map(fn (string $label, string $value): array => [
                        'value' => $value,
                        'label' => $label,
                    ])
                    ->values()
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
                ->whereNull('area_type')
                ->whereNull('department_id')
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
            'area_type' => ['nullable', Rule::in(FundRequestLimit::AREA_TYPES)],
            'department_id' => ['nullable', 'integer'],
        ]);

        $service = app(FundRequestLimitService::class);
        $hasil = [];

        foreach (FundRequestLimit::AREA_TYPES as $area) {
            $batas = $service->resolve(
                $area,
                isset($validated['department_id']) ? (int) $validated['department_id'] : null,
            );

            $hasil[] = [
                'area_type' => $area,
                'area_label' => FundRequestLimit::areaTypeLabel($area),
                'limit_name' => $batas?->name,
                'max_outstanding' => $batas?->max_outstanding,
                'max_realization_days' => $batas?->max_realization_days,
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
            'area_type' => ['nullable', Rule::in(FundRequestLimit::AREA_TYPES)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],

            /*
            | Minimal satu: batas nol berarti tidak ada yang boleh mengajukan
            | sama sekali, dan itu lebih jujur dinyatakan dengan menonaktifkan
            | barisnya daripada lewat angka yang mudah terketik tanpa sengaja.
            */
            'max_outstanding' => ['required', 'integer', 'min:1', 'max:' . self::MAX_OUTSTANDING],
            'max_realization_days' => ['required', 'integer', 'min:1', 'max:' . self::MAX_DAYS],

            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $kembar = FundRequestLimit::query()
            ->where('area_type', $validated['area_type'] ?? null)
            ->where('department_id', $validated['department_id'] ?? null)
            ->when($id !== null, fn ($q) => $q->where('id', '!=', $id))
            ->exists();

        if ($kembar) {
            return response()->json([
                'success' => false,
                'message' => __('fund_request_limit_messages.duplicate_scope'),
            ], 422);
        }

        try {
            $hasil = DB::transaction(function () use ($validated, $batas, $user): FundRequestLimit {
                $isi = [
                    'name' => trim($validated['name']),
                    'area_type' => $validated['area_type'] ?? null,
                    'department_id' => $validated['department_id'] ?? null,
                    'max_outstanding' => (int) $validated['max_outstanding'],
                    'max_realization_days' => (int) $validated['max_realization_days'],
                    'is_active' => (bool) $validated['is_active'],
                    'notes' => $validated['notes'] ?? null,
                    'updated_by' => $user->id,
                ];

                if ($batas) {
                    $batas->update($isi);

                    return $batas;
                }

                $isi['created_by'] = $user->id;

                return FundRequestLimit::create($isi);
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
            'data' => $this->transform($hasil->fresh('department')),
        ], $id === null ? 201 : 200);
    }

    private function transform(FundRequestLimit $batas): array
    {
        return [
            'id' => $batas->id,
            'name' => $batas->name,

            'area_type' => $batas->area_type,
            'area_type_label' => FundRequestLimit::areaTypeLabel($batas->area_type),

            'department_id' => $batas->department_id,
            'department_name' => $batas->department?->nama,

            'max_outstanding' => $batas->max_outstanding,
            'max_realization_days' => $batas->max_realization_days,

            'is_active' => (bool) $batas->is_active,
            'notes' => $batas->notes,

            /* Batas umum tidak boleh dihapus, dan layar perlu tahu itu. */
            'is_fallback' => $batas->is_fallback,
        ];
    }
}
