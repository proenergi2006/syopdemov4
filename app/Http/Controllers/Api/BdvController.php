<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankDisbursementVoucher;
use App\Models\Cabang;
use App\Models\Department;
use App\Services\Bdv\BdvSourceRegistry;
use App\Support\UserHomeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Bank Disbursement Voucher
|--------------------------------------------------------------------------
| Tahap ini BARU BAGIAN YANG MEMBACA: daftar beserta penyaringnya, pilihan
| jenis dokumen, pencarian dokumen yang layak diangkat, dan rincian dokumen
| yang dipilih. Menyimpan, mengajukan, dan approval flow menyusul.
|
| Ditulis terpisah seperti itu dengan sengaja, supaya tampilannya bisa
| diperiksa lebih dulu sebelum aturan penyimpanannya dikunci.
|--------------------------------------------------------------------------
*/
class BdvController extends Controller
{
    private const ALLOWED_SCOPES = [
        'NONE',
        'OWN_DATA',
        'OWN_DEPARTMENT',
        'OWN_CABANG',
        'ALL',
    ];

    public function __construct(
        private readonly BdvSourceRegistry $registry,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Daftar
    |--------------------------------------------------------------------------
    */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.view')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_view'),
            ], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'max:30'],
            'document_type' => ['nullable', 'string', 'max:20'],
            'branch' => ['nullable', 'string', 'max:30'],
            'department_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = BankDisbursementVoucher::query()
            ->with([
                'branchData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'creator:id,name',
            ])
            ->orderByDesc('id');

        $this->terapkanBatasAkses($query, $user);

        $cari = trim((string) ($validated['search'] ?? ''));

        if ($cari !== '') {
            $query->where(function ($sub) use ($cari) {
                $sub->where('bdv_number', 'ILIKE', "%{$cari}%")
                    ->orWhere('source_number', 'ILIKE', "%{$cari}%")
                    ->orWhere('notes', 'ILIKE', "%{$cari}%");
            });
        }

        if (!empty($validated['status'])) {
            $query->whereRaw('UPPER(status) = ?', [strtoupper($validated['status'])]);
        }

        if (!empty($validated['document_type'])) {
            $query->where('document_type', strtoupper($validated['document_type']));
        }

        if (!empty($validated['branch'])) {
            $query->where('branch', $validated['branch']);
        }

        if (!empty($validated['department_id'])) {
            $query->where('department_id', (int) $validated['department_id']);
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('date', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('date', '<=', $validated['date_to']);
        }

        $halaman = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => $halaman->getCollection()
                ->map(fn (BankDisbursementVoucher $bdv): array => $this->bentukBaris($bdv))
                ->values(),

            'meta' => [
                'current_page' => $halaman->currentPage(),
                'last_page' => $halaman->lastPage(),
                'per_page' => $halaman->perPage(),
                'total' => $halaman->total(),
            ],

            'abilities' => [
                'can_view' => true,
                'can_create' => (bool) $user->hasPermission('bdv.create'),
                'can_update' => (bool) $user->hasPermission('bdv.update'),
                'can_delete' => (bool) $user->hasPermission('bdv.delete'),
                'can_submit' => (bool) $user->hasPermission('bdv.submit'),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Isi pilihan untuk penyaring dan formulir
    |--------------------------------------------------------------------------
    | Dikirim sekali sebagai satu permintaan, bukan satu permintaan per
    | dropdown: ketiganya selalu dibutuhkan bersamaan saat halaman dibuka.
    |--------------------------------------------------------------------------
    */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.view')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_view'),
            ], 403);
        }

        $cabang = Cabang::query()
            ->orderBy('nama_cabang')
            ->get(['id', 'nama_cabang', 'inisial_cabang'])
            ->map(fn (Cabang $c): array => [
                'value' => (string) $c->id,
                'title' => trim(
                    trim((string) $c->inisial_cabang) !== ''
                        ? $c->inisial_cabang . ' - ' . $c->nama_cabang
                        : (string) $c->nama_cabang,
                ),
            ]);

        $department = Department::query()
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama'])
            ->map(fn (Department $d): array => [
                'value' => $d->id,
                'title' => trim(
                    trim((string) $d->kode) !== ''
                        ? $d->kode . ' - ' . $d->nama
                        : (string) $d->nama,
                ),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'document_types' => $this->registry->all(),
                'statuses' => BankDisbursementVoucher::SEMUA_STATUS,
                'branches' => $cabang,
                'departments' => $department,

                /*
                | Kode jenis akun Accurate untuk kolom Kas/Bank. Dikirim dari
                | sini supaya kodenya tinggal di config, bukan tertulis di
                | dalam halaman Vue -- bila Accurate kelak memakai kode yang
                | berbeda, yang diubah satu baris config, bukan berkas layar.
                */
                'cash_bank_account_type' => config('bdv.cash_bank_account_type'),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Dokumen yang layak diangkat menjadi BDV
    |--------------------------------------------------------------------------
    | Dicari dengan mengetik nomornya, bukan diturunkan seluruhnya: dokumen
    | yang layak bisa ratusan, dan daftar sepanjang itu tidak membantu
    | siapa pun menemukan nomor yang ia cari.
    |--------------------------------------------------------------------------
    */
    public function availableDocuments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.create')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_create'),
            ], 403);
        }

        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:150'],
        ]);

        $kode = strtoupper(trim($validated['document_type']));

        if (!$this->registry->has($kode)) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.unknown_document_type'),
            ], 422);
        }

        $jenis = $this->registry->get($kode);

        $query = $this->registry->eligibleQuery($kode);

        /* Dokumen yang boleh dilihat user ini saja, aturannya sama dengan daftar. */
        $this->terapkanBatasAkses($query, $user);

        $cari = trim((string) ($validated['search'] ?? ''));

        if ($cari !== '') {
            $kolomNomor = $jenis['number_column'];

            $query->where(function ($sub) use ($cari, $kolomNomor) {
                $sub->where($kolomNomor, 'ILIKE', "%{$cari}%")
                    ->orWhere('subject', 'ILIKE', "%{$cari}%");
            });
        }

        $dokumen = $query
            ->orderByDesc('id')
            ->limit((int) config('bdv.search_limit', 30))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $dokumen->map(function ($satu) use ($kode): array {
                $ringkas = $this->registry->ringkasan($kode, $satu);

                /* Id asli tidak pernah keluar, sama seperti modul lain. */
                $ringkas['public_id'] = Crypt::encryptString((string) $satu->getKey());

                unset($ringkas['id']);

                return $ringkas;
            })->values(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Rincian satu dokumen sumber
    |--------------------------------------------------------------------------
    */
    public function documentDetail(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('bdv.create')) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.forbidden_create'),
            ], 403);
        }

        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:20'],
            'public_id' => ['required', 'string', 'max:512'],
        ]);

        $kode = strtoupper(trim($validated['document_type']));

        if (!$this->registry->has($kode)) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.unknown_document_type'),
            ], 422);
        }

        try {
            $id = (int) Crypt::decryptString($validated['public_id']);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.document_not_found'),
            ], 404);
        }

        $query = $this->registry->eligibleQuery($kode);

        $this->terapkanBatasAkses($query, $user);

        $dokumen = $query->find($id);

        if (!$dokumen) {
            /*
            | Tidak dibedakan antara "tidak ada", "sudah dicairkan", dan "sudah
            | diangkat BDV lain": ketiganya sama-sama berarti nomor itu tidak
            | boleh dipakai sekarang, dan membedakannya hanya membocorkan
            | keberadaan dokumen yang tidak boleh dilihat user ini.
            */
            return response()->json([
                'success' => false,
                'message' => __('bdv_messages.document_not_available'),
            ], 404);
        }

        $rincian = $this->registry->rincian($kode, $dokumen);

        $rincian['public_id'] = $validated['public_id'];

        unset($rincian['id']);

        return response()->json([
            'success' => true,
            'data' => $rincian,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function bentukBaris(BankDisbursementVoucher $bdv): array
    {
        return [
            'public_id' => $bdv->encrypted_id,
            'bdv_number' => $bdv->bdv_number,
            'date' => optional($bdv->date)->toDateString(),

            'document_type' => $bdv->document_type,
            'document_type_label' => $this->registry->has((string) $bdv->document_type)
                ? $this->registry->label((string) $bdv->document_type)
                : $bdv->document_type,

            'source_number' => $bdv->source_number,

            'branch' => $bdv->branchData
                ? trim(
                    trim((string) $bdv->branchData->inisial_cabang) !== ''
                        ? $bdv->branchData->inisial_cabang . ' - ' . $bdv->branchData->nama_cabang
                        : (string) $bdv->branchData->nama_cabang,
                )
                : null,

            'department' => $bdv->departmentData
                ? trim(
                    trim((string) $bdv->departmentData->kode) !== ''
                        ? $bdv->departmentData->kode . ' - ' . $bdv->departmentData->nama
                        : (string) $bdv->departmentData->nama,
                )
                : null,

            'total_amount' => (float) $bdv->total_amount,
            'status' => $bdv->status,
            'requester' => $bdv->creator->name ?? null,
            'created_at' => $bdv->created_at,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Batas akses
    |--------------------------------------------------------------------------
    | Memakai scope pada permission, persis seperti modul pengajuan dana lain.
    |
    | BELUM ada bagian "approver selalu boleh melihat dokumen yang masuk ke
    | flow-nya", karena BDV memang belum punya tabel approval. Bagian itu
    | ditambahkan bersamaan dengan approval flow-nya nanti -- ditulis di sini
    | supaya tidak terlewat.
    |--------------------------------------------------------------------------
    */
    private function terapkanBatasAkses($query, $user): void
    {
        $scope = strtoupper(trim((string) ($user->getPermissionScope('bdv.view') ?? 'NONE')));

        if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
            $scope = 'NONE';
        }

        if ($scope === 'ALL') {
            return;
        }

        if ($scope === 'OWN_DATA') {
            $query->where('created_by', $user->id);

            return;
        }

        $penugasan = $this->penugasanAkses($user);

        $idCabang = collect([(int) ($user->cabang_id ?? 0)])
            ->merge($penugasan->pluck('branch_id')->map(fn ($id) => (int) $id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $idDepartment = collect([(int) ($user->departemen_id ?? 0)])
            ->merge($penugasan->pluck('department_id')->map(fn ($id) => (int) $id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($scope === 'OWN_CABANG') {
            $query->whereIn('branch', $idCabang->map(fn ($id) => (string) $id));

            return;
        }

        if ($scope === 'OWN_DEPARTMENT') {
            $query->whereIn('department_id', $idDepartment);

            return;
        }

        /* NONE: hanya dokumen yang ia buat sendiri. */
        $query->where('created_by', $user->id);
    }

    private function penugasanAkses($user)
    {
        if (!$user) {
            return collect();
        }

        try {
            $penugasan = DB::table('user_access_assignments')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->select(['branch_id', 'department_id'])
                ->get()
                ->map(fn ($satu) => [
                    'branch_id' => (int) $satu->branch_id,
                    'department_id' => (int) $satu->department_id,
                ])
                ->filter(fn ($satu) => $satu['branch_id'] > 0 && $satu['department_id'] > 0)
                ->values();

            return collect(UserHomeAccess::gabungkan($user, $penugasan));
        } catch (\Throwable $e) {
            Log::warning('[BDV] Gagal membaca penugasan akses', [
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
            ]);

            return collect();
        }
    }
}
