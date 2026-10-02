<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\BusinessTripExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/*
|--------------------------------------------------------------------------
| Master kategori biaya perjalanan dinas
|--------------------------------------------------------------------------
| Transportasi, Penginapan, Uang Saku -- pengelompokan rincian FPU dan
| Realisasi berketerangan perdin.
|
| Berupa master, bukan tetapan di kode, supaya urutan tampilnya dan kategori
| keempat tidak perlu menunggu rilis.
|
| TIDAK ADA PENGHAPUSAN YANG MENYANDERA DOKUMEN.
|
| Kategori yang sudah dipakai dokumen mana pun tidak bisa dihapus. Kolom
| penunjuknya memang nullOnDelete -- dokumennya tidak akan rusak -- tetapi
| yang hilang adalah pengelompokan pada dokumen yang sudah disetujui dan
| sudah dicetak. Yang sudah tidak dipakai lagi dinonaktifkan saja; ia hilang
| dari pilihan tanpa menyentuh dokumen lama.
|--------------------------------------------------------------------------
*/
class BusinessTripExpenseCategoryController extends Controller
{
    private const MODULE = 'business_trip_expense_category';

    /** Tabel rincian yang menunjuk kategori ini. */
    private const PEMAKAI = [
        'cash_advance_items',
        'cash_advance_realization_items',
    ];

    public function index(Request $request): JsonResponse
    {
        if (($tolak = $this->tolakTanpaIzin($request, 'view')) !== null) {
            return $tolak;
        }

        $query = BusinessTripExpenseCategory::query()->ordered();

        if ($request->filled('search')) {
            $cari = trim((string) $request->input('search'));

            $query->where(function ($q) use ($cari): void {
                $q->where('name', 'ILIKE', "%{$cari}%")
                    ->orWhere('description', 'ILIKE', "%{$cari}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $rows = $query->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn (BusinessTripExpenseCategory $k): array => [
                'id' => $k->id,
                'name' => $k->name,
                'description' => $k->description,
                'sort_order' => $k->sort_order,
                'is_active' => (bool) $k->is_active,

                /*
                | Dipakai layar untuk mematikan tombol hapusnya sebelum
                | ditekan. Penjagaan sebenarnya tetap di destroy() -- yang
                | hanya ada di layar bukan penjagaan.
                */
                'in_use' => $this->terpakai((int) $k->id),
            ])->all(),

            'abilities' => [
                'can_create' => $request->user()?->hasPermission(self::MODULE . '.create') ?? false,
                'can_update' => $request->user()?->hasPermission(self::MODULE . '.update') ?? false,
                'can_delete' => $request->user()?->hasPermission(self::MODULE . '.delete') ?? false,
            ],
        ], 200);
    }

    /**
     * Daftar ringkas untuk pemilih di formulir FPU dan Realisasi.
     *
     * Hanya yang aktif: kategori yang sudah dinonaktifkan tidak boleh
     * ditawarkan lagi, sementara dokumen lama yang memakainya tetap terbaca.
     */
    public function dropdownSelect(Request $request): JsonResponse
    {
        $rows = BusinessTripExpenseCategory::query()
            ->active()
            ->ordered()
            ->get(['id', 'name', 'sort_order']);

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn (BusinessTripExpenseCategory $k): array => [
                'id' => $k->id,
                'name' => $k->name,
                'title' => $k->name,
                'sort_order' => $k->sort_order,
            ])->all(),
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        if (($tolak = $this->tolakTanpaIzin($request, 'create')) !== null) {
            return $tolak;
        }

        $validated = $this->validasi($request);

        $kategori = BusinessTripExpenseCategory::create($validated);

        return response()->json([
            'success' => true,
            'message' => __('business_trip_expense_category_messages.store.success'),
            'data' => ['id' => $kategori->id],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (($tolak = $this->tolakTanpaIzin($request, 'update')) !== null) {
            return $tolak;
        }

        $kategori = BusinessTripExpenseCategory::find($id);

        if (!$kategori) {
            return $this->tidakAda();
        }

        $kategori->update($this->validasi($request, $id));

        return response()->json([
            'success' => true,
            'message' => __('business_trip_expense_category_messages.update.success'),
        ], 200);
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        if (($tolak = $this->tolakTanpaIzin($request, 'update')) !== null) {
            return $tolak;
        }

        $kategori = BusinessTripExpenseCategory::find($id);

        if (!$kategori) {
            return $this->tidakAda();
        }

        $kategori->update(['is_active' => !$kategori->is_active]);

        return response()->json([
            'success' => true,
            'message' => __('business_trip_expense_category_messages.toggle.success'),
            'data' => ['is_active' => (bool) $kategori->is_active],
        ], 200);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (($tolak = $this->tolakTanpaIzin($request, 'delete')) !== null) {
            return $tolak;
        }

        $kategori = BusinessTripExpenseCategory::find($id);

        if (!$kategori) {
            return $this->tidakAda();
        }

        /*
        | Yang sudah dipakai tidak dihapus, melainkan dinonaktifkan.
        |
        | Kolom penunjuknya nullOnDelete, jadi dokumennya tidak akan rusak --
        | tetapi pengelompokan pada dokumen yang sudah disetujui dan sudah
        | dicetak akan hilang, dan tidak ada yang bisa mengembalikannya.
        */
        if ($this->terpakai($id)) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_expense_category_messages.destroy.in_use'),
            ], 422);
        }

        try {
            $kategori->delete();
        } catch (\Throwable $e) {
            Log::error('[Master Kategori Biaya Perdin] Hapus gagal', [
                'id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_expense_category_messages.destroy.failed'),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => __('business_trip_expense_category_messages.destroy.success'),
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Pendukung
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function validasi(Request $request, ?int $kecualikan = null): array
    {
        $validated = $request->validate([
            /*
            | Namanya unik supaya dua kategori bernama sama tidak muncul
            | berdampingan pada pemilih -- yang mengisi formulir tidak punya
            | cara membedakannya.
            */
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('business_trip_expense_categories', 'name')
                    ->whereNull('deleted_at')
                    ->ignore($kecualikan),
            ],

            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'name' => __('business_trip_expense_category_messages.fields.name'),
            'sort_order' => __('business_trip_expense_category_messages.fields.sort_order'),
        ]);

        return [
            'name' => trim($validated['name']),
            'description' => trim((string) ($validated['description'] ?? '')) ?: null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => $request->has('is_active')
                ? $request->boolean('is_active')
                : true,
        ];
    }

    /** Kategori ini sedang dipakai baris rincian mana pun. */
    private function terpakai(int $id): bool
    {
        foreach (self::PEMAKAI as $tabel) {
            $ada = DB::table($tabel)
                ->where('expense_category_id', $id)
                ->whereNull('deleted_at')
                ->exists();

            if ($ada) {
                return true;
            }
        }

        return false;
    }

    private function tolakTanpaIzin(Request $request, string $action): ?JsonResponse
    {
        $user = $request->user();

        if ($user && $user->hasPermission(self::MODULE . '.' . $action)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => __('business_trip_expense_category_messages.forbidden'),
        ], 403);
    }

    private function tidakAda(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('business_trip_expense_category_messages.not_found'),
        ], 404);
    }
}
