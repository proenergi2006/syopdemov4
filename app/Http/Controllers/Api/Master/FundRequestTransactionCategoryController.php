<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\CashAdvance;
use App\Models\FundRequestTransactionCategory;
use App\Services\FundRequest\CashAdvance\CashAdvanceApprovalGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Master Keterangan Transaksi (modul Pengajuan Dana)
|--------------------------------------------------------------------------
| Dipakai dua tempat: pilihan pada form FPU, dan cakupan pada form approval
| flow FPU -- keterangan transaksi ikut menentukan flow mana yang berlaku.
|
| Berbeda dari MasterKeteranganTransaksi yang berisi kategori pajak vendor.
| Keduanya tidak berhubungan meski namanya mirip.
|--------------------------------------------------------------------------
*/
class FundRequestTransactionCategoryController extends Controller
{
    private const PERMISSION_PREFIX = 'fund_request_transaction_category';

    /*
    |--------------------------------------------------------------------------
    | Daftar
    |--------------------------------------------------------------------------
    */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = (int) $request->input('per_page', 10);
        $perPage = $perPage > 0 ? $perPage : 10;
        $perPage = $perPage > 100 ? 100 : $perPage;

        try {
            if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.view')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk melihat master keterangan transaksi.',
                    'data' => [],
                    'meta' => $this->emptyMeta($perPage),
                    'summary' => $this->emptySummary(),
                    'abilities' => $this->emptyAbilities(),
                ], 403);
            }

            $search = trim((string) $request->input('search', ''));
            $status = $this->resolveBooleanFilter($request->input('is_active'));

            /*
            | Statistik tidak terpengaruh pencarian dan pagination, tetapi tetap
            | mengikuti modul yang sedang dilihat -- angka gabungan FPU dan
            | Claim tidak berarti apa-apa bagi pembacanya.
            */
            $summaryQuery = FundRequestTransactionCategory::query();

            $summaryDocumentType = $this->resolveDocumentTypeFilter($request->input('document_type'));

            if ($summaryDocumentType !== null) {
                $summaryQuery->forDocumentType($summaryDocumentType);
            }

            $summary = $summaryQuery
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('SUM(CASE WHEN is_active = TRUE THEN 1 ELSE 0 END) AS active')
                ->selectRaw('SUM(CASE WHEN is_active = FALSE THEN 1 ELSE 0 END) AS inactive')
                ->first();

            $query = FundRequestTransactionCategory::query();

            if ($search !== '') {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('code', 'ILIKE', "%{$search}%")
                        ->orWhere('name', 'ILIKE', "%{$search}%")
                        ->orWhere('description', 'ILIKE', "%{$search}%")
                        ->orWhere('required_documents', 'ILIKE', "%{$search}%");
                });
            }

            if ($status !== null) {
                $query->where('is_active', $status);
            }

            /*
            | Satu tabel menampung dua daftar. Tanpa penyaring ini keduanya
            | tercampur, padahal FPU dan Claim memakai jenis transaksi yang
            | sama sekali berbeda.
            */
            $documentType = $this->resolveDocumentTypeFilter($request->input('document_type'));

            if ($documentType !== null) {
                $query->forDocumentType($documentType);
            }

            $categories = $query
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate($perPage);

            $categories->through(
                fn(FundRequestTransactionCategory $category) => $this->transform($category),
            );

            return response()->json([
                'success' => true,
                'message' => 'Master keterangan transaksi berhasil dimuat.',
                'data' => $categories->items(),
                'meta' => [
                    'current_page' => $categories->currentPage(),
                    'last_page' => $categories->lastPage(),
                    'per_page' => $categories->perPage(),
                    'total' => $categories->total(),
                ],
                'summary' => [
                    'total' => (int) ($summary->total ?? 0),
                    'active' => (int) ($summary->active ?? 0),
                    'inactive' => (int) ($summary->inactive ?? 0),
                ],
                'abilities' => $this->abilitiesFor($user),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Master Keterangan Transaksi] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Master keterangan transaksi gagal dimuat.',
                'data' => [],
                'meta' => $this->emptyMeta($perPage),
                'summary' => $this->emptySummary(),
                'abilities' => $this->emptyAbilities(),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Simpan
    |--------------------------------------------------------------------------
    */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.create')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menambah keterangan transaksi.',
            ], 403);
        }

        try {
            $validated = $this->validatePayload($request);

            $category = FundRequestTransactionCategory::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Keterangan transaksi berhasil ditambahkan.',
                'data' => $this->transform($category),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? 'Data keterangan transaksi tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[Master Keterangan Transaksi] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Keterangan transaksi gagal ditambahkan.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Ubah
    |--------------------------------------------------------------------------
    */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.update')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah keterangan transaksi.',
            ], 403);
        }

        try {
            $category = FundRequestTransactionCategory::findOrFail((int) $id);

            $validated = $this->validatePayload($request, $category->id);

            $category->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Keterangan transaksi berhasil diperbarui.',
                'data' => $this->transform($category->fresh()),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? 'Data keterangan transaksi tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[Master Keterangan Transaksi] Update error', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Keterangan transaksi gagal diperbarui.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Aktif / nonaktif
    |--------------------------------------------------------------------------
    | Menonaktifkan lebih aman daripada menghapus: kategori hilang dari form
    | FPU, tetapi flow dan dokumen lama yang memakainya tetap utuh.
    |--------------------------------------------------------------------------
    */
    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.update')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah keterangan transaksi.',
            ], 403);
        }

        try {
            $category = FundRequestTransactionCategory::findOrFail((int) $id);

            $category->update(['is_active' => !$category->is_active]);

            return response()->json([
                'success' => true,
                'message' => $category->is_active
                    ? 'Keterangan transaksi diaktifkan.'
                    : 'Keterangan transaksi dinonaktifkan.',
                'data' => $this->transform($category->fresh()),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Master Keterangan Transaksi] Toggle status error', [
                'id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Status keterangan transaksi gagal diubah.',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Hapus
    |--------------------------------------------------------------------------
    */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.delete')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus keterangan transaksi.',
            ], 403);
        }

        try {
            $category = FundRequestTransactionCategory::findOrFail((int) $id);

            $usage = $this->countUsage($category->id);

            /*
            | Kategori yang sudah dipakai tidak boleh dihapus: approval flow
            | akan kehilangan cakupannya, dan dokumen lama kehilangan
            | keterangannya. Arahkan ke nonaktifkan.
            */
            if ($usage['total'] > 0) {
                return response()->json([
                    'success' => false,
                    'message' => sprintf(
                        'Keterangan transaksi ini masih dipakai %d approval flow, %d FPU, dan %d Claim. '
                        . 'Nonaktifkan saja agar data lama tetap utuh.',
                        $usage['approval_flows'],
                        $usage['cash_advances'],
                        $usage['claims'],
                    ),
                ], 422);
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'Keterangan transaksi berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Master Keterangan Transaksi] Destroy error', [
                'id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Keterangan transaksi gagal dihapus.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET /fund-request/transaction-categories/dropdown-select
     *
     * Dipakai form FPU dan form approval flow FPU.
     */
    public function dropdownSelect(
        Request $request,
        CashAdvanceApprovalGeneratorService $approvalGenerator,
    ): JsonResponse {
        $query = FundRequestTransactionCategory::query();

        /*
        | Form hanya boleh memilih kategori aktif. Halaman approval flow bisa
        | meminta seluruhnya lewat include_inactive supaya flow lama yang
        | memakai kategori nonaktif tetap tampil utuh.
        */
        if (!$request->boolean('include_inactive')) {
            $query->active();
        }

        /*
        | Form FPU dan form Claim memakai daftar yang berbeda, jadi pemanggilnya
        | wajib menyebut modulnya. Tanpa document_type seluruh baris dikirim --
        | itu yang dipakai halaman approval flow, yang memang perlu melihat
        | semuanya.
        */
        $documentType = $this->resolveDocumentTypeFilter($request->input('document_type'));

        if ($documentType !== null) {
            $query->forDocumentType($documentType);
        }

        /*
        | Penyaringan menurut approval flow.
        |
        | Matriks "departemen mana boleh mengajukan keterangan transaksi apa"
        | sudah tercatat pada approval flow FPU, jadi daftar ini diturunkan
        | dari sana alih-alih disimpan ulang sebagai relasi master tersendiri.
        | Dengan begitu form tidak mungkin menawarkan pilihan yang justru
        | ditolak saat submit karena flow-nya tidak ada.
        |
        | Hanya berlaku untuk FPU. Claim memang tidak dibatasi per department,
        | dan halaman approval flow perlu melihat seluruh daftar.
        */
        $flowInfo = null;

        $branchId = $request->input('branch_id');
        $departmentId = $request->input('department_id');

        if (
            $documentType === FundRequestTransactionCategory::DOCUMENT_TYPE_ADVANCE
            && $branchId !== null
            && $branchId !== ''
            && $departmentId !== null
            && $departmentId !== ''
        ) {
            $areaType = (string) $branchId === (string) CashAdvance::HO_BRANCH_ID
                ? 'HO'
                : 'CABANG';

            $flowInfo = $approvalGenerator->resolveSelectableCategories(
                areaType: $areaType,
                departmentId: (int) $departmentId,
            );

            /*
            | Tanpa flow yang cocok, daftarnya memang harus kosong -- itulah
            | yang membuat form bisa memberi pesan tegas ke user, bukan
            | membiarkannya memilih lalu gagal saat submit.
            */
            if (!$flowInfo['all_categories']) {
                $query->whereIn('id', $flowInfo['category_ids'] ?: [0]);
            }
        }

        $categories = $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'code',
                'document_type',
                'name',
                'description',
                'claimable_status',
                'required_documents',

                /*
                | WAJIB ada di sini. Daftar kolomnya eksplisit, jadi kolom yang
                | tidak disebut tidak ikut terbaca -- nilainya null, lalu (bool)
                | null menjadikannya false. Layar FPU menyimpulkan keterangan
                | transaksi perdin tidak menuntut dokumen apa pun, dan pemilih
                | Perdin-nya tidak pernah muncul.
                */
                'requires_business_trip',

                'is_active',
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Master keterangan transaksi berhasil dimuat.',

            /*
            | Membedakan "belum ada approval flow" dari "daftarnya memang
            | kosong". Keduanya sama-sama menghasilkan nol baris, tetapi hanya
            | yang pertama yang perlu diberitahukan ke user.
            */
            'meta' => [
                'filtered_by_flow' => $flowInfo !== null,
                'has_matching_flow' => $flowInfo['has_flow'] ?? null,
            ],

            'data' => $categories
                ->map(fn(FundRequestTransactionCategory $category) => [
                    'id' => $category->id,
                    'code' => $category->code,
                    'document_type' => $category->document_type,
                    'name' => $category->name,
                    'title' => $category->name,

                    /*
                     * Ikut dikirim supaya form Claim nanti bisa menampilkan
                     * batasan dan dokumen wajibnya tanpa permintaan tambahan.
                     */
                    'description' => $category->description,
                    'claimable_status' => $category->claimable_status,
                    'claimable_label' => FundRequestTransactionCategory::claimableLabel(
                        $category->claimable_status,
                    ),
                    'required_documents' => $category->required_documents,

                    /*
                     * Menentukan apakah form FPU wajib menautkan dokumen
                     * Perdin. Ikut dikirim di sini supaya layar tidak perlu
                     * bertanya lagi setiap kali pilihannya berganti.
                     */
                    'requires_business_trip' => (bool) $category->requires_business_trip,

                    'is_active' => (bool) $category->is_active,
                ])
                ->values(),
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_]+$/',
                Rule::unique('fund_request_transaction_categories', 'code')
                    ->ignore($ignoreId)
                    ->whereNull('deleted_at'),
            ],
            'document_type' => [
                'required',
                'string',
                Rule::in(FundRequestTransactionCategory::DOCUMENT_TYPES),
            ],
            'name' => ['required', 'string', 'max:255'],

            // Kolom "Batasan / Ketentuan" pada matriks Claim.
            'description' => ['nullable', 'string', 'max:1000'],

            /*
            | "Dapat Diklaim?" dan dokumen pendukung wajib hanya berlaku pada
            | baris Claim. Keduanya tetap nullable, bukan required_if, karena
            | matriksnya belum tentu terisi lengkap sejak awal.
            */
            'claimable_status' => [
                'nullable',
                'integer',
                Rule::in(FundRequestTransactionCategory::CLAIMABLE_STATUSES),
            ],
            'required_documents' => ['nullable', 'string', 'max:1000'],

            /*
            | Menandai keterangan transaksi perjalanan dinas: FPU yang memakainya
            | wajib menunjuk dokumen Perdin yang sudah disetujui. Hanya berlaku
            | pada baris FPU -- Claim tidak mengenal Perdin.
            */
            'requires_business_trip' => ['nullable', 'boolean'],

            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, dan garis bawah.',
            'document_type.required' => 'Modul pemilik keterangan transaksi wajib dipilih.',
            'document_type.in' => 'Modul pemilik keterangan transaksi tidak dikenali.',
            'claimable_status.in' => 'Pilihan "Dapat Diklaim?" tidak dikenali.',
        ]);

        $documentType = strtoupper(trim($validated['document_type']));

        $bersihkan = fn ($nilai): ?string => isset($nilai)
            ? (trim((string) $nilai) ?: null)
            : null;

        /*
        | Baris FPU tidak memiliki kedua kolom Claim. Dikosongkan di sini, bukan
        | diserahkan ke pengirimnya, supaya data tidak pernah menyimpan isian
        | yang tidak berlaku pada modulnya.
        */
        $isClaim = $documentType === FundRequestTransactionCategory::DOCUMENT_TYPE_CLAIM;

        return [
            // Kode disimpan huruf besar supaya konsisten dengan data awal.
            'code' => strtoupper(trim($validated['code'])),
            'document_type' => $documentType,
            'name' => trim($validated['name']),
            'description' => $bersihkan($validated['description'] ?? null),

            'claimable_status' => $isClaim
                ? ($validated['claimable_status'] ?? null)
                : null,

            'required_documents' => $isClaim
                ? $bersihkan($validated['required_documents'] ?? null)
                : null,

            /* Dikosongkan pada baris Claim, seperti dua kolom Claim di atas. */
            'requires_business_trip' => !$isClaim && $request->boolean('requires_business_trip'),

            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => $request->has('is_active')
                ? $request->boolean('is_active')
                : true,
        ];
    }

    /**
     * Menerjemahkan filter modul dari query string.
     *
     * null berarti tidak menyaring -- dipakai halaman master saat menampilkan
     * kedua daftar sekaligus.
     */
    private function resolveDocumentTypeFilter($value): ?string
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '' || $value === 'ALL') {
            return null;
        }

        return in_array($value, FundRequestTransactionCategory::DOCUMENT_TYPES, true)
            ? $value
            : null;
    }

    /**
     * Menghitung pemakaian kategori pada approval flow dan dokumen yang memakainya.
     *
     * @return array{approval_flows: int, cash_advances: int, claims: int, total: int}
     */
    private function countUsage(int $categoryId): array
    {
        $approvalFlows = DB::table('approval_flow_transaction_categories')
            ->where('transaction_category_id', $categoryId)
            ->count();

        $cashAdvances = DB::table('cash_advances')
            ->where('transaction_category_id', $categoryId)
            ->whereNull('deleted_at')
            ->count();

        $claims = DB::table('claims')
            ->where('transaction_category_id', $categoryId)
            ->whereNull('deleted_at')
            ->count();

        return [
            'approval_flows' => $approvalFlows,
            'cash_advances' => $cashAdvances,
            'claims' => $claims,
            'total' => $approvalFlows + $cashAdvances + $claims,
        ];
    }

    private function transform(FundRequestTransactionCategory $category): array
    {
        $usage = $this->countUsage($category->id);

        return [
            'id' => $category->id,
            'code' => $category->code,
            'document_type' => $category->document_type,
            'name' => $category->name,

            // Kolom "Batasan / Ketentuan" pada matriks Claim.
            'description' => $category->description,

            'claimable_status' => $category->claimable_status,

            /*
             * Label dikirim dari sini supaya penamaannya satu sumber, tidak
             * ditulis ulang di setiap tempat yang menampilkannya.
             */
            'claimable_label' => FundRequestTransactionCategory::claimableLabel(
                $category->claimable_status,
            ),

            'required_documents' => $category->required_documents,

            'requires_business_trip' => (bool) $category->requires_business_trip,

            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,

            /*
             * Dipakai frontend untuk menonaktifkan tombol hapus dan
             * menjelaskan alasannya sebelum user menekan tombolnya.
             */
            'usage_approval_flows' => $usage['approval_flows'],
            'usage_cash_advances' => $usage['cash_advances'],
            'usage_claims' => $usage['claims'],
            'is_deletable' => $usage['total'] === 0,

            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
        ];
    }

    /**
     * Menerjemahkan filter status dari query string.
     */
    private function resolveBooleanFilter($value): ?bool
    {
        if ($value === null || $value === '' || $value === 'all') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function abilitiesFor($user): array
    {
        return [
            'can_view' => $user->hasPermission(self::PERMISSION_PREFIX . '.view'),
            'can_create' => $user->hasPermission(self::PERMISSION_PREFIX . '.create'),
            'can_update' => $user->hasPermission(self::PERMISSION_PREFIX . '.update'),
            'can_delete' => $user->hasPermission(self::PERMISSION_PREFIX . '.delete'),
        ];
    }

    private function emptyAbilities(): array
    {
        return [
            'can_view' => false,
            'can_create' => false,
            'can_update' => false,
            'can_delete' => false,
        ];
    }

    private function emptyMeta(int $perPage): array
    {
        return [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
        ];
    }
}
