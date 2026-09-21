<?php

namespace App\Http\Controllers\Api;

use App\Exports\BusinessTripExport;
use App\Http\Controllers\Controller;
use App\Models\BusinessTrip;
use App\Models\BusinessTripItinerary;
use App\Models\CashAdvance;
use App\Models\BusinessTripApproval;
use App\Services\BusinessTrip\BusinessTripApprovalGeneratorService;
use App\Services\BusinessTrip\BusinessTripApprovalService;
use App\Services\BusinessTrip\BusinessTripMailService;
use App\Services\BusinessTrip\BusinessTripNotificationService;
use App\Services\BusinessTrip\BusinessTripNumberService;
use App\Services\BusinessTrip\BusinessTripPresenter;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/*
|--------------------------------------------------------------------------
| Perjalanan Dinas (Perdin)
|--------------------------------------------------------------------------
| Dokumen berpersetujuan: draft -> diajukan -> disetujui berjenjang, dengan
| matriks penyetuju yang diatur di master Approval Flow.
|
| ITINERARY TIDAK PUNYA PERSETUJUAN SENDIRI. Ia bagian dari formulirnya,
| disetujui bersama induknya.
|
| Perdin yang sudah APPROVED menjadi syarat FPU berketerangan transaksi
| perdin -- tautannya dibuat dari sisi FPU, bukan dari sini.
|
| Tiga hal diisi sendiri oleh sistem dari akun pemohon: nama, department, dan
| jabatan. Ketiganya TIDAK diterima dari kiriman layar. Kalau layar boleh
| mengirimnya, siapa pun yang bisa menyusun permintaan sendiri bisa
| mengatasnamakan orang lain -- dan formulir ini adalah dokumen yang
| ditandatangani.
|--------------------------------------------------------------------------
*/
class BusinessTripController extends Controller
{
    private const ALLOWED_SCOPES = [
        'NONE',
        'OWN_DATA',
        'OWN_DEPARTMENT',
        'OWN_CABANG',
        'ALL',
    ];

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

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.user_not_authenticated'),
                'data' => [],
                'meta' => $this->emptyMeta($perPage),
                'abilities' => $this->emptyAbilities(),
            ], 401);
        }

        try {
            $context = $this->resolveViewContext($user);

            $abilities = [
                'can_view' => $user->hasPermission('business_trip.view'),
                'view_scope' => $context['scope'],
                'can_create' => $user->hasPermission('business_trip.create'),
                'can_update' => $user->hasPermission('business_trip.update'),
                'can_delete' => $user->hasPermission('business_trip.delete'),
                'can_submit' => $user->hasPermission('business_trip.submit'),
                'can_cancel' => $user->hasPermission('business_trip.cancel'),
                'can_print' => $user->hasPermission('business_trip.print'),

                /*
                | Berdiri sendiri, seperti pada FPU: menarik data keluar
                | pertanyaannya lain dari sekadar membukanya di layar.
                */
                'can_export' => $user->hasPermission('business_trip.export'),
            ];

            if (!$abilities['can_view']) {
                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.forbidden_view'),
                    'data' => [],
                    'meta' => $this->emptyMeta($perPage),
                    'abilities' => $abilities,
                ], 403);
            }

            /*
            | Riwayat approval ikut dimuat: modal rincian dibuka dari baris daftar
            | ini, jadi apa pun yang tidak dimuat di sini tidak akan pernah sampai
            | ke sana. Beberapa langkah per dokumen -- ringan, dan menghindarkan
            | satu permintaan tambahan setiap kali rincian dibuka.
            */
            $query = BusinessTrip::query()->with(['itineraries', 'approvals']);

            $this->applyVisibilityScope($query, $user, $context);

            $this->applyListFilters($query, $request);

            $sortBy = in_array($request->input('sort_by'), [
                'trip_number',
                'date',
                'depart_date',
                'return_date',
                'employee_name',
                'destination',
            ], true)
                ? $request->input('sort_by')
                : 'depart_date';

            $sortDir = strtolower((string) $request->input('sort_dir')) === 'asc' ? 'asc' : 'desc';

            $paginator = $query
                ->orderBy($sortBy, $sortDir)
                ->orderByDesc('id')
                ->paginate($perPage);

            $menungguSaya = $this->tripIdsWaitingFor(
                $user,
                collect($paginator->items())->pluck('id')->all(),
            );

            return response()->json([
                'success' => true,
                'message' => __('business_trip_messages.list_loaded'),

                'data' => collect($paginator->items())
                    ->map(fn (BusinessTrip $trip): array => $this->transform(
                        $trip,
                        in_array($trip->id, $menungguSaya, true),
                    ))
                    ->all(),

                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],

                'abilities' => $abilities,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Perdin] Index error', [
                'message' => $e->getMessage(),
                'user_id' => $user->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.list_failed'),
                'data' => [],
                'meta' => $this->emptyMeta($perPage),
                'abilities' => $this->emptyAbilities(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Bekal formulir
    |--------------------------------------------------------------------------
    | Identitas pemohon dikirim dari sini supaya layar bisa MENAMPILKANNYA,
    | bukan supaya layar mengirimkannya kembali. Saat menyimpan, identitasnya
    | dibaca ulang dari akun -- lihat catatan di kepala berkas.
    |--------------------------------------------------------------------------
    */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.user_not_authenticated'),
                'data' => null,
            ], 401);
        }

        $identitas = $this->resolveEmployeeIdentity($user);

        return response()->json([
            'success' => true,
            'message' => __('business_trip_messages.options_loaded'),

            'data' => [
                'employee' => [
                    'user_id' => (int) $user->id,
                    'employee_name' => $identitas['employee_name'],
                    'department_id' => $identitas['department_id'],
                    'department_name' => $identitas['department_name'],
                    'position_name' => $identitas['position_name'],
                    'branch' => $identitas['branch'],
                    'branch_name' => $identitas['branch_name'],
                ],

                'timezones' => BusinessTripItinerary::TIMEZONES,
            ],
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Perdin yang boleh dipakai FPU
    |--------------------------------------------------------------------------
    | Dipanggil layar FPU saat keterangan transaksinya mewajibkan perdin.
    |
    | Daftarnya milik SATU ORANG: perdin atas nama orang lain tidak pernah
    | ditawarkan, sekalipun akun ini boleh melihatnya. Perjalanan itu miliknya,
    | dan uang mukanya diajukan olehnya sendiri.
    |--------------------------------------------------------------------------
    */
    public function eligible(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.user_not_authenticated'),
                'data' => [],
            ], 401);
        }

        /*
        | Perdin yang sedang dipakai dokumen ini sendiri tetap ditawarkan saat
        | menyunting -- kalau tidak, membuka FPU lama lalu menyimpannya kembali
        | akan kehilangan tautannya.
        */
        $kecualikan = null;

        if ($request->filled('cash_advance_public_id')) {
            try {
                $kecualikan = (int) Crypt::decryptString(
                    (string) $request->input('cash_advance_public_id'),
                );
            } catch (\Throwable $e) {
                /* Id yang tidak terbaca diperlakukan seolah tidak dikirim. */
                $kecualikan = null;
            }
        }

        /*
        | Dua kumpulan, dan bedanya penting:
        |
        |   calon  -- seluruh perdin miliknya yang sudah diajukan, untuk DITAMPILKAN
        |   layak  -- yang benar-benar boleh DIPILIH
        |
        | Yang layak dibaca dari eligibleQuery, sumber yang sama yang dipakai
        | memeriksa kiriman nanti. Daftarnya melebar, izinnya tidak.
        */
        $rows = $this->candidateQuery($user->id, $kecualikan)
            ->orderByDesc('depart_date')
            ->limit(100)
            ->get();

        $layak = $this->eligibleQuery($user->id, $kecualikan)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $menunggu = $this->waitingStepLabels($rows->pluck('id')->all());

        return response()->json([
            'success' => true,
            'message' => __('business_trip_messages.list_loaded'),

            'data' => $rows
                ->map(fn (BusinessTrip $trip): array => [
                    'id' => $trip->id,
                    'public_id' => Crypt::encryptString((string) $trip->id),
                    'trip_number' => $trip->trip_number,
                    'destination' => $trip->destination,
                    'depart_date' => optional($trip->depart_date)->toDateString(),
                    'return_date' => optional($trip->return_date)->toDateString(),
                    'duration_days' => $trip->duration_days,
                    'purpose' => $trip->purpose,

                    'status' => $trip->status,
                    'is_on_time' => $trip->is_on_time,

                    /*
                    | Yang terkunci tetap tampil, supaya pemohon tahu perdinnya
                    | ADA dan sedang menunggu -- bukan menghadapi daftar kosong
                    | yang tidak menjelaskan apa pun.
                    */
                    'selectable' => in_array((int) $trip->id, $layak, true),
                    'waiting_step' => $menunggu[$trip->id] ?? null,
                ])
                ->all(),
        ], 200);
    }

    /**
     * Seluruh perdin milik seseorang yang PANTAS DITAMPILKAN pada pemilih FPU.
     *
     * Lebih luas dari eligibleQuery: yang masih menunggu persetujuan ikut,
     * termasuk yang terlambat diajukan dan karena itu belum boleh dipakai.
     * Keduanya tetap disaring dari perdin yang sudah dipegang FPU lain yang
     * masih hidup -- yang sudah terpakai tidak perlu ditawarkan sama sekali.
     *
     * DRAFT tidak ikut: selama belum diajukan, tidak ada yang bisa ditunggu,
     * dan yang perlu dilakukan pemohon adalah mengajukannya lebih dulu.
     */
    public function candidateQuery(int $userId, ?int $kecualikanCashAdvanceId = null)
    {
        return BusinessTrip::query()
            ->where('user_id', $userId)
            ->whereIn('status', [
                BusinessTrip::STATUS_APPROVED,
                BusinessTrip::STATUS_IN_PROGRESS,
            ])
            ->whereNotExists(function ($q) use ($kecualikanCashAdvanceId): void {
                $q->select(DB::raw(1))
                    ->from('cash_advances as ca')
                    ->whereColumn('ca.business_trip_id', 'business_trips.id')
                    ->whereNull('ca.deleted_at')
                    ->whereNotIn('ca.status', [
                        CashAdvance::STATUS_REJECTED,
                        CashAdvance::STATUS_CANCELLED,
                    ]);

                if ($kecualikanCashAdvanceId) {
                    $q->where('ca.id', '!=', $kecualikanCashAdvanceId);
                }
            });
    }

    /**
     * Sedang menunggu siapa, pada tahap keberapa -- untuk tiap perdin.
     *
     * Satu kueri untuk seluruh daftar. Menanyakannya per baris berarti kueri
     * yang berlipat mengikuti jumlah pilihan, dan daftar pilihan adalah tempat
     * paling buruk untuk itu: ia dibuka berulang kali.
     *
     * @param  int[]  $tripIds
     * @return array<int, array{step_order: int, label: string|null, approver: string|null}>
     */
    private function waitingStepLabels(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $baris = BusinessTripApproval::query()
            ->whereIn('business_trip_id', $tripIds)
            ->where('status', BusinessTripApproval::STATUS_WAITING)
            ->orderBy('business_trip_id')
            ->orderBy('step_order')
            ->get(['business_trip_id', 'step_order', 'label', 'approver_name_snapshot']);

        $hasil = [];

        foreach ($baris as $row) {
            /* Yang pertama pada tiap dokumen adalah tahap yang sedang aktif. */
            if (isset($hasil[$row->business_trip_id])) {
                continue;
            }

            $hasil[$row->business_trip_id] = [
                'step_order' => (int) $row->step_order,
                'label' => $row->label,
                'approver' => $row->approver_name_snapshot,
            ];
        }

        return $hasil;
    }
    /**
     * Perdin milik seseorang yang boleh dijadikan dasar FPU baru.
     *
     * Tiga syarat, dan semuanya harus dipenuhi bersamaan:
     *
     *   1. sudah APPROVED     -- perjalanannya memang sudah diizinkan
     *   2. miliknya sendiri   -- bukan perjalanan orang lain
     *   3. belum dipegang FPU yang masih hidup
     *
     * Syarat ketiga sengaja memakai "masih hidup", bukan "pernah dipakai": FPU
     * yang ditolak atau dibatalkan melepaskan perdin-nya kembali. Kalau tidak,
     * satu penolakan memaksa pemohon mengulang seluruh izin perjalanannya --
     * hukuman yang tidak sebanding dengan kesalahan mengisi formulir.
     *
     * @param  int|null  $kecualikanCashAdvanceId  FPU yang sedang disunting.
     */
    public function eligibleQuery(int $userId, ?int $kecualikanCashAdvanceId = null)
    {
        return BusinessTrip::query()
            ->where('user_id', $userId)
            /*
            | Yang sudah disetujui selalu boleh. Yang masih menunggu hanya
            | boleh bila diajukan tepat waktu -- tujuh hari kalender atau lebih
            | sebelum berangkat.
            |
            | Selisihnya dihitung di basis data supaya penyaringannya terjadi
            | dalam satu kueri, bukan dengan memuat seluruh perdin lalu
            | menyaringnya di PHP.
            */
            ->where(function ($boleh): void {
                $boleh
                    ->where('status', BusinessTrip::STATUS_APPROVED)
                    ->orWhere(function ($paralel): void {
                        $paralel
                            ->where('status', BusinessTrip::STATUS_IN_PROGRESS)
                            ->whereNotNull('submitted_at')
                            ->whereRaw(
                                'depart_date - submitted_at::date >= ?',
                                [BusinessTrip::PARALLEL_DAYS],
                            );
                    });
            })
            ->whereNotExists(function ($q) use ($kecualikanCashAdvanceId): void {
                $q->select(DB::raw(1))
                    ->from('cash_advances as ca')
                    ->whereColumn('ca.business_trip_id', 'business_trips.id')
                    ->whereNull('ca.deleted_at')
                    ->whereNotIn('ca.status', [
                        CashAdvance::STATUS_REJECTED,
                        CashAdvance::STATUS_CANCELLED,
                    ]);

                if ($kecualikanCashAdvanceId) {
                    $q->where('ca.id', '!=', $kecualikanCashAdvanceId);
                }
            });
    }
    /*
    |--------------------------------------------------------------------------
    | Simpan
    |--------------------------------------------------------------------------
    */
    public function store(
        Request $request,
        BusinessTripNumberService $numberService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission('business_trip.create')) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.forbidden_create'),
                'data' => null,
            ], 403);
        }

        $validated = $request->validate($this->rules());

        try {
            $trip = DB::transaction(function () use ($validated, $user, $numberService): BusinessTrip {
                $identitas = $this->resolveEmployeeIdentity($user);

                $trip = BusinessTrip::create([
                    /*
                    | Nomor sementara. Nomor resmi baru dibentuk saat diajukan,
                    | supaya deret nomor resmi tidak terpakai oleh draft yang
                    | batal diajukan.
                    */
                    'trip_number' => $numberService->generateDraftNumber(),
                    'date' => now()->toDateString(),
                    'status' => BusinessTrip::STATUS_DRAFT,

                    'user_id' => $user->id,
                    'employee_name' => $identitas['employee_name'],
                    'department_id' => $identitas['department_id'],
                    'department_name' => $identitas['department_name'],
                    'position_name' => $identitas['position_name'],
                    'branch' => $identitas['branch'],

                    'destination' => trim((string) $validated['destination']),
                    'depart_date' => $validated['depart_date'],
                    'depart_time' => $validated['depart_time'],
                    'return_date' => $validated['return_date'],
                    'return_time' => $validated['return_time'],
                    'purpose' => trim((string) $validated['purpose']),
                    'notes' => isset($validated['notes']) ? trim((string) $validated['notes']) : null,

                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                $this->syncItineraries($trip, $validated['itineraries'] ?? []);

                return $trip;
            });

            $trip->load(['itineraries', 'approvals']);

            return response()->json([
                'success' => true,
                'message' => __('business_trip_messages.created'),
                'data' => $this->transform($trip),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[Perdin] Store error', [
                'message' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.create_failed'),
                'data' => null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Rincian
    |--------------------------------------------------------------------------
    */
    public function show(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('business_trip.view')) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.forbidden_view'),
                'data' => null,
            ], 403);
        }

        $trip = $this->findVisible($publicId, $user);

        if (!$trip) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.not_found'),
                'data' => null,
            ], 404);
        }

        $trip->load(['itineraries', 'approvals']);

        return response()->json([
            'success' => true,
            'message' => __('business_trip_messages.detail_loaded'),
            'data' => $this->transform($trip),
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Ubah
    |--------------------------------------------------------------------------
    */
    public function update(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('business_trip.update')) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.forbidden_update'),
                'data' => null,
            ], 403);
        }

        $trip = $this->findVisible($publicId, $user);

        if (!$trip) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.not_found'),
                'data' => null,
            ], 404);
        }

        if (!$trip->is_editable) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.not_editable'),
                'data' => null,
            ], 422);
        }

        $validated = $request->validate($this->rules());

        try {
            DB::transaction(function () use ($trip, $validated, $user): void {
                /*
                | Identitas TIDAK ikut diperbarui. Salinannya adalah keadaan
                | saat dokumen dibuat; memperbaruinya saat penyuntingan membuat
                | dokumen lama berubah sendiri mengikuti mutasi jabatan.
                */
                $trip->update([
                    'destination' => trim((string) $validated['destination']),
                    'depart_date' => $validated['depart_date'],
                    'depart_time' => $validated['depart_time'],
                    'return_date' => $validated['return_date'],
                    'return_time' => $validated['return_time'],
                    'purpose' => trim((string) $validated['purpose']),
                    'notes' => isset($validated['notes']) ? trim((string) $validated['notes']) : null,
                    'updated_by' => $user->id,
                ]);

                $this->syncItineraries($trip, $validated['itineraries'] ?? []);
            });

            $trip->refresh()->load(['itineraries', 'approvals']);

            return response()->json([
                'success' => true,
                'message' => __('business_trip_messages.updated'),
                'data' => $this->transform($trip),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Perdin] Update error', [
                'message' => $e->getMessage(),
                'trip_id' => $trip->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.update_failed'),
                'data' => null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Hapus
    |--------------------------------------------------------------------------
    */
    public function destroy(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('business_trip.delete')) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.forbidden_delete'),
                'data' => null,
            ], 403);
        }

        $trip = $this->findVisible($publicId, $user);

        if (!$trip) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.not_found'),
                'data' => null,
            ], 404);
        }

        /*
        | Hanya draft yang boleh dihapus. Dokumen yang pernah diajukan
        | meninggalkan jejak pada orang lain -- approver melihatnya di
        | daftarnya, notifikasi sudah terkirim -- dan jejak itu tidak ikut
        | hilang saat barisnya dihapus.
        */
        if (strtoupper((string) $trip->status) !== BusinessTrip::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.delete_only_draft'),
                'data' => null,
            ], 422);
        }

        try {
            DB::transaction(function () use ($trip): void {
                /* Rundown-nya ikut dihapus; ia tidak punya arti tanpa induknya. */
                $trip->itineraries()->delete();
                $trip->delete();
            });

            return response()->json([
                'success' => true,
                'message' => __('business_trip_messages.deleted'),
                'data' => null,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Perdin] Destroy error', [
                'message' => $e->getMessage(),
                'trip_id' => $trip->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.delete_failed'),
                'data' => null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Ajukan
    |--------------------------------------------------------------------------
    | Di sinilah dokumen berhenti menjadi milik pemohon sendiri: nomor resmi
    | dibentuk, susunan penyetuju dibekukan, dan orang lain mulai diberi tahu.
    |--------------------------------------------------------------------------
    */
    public function submit(
        string $publicId,
        Request $request,
        BusinessTripNumberService $numberService,
        BusinessTripApprovalGeneratorService $approvalGenerator,
        BusinessTripNotificationService $notificationService,
        BusinessTripMailService $mailService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission('business_trip.submit')) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.forbidden_submit'),
            ], 403);
        }

        DB::beginTransaction();

        try {
            $trip = $this->lockVisible($publicId, $user);

            if (!$trip) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.not_found'),
                ], 404);
            }

            if (!in_array(
                strtoupper((string) $trip->status),
                [BusinessTrip::STATUS_DRAFT, BusinessTrip::STATUS_REJECTED],
                true,
            )) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.submit_only_draft'),
                ], 422);
            }

            if ($trip->itineraries()->count() === 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.submit_itinerary_missing'),
                ], 422);
            }

            /*
            | Tanda tangan pemohon disyaratkan di sini, bukan saat menyimpan
            | draft: draft memang belum menyatakan apa-apa.
            */
            if (blank($user->signature_path)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.submit_signature_missing'),
                ], 422);
            }

            /*
            | Pengajuan ulang setelah ditolak memakai nomor yang sama: dokumen
            | yang sama, bukan dokumen baru.
            */
            if (str_starts_with((string) $trip->trip_number, 'DRAFT/')) {
                $trip->trip_number = $numberService->generateFinalNumber($trip->branch);
            }

            /*
            | Baris approval lama dibersihkan sebelum digenerate ulang --
            | tanpa ini, pengajuan ulang ditolak generatornya karena dianggap
            | sudah pernah dibuat.
            */
            BusinessTripApproval::where('business_trip_id', $trip->id)->delete();

            $approvalGenerator->generate($trip);

            $submittedAt = now();

            $trip->status = BusinessTrip::STATUS_IN_PROGRESS;
            $trip->submitted_by = $user->id;
            $trip->submitted_at = $submittedAt;

            $trip->requester_signed_by = $user->id;
            $trip->requester_signature_path = $user->signature_path;
            $trip->requester_signed_at = $submittedAt;

            /* Penolakan sebelumnya dihapus jejaknya; dokumennya berjalan lagi. */
            $trip->rejected_by = null;
            $trip->rejected_at = null;
            $trip->rejection_notes = null;

            $trip->save();

            DB::commit();

            $trip->refresh();

            /*
            | Notifikasi dan email dipisah supaya kegagalan salah satu tidak
            | ikut membatalkan yang lain -- dan keduanya di luar transaksi,
            | supaya kegagalannya tidak membatalkan pengajuannya.
            */
            try {
                $notificationService->notifyApprovalRequest($trip);
            } catch (\Throwable $e) {
                Log::error('[Perdin] Notifikasi approver gagal dibuat', [
                    'business_trip_id' => $trip->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $mailService->sendApprovalRequest($trip);
            } catch (\Throwable $e) {
                Log::error('[Perdin] Email approver gagal dikirim', [
                    'business_trip_id' => $trip->id,
                    'message' => $e->getMessage(),
                ]);
            }

            $trip->load(['itineraries', 'approvals']);

            return response()->json([
                'success' => true,
                'message' => __('business_trip_messages.submitted'),
                'data' => $this->transform($trip),
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('business_trip_messages.submit_failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Perdin] Submit error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.submit_failed'),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Setujui
    |--------------------------------------------------------------------------
    | Wewenangnya TIDAK datang dari permission, melainkan dari baris approval:
    | yang boleh menyetujui hanyalah orang yang memang tercantum pada langkah
    | yang sedang aktif. Permission approve akan memberi hak menyetujui apa
    | saja kepada siapa saja yang memilikinya -- justru kebalikan dari gunanya
    | approval flow.
    |--------------------------------------------------------------------------
    */
    public function approve(
        string $publicId,
        Request $request,
        BusinessTripApprovalService $approvalService,
        BusinessTripNotificationService $notificationService,
        BusinessTripMailService $mailService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.user_not_authenticated'),
            ], 401);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::beginTransaction();

        try {
            $trip = $this->lockVisible($publicId, $user);

            if (!$trip) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.not_found'),
                ], 404);
            }

            if (strtoupper((string) $trip->status) !== BusinessTrip::STATUS_IN_PROGRESS) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.approve_not_in_progress'),
                ], 422);
            }

            $hasil = $approvalService->approveCurrentStep(
                $trip,
                $user,
                $validated['notes'] ?? null,
            );

            if ($hasil['is_final_approved'] ?? false) {
                $approvalService->markTripApproved($trip, $user);
            }

            DB::commit();

            $trip->refresh();

            $adaLagi = (bool) ($hasil['has_pending_approval'] ?? false);

            try {
                $notificationService->notifyApprovalStep($trip, $user, $hasil['approval'], $adaLagi);

                /* Langkah berikutnya perlu diberi tahu giliran sudah sampai padanya. */
                if ($adaLagi) {
                    $notificationService->notifyApprovalRequest($trip);
                }
            } catch (\Throwable $e) {
                Log::error('[Perdin] Notifikasi approval gagal dibuat', [
                    'business_trip_id' => $trip->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $mailService->sendApprovalStep($trip, $user, $adaLagi);

                if ($adaLagi) {
                    $mailService->sendApprovalRequest($trip);
                }
            } catch (\Throwable $e) {
                Log::error('[Perdin] Email approval gagal dikirim', [
                    'business_trip_id' => $trip->id,
                    'message' => $e->getMessage(),
                ]);
            }

            $trip->load(['itineraries', 'approvals']);

            return response()->json([
                'success' => true,
                'message' => __('business_trip_messages.approved'),
                'data' => $this->transform($trip),
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('business_trip_messages.approve_failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Perdin] Approve error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.approve_failed'),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Tolak
    |--------------------------------------------------------------------------
    | Dokumen yang ditolak kembali bisa disunting dan diajukan ulang, dengan
    | nomor yang sama. Ia dokumen yang sama, bukan dokumen baru.
    |--------------------------------------------------------------------------
    */
    public function reject(
        string $publicId,
        Request $request,
        BusinessTripApprovalService $approvalService,
        BusinessTripNotificationService $notificationService,
        BusinessTripMailService $mailService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.user_not_authenticated'),
            ], 401);
        }

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:1000'],
        ]);

        DB::beginTransaction();

        try {
            $trip = $this->lockVisible($publicId, $user);

            if (!$trip) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.not_found'),
                ], 404);
            }

            if (strtoupper((string) $trip->status) !== BusinessTrip::STATUS_IN_PROGRESS) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.approve_not_in_progress'),
                ], 422);
            }

            $approvalService->rejectCurrentStep($trip, $user, $validated['notes']);
            $approvalService->markTripRejected($trip, $user, $validated['notes']);

            DB::commit();

            $trip->refresh();

            try {
                $notificationService->notifyRejected($trip, $user, $validated['notes']);
            } catch (\Throwable $e) {
                Log::error('[Perdin] Notifikasi penolakan gagal dibuat', [
                    'business_trip_id' => $trip->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $mailService->sendRejected($trip, $user, $validated['notes']);
            } catch (\Throwable $e) {
                Log::error('[Perdin] Email penolakan gagal dikirim', [
                    'business_trip_id' => $trip->id,
                    'message' => $e->getMessage(),
                ]);
            }

            $trip->load(['itineraries', 'approvals']);

            return response()->json([
                'success' => true,
                'message' => __('business_trip_messages.rejected'),
                'data' => $this->transform($trip),
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('business_trip_messages.reject_failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Perdin] Reject error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.reject_failed'),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Batalkan
    |--------------------------------------------------------------------------
    | Dipakai pemohon yang perjalanannya urung. Berbeda dari hapus: dokumennya
    | tetap ada, hanya berhenti berjalan -- karena approver sudah terlanjur
    | melihatnya.
    |--------------------------------------------------------------------------
    */
    public function cancel(
        string $publicId,
        Request $request,
        BusinessTripApprovalService $approvalService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission('business_trip.cancel')) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.forbidden_cancel'),
            ], 403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::beginTransaction();

        try {
            $trip = $this->lockVisible($publicId, $user);

            if (!$trip) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.not_found'),
                ], 404);
            }

            if (!in_array(
                strtoupper((string) $trip->status),
                [BusinessTrip::STATUS_DRAFT, BusinessTrip::STATUS_IN_PROGRESS],
                true,
            )) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.cancel_not_allowed'),
                ], 422);
            }

            /* Langkah yang belum terpakai ikut dimatikan supaya tidak menggantung. */
            BusinessTripApproval::where('business_trip_id', $trip->id)
                ->whereIn('status', [
                    BusinessTripApproval::STATUS_WAITING,
                    BusinessTripApproval::STATUS_PENDING,
                ])
                ->update([
                    'status' => BusinessTripApproval::STATUS_CANCELLED,
                    'notes' => 'Cancelled karena Perdin dibatalkan.',
                    'updated_at' => now(),
                ]);

            $trip->update([
                'status' => BusinessTrip::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();

            $trip->refresh()->load(['itineraries', 'approvals']);

            return response()->json([
                'success' => true,
                'message' => __('business_trip_messages.cancelled'),
                'data' => $this->transform($trip),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Perdin] Cancel error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.cancel_failed'),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cetak
    |--------------------------------------------------------------------------
    */

    /**
     * Tautan cetak berumur sepuluh menit.
     *
     * Dua syarat diperiksa DI SINI, bukan hanya saat PDF-nya dibuat: tautan
     * yang terlanjur diberikan akan disimpan orang, dan syarat yang hanya
     * diperiksa di ujung berarti tautan lama tetap bisa dipakai setelah
     * keadaannya berubah.
     */
    public function generatePrintUrl(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('business_trip.print')) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.forbidden_print'),
            ], 403);
        }

        try {
            $trip = $this->findVisible($publicId, $user);

            if (!$trip) {
                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.not_found'),
                ], 404);
            }

            if (strtoupper((string) $trip->status) !== BusinessTrip::STATUS_APPROVED) {
                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.print_not_approved'),
                ], 422);
            }

            $relativeUrl = URL::temporarySignedRoute(
                'business-trip.perdin.print-signed',
                now()->addMinutes(10),
                ['publicId' => $publicId],
                false,
            );

            return response()->json([
                'success' => true,
                'url' => rtrim(config('app.url'), '/') . $relativeUrl,
            ])->header('Content-Type', 'application/json; charset=UTF-8');
        } catch (DecryptException | ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.not_found'),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[Perdin] Generate print URL error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.print_failed'),
            ], 500);
        }
    }

    /**
     * PDF-nya sendiri, dilayani lewat tautan bertanda tangan.
     *
     * Syaratnya diperiksa ulang di sini. Tautan bertanda tangan membuktikan
     * tautannya sah dan belum kedaluwarsa -- ia tidak membuktikan dokumennya
     * masih layak dicetak.
     */
    public function printSigned(string $publicId, Request $request)
    {
        try {
            $id = (int) Crypt::decryptString($publicId);

            $trip = BusinessTrip::with(['itineraries', 'approvals', 'branchData'])
                ->findOrFail($id);

            if (strtoupper((string) $trip->status) !== BusinessTrip::STATUS_APPROVED) {
                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.print_not_approved'),
                ], 422);
            }

            $pdf = Pdf::loadView('pdf.business-trip', [
                'trip' => $trip,
                'requester' => $this->buildRequesterSigner($trip),
                'approvers' => $this->buildApproverSigners($trip),
            ])->setPaper('a4', 'portrait');

            $nama = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $trip->trip_number), '-');

            $response = response()->make($pdf->output(), 200);

            $response->headers->set('Content-Type', 'application/pdf');

            /* inline supaya PDF terbuka langsung di tab, bukan terunduh. */
            $response->headers->set(
                'Content-Disposition',
                'inline; filename="' . ($nama !== '' ? $nama : 'PERDIN-' . $trip->id) . '.pdf"',
            );

            return $response;
        } catch (DecryptException | ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.not_found'),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[Perdin] Print error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.print_failed'),
            ], 500);
        }
    }

    /**
     * Penanda tangan pemohon, dibekukan saat mengajukan.
     */
    private function buildRequesterSigner(BusinessTrip $trip): object
    {
        return (object) [
            'name' => $trip->employee_name ?: '-',
            'signature_file' => $this->signatureFile($trip->requester_signature_path),
            'signed_at' => $trip->requester_signed_at ?? $trip->submitted_at,
        ];
    }

    /**
     * Penyetuju yang benar-benar sudah menyetujui, berurutan menurut langkahnya.
     *
     * Yang belum menyetujui tidak dicetak: kolom tanda tangan kosong pada
     * dokumen yang sudah sah justru menimbulkan pertanyaan yang tidak perlu.
     */
    private function buildApproverSigners(BusinessTrip $trip)
    {
        return $trip->approvals
            ->filter(
                fn (BusinessTripApproval $a): bool => strtoupper(trim((string) $a->status))
                    === BusinessTripApproval::STATUS_APPROVED,
            )
            ->sortBy(
                fn (BusinessTripApproval $a): string => sprintf(
                    '%010d-%010d',
                    (int) $a->step_order,
                    (int) $a->id,
                ),
            )
            ->map(fn (BusinessTripApproval $a): object => (object) [
                'label' => $a->label ?: 'Penyetuju',
                'name' => $a->approver_name_snapshot ?? '-',
                'signature_file' => $this->signatureFile($a->signature_path),
                'signed_at' => $a->approved_at ?? $a->signed_at,
            ])
            ->values();
    }

    private function signatureFile(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return storage_path('app/public/' . ltrim($path, '/'));
    }
    /*
    |==========================================================================
    | Bagian dalam
    |==========================================================================
    */

    /**
     * Dokumen yang boleh dilihat akun ini, dikunci untuk diubah.
     *
     * Dikunci karena dua orang bisa menekan Setujui bersamaan pada langkah
     * yang sama.
     */
    private function lockVisible(string $publicId, $user): ?BusinessTrip
    {
        try {
            $id = (int) Crypt::decryptString($publicId);
        } catch (\Throwable $e) {
            return null;
        }

        $query = BusinessTrip::query()->whereKey($id)->lockForUpdate();

        $this->applyVisibilityScope($query, $user, $this->resolveViewContext($user));

        return $query->first();
    }

    /**
     * Aturan yang sama dipakai saat menyimpan dan mengubah.
     *
     * Identitas pemohon sengaja TIDAK ada di sini -- ia tidak diterima dari
     * layar sama sekali.
     */
    private function rules(): array
    {
        return [
            'destination' => ['required', 'string', 'max:255'],

            /*
            | Tidak boleh mundur ke belakang. Izin perjalanan mendahului
            | perjalanannya, bukan menyusul -- dan perdin yang berangkatnya
            | sudah lewat tidak ada lagi yang bisa diizinkan.
            |
            | Ditegakkan di sini juga, bukan hanya di kalender: batas yang hanya
            | ada di layar bukan batas.
            */
            'depart_date' => ['required', 'date', 'after_or_equal:today'],
            'depart_time' => ['required', 'date_format:H:i'],

            /* Pulang tidak boleh mendahului berangkat. */
            'return_date' => ['required', 'date', 'after_or_equal:depart_date'],
            'return_time' => ['required', 'date_format:H:i'],

            'purpose' => ['required', 'string'],
            'notes' => ['nullable', 'string'],

            'itineraries' => ['required', 'array', 'min:1'],
            /*
            | Tanggal rundown harus berada DI DALAM periode perjalanannya.
            |
            | Aturannya menunjuk ke medan lain pada kiriman yang sama, jadi ia
            | ikut berpindah kalau periodenya diubah -- tidak ada angka tetap
            | yang bisa tertinggal.
            |
            | Ditegakkan DI SINI, bukan hanya di layar: batas yang hanya ada di
            | browser bukan batas -- siapa pun yang bisa menyusun permintaan
            | sendiri melewatinya tanpa usaha.
            */
            'itineraries.*.date' => [
                'required',
                'date',
                'after_or_equal:depart_date',
                'before_or_equal:return_date',
            ],
            'itineraries.*.time_start' => ['required', 'date_format:H:i'],
            'itineraries.*.time_end' => ['nullable', 'date_format:H:i'],
            'itineraries.*.timezone' => ['required', Rule::in(BusinessTripItinerary::TIMEZONES)],
            'itineraries.*.description' => ['required', 'string'],
            'itineraries.*.pic' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * Menulis ulang seluruh rundown.
     *
     * Dihapus lalu ditulis ulang, bukan dicocokkan baris per baris: barisnya
     * tidak punya identitas yang berarti bagi pengguna -- ia hanya urutan --
     * sehingga mencocokkan id justru memperumit tanpa menambah apa pun.
     */
    private function syncItineraries(BusinessTrip $trip, array $baris): void
    {
        $trip->itineraries()->forceDelete();

        $urut = 1;

        foreach ($baris as $b) {
            BusinessTripItinerary::create([
                'business_trip_id' => $trip->id,
                'sort_no' => $urut++,
                'date' => $b['date'],
                'time_start' => $b['time_start'],
                'time_end' => $b['time_end'] ?? null,
                'timezone' => $b['timezone'],
                'description' => trim((string) $b['description']),
                'pic' => isset($b['pic']) ? trim((string) $b['pic']) : null,
            ]);
        }
    }

    /**
     * Nama, department, jabatan, dan cabang milik akun yang sedang login.
     *
     * Jabatan diambil dari nama role -- di aplikasi ini role memang berisi
     * jabatan ("Supervisor Procurement", "Finance Spv"), bukan peran teknis.
     */
    private function resolveEmployeeIdentity($user): array
    {
        $baris = DB::table('users as u')
            ->leftJoin('departments as d', 'd.id', '=', 'u.departemen_id')
            ->leftJoin('cabang as c', 'c.id', '=', 'u.cabang_id')
            ->where('u.id', $user->id)
            ->first([
                'u.name',
                'u.departemen_id',
                'u.cabang_id',
                'u.id_role',
                'd.nama as department_name',
                'c.nama_cabang as branch_name',
            ]);

        return [
            'employee_name' => (string) ($baris->name ?? $user->name),
            'department_id' => $baris && $baris->departemen_id ? (int) $baris->departemen_id : null,
            'department_name' => $baris->department_name ?? null,
            'position_name' => $this->resolvePositionName($user->id, $baris->id_role ?? null),
            'branch' => $baris && $baris->cabang_id ? (string) $baris->cabang_id : null,
            'branch_name' => $baris->branch_name ?? null,
        ];
    }

    /**
     * Nama jabatan seseorang, dibaca dari tempat yang benar-benar dipakai.
     *
     * user_roles LEBIH DULU, users.id_role sebagai cadangan.
     *
     * Urutannya penting. Kolom users.id_role adalah peninggalan struktur lama:
     * sebagian besar akun mengisinya NULL, dan peran yang sesungguhnya ada di
     * user_roles. Membaca kolom lamanya lebih dulu membuat kolom Jabatan kosong
     * untuk hampir semua orang -- termasuk akun yang jelas menampilkan
     * jabatannya di menu profil.
     *
     * Satu akun bisa memegang lebih dari satu role; yang pertama dipakai,
     * sama seperti getActiveRoleId() milik User.
     */
    private function resolvePositionName(int $userId, $legacyRoleId): ?string
    {
        $roleId = DB::table('user_roles')
            ->where('user_id', $userId)
            ->value('role_id')
            ?? $legacyRoleId;

        if (!$roleId) {
            return null;
        }

        return DB::table('roles')->where('id', (int) $roleId)->value('nama');
    }

    private function findVisible(string $publicId, $user): ?BusinessTrip
    {
        try {
            $id = (int) Crypt::decryptString($publicId);
        } catch (\Throwable $e) {
            return null;
        }

        $query = BusinessTrip::query()->whereKey($id);

        $this->applyVisibilityScope($query, $user, $this->resolveViewContext($user));

        return $query->first();
    }

    /**
     * Cakupan data yang boleh dilihat akun ini.
     *
     * Bentuknya ditiru dari modul pengajuan dana, dikurangi jalur approver --
     * perdin belum punya persetujuan, jadi belum ada orang yang perlu melihat
     * dokumen di luar cakupannya.
     */
    private function resolveViewContext($user): array
    {
        $scope = strtoupper(
            trim((string) ($user->getPermissionScope('business_trip.view') ?? 'NONE')),
        );

        if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
            $scope = 'NONE';
        }

        $penugasan = DB::table('user_access_assignments')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get(['branch_id', 'department_id']);

        $branchIds = collect([(int) ($user->cabang_id ?? 0)])
            ->merge($penugasan->pluck('branch_id')->map(fn ($id): int => (int) $id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $departmentIds = collect([(int) ($user->departemen_id ?? 0)])
            ->merge($penugasan->pluck('department_id')->map(fn ($id): int => (int) $id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        /*
        | Role dipakai menjawab "apakah saya penyetujunya" -- sebuah langkah
        | boleh menunjuk jabatan, bukan hanya orang.
        */
        $roleIds = collect([$user->id_role ?? null])
            ->merge(
                DB::table('user_roles')->where('user_id', $user->id)->pluck('role_id'),
            )
            ->filter(fn ($id): bool => $id !== null && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        return [
            'scope' => $scope,
            'branchIds' => $branchIds,
            'departmentIds' => $departmentIds,
            'roleIds' => $roleIds,
        ];
    }

    private function applyVisibilityScope($query, $user, array $context): void
    {
        $scope = $context['scope'];

        if ($scope === 'ALL') {
            return;
        }

        /*
        | Cakupan biasa, ATAU tercantum sebagai penyetuju.
        |
        | Penyetuju sering berada di luar cabang dan department pemohon --
        | itulah gunanya matriks approval. Tanpa jalur kedua ini, dokumennya
        | tidak terlihat oleh orang yang justru diminta menandatanganinya, dan
        | ia berhenti di tengah tanpa penjelasan: yang menekan Setujui hanya
        | melihat "tidak ditemukan".
        |
        | Bentuknya sama dengan FPU dan Claim.
        */
        $query->where(function ($boleh) use ($scope, $user, $context): void {
            $boleh->where(function ($cakupan) use ($scope, $user, $context): void {
                if ($scope === 'OWN_DATA') {
                    $cakupan->where('business_trips.user_id', $user->id);

                    return;
                }

                if ($scope === 'OWN_DEPARTMENT') {
                    $context['departmentIds']->isEmpty()
                        ? $cakupan->whereRaw('1 = 0')
                        : $cakupan->whereIn(
                            'business_trips.department_id',
                            $context['departmentIds']->all(),
                        );

                    return;
                }

                if ($scope === 'OWN_CABANG') {
                    $context['branchIds']->isEmpty()
                        ? $cakupan->whereRaw('1 = 0')
                        : $cakupan->whereIn(
                            'business_trips.branch',
                            $context['branchIds']->map(fn (int $id): string => (string) $id)->all(),
                        );

                    return;
                }

                /* NONE atau cakupan yang tidak dikenal: tidak melihat apa pun. */
                $cakupan->whereRaw('1 = 0');
            });

            $boleh->orWhereHas('approvals', function ($approval) use ($user, $context): void {
                $approval->where(function ($penyetuju) use ($user, $context): void {
                    $penyetuju->where(function ($perOrang) use ($user): void {
                        $perOrang
                            ->where('approver_type', BusinessTripApproval::APPROVER_TYPE_USER)
                            ->where('approver_id', $user->id);
                    });

                    if ($context['roleIds']->isNotEmpty()) {
                        $penyetuju->orWhere(function ($perRole) use ($context): void {
                            $perRole
                                ->where('approver_type', BusinessTripApproval::APPROVER_TYPE_ROLE)
                                ->whereIn('approver_id', $context['roleIds']->all());
                        });
                    }
                });
            });
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Export Excel
    |--------------------------------------------------------------------------
    | Isinya persis daftar yang sedang tampil, termasuk saringannya -- lengkap
    | dengan rundown tiap perjalanan, yang di layar hanya terlihat setelah
    | rinciannya dibuka satu per satu.
    |--------------------------------------------------------------------------
    */
    public function exportExcel(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.user_not_authenticated'),
                ], 401);
            }

            $lang = strtolower(trim((string) $request->query('lang', 'id')));

            if (!in_array($lang, ['id', 'en'], true)) {
                $lang = 'id';
            }

            app()->setLocale($lang);

            /*
            | Permission export berdiri sendiri dan tidak memakai scope. Yang
            | menentukan ISI file adalah visibility view yang sama persis dengan
            | daftar; permission ini hanya menjawab "boleh menarik data keluar
            | atau tidak", bukan "boleh melihat data siapa".
            */
            if (!$user->hasPermission('business_trip.export')) {
                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.export.forbidden'),
                ], 403);
            }

            $context = $this->resolveViewContext($user);

            /*
            | Scope NONE berarti tidak ada satu pun perdin yang terlihat olehnya,
            | sehingga export pun tidak akan ada isinya.
            */
            if ($context['scope'] === 'NONE') {
                return response()->json([
                    'success' => false,
                    'message' => __('business_trip_messages.export.forbidden'),
                ], 403);
            }

            $query = BusinessTrip::query()
                ->with([
                    'branchData',

                    /*
                    | FPU yang menumpang perdin ini -- satu baris file untuk
                    | masing-masing. Diurutkan dari yang terlama supaya riwayat
                    | pengajuannya terbaca berurutan bila sempat ditolak.
                    |
                    | Rundown tidak dimuat: ia tidak ikut ke dalam file.
                    */
                    'cashAdvances' => function ($fpu): void {
                        $fpu->orderBy('id');
                    },
                ]);

            $this->applyVisibilityScope($query, $user, $context);
            $this->applyListFilters($query, $request);

            $data = $query
                ->orderByDesc('business_trips.id')
                ->get();

            $fileName = __('business_trip_messages.export.filename')
                . '_' . now()->format('Ymd_His')
                . '.xlsx';

            return Excel::download(
                new BusinessTripExport($data),
                $fileName,
            );
        } catch (\Throwable $e) {
            Log::error('[Perdin] Export excel error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('business_trip_messages.export.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Saringan daftar, dipakai layar maupun export.
     *
     * Satu tempat untuk keduanya, bukan dua salinan: file yang isinya
     * berbeda dengan daftar yang sedang tampil membuat orang mengira salah
     * satunya bohong.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    private function applyListFilters($query, Request $request): void
    {
        /* Pencarian bebas: nomor, nama, tujuan, keperluan. */
        if ($request->filled('search')) {
            $kata = trim((string) $request->input('search'));

            $query->where(function ($cari) use ($kata): void {
                foreach (['trip_number', 'employee_name', 'destination', 'purpose'] as $kolom) {
                    $cari->orWhere($kolom, 'ILIKE', '%' . $kata . '%');
                }
            });
        }

        if ($request->filled('status')) {
            $query->where('status', strtoupper(trim((string) $request->input('status'))));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->input('department_id'));
        }

        if ($request->filled('branch')) {
            $query->where('branch', (string) $request->input('branch'));
        }

        /*
        | Saringan tanggal memakai periode perjalanannya, bukan tanggal
        | dokumen -- yang dicari orang adalah "siapa yang sedang di luar kota
        | minggu ini", bukan "dokumen mana yang diketik minggu ini".
        */
        if ($request->filled('depart_date_from')) {
            $query->whereDate('depart_date', '>=', $request->input('depart_date_from'));
        }

        if ($request->filled('depart_date_to')) {
            $query->whereDate('depart_date', '<=', $request->input('depart_date_to'));
        }
    }

    /**
     * Bentuk baris perdin untuk layar.
     *
     * Isinya tinggal di BusinessTripPresenter, karena detail FPU memakai
     * bentuk yang sama persis untuk modal rincian yang sama persis.
     *
     * @return array<string, mixed>
     */
    private function transform(BusinessTrip $trip, bool $canApprove = false): array
    {
        return BusinessTripPresenter::row($trip, $canApprove);
    }

    /**
     * Dokumen mana saja pada halaman ini yang menunggu tanda tangan akun ini.
     *
     * Satu kueri untuk seluruh halaman. Role ikut dihitung: sebuah langkah
     * boleh menunjuk jabatan, bukan hanya orang.
     *
     * @param  int[]  $tripIds
     * @return int[]
     */
    private function tripIdsWaitingFor($user, array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $roleIds = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->pluck('role_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($user->id_role) {
            $roleIds[] = (int) $user->id_role;
        }

        return BusinessTripApproval::query()
            ->whereIn('business_trip_id', $tripIds)
            ->where('status', BusinessTripApproval::STATUS_WAITING)
            ->where(function ($q) use ($user, $roleIds): void {
                $q->where(function ($byUser) use ($user): void {
                    $byUser
                        ->where('approver_type', BusinessTripApproval::APPROVER_TYPE_USER)
                        ->where('approver_id', $user->id);
                });

                if ($roleIds !== []) {
                    $q->orWhere(function ($byRole) use ($roleIds): void {
                        $byRole
                            ->where('approver_type', BusinessTripApproval::APPROVER_TYPE_ROLE)
                            ->whereIn('approver_id', $roleIds);
                    });
                }
            })
            ->pluck('business_trip_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
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

    private function emptyAbilities(): array
    {
        return [
            'can_view' => false,
            'view_scope' => 'NONE',
            'can_create' => false,
            'can_update' => false,
            'can_delete' => false,
            'can_submit' => false,
            'can_cancel' => false,
            'can_print' => false,
            'can_export' => false,
        ];
    }
}
