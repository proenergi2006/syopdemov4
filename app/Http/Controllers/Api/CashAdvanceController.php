<?php

namespace App\Http\Controllers\Api;

use App\Exports\CashAdvanceExport;
use App\Exceptions\BulkDocumentActionException;
use App\Http\Controllers\Concerns\ProcessesBulkDocumentAction;
use App\Http\Controllers\Concerns\SchedulesPayment;
use App\Http\Controllers\Controller;
use App\Models\BusinessTrip;
use App\Models\BusinessTripApproval;
use App\Models\CashAdvance;
use App\Models\CashAdvanceApproval;
use App\Models\CashAdvanceAttachment;
use App\Models\CashAdvanceItem;
use App\Models\CashAdvanceRealization;
use App\Services\FundRequest\FundRequestLimitService;
use App\Services\BusinessTrip\BusinessTripPresenter;
use App\Services\FundRequest\CashAdvance\CashAdvanceApprovalGeneratorService;
use App\Services\FundRequest\CashAdvance\CashAdvanceApprovalService;
use App\Services\FundRequest\CashAdvance\CashAdvanceMailService;
use App\Services\FundRequest\CashAdvance\CashAdvanceNotificationService;
use App\Services\FundRequest\CashAdvance\CashAdvanceNumberService;
use App\Services\FundRequest\RupiahWords;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/*
|--------------------------------------------------------------------------
| FPU -- Form Pengajuan Uang (Cash Advance)
|--------------------------------------------------------------------------
| Modul Pengajuan Dana. Alur dokumen:
|
|   DRAFT -> IN PROGRESS -> APPROVED -> DISBURSED
|                        \-> REJECTED
|             (APPROVED) \-> CANCELLED
|
| Pertanggungjawaban dana dicatat pada dokumen Realisasi terpisah, yang hanya
| boleh dibuat setelah FPU berstatus DISBURSED.
|--------------------------------------------------------------------------
*/
class CashAdvanceController extends Controller
{
    use ProcessesBulkDocumentAction;
    use SchedulesPayment;

    private const ALLOWED_SCOPES = [
        'NONE',
        'OWN_DATA',
        'OWN_DEPARTMENT',
        'OWN_CABANG',
        'ALL',
    ];

    private const ATTACHMENT_MIMES = 'jpg,jpeg,png,pdf';

    private const ATTACHMENT_MAX_KB = 3000;

    /*
    |--------------------------------------------------------------------------
    | Daftar FPU
    |--------------------------------------------------------------------------
    */
    public function index(
        Request $request,
        CashAdvanceApprovalService $approvalService,
    ): JsonResponse {
        $user = $request->user();

        $perPage = (int) $request->input('per_page', 10);
        $perPage = $perPage > 0 ? $perPage : 10;

        try {
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.user_not_authenticated'),
                    'data' => [],
                    'meta' => $this->emptyMeta($perPage),
                    'abilities' => $this->emptyAbilities(),
                ], 401);
            }

            $context = $this->resolveViewContext($user);

            $canSubmit = $user->hasPermission('cash_advance.submit');

            $abilities = [
                'can_view' => $user->hasPermission('cash_advance.view'),
                'view_scope' => $context['scope'],
                'can_create' => $user->hasPermission('cash_advance.create'),
                'can_update' => $user->hasPermission('cash_advance.update'),
                'can_submit' => $canSubmit,
                'can_delete' => $user->hasPermission('cash_advance.delete'),
                'can_cancel' => $user->hasPermission('cash_advance.cancel'),
                'can_receive' => $user->hasPermission('cash_advance.receive'),
                'can_disburse' => $user->hasPermission('cash_advance.disburse'),

                /*
                | Menentukan tampil-tidaknya pintasan "Buat Realisasi" pada
                | daftar. Permission-nya milik modul Realisasi, bukan FPU.
                */
                'can_create_realization' => $user->hasPermission(
                    'cash_advance_realization.create',
                ),
            ];

            $query = CashAdvance::query()
                ->with([
                    'branchData',
                    'departmentData',
                    'transactionCategory',
                    'items',
                    'approvals' => function ($approvalQuery) {
                        $approvalQuery
                            ->orderBy('step_order')
                            ->orderBy('id');
                    },
                ]);

            $this->applyVisibilityScope(
                $query,
                $user,
                $context['scope'],
                $context['userRoleIds'],
                $context['branchIds'],
                $context['departmentIds'],
            );

            /*
            | Filter "menunggu approval saya" harus diterapkan pada query, bukan
            | setelah paginate, agar jumlah halaman tetap benar.
            */
            if ($request->boolean('waiting_my_approval')) {
                $query
                    ->where('cash_advances.status', CashAdvance::STATUS_IN_PROGRESS)
                    ->whereHas('approvals', function ($approvalQuery) use ($context, $user) {
                        $approvalQuery->where(
                            'cash_advance_approvals.status',
                            CashAdvanceApproval::STATUS_WAITING,
                        );

                        $this->applyApproverMatch($approvalQuery, $user, $context['userRoleIds']);
                    });
            }

            $this->applyListFilters($query, $request);

            /*
            | Dokumen yang menunggu approval user login selalu tampil di atas.
            | Hanya memengaruhi urutan -- scope, filter, dan isi response tidak
            | terpengaruh.
            */
            $query->withExists([
                'approvals as is_waiting_my_approval' => function ($approvalQuery) use (
                    $context,
                    $user,
                ) {
                    $approvalQuery
                        ->whereRaw('cash_advances.status = ?', [CashAdvance::STATUS_IN_PROGRESS])
                        ->where(
                            'cash_advance_approvals.status',
                            CashAdvanceApproval::STATUS_WAITING,
                        );

                    $this->applyApproverMatch($approvalQuery, $user, $context['userRoleIds']);
                },

                /*
                | Satu subquery, bukan pemuatan relasi -- daftar hanya perlu
                | tahu ada atau tidaknya, bukan isi dokumen realisasinya.
                */
                'realization as has_realization',

                /*
                | Realisasi yang masih hidup menahan tombol Batalkan. Yang sudah
                | ditolak atau dibatalkan tidak dihitung.
                */
                'realization as has_active_realization' => function ($realizationQuery) {
                    $realizationQuery->whereNotIn(
                        'cash_advance_realizations.status',
                        CashAdvanceRealization::RELEASED_STATUSES,
                    );
                },
            ]);

            $records = $query
                ->orderByDesc('is_waiting_my_approval')
                ->orderByDesc('cash_advances.id')
                ->paginate($perPage);

            /*
            | Persetujuan perdin yang PERNAH DIBERIKAN si pembuka, untuk seluruh
            | dokumen di halaman ini sekaligus. Ditanyakan per baris berarti
            | kueri yang berlipat mengikuti jumlah baris -- dan daftar ini
            | dibuka berulang kali.
            */
            $perdinSaya = $this->myBusinessTripApprovals(
                $user,
                collect($records->items())
                    ->pluck('business_trip_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->all(),
            );

            $records->through(
                function (CashAdvance $cashAdvance) use (
                    $user,
                    $approvalService,
                    $canSubmit,
                    $perdinSaya,
                ): array {
                    $status = strtoupper(trim((string) $cashAdvance->status));

                    $isCreator = (int) $cashAdvance->created_by === (int) $user->id;

                    $isSameDepartment = (
                        $user->departemen_id !== null
                        && (int) $cashAdvance->department_id === (int) $user->departemen_id
                    );

                    $rowCanSubmit = (
                        $canSubmit
                        && $status === CashAdvance::STATUS_DRAFT
                        && ($isCreator || $isSameDepartment)
                    );

                    $currentApproval = $cashAdvance->approvals->first(
                        fn(CashAdvanceApproval $approval): bool =>
                        strtoupper((string) $approval->status) === CashAdvanceApproval::STATUS_WAITING
                            && $approvalService->userCanApprove($approval, $user),
                    );

                    return [
                        'id' => $cashAdvance->id,
                        'public_id' => $cashAdvance->encrypted_id,
                        'advance_number' => $cashAdvance->advance_number,

                        /*
                        | Konteks perdin: memberi tahu penyetuju bahwa ia sudah
                        | menyetujui perjalanannya, supaya tidak perlu
                        | mencari-cari sebelum memutuskan. BUKAN untuk melewati
                        | persetujuannya.
                        */
                        'business_trip' => $cashAdvance->businessTrip ? [
                            'trip_number' => $cashAdvance->businessTrip->trip_number,
                            'destination' => $cashAdvance->businessTrip->destination,
                            'depart_date' => optional($cashAdvance->businessTrip->depart_date)->toDateString(),
                            'return_date' => optional($cashAdvance->businessTrip->return_date)->toDateString(),
                            'status' => $cashAdvance->businessTrip->status,
                            'my_approval' => $perdinSaya[(int) $cashAdvance->business_trip_id] ?? null,
                        ] : null,
                        'date' => optional($cashAdvance->date)->toDateString(),
                        'subject' => $cashAdvance->subject,

                        'transaction_category_id' => $cashAdvance->transaction_category_id,
                        'transaction_category' => $cashAdvance->transactionCategory?->name,
                        'request_type' => $cashAdvance->request_type,

                        'branch' => $cashAdvance->branchData?->nama_cabang ?? '-',
                        'branch_id' => $cashAdvance->branch,

                        'department' => $cashAdvance->departmentData?->kode ?? '-',
                        'department_name' => $cashAdvance->departmentData?->nama ?? '-',
                        'department_id' => $cashAdvance->department_id,

                        'total_amount' => (float) $cashAdvance->total_amount,
                        'notes' => $cashAdvance->notes,
                        'status' => $cashAdvance->status,

                        'can_submit' => $rowCanSubmit,
                        'can_approve' => $currentApproval !== null,

                        'approval_id' => $currentApproval?->id,
                        'approval_step_order' => $currentApproval
                            ? (int) $currentApproval->step_order
                            : null,
                        'approval_label' => $currentApproval?->label,
                        'approval_mode' => $currentApproval?->approval_mode,

                        'item_count' => $cashAdvance->items->count(),

                        'created_at' => $cashAdvance->created_at,
                        'created_by' => $cashAdvance->created_by,

                        'submitted_at' => $cashAdvance->submitted_at,
                        'submitted_by' => $cashAdvance->submitted_by,

                        'received_at' => $cashAdvance->received_at,
                        'scheduled_payment_date' => $cashAdvance->scheduled_payment_date,
                        'can_pay_today' => $this->canPayToday(
                            $cashAdvance->scheduled_payment_date,
                            'FPU',
                            $cashAdvance->transaction_category_id,
                        ),
                        'payment_timing' => $this->paymentTiming(
                            $cashAdvance->scheduled_payment_date,
                            $cashAdvance->disbursed_at,
                        ),
                        'disbursed_at' => $cashAdvance->disbursed_at,

                        /*
                         * Dipakai daftar untuk menawarkan pintasan "Buat
                         * Realisasi" hanya pada FPU yang memang belum
                         * dipertanggungjawabkan.
                         */
                        'has_realization' => (bool) $cashAdvance->has_realization,

                        /*
                         * Menahan pilihan "Batalkan" selama realisasinya belum
                         * ditolak atau dibatalkan.
                         */
                        'has_active_realization' => (bool) $cashAdvance->has_active_realization,
                    ];
                },
            );

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_messages.index.loaded'),
                'data' => $records->items(),
                'meta' => [
                    'current_page' => $records->currentPage(),
                    'last_page' => $records->lastPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                ],
                'abilities' => $abilities,

                /*
                | Keadaan batas pengajuan milik pengguna ini. Dikirim supaya
                | ia diberi tahu sebelum mengisi formulir, bukan setelah
                | ditolak -- memakai angka yang sama persis dengan yang
                | dipakai penolakannya.
                */
                'submission_limit' => $this->ringkasBatasFpu(
                    app(FundRequestLimitService::class)->evaluate($user->id),
                ),

                /*
                | Hari kerja kasir, dikirim sekali untuk seluruh daftar -- dipakai
                | layar menjelaskan kenapa tombol bayarnya mati hari ini.
                */
                'payment_days_text' => $this->paymentDaysText('FPU'),

                /* Angkanya juga, supaya layar bisa merangkai namanya sendiri. */
                'payment_days' => $this->paymentDays('FPU'),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[FPU] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $user?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.index.load_failed'),
                'data' => [],
                'meta' => $this->emptyMeta($perPage),
                'abilities' => $this->emptyAbilities(),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Simpan FPU baru
    |--------------------------------------------------------------------------
    */
    public function store(
        Request $request,
        CashAdvanceNumberService $numberService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission('cash_advance.create')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.store.forbidden'),
            ], 403);
        }

        $storedPaths = [];

        DB::beginTransaction();

        try {
            $request->validate([
                'date' => ['required', 'date_format:Y-m-d'],
                'subject' => ['required', 'string', 'max:1000'],

                /*
                | Keterangan transaksi ikut menentukan approval flow, jadi
                | wajib diisi dan harus mengacu pada master yang aktif.
                */
                'transaction_category_id' => [
                    'required',
                    'integer',
                    'exists:fund_request_transaction_categories,id',
                ],

                /*
                | Dokumen Perdin yang mendasari FPU ini. Wajib atau tidaknya
                | ditentukan keterangan transaksinya -- lihat
                | resolveBusinessTripId() di bawah.
                */
                'business_trip_id' => ['nullable', 'integer'],

                // Rutin / Non Rutin, mengikuti pr_type pada PR.
                'request_type' => ['required', 'string', 'max:50', 'in:Rutin,Non Rutin'],
                'branch' => ['required'],
                'department_id' => ['required', 'integer'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'items' => ['required', 'string'],

                /*
                | Lampiran kini dikirim per baris rincian: line_attachments[i][].
                */
                'line_attachments.*.*' => [
                    'file',
                    'mimes:' . self::ATTACHMENT_MIMES,
                    'max:' . self::ATTACHMENT_MAX_KB,
                ],
            ]);

            $this->assertUserAccessAssignment(
                $user,
                $request->input('branch'),
                $request->input('department_id'),
            );

            $items = $this->decodeItems($request->input('items'));

            $totalAmount = round(
                array_sum(array_map(fn(array $item) => (float) $item['amount'], $items)),
                2,
            );

            $businessTripId = $this->resolveBusinessTripId($request, $user);

            $this->assertItemDatesWithinTrip($items, $businessTripId);

            $cashAdvance = CashAdvance::create([
                // Nomor final dibentuk saat submit, bukan saat draft dibuat.
                'advance_number' => $numberService->generateDraftNumber(),

                'business_trip_id' => $businessTripId,

                'date' => $request->input('date'),
                'subject' => $this->clean($request->input('subject')),

                'transaction_category_id'
                => (int) $request->input('transaction_category_id'),

                'request_type' => $request->input('request_type'),

                'branch' => (string) $request->input('branch'),
                'department_id' => (int) $request->input('department_id'),
                'total_amount' => $totalAmount,
                'notes' => $this->clean($request->input('notes')) ?: null,
                'status' => CashAdvance::STATUS_DRAFT,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $itemIdsByIndex = [];

            foreach ($items as $index => $item) {
                $row = CashAdvanceItem::create([
                    'cash_advance_id' => $cashAdvance->id,
                    'date' => $item['date'],
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                ]);

                $itemIdsByIndex[(int) $index] = (int) $row->id;
            }

            $this->assertEveryLineHasAttachment($request, $itemIdsByIndex, []);

            $storedPaths = $this->storeLineAttachments(
                $request,
                $cashAdvance,
                $itemIdsByIndex,
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_messages.store.success'),
                'data' => [
                    'id' => $cashAdvance->id,
                    'public_id' => $cashAdvance->encrypted_id,
                    'advance_number' => $cashAdvance->advance_number,
                ],
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('cash_advance_messages.store.invalid'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            Log::error('[FPU] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.store.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Detail FPU
    |--------------------------------------------------------------------------
    */
    public function show(
        string $publicId,
        Request $request,
        CashAdvanceApprovalService $approvalService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.user_not_authenticated'),
            ], 401);
        }

        try {
            $cashAdvance = $this->findVisibleCashAdvance($publicId, $user);

            if (!$cashAdvance) {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.show.not_found'),
                ], 404);
            }

            $cashAdvance->load([
                'branchData',
                'departmentData',
                'transactionCategory',

                /*
                | Perdin beserta rundown dan rantai persetujuannya: detail FPU
                | membuka modal rincian perdin yang sama persis dengan yang di
                | halaman perdin, dan modal itu menampilkan keduanya.
                */
                'businessTrip.itineraries',
                'businessTrip.approvals' => function ($tripApprovalQuery) {
                    $tripApprovalQuery->orderBy('step_order')->orderBy('id');
                },
                'items',
                'attachments',
                'creator:id,name',
                'submitter:id,name',
                'finalApprover:id,name',
                'rejecter:id,name',
                'canceller:id,name',
                'receiver:id,name',
                'disburser:id,name',
                'approvals' => function ($approvalQuery) {
                    $approvalQuery->orderBy('step_order')->orderBy('id');
                },
            ]);

            $currentApproval = $cashAdvance->approvals->first(
                fn(CashAdvanceApproval $approval): bool =>
                strtoupper((string) $approval->status) === CashAdvanceApproval::STATUS_WAITING
                    && $approvalService->userCanApprove($approval, $user),
            );

            /*
            | Persetujuan perdin yang PERNAH DIBERIKAN pembacanya sendiri.
            | Dipakai untuk mengatakan "Anda sudah menyetujui perjalanannya",
            | bukan untuk melewati persetujuan FPU-nya.
            */
            $perdinSaya = $this->myBusinessTripApprovals(
                $user,
                $cashAdvance->business_trip_id
                    ? [(int) $cashAdvance->business_trip_id]
                    : [],
            );

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_messages.show.loaded'),
                'data' => $this->transformDetail(
                    $cashAdvance,
                    $currentApproval,
                    $perdinSaya[(int) $cashAdvance->business_trip_id] ?? null,
                ),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[FPU] Show error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.show.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Data untuk form edit
    |--------------------------------------------------------------------------
    */
    public function edit(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('cash_advance.update')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.update.forbidden'),
            ], 403);
        }

        try {
            $cashAdvance = $this->findVisibleCashAdvance($publicId, $user);

            if (!$cashAdvance) {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.show.not_found'),
                ], 404);
            }

            $cashAdvance->load(['items', 'attachments', 'transactionCategory:id,name']);

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_messages.show.loaded'),
                'data' => [
                    'id' => $cashAdvance->id,
                    'public_id' => $cashAdvance->encrypted_id,
                    'advance_number' => $cashAdvance->advance_number,
                    'date' => optional($cashAdvance->date)->toDateString(),
                    'subject' => $cashAdvance->subject,
                    'transaction_category_id' => $cashAdvance->transaction_category_id,
                    'transaction_category' => $cashAdvance->transactionCategory?->name,
                    'business_trip_id' => $cashAdvance->business_trip_id,
                    'request_type' => $cashAdvance->request_type,
                    'branch' => $cashAdvance->branch,
                    'department_id' => $cashAdvance->department_id,
                    'total_amount' => (float) $cashAdvance->total_amount,
                    'notes' => $cashAdvance->notes,
                    'status' => $cashAdvance->status,
                    'items' => $this->transformItems($cashAdvance),
                    'attachments' => $this->transformAttachments($cashAdvance),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[FPU] Edit error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.show.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Ubah FPU
    |--------------------------------------------------------------------------
    | Hanya berlaku pada dokumen DRAFT. Setelah disubmit, isi dokumen sudah
    | menjadi dasar approval sehingga tidak boleh berubah.
    |--------------------------------------------------------------------------
    */
    public function update(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('cash_advance.update')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.update.forbidden'),
            ], 403);
        }

        $storedPaths = [];

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $cashAdvance = CashAdvance::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $cashAdvance->status) !== CashAdvance::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.update.only_draft'),
                ], 422);
            }

            $request->validate([
                'date' => ['required', 'date_format:Y-m-d'],
                'subject' => ['required', 'string', 'max:1000'],

                /*
                | Keterangan transaksi ikut menentukan approval flow, jadi
                | wajib diisi dan harus mengacu pada master yang aktif.
                */
                'transaction_category_id' => [
                    'required',
                    'integer',
                    'exists:fund_request_transaction_categories,id',
                ],

                /*
                | Dokumen Perdin yang mendasari FPU ini. Wajib atau tidaknya
                | ditentukan keterangan transaksinya -- lihat
                | resolveBusinessTripId() di bawah.
                */
                'business_trip_id' => ['nullable', 'integer'],

                // Rutin / Non Rutin, mengikuti pr_type pada PR.
                'request_type' => ['required', 'string', 'max:50', 'in:Rutin,Non Rutin'],
                'branch' => ['required'],
                'department_id' => ['required', 'integer'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'items' => ['required', 'string'],
                'deleted_attachment_ids' => ['nullable', 'string'],

                /*
                | Lampiran kini dikirim per baris rincian: line_attachments[i][].
                */
                'line_attachments.*.*' => [
                    'file',
                    'mimes:' . self::ATTACHMENT_MIMES,
                    'max:' . self::ATTACHMENT_MAX_KB,
                ],
            ]);

            $this->assertUserAccessAssignment(
                $user,
                $request->input('branch'),
                $request->input('department_id'),
            );

            $items = $this->decodeItems($request->input('items'));

            $totalAmount = round(
                array_sum(array_map(fn(array $item) => (float) $item['amount'], $items)),
                2,
            );

            $businessTripId = $this->resolveBusinessTripId($request, $user, $cashAdvance->id);

            $this->assertItemDatesWithinTrip($items, $businessTripId);

            $cashAdvance->update([
                'business_trip_id' => $businessTripId,

                'date' => $request->input('date'),
                'subject' => $this->clean($request->input('subject')),

                'transaction_category_id'
                => (int) $request->input('transaction_category_id'),

                'request_type' => $request->input('request_type'),

                'branch' => (string) $request->input('branch'),
                'department_id' => (int) $request->input('department_id'),
                'total_amount' => $totalAmount,
                'notes' => $this->clean($request->input('notes')) ?: null,
                'updated_by' => $user->id,
            ]);

            /*
            | Baris rincian TIDAK lagi ditulis ulang seluruhnya.
            |
            | Sejak lampiran melekat pada baris, id baris menjadi identitas yang
            | dipakai baris lampiran maupun nama folder di server. Menghapus lalu
            | membuat ulang akan memutus keduanya setiap kali draft disunting.
            | Karena itu baris lama dicocokkan lewat id yang dikirim formulir.
            */
            $itemIdsByIndex = $this->syncItems($cashAdvance, $items);

            $deletedIds = $this->requestedDeletedAttachmentIds($request);

            $this->assertEveryLineHasAttachment($request, $itemIdsByIndex, $deletedIds);

            $this->deleteRequestedAttachments($request, $cashAdvance);

            $storedPaths = $this->storeLineAttachments(
                $request,
                $cashAdvance,
                $itemIdsByIndex,
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_messages.update.success'),
                'data' => [
                    'id' => $cashAdvance->id,
                    'public_id' => $cashAdvance->encrypted_id,
                    'advance_number' => $cashAdvance->advance_number,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('cash_advance_messages.store.invalid'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            Log::error('[FPU] Update error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.update.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Hapus FPU
    |--------------------------------------------------------------------------
    | Hanya draft yang boleh dihapus. Dokumen yang sudah masuk approval harus
    | melalui reject atau cancel supaya jejaknya tetap ada.
    |--------------------------------------------------------------------------
    */
    public function destroy(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('cash_advance.delete')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.destroy.forbidden'),
            ], 403);
        }

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $cashAdvance = CashAdvance::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $cashAdvance->status) !== CashAdvance::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.destroy.only_draft'),
                ], 422);
            }

            $cashAdvance->items()->delete();
            $cashAdvance->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_messages.destroy.success'),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[FPU] Destroy error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.destroy.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Submit ke proses approval
    |--------------------------------------------------------------------------
    */
    /**
     * Kalimat penolakan yang menyebut apa yang menahan pemohon.
     *
     * Dua sebab bisa berlaku sekaligus. Yang disebut lebih dulu adalah dokumen
     * yang terlambat, karena itu yang paling mendesak dikerjakan -- sedangkan
     * "sudah 3 dari 3" hanya memberi tahu keadaannya.
     */
    private function pesanBatasFpu(array $batas): string
    {
        if (in_array('OVERDUE', $batas['reasons'], true)) {
            $nomor = collect($batas['overdue'])
                ->map(fn (array $d): string => $d['number'] . ' (' . $d['days'] . ' hari)')
                ->implode(', ');

            return __('cash_advance_messages.submit.limit_overdue', [
                'days' => $batas['max_days'],
                'documents' => $nomor,
            ]);
        }

        return __('cash_advance_messages.submit.limit_too_many', [
            'count' => $batas['outstanding'],
            'max' => $batas['max_outstanding'],
        ]);
    }

    /**
     * Ringkasan batas untuk dibaca layar.
     *
     * Dipakai bersama oleh penolakan dan oleh peringatan di daftar, supaya
     * keduanya menyebut angka yang sama persis.
     */
    private function ringkasBatasFpu(array $batas): array
    {
        return [
            'outstanding' => $batas['outstanding'],
            'max_outstanding' => $batas['max_outstanding'],
            'max_days' => $batas['max_days'],
            'overdue' => $batas['overdue'],
            'blocked' => $batas['blocked'],
            'reasons' => $batas['reasons'],
            'limit_name' => $batas['limit']?->name,
        ];
    }
    public function submit(
        string $publicId,
        Request $request,
        CashAdvanceNumberService $numberService,
        CashAdvanceApprovalGeneratorService $approvalGenerator,
        CashAdvanceNotificationService $notificationService,
        CashAdvanceMailService $mailService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission('cash_advance.submit')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.submit.forbidden'),
            ], 403);
        }

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $cashAdvance = CashAdvance::with(['items'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $cashAdvance->status) !== CashAdvance::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.submit.only_draft'),
                ], 422);
            }

            if ($cashAdvance->items->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.submit.items_unavailable'),
                ], 422);
            }

            /*
            | Batas FPU berjalan. Dinilai di sini -- bukan saat dokumennya
            | dibuat -- supaya draft tetap boleh disiapkan; yang dicegah
            | adalah dokumen baru masuk antrean sebelum yang lama
            | dipertanggungjawabkan.
            |
            | Sengaja tanpa jalan keluar darurat. Memaksa realisasi adalah
            | maksud aturannya, dan tombol terobos akan dipakai begitu ada.
            */
            $limitService = app(FundRequestLimitService::class);

            $batas = $limitService->evaluate(
                (int) $cashAdvance->created_by,
                $limitService->areaTypeOf($cashAdvance->branch),
                $cashAdvance->department_id ? (int) $cashAdvance->department_id : null,
            );

            if ($batas['blocked']) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => $this->pesanBatasFpu($batas),
                    'data' => ['limit' => $this->ringkasBatasFpu($batas)],
                ], 422);
            }

            $requesterSignaturePath = $user->signature_path;

            if (blank($requesterSignaturePath)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.submit.signature_missing'),
                ], 422);
            }

            /*
            | Nomor final baru dibentuk di sini supaya deret nomor tidak habis
            | terpakai oleh draft yang batal diajukan.
            */
            if (str_starts_with((string) $cashAdvance->advance_number, 'DRAFT/')) {
                $cashAdvance->advance_number = $numberService->generateFinalNumber($cashAdvance);
            }

            $approvalGenerator->generate($cashAdvance);

            $submittedAt = now();

            $cashAdvance->status = CashAdvance::STATUS_IN_PROGRESS;
            $cashAdvance->submitted_by = $user->id;
            $cashAdvance->submitted_at = $submittedAt;

            $cashAdvance->requester_signed_by = $user->id;
            $cashAdvance->requester_signature_path = $requesterSignaturePath;
            $cashAdvance->requester_signed_at = $submittedAt;

            $cashAdvance->save();

            DB::commit();

            $cashAdvance->refresh();

            try {
                $notificationService->notifyApprovalRequest($cashAdvance);
            } catch (\Throwable $notificationError) {
                Log::error('[FPU] Notifikasi approver gagal dibuat', [
                    'cash_advance_id' => $cashAdvance->id,
                    'advance_number' => $cashAdvance->advance_number,
                    'message' => $notificationError->getMessage(),
                ]);
            }

            /*
            | Email dipisah dari notifikasi in-app supaya kegagalan salah satu
            | tidak ikut membatalkan yang lain.
            */
            try {
                $mailService->sendApprovalRequest($cashAdvance);
            } catch (\Throwable $mailError) {
                Log::error('[FPU] Email approver gagal dikirim', [
                    'cash_advance_id' => $cashAdvance->id,
                    'advance_number' => $cashAdvance->advance_number,
                    'message' => $mailError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_messages.submit.success'),
                'data' => [
                    'id' => $cashAdvance->id,
                    'public_id' => $cashAdvance->encrypted_id,
                    'advance_number' => $cashAdvance->advance_number,
                    'status' => $cashAdvance->status,
                    'submitted_at' => $cashAdvance->submitted_at,
                    'submitted_by' => $cashAdvance->submitted_by,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('cash_advance_messages.submit.failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[FPU] Submit error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.submit.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Approve step aktif
    |--------------------------------------------------------------------------
    */
    public function approve(
        string $publicId,
        Request $request,
        CashAdvanceApprovalService $approvalService,
        CashAdvanceNotificationService $notificationService,
        CashAdvanceMailService $mailService,
    ): JsonResponse {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            if (!$user) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.user_not_authenticated'),
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $cashAdvance = CashAdvance::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $cashAdvance->status)
                !== CashAdvance::STATUS_IN_PROGRESS
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.approve.not_in_progress'),
                ], 422);
            }

            $result = $approvalService->approveCurrentStep(
                $cashAdvance,
                $user,
                $validated['notes'] ?? null,
            );

            DB::commit();

            $cashAdvance->refresh();

            try {
                $notificationService->notifyApprovalStep(
                    $cashAdvance,
                    $user,
                    $result['approval'],
                    $result['has_pending_approval'],
                );

                /*
                | Pemohon hanya diemail saat approval tuntas; tahap tengah cukup
                | lewat notifikasi in-app.
                */
                if ($result['is_final_approved']) {
                    $mailService->sendApprovalStep($cashAdvance, $user, false);

                    /*
                    | Approval tuntas berarti dokumennya berpindah ke meja PIC
                    | penerimaan -- bukan langsung ke Finance. Pemberitahuannya
                    | berada di luar approval flow, jadi penerimanya pemegang
                    | permission penerimaan.
                    */
                    $notificationService->notifyReceiptRequest($cashAdvance);

                    $mailService->sendReceiptRequest($cashAdvance);
                }
            } catch (\Throwable $notifyError) {
                Log::error('[FPU] Notify approval result gagal', [
                    'cash_advance_id' => $cashAdvance->id,
                    'message' => $notifyError->getMessage(),
                ]);
            }

            if (
                $result['step_completed']
                && $result['has_pending_approval']
                && $result['next_step_order'] !== null
            ) {
                try {
                    $notificationService->notifyApprovalRequest($cashAdvance);

                    $mailService->sendApprovalRequest($cashAdvance);
                } catch (\Throwable $nextApproverError) {
                    Log::error('[FPU] Notify next approver gagal', [
                        'cash_advance_id' => $cashAdvance->id,
                        'next_step_order' => $result['next_step_order'],
                        'message' => $nextApproverError->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,

                'message' => $result['is_final_approved']
                    ? __('cash_advance_messages.approve.final_success')
                    : (
                        $result['step_completed']
                        ? __('cash_advance_messages.approve.step_success')
                        : __('cash_advance_messages.approve.waiting_others')
                    ),

                'data' => [
                    'id' => $cashAdvance->id,
                    'public_id' => $cashAdvance->encrypted_id,
                    'advance_number' => $cashAdvance->advance_number,
                    'status' => $cashAdvance->status,
                    'step_completed' => $result['step_completed'],
                    'has_pending_approval' => $result['has_pending_approval'],
                    'is_final_approved' => $result['is_final_approved'],
                    'next_step_order' => $result['next_step_order'],
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('cash_advance_messages.approve.failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[FPU] Approve error', [
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.approve.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reject step aktif
    |--------------------------------------------------------------------------
    */
    public function reject(
        string $publicId,
        Request $request,
        CashAdvanceApprovalService $approvalService,
        CashAdvanceNotificationService $notificationService,
        CashAdvanceMailService $mailService,
    ): JsonResponse {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            if (!$user) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.user_not_authenticated'),
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $cashAdvance = CashAdvance::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $cashAdvance->status)
                !== CashAdvance::STATUS_IN_PROGRESS
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.reject.not_in_progress'),
                ], 422);
            }

            $approval = $approvalService->rejectCurrentStep(
                $cashAdvance,
                $user,
                $validated['notes'] ?? null,
            );

            DB::commit();

            $cashAdvance->refresh();

            try {
                $notificationService->notifyRejected($cashAdvance, $user);

                $mailService->sendRejected(
                    $cashAdvance,
                    $user,
                    $validated['notes'] ?? null,
                );
            } catch (\Throwable $notifyError) {
                Log::error('[FPU] Notify reject gagal', [
                    'cash_advance_id' => $cashAdvance->id,
                    'message' => $notifyError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_messages.reject.success'),
                'data' => [
                    'id' => $cashAdvance->id,
                    'public_id' => $cashAdvance->encrypted_id,
                    'advance_number' => $cashAdvance->advance_number,
                    'status' => $cashAdvance->status,
                    'approval_id' => $approval->id,
                    'rejected_at' => $approval->rejected_at,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('cash_advance_messages.reject.failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[FPU] Reject error', [
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.reject.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Batalkan FPU yang sudah approved
    |--------------------------------------------------------------------------
    | Hanya status APPROVED yang boleh dibatalkan:
    |
    |  - DRAFT           : cukup dihapus, tidak perlu dibatalkan.
    |  - IN PROGRESS     : approver masih bisa menolaknya lewat jalur reject.
    |  - DISBURSED       : dananya sudah keluar dari Finance, penyelesaiannya
    |                      lewat dokumen Realisasi, bukan pembatalan.
    |
    | Selain itu FPU yang realisasinya masih hidup juga ditahan -- realisasinya
    | harus dibatalkan atau ditolak lebih dulu supaya tidak ada dokumen anak
    | yang menggantung pada induk yang sudah batal.
    |--------------------------------------------------------------------------
    */
    public function cancel(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('cash_advance.cancel')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.cancel.forbidden'),
            ], 403);
        }

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $cashAdvance = CashAdvance::query()
                ->lockForUpdate()
                ->findOrFail($id);

            /*
            | Dokumen yang sudah diterima pun masih boleh dibatalkan -- dananya
            | belum keluar. Yang tidak bisa dibatalkan adalah yang sudah
            | dicairkan.
            */
            if (
                !in_array(
                    strtoupper((string) $cashAdvance->status),
                    [CashAdvance::STATUS_APPROVED, CashAdvance::STATUS_RECEIVED],
                    true,
                )
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.cancel.only_approved'),
                ], 422);
            }

            $activeRealization = $this->findActiveRealization($cashAdvance);

            if ($activeRealization) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.cancel.has_realization', [
                        'realization_number' => $activeRealization->realization_number ?: '-',
                        'realization_status' => $activeRealization->status,
                    ]),
                ], 422);
            }

            $cashAdvance->update([
                'status' => CashAdvance::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_notes' => $this->clean($validated['notes']),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_messages.cancel.success'),
                'data' => [
                    'id' => $cashAdvance->id,
                    'public_id' => $cashAdvance->encrypted_id,
                    'status' => $cashAdvance->status,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[FPU] Cancel error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.cancel.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Realisasi yang masih mengikat FPU, bila ada.
     *
     * Realisasi yang sudah ditolak atau dibatalkan tidak dihitung -- dokumen
     * itu sudah mati dan tidak lagi menahan induknya.
     */
    private function findActiveRealization(CashAdvance $cashAdvance): ?CashAdvanceRealization
    {
        $released = CashAdvanceRealization::RELEASED_STATUSES;

        return CashAdvanceRealization::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->whereRaw(
                'UPPER(TRIM(status)) NOT IN (' . implode(
                    ', ',
                    array_fill(0, count($released), '?'),
                ) . ')',
                $released,
            )
            ->orderByDesc('id')
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Export Excel FPU
    |--------------------------------------------------------------------------
    | Menerima parameter filter yang sama persis dengan index(), sehingga isi
    | file selalu sama dengan daftar yang sedang dilihat user -- termasuk
    | batasan visibility scope-nya. Tanpa filter, seluruh data yang boleh
    | dilihat user ikut terekspor.
    |
    | Judul kolom mengikuti ?lang=id|en, seperti pola export PR dan PO.
    |--------------------------------------------------------------------------
    */
    public function exportExcel(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.user_not_authenticated'),
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
            if (!$user->hasPermission('cash_advance.export')) {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.export.forbidden'),
                ], 403);
            }

            $context = $this->resolveViewContext($user);

            /*
            | Scope NONE berarti tidak ada satu pun FPU yang terlihat olehnya,
            | sehingga export pun tidak akan ada isinya.
            */
            if ($context['scope'] === 'NONE') {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.export.forbidden'),
                ], 403);
            }

            $query = CashAdvance::query()
                ->with([
                    'items',
                    'branchData',
                    'departmentData',
                    'transactionCategory',
                    'creator',

                    /*
                    | Dipakai untuk kolom referensi Nomor Realisasi. Dokumen yang
                    | belum punya realisasi meninggalkan kolom itu kosong.
                    */
                    'realization',
                ]);

            $this->applyVisibilityScope(
                $query,
                $user,
                $context['scope'],
                $context['userRoleIds'],
                $context['branchIds'],
                $context['departmentIds'],
            );

            $this->applyListFilters($query, $request);

            $data = $query
                ->orderByDesc('cash_advances.id')
                ->get();

            $fileName = __('cash_advance_messages.export.filename')
                . '_' . now()->format('Ymd_His')
                . '.xlsx';

            return Excel::download(
                new CashAdvanceExport($data),
                $fileName,
            );
        } catch (\Throwable $e) {
            Log::error('[FPU] Export excel error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.export.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pencairan oleh Finance
    |--------------------------------------------------------------------------
    | Menandai uang benar-benar sudah keluar. Realisasi hanya boleh dibuat
    | setelah dokumen berada pada status ini.
    |
    | Bukti transfer boleh dilampirkan, boleh juga tidak -- sebagian pencairan
    | tunai memang tidak menghasilkan bukti. Berkasnya disimpan terpisah dari
    | lampiran pengajuan supaya tidak tercampur.
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | Penerimaan dokumen
    |--------------------------------------------------------------------------
    | Tahap antara persetujuan dan pencairan. PIC pemegang permission menandai
    | bahwa dokumennya sudah diterima untuk diproses; barulah setelah itu
    | dananya boleh dicairkan.
    |
    | Bentuknya sengaja dibuat sama persis dengan pencairan -- catatan opsional
    | dan lampiran dengan aturan berkas yang sama -- supaya PIC tidak
    | menghadapi dua tata cara berbeda untuk dua tahap yang berurutan.
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | Penerimaan dan pencairan banyak FPU sekaligus
    |--------------------------------------------------------------------------
    | Keduanya lumrah dikerjakan berombongan: PIC menerima setumpuk berkas
    | sekaligus, Finance mencairkan sekumpulan dokumen dalam satu proses.
    |
    | Penjagaannya sama persis dengan versi satuan, termasuk batas akses --
    | dokumen di luar wewenang user tidak bisa ikut terbawa hanya karena
    | id-nya dikirim.
    |--------------------------------------------------------------------------
    */
    public function bulkReceive(Request $request): JsonResponse
    {
        return $this->processBulkStage(
            $request,
            permission: 'cash_advance.receive',
            forbiddenKey: 'cash_advance_messages.receive.forbidden',
            fromStatus: CashAdvance::STATUS_APPROVED,
            toStatus: CashAdvance::STATUS_RECEIVED,
            wrongStatusKey: 'cash_advance_messages.receive.only_approved',
            logTag: '[FPU] Bulk receive',
            actorColumn: 'received_by',
            timeColumn: 'received_at',
        );
    }

    public function bulkDisburse(Request $request): JsonResponse
    {
        return $this->processBulkStage(
            $request,
            permission: 'cash_advance.disburse',
            forbiddenKey: 'cash_advance_messages.disburse.forbidden',
            fromStatus: CashAdvance::STATUS_RECEIVED,
            toStatus: CashAdvance::STATUS_DISBURSED,
            wrongStatusKey: 'cash_advance_messages.disburse.only_received',
            logTag: '[FPU] Bulk disburse',
            actorColumn: 'disbursed_by',
            timeColumn: 'disbursed_at',
        );
    }

    /**
     * Inti kedua tindakan massal FPU.
     *
     * Alurnya identik -- yang berbeda hanya permission, status asal dan tujuan,
     * serta kolom pencatat pelakunya. Disatukan supaya aturan status keduanya
     * tidak berpeluang menyimpang seiring waktu.
     */
    private function processBulkStage(
        Request $request,
        string $permission,
        string $forbiddenKey,
        string $fromStatus,
        string $toStatus,
        string $wrongStatusKey,
        string $logTag,
        string $actorColumn,
        string $timeColumn,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => __($forbiddenKey),
            ], 403);
        }

        $validated = $request->validate($this->bulkDocumentRules());

        $hasil = $this->processBulkDocuments(
            $validated['public_ids'],
            $logTag,
            function (string $publicId) use (
                $user,
                $fromStatus,
                $toStatus,
                $wrongStatusKey,
                $actorColumn,
                $timeColumn,
            ): string {
                $cashAdvance = DB::transaction(function () use (
                    $publicId,
                    $user,
                    $fromStatus,
                    $toStatus,
                    $wrongStatusKey,
                    $actorColumn,
                    $timeColumn,
                ): CashAdvance {
                    $terlihat = $this->findVisibleCashAdvance($publicId, $user);

                    if (!$terlihat) {
                        throw new BulkDocumentActionException('-', __('bulk_action.not_found'));
                    }

                    $terkunci = CashAdvance::query()->lockForUpdate()->find($terlihat->id);

                    if (strtoupper((string) $terkunci->status) !== $fromStatus) {
                        throw new BulkDocumentActionException(
                            (string) $terkunci->advance_number,
                            __($wrongStatusKey),
                        );
                    }

                    /*
                    | Pencairan hanya boleh jatuh pada hari pembayaran. Tahap
                    | penerimaan tidak dibatasi -- berkas boleh diterima kapan saja.
                    */
                    if (
                        $toStatus === CashAdvance::STATUS_DISBURSED
                        && !$this->canPayToday(
                            $terkunci->scheduled_payment_date,
                            'FPU',
                            $terkunci->transaction_category_id,
                        )
                    ) {
                        throw new BulkDocumentActionException(
                            (string) $terkunci->advance_number,
                            __('payment_schedule_messages.not_payment_day', [
                                'days' => $this->paymentDaysText('FPU', $terkunci->transaction_category_id),
                            ]),
                        );
                    }

                    /* Penjagaan yang sama pada jalur massal -- lihat disburse(). */
                    if ($toStatus === CashAdvance::STATUS_DISBURSED) {
                        $penahan = $this->businessTripBlockingDisbursement($terkunci);

                        if ($penahan) {
                            throw new BulkDocumentActionException(
                                (string) $terkunci->advance_number,
                                $penahan,
                            );
                        }
                    }

                    $sekarang = now();

                    $perubahan = [
                        'status' => $toStatus,
                        $actorColumn => $user->id,
                        $timeColumn => $sekarang,
                    ];

                    /* Jadwal pembayaran hanya lahir pada tahap penerimaan. */
                    if ($toStatus === CashAdvance::STATUS_RECEIVED) {
                        $perubahan['scheduled_payment_date'] = $this->freezePaymentDate(
                            'FPU',
                            $sekarang,
                            $terkunci->transaction_category_id,
                        );
                    }

                    $terkunci->update($perubahan);

                    return $terkunci;
                });

                /*
                | Pemberitahuan menyusul setelah transaksinya selesai, dan
                | kegagalannya tidak membatalkan perubahan yang sudah tercatat.
                */
                try {
                    $notificationService = app(CashAdvanceNotificationService::class);
                    $mailService = app(CashAdvanceMailService::class);

                    if ($toStatus === CashAdvance::STATUS_RECEIVED) {
                        $notificationService->notifyReceived($cashAdvance, $user);
                        $mailService->sendReceived($cashAdvance, $user);

                        /* Giliran Finance dimulai setelah dokumennya diterima. */
                        $notificationService->notifyDisbursementRequest($cashAdvance);
                        $mailService->sendDisbursementRequest($cashAdvance);
                    } else {
                        $notificationService->notifyDisbursed($cashAdvance, $user);
                        $mailService->sendDisbursed($cashAdvance, $user);
                    }
                } catch (\Throwable $notifyError) {
                    Log::error('[FPU] Notify bulk stage gagal', [
                        'cash_advance_id' => $cashAdvance->id,
                        'status' => $toStatus,
                        'message' => $notifyError->getMessage(),
                    ]);
                }

                return (string) $cashAdvance->advance_number;
            },
        );

        return response()->json([
            'success' => $hasil['succeeded'] !== [],
            'message' => $this->bulkResultMessage($hasil),
            'data' => $hasil,
        ], 200);
    }
    public function receive(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('cash_advance.receive')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.receive.forbidden'),
            ], 403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],

            'attachments' => ['nullable', 'array'],
            'attachments.*' => [
                'file',
                'mimes:' . self::ATTACHMENT_MIMES,
                'max:' . self::ATTACHMENT_MAX_KB,
            ],
        ]);

        DB::beginTransaction();

        $storedPaths = [];

        try {
            $id = Crypt::decryptString($publicId);

            $cashAdvance = CashAdvance::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $cashAdvance->status) !== CashAdvance::STATUS_APPROVED) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.receive.only_approved'),
                ], 422);
            }

            $diterimaPada = now();

            $cashAdvance->update([
                'status' => CashAdvance::STATUS_RECEIVED,
                'received_by' => $user->id,
                'received_at' => $diterimaPada,
                'receipt_notes' => $this->clean($validated['notes'] ?? '') ?: null,
                'scheduled_payment_date' => $this->freezePaymentDate(
                    'FPU',
                    $diterimaPada,
                    $cashAdvance->transaction_category_id,
                ),
            ]);

            $storedPaths = $this->storeAttachments(
                $request,
                $cashAdvance,
                CashAdvanceAttachment::TYPE_RECEIPT,
            );

            DB::commit();

            $cashAdvance->refresh();

            try {
                $notificationService = app(CashAdvanceNotificationService::class);
                $mailService = app(CashAdvanceMailService::class);

                /* Pemohon diberi tahu dokumennya sudah diterima. */
                $notificationService->notifyReceived($cashAdvance, $user);
                $mailService->sendReceived($cashAdvance, $user);

                /*
                | Dan giliran Finance dimulai di sini -- bukan lagi sejak
                | approval tuntas.
                */
                $notificationService->notifyDisbursementRequest($cashAdvance);
                $mailService->sendDisbursementRequest($cashAdvance);
            } catch (\Throwable $notifyError) {
                Log::error('[FPU] Notify received gagal', [
                    'cash_advance_id' => $cashAdvance->id,
                    'message' => $notifyError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_messages.receive.success'),
                'data' => [
                    'id' => $cashAdvance->id,
                    'public_id' => $cashAdvance->encrypted_id,
                    'status' => $cashAdvance->status,
                    'received_at' => $cashAdvance->received_at,

                    'receipt_attachments' => $this->transformReceiptAttachments(
                        $cashAdvance->load('attachments'),
                    ),
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            $this->cleanupStoredFiles($storedPaths);

            Log::error('[FPU] Receive error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.receive.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
    public function disburse(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('cash_advance.disburse')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.disburse.forbidden'),
            ], 403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],

            /*
            | Aturan berkasnya sengaja sama persis dengan lampiran pengajuan
            | supaya user tidak menghadapi dua batasan berbeda di satu modul.
            */
            'attachments' => ['nullable', 'array'],
            'attachments.*' => [
                'file',
                'mimes:' . self::ATTACHMENT_MIMES,
                'max:' . self::ATTACHMENT_MAX_KB,
            ],
        ]);

        DB::beginTransaction();

        $storedPaths = [];

        try {
            $id = Crypt::decryptString($publicId);

            $cashAdvance = CashAdvance::query()
                ->lockForUpdate()
                ->findOrFail($id);

            /*
            | Sejak tahap penerimaan diberlakukan, pencairan tidak lagi
            | langsung menyusul persetujuan: dokumennya harus lebih dulu
            | diterima PIC.
            */
            if (strtoupper((string) $cashAdvance->status) !== CashAdvance::STATUS_RECEIVED) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.disburse.only_received'),
                ], 422);
            }

            /*
            | Hari ini harus hari pembayaran. Lebih awal maupun terlambat tetap
            | boleh, tetapi tetap harus jatuh pada hari kerja kasirnya.
            */
            if (!$this->canPayToday(
                $cashAdvance->scheduled_payment_date,
                'FPU',
                $cashAdvance->transaction_category_id,
            )) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('payment_schedule_messages.not_payment_day', [
                        'days' => $this->paymentDaysText('FPU', $cashAdvance->transaction_category_id),
                    ]),
                ], 422);
            }

            /*
            | Perjalanannya harus sudah diizinkan sebelum dananya keluar.
            |
            | FPU boleh berjalan paralel dengan perdin yang diajukan tepat
            | waktu -- tetapi hanya sampai sebelum langkah ini.
            */
            $penahan = $this->businessTripBlockingDisbursement($cashAdvance);

            if ($penahan) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => $penahan,
                ], 422);
            }

            $cashAdvance->update([
                'status' => CashAdvance::STATUS_DISBURSED,
                'disbursed_by' => $user->id,
                'disbursed_at' => now(),
                'disbursement_notes' => $this->clean($validated['notes'] ?? '') ?: null,
            ]);

            $storedPaths = $this->storeAttachments(
                $request,
                $cashAdvance,
                CashAdvanceAttachment::TYPE_DISBURSEMENT,
            );

            DB::commit();

            $cashAdvance->refresh();

            try {
                app(CashAdvanceNotificationService::class)
                    ->notifyDisbursed($cashAdvance, $user);

                app(CashAdvanceMailService::class)
                    ->sendDisbursed($cashAdvance, $user);
            } catch (\Throwable $notifyError) {
                Log::error('[FPU] Notify disbursed gagal', [
                    'cash_advance_id' => $cashAdvance->id,
                    'message' => $notifyError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_messages.disburse.success'),
                'data' => [
                    'id' => $cashAdvance->id,
                    'public_id' => $cashAdvance->encrypted_id,
                    'status' => $cashAdvance->status,
                    'disbursed_at' => $cashAdvance->disbursed_at,

                    'disbursement_attachments' => $this->transformDisbursementAttachments(
                        $cashAdvance->load('attachments'),
                    ),
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            /*
            | Berkas sudah terlanjur ditulis ke disk sebelum transaksi gagal.
            | Tanpa pembersihan ini, disk terisi berkas yatim tanpa baris
            | lampiran yang menunjuknya.
            */
            $this->cleanupStoredFiles($storedPaths);

            Log::error('[FPU] Disburse error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.disburse.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cetakan PDF
    |--------------------------------------------------------------------------
    | Mengikuti pola PR dan PO: frontend meminta signed URL berumur pendek,
    | lalu membuka URL itu di tab baru sehingga browser merender PDF-nya
    | sendiri tanpa menunggu unduhan selesai di sisi Axios.
    |
    | Cetakan FPU hanya berbahasa Indonesia -- dokumen internal.
    |--------------------------------------------------------------------------
    */
    public function generatePrintUrl(Request $request, string $publicId): JsonResponse
    {
        try {
            $id = (int) Crypt::decryptString($publicId);

            CashAdvance::query()->findOrFail($id);

            $relativeUrl = URL::temporarySignedRoute(
                'fund-request.cash-advance.print-signed',
                now()->addMinutes(10),
                ['publicId' => $publicId],
                false,
            );

            return response()
                ->json([
                    'success' => true,
                    'url' => rtrim(config('app.url'), '/') . $relativeUrl,
                ])
                ->header('Content-Type', 'application/json; charset=UTF-8');
        } catch (DecryptException | ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.print.not_found'),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[FPU] Generate print URL error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.print.url_failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function printSigned(Request $request, string $publicId)
    {
        return $this->print($request, $publicId);
    }

    public function print(Request $request, string $publicId)
    {
        try {
            $id = Crypt::decryptString($publicId);

            $fpu = CashAdvance::with([
                'branchData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'transactionCategory:id,code,name',
                'items',
                'creator:id,name',
                'submitter:id,name',
                'receiver:id,name',
                'disburser:id,name',
                'approvals' => function ($query) {
                    $query->orderBy('step_order')->orderBy('id');
                },
            ])->findOrFail($id);

            /*
            | Draft dan dokumen yang masih berjalan belum boleh dicetak:
            | tanda tangan penyetujunya belum lengkap, sehingga hasil cetak
            | bisa disalahpahami sebagai dokumen sah.
            */
            $status = strtoupper(trim((string) $fpu->status));

            if (
                !in_array(
                    $status,
                    [
                        CashAdvance::STATUS_APPROVED,
                        CashAdvance::STATUS_RECEIVED,
                        CashAdvance::STATUS_DISBURSED,
                    ],
                    true,
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_messages.print.not_printable'),
                ], 422);
            }

            $totalAmount = (float) (
                $fpu->total_amount
                ?: $fpu->items->sum(fn($item) => (float) ($item->amount ?? 0))
            );

            $pdf = Pdf::loadView('pdf.cash-advance', [
                'fpu' => $fpu,
                'totalAmount' => $totalAmount,
                'terbilang' => RupiahWords::of($totalAmount),
                'requester' => $this->buildRequesterSigner($fpu),
                'approvers' => $this->buildApproverSigners($fpu),
            ])->setPaper('a4', 'portrait');

            return $this->pdfResponse(
                $pdf->output(),
                'FPU-' . $this->safeFileName((string) $fpu->advance_number, (string) $fpu->id),
            );
        } catch (DecryptException | ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.print.not_found'),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[FPU] Print error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_messages.print.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Blok tanda tangan "Dibuat Oleh".
     */
    private function buildRequesterSigner(CashAdvance $fpu): object
    {
        return (object) [
            'name' => $fpu->submitter?->name ?? $fpu->creator?->name ?? '-',
            'signature_file' => $this->signatureFile($fpu->requester_signature_path),
            'signed_at' => $fpu->requester_signed_at ?? $fpu->submitted_at,
        ];
    }

    /**
     * Blok tanda tangan "Disetujui Oleh".
     *
     * Hanya baris yang benar-benar APPROVED yang ikut dicetak; baris SKIPPED
     * pada mode ANY tidak pernah ditandatangani siapa pun.
     */
    private function buildApproverSigners(CashAdvance $fpu)
    {
        return $fpu->approvals
            ->filter(
                fn(CashAdvanceApproval $approval) => strtoupper(trim((string) $approval->status))
                    === CashAdvanceApproval::STATUS_APPROVED,
            )
            ->sortBy(
                fn(CashAdvanceApproval $approval) => sprintf(
                    '%010d-%010d',
                    (int) $approval->step_order,
                    (int) $approval->id,
                ),
            )
            ->map(fn(CashAdvanceApproval $approval) => (object) [
                'label' => $approval->label ?: 'Penyetuju',
                'name' => $approval->approver_name_snapshot ?? '-',
                'signature_file' => $this->signatureFile($approval->signature_path),
                'signed_at' => $approval->approved_at ?? $approval->signed_at,
            ])
            ->values();
    }

    /**
     * Mengubah path tanda tangan menjadi path absolut yang bisa dibaca DomPDF.
     */
    private function signatureFile(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return storage_path('app/public/' . ltrim($path, '/'));
    }

    private function safeFileName(string $number, string $fallback): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $number), '-');

        return $name !== '' ? $name : $fallback;
    }

    private function pdfResponse(string $output, string $fileName)
    {
        $response = response()->make($output, 200);

        $response->headers->set('Content-Type', 'application/pdf');

        /*
        | inline supaya PDF terbuka langsung di tab, bukan terunduh.
        */
        $response->headers->set(
            'Content-Disposition',
            'inline; filename="' . $fileName . '.pdf"',
        );

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper: konteks visibilitas
    |--------------------------------------------------------------------------
    */
    private function resolveViewContext($user): array
    {
        $scope = strtoupper(
            trim((string) ($user->getPermissionScope('cash_advance.view') ?? 'NONE')),
        );

        if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
            $scope = 'NONE';
        }

        $userRoleIds = collect();

        if ($user->getAttribute('role_id')) {
            $userRoleIds->push((int) $user->getAttribute('role_id'));
        }

        $activeRoleId = $user->getActiveRoleId();

        if ($activeRoleId) {
            $userRoleIds->push((int) $activeRoleId);
        }

        $userRoleIds = $userRoleIds
            ->merge(
                DB::table('user_roles')
                    ->where('user_id', $user->id)
                    ->pluck('role_id'),
            )
            ->filter(fn($roleId) => $roleId !== null && (int) $roleId > 0)
            ->map(fn($roleId) => (int) $roleId)
            ->unique()
            ->values();

        $assignments = $this->getActiveUserAccessAssignments($user);

        $branchIds = collect([(int) ($user->cabang_id ?? 0)])
            ->merge($assignments->pluck('branch_id')->map(fn($id) => (int) $id))
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        $departmentIds = collect([(int) ($user->departemen_id ?? 0)])
            ->merge($assignments->pluck('department_id')->map(fn($id) => (int) $id))
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        return [
            'scope' => $scope,
            'userRoleIds' => $userRoleIds,
            'branchIds' => $branchIds,
            'departmentIds' => $departmentIds,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper: pembatasan data yang boleh dilihat
    |--------------------------------------------------------------------------
    | Approver selalu bisa melihat dokumen yang masuk ke flow-nya, meskipun di
    | luar cabang atau department-nya.
    |--------------------------------------------------------------------------
    */
    private function applyVisibilityScope(
        $query,
        $user,
        string $scope,
        $userRoleIds,
        $branchIds,
        $departmentIds,
    ): void {
        if ($scope === 'ALL') {
            return;
        }

        $query->where(function ($visibilityQuery) use (
            $scope,
            $user,
            $userRoleIds,
            $branchIds,
            $departmentIds,
        ) {
            $visibilityQuery->where(function ($scopeQuery) use (
                $scope,
                $user,
                $branchIds,
                $departmentIds,
            ) {
                if ($scope === 'OWN_DATA') {
                    $user->id
                        ? $scopeQuery->where('cash_advances.created_by', $user->id)
                        : $scopeQuery->whereRaw('1 = 0');

                    return;
                }

                if ($scope === 'OWN_DEPARTMENT') {
                    $departmentIds->isEmpty()
                        ? $scopeQuery->whereRaw('1 = 0')
                        : $scopeQuery->whereIn('cash_advances.department_id', $departmentIds->all());

                    return;
                }

                if ($scope === 'OWN_CABANG') {
                    $branchIds->isEmpty()
                        ? $scopeQuery->whereRaw('1 = 0')
                        : $scopeQuery->whereIn(
                            'cash_advances.branch',
                            $branchIds->map(fn($id) => (string) $id)->all(),
                        );

                    return;
                }

                // NONE atau scope tidak dikenal.
                $scopeQuery->whereRaw('1 = 0');
            });

            $visibilityQuery->orWhereHas(
                'approvals',
                function ($approvalQuery) use ($user, $userRoleIds) {
                    $this->applyApproverMatch($approvalQuery, $user, $userRoleIds);
                },
            );
        });
    }

    /**
     * Menyaring baris approval yang ditujukan ke user login atau salah satu role-nya.
     */
    private function applyApproverMatch($approvalQuery, $user, $userRoleIds): void
    {
        $approvalQuery->where(function ($approverQuery) use ($user, $userRoleIds) {
            $approverQuery->where(function ($userQuery) use ($user) {
                $userQuery
                    ->where(
                        'cash_advance_approvals.approver_type',
                        CashAdvanceApproval::APPROVER_TYPE_USER,
                    )
                    ->where('cash_advance_approvals.approver_id', $user->id);
            });

            if ($userRoleIds->isNotEmpty()) {
                $approverQuery->orWhere(function ($roleQuery) use ($userRoleIds) {
                    $roleQuery
                        ->where(
                            'cash_advance_approvals.approver_type',
                            CashAdvanceApproval::APPROVER_TYPE_ROLE,
                        )
                        ->whereIn('cash_advance_approvals.approver_id', $userRoleIds->all());
                });
            }
        });
    }

    private function applyListFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            if ($search !== '') {
                $searchLike = "%{$search}%";

                $query->where(function ($q) use ($searchLike) {
                    $q->where('cash_advances.advance_number', 'ILIKE', $searchLike)
                        ->orWhere('cash_advances.subject', 'ILIKE', $searchLike)
                        ->orWhere('cash_advances.notes', 'ILIKE', $searchLike)
                        ->orWhereHas('departmentData', function ($deptQuery) use ($searchLike) {
                            $deptQuery->where('kode', 'ILIKE', $searchLike)
                                ->orWhere('nama', 'ILIKE', $searchLike);
                        })
                        ->orWhereExists(function ($branchQuery) use ($searchLike) {
                            $branchQuery->select(DB::raw(1))
                                ->from('cabang')
                                ->whereRaw('CAST(cabang.id AS VARCHAR) = cash_advances.branch')
                                ->where(function ($sub) use ($searchLike) {
                                    $sub->where('cabang.nama_cabang', 'ILIKE', $searchLike)
                                        ->orWhere('cabang.inisial_cabang', 'ILIKE', $searchLike);
                                });
                        });
                });
            }
        }

        if ($request->filled('start_date')) {
            $query->whereDate('cash_advances.date', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('cash_advances.date', '<=', $request->input('end_date'));
        }

        /*
        | Saringan tanggal pembayaran, khusus pemegang wewenang membayar.
        |
        | Penjagaannya di sini, bukan hanya dengan menyembunyikan kotaknya di
        | layar -- parameter tetap bisa dikirim sendiri oleh siapa pun. Yang
        | tidak berwenang tidak boleh memakai tanggal jadwal sebagai jalan
        | menyaring dokumen yang tidak menjadi urusannya.
        */
        if (
            $request->filled('scheduled_payment_date')
            && $request->user()?->hasPermission('cash_advance.disburse')
        ) {
            $query->whereDate(
                'cash_advances.scheduled_payment_date',
                '=',
                $request->input('scheduled_payment_date'),
            );
        }

        if ($request->filled('status')) {
            $status = strtoupper(trim((string) $request->input('status')));

            if ($status !== '' && !in_array($status, ['ALL', 'SEMUA'], true)) {
                $query->where('cash_advances.status', $status);
            }
        }

        if ($request->filled('department_id')) {
            $query->where('cash_advances.department_id', (int) $request->input('department_id'));
        }

        if ($request->filled('branch')) {
            $query->where('cash_advances.branch', (string) $request->input('branch'));
        }

        $this->applyPendingActionFilter($query, $request);
    }

    /*
    |--------------------------------------------------------------------------
    | Filter "butuh aksi"
    |--------------------------------------------------------------------------
    | Menyaring dokumen yang masih menunggu tindakan lanjutan di luar approval.
    | Definisinya disamakan dengan penanda pada daftar supaya angka yang tampil
    | selalu cocok dengan jumlah baris bertanda.
    |
    | Tidak dijaga permission di sini: penyaringnya hanya mempersempit baris
    | yang memang sudah boleh dilihat user, bukan membuka data baru. Yang
    | dijaga permission adalah kemunculan pilihannya di layar.
    |--------------------------------------------------------------------------
    */
    private function applyPendingActionFilter($query, Request $request): void
    {
        if (!$request->filled('pending_action')) {
            return;
        }

        $action = strtoupper(trim((string) $request->input('pending_action')));

        /*
        | Menunggu diterima PIC: sudah disetujui penuh, belum disentuh tahap
        | penerimaan.
        */
        if ($action === 'NOT_RECEIVED') {
            $query->where('cash_advances.status', CashAdvance::STATUS_APPROVED);

            return;
        }

        /*
        | Menunggu dicairkan: sudah diterima, dananya belum keluar. Sebelum
        | tahap penerimaan ada, penyaring ini menunjuk status APPROVED.
        */
        if ($action === 'NOT_DISBURSED') {
            $query->where('cash_advances.status', CashAdvance::STATUS_RECEIVED);

            return;
        }

        if ($action === 'NOT_REALIZED') {
            $query
                ->where('cash_advances.status', CashAdvance::STATUS_DISBURSED)
                ->whereDoesntHave('realization');
        }
    }

    /**
     * Mengambil dokumen sekaligus memastikan user memang boleh melihatnya.
     */
    private function findVisibleCashAdvance(string $publicId, $user): ?CashAdvance
    {
        $id = Crypt::decryptString($publicId);

        $context = $this->resolveViewContext($user);

        $query = CashAdvance::query()->whereKey($id);

        $this->applyVisibilityScope(
            $query,
            $user,
            $context['scope'],
            $context['userRoleIds'],
            $context['branchIds'],
            $context['departmentIds'],
        );

        return $query->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper: akses cabang + department
    |--------------------------------------------------------------------------
    */
    /**
     * Menentukan perdin mana yang ditautkan, sekaligus menjaganya.
     *
     * Empat keadaan:
     *
     *   kategori tidak mewajibkan  -> tautannya dikosongkan, apa pun kiriman
     *                                 layar. FPU biasa tidak boleh menggandeng
     *                                 perdin lewat permintaan buatan.
     *   mewajibkan, tidak dikirim  -> ditolak
     *   mewajibkan, tidak layak    -> ditolak
     *   mewajibkan, layak          -> dipakai
     *
     * @param  int|null  $cashAdvanceId  FPU yang sedang disunting, supaya
     *                                   tautannya sendiri tidak dianggap
     *                                   "sudah dipakai orang lain".
     */
    /**
     * Alasan sebuah FPU belum boleh dicairkan karena perdinnya, bila ada.
     *
     * Mengembalikan null bila tidak ada yang menahan: FPU tanpa tautan perdin,
     * atau perdin yang sudah disetujui.
     *
     * Dua keadaan ditahan, dan pesannya dibedakan karena tindak lanjutnya
     * berbeda jauh:
     *
     *   masih menunggu  -> tunggu manajemen menyetujui perjalanannya
     *   ditolak/batal   -> perjalanannya tidak jadi, FPU-nya ikut dibatalkan
     */
    /**
     * Persetujuan perdin yang PERNAH DIBERIKAN akun ini, per dokumen perdin.
     *
     * Dipakai memberi konteks kepada penyetuju FPU: ia sudah menyetujui
     * perjalanannya, jadi tidak perlu mencari-cari sebelum memutuskan.
     *
     * TIDAK dipakai untuk melewati persetujuan FPU-nya. Yang disetujui pada
     * perdin adalah perjalanannya; formulir perdin tidak memuat nominal sama
     * sekali, sehingga persetujuannya tidak pernah bisa mewakili keputusan
     * yang dasarnya nominal.
     *
     * @param  int[]  $tripIds
     * @return array<int, array{step_order: int, label: string|null, approved_at: string|null}>
     */
    /**
     * Tanggal baris rincian harus berada di dalam periode perjalanannya.
     *
     * Hanya berlaku bila FPU-nya bertaut ke perdin. FPU biasa tidak dibatasi
     * sama sekali -- pengeluarannya tidak terikat periode apa pun.
     *
     * Ditegakkan di sini juga, bukan hanya di kalender: batas yang hanya ada
     * di layar bukan batas.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertItemDatesWithinTrip(array $items, ?int $businessTripId): void
    {
        if (!$businessTripId) {
            return;
        }

        $trip = BusinessTrip::find($businessTripId);

        if (!$trip || !$trip->depart_date || !$trip->return_date) {
            return;
        }

        $mulai = $trip->depart_date->toDateString();
        $akhir = $trip->return_date->toDateString();

        foreach ($items as $index => $item) {
            $tanggal = trim((string) ($item['date'] ?? ''));

            if ($tanggal === '') {
                continue;
            }

            if ($tanggal < $mulai || $tanggal > $akhir) {
                throw ValidationException::withMessages([
                    'items' => [
                        __('cash_advance_messages.business_trip.item_date_outside_trip', [
                            'row' => $index + 1,
                            'number' => (string) $trip->trip_number,
                            'from' => $mulai,
                            'to' => $akhir,
                        ]),
                    ],
                ]);
            }
        }
    }

    private function myBusinessTripApprovals($user, array $tripIds): array
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

        $baris = BusinessTripApproval::query()
            ->whereIn('business_trip_id', $tripIds)
            ->where('status', BusinessTripApproval::STATUS_APPROVED)
            ->where(function ($q) use ($user, $roleIds): void {
                $q->where(function ($perOrang) use ($user): void {
                    $perOrang
                        ->where('approver_type', BusinessTripApproval::APPROVER_TYPE_USER)
                        ->where('approver_id', $user->id);
                });

                if ($roleIds !== []) {
                    $q->orWhere(function ($perRole) use ($roleIds): void {
                        $perRole
                            ->where('approver_type', BusinessTripApproval::APPROVER_TYPE_ROLE)
                            ->whereIn('approver_id', $roleIds);
                    });
                }
            })
            ->orderBy('business_trip_id')
            ->orderBy('step_order')
            ->get(['business_trip_id', 'step_order', 'label', 'approved_at']);

        $hasil = [];

        foreach ($baris as $row) {
            /* Satu saja cukup untuk mengatakan "Anda sudah menyetujuinya". */
            if (isset($hasil[(int) $row->business_trip_id])) {
                continue;
            }

            $hasil[(int) $row->business_trip_id] = [
                'step_order' => (int) $row->step_order,
                'label' => $row->label,
                'approved_at' => optional($row->approved_at)->toIso8601String(),
            ];
        }

        return $hasil;
    }

    private function businessTripBlockingDisbursement(CashAdvance $cashAdvance): ?string
    {
        if (!$cashAdvance->business_trip_id) {
            return null;
        }

        $trip = BusinessTrip::find($cashAdvance->business_trip_id);

        if (!$trip) {
            return null;
        }

        $status = strtoupper((string) $trip->status);

        if ($status === BusinessTrip::STATUS_APPROVED) {
            return null;
        }

        return in_array(
            $status,
            [BusinessTrip::STATUS_REJECTED, BusinessTrip::STATUS_CANCELLED],
            true,
        )
            ? __('cash_advance_messages.business_trip.trip_not_valid', [
                'number' => (string) $trip->trip_number,
            ])
            : __('cash_advance_messages.business_trip.trip_not_approved', [
                'number' => (string) $trip->trip_number,
            ]);
    }
    private function resolveBusinessTripId(
        Request $request,
        $user,
        ?int $cashAdvanceId = null,
    ): ?int {
        $wajib = (bool) DB::table('fund_request_transaction_categories')
            ->where('id', (int) $request->input('transaction_category_id'))
            ->value('requires_business_trip');

        if (!$wajib) {
            return null;
        }

        $tripId = $request->filled('business_trip_id')
            ? (int) $request->input('business_trip_id')
            : 0;

        if ($tripId <= 0) {
            throw ValidationException::withMessages([
                'business_trip_id' => [
                    __('cash_advance_messages.business_trip.required'),
                ],
            ]);
        }

        /*
        | Aturan kelayakannya dibaca dari sumber yang sama dengan yang dipakai
        | menyusun pilihan di layar, bukan ditulis ulang di sini.
        */
        $layak = app(BusinessTripController::class)
            ->eligibleQuery((int) $user->id, $cashAdvanceId)
            ->whereKey($tripId)
            ->exists();

        if (!$layak) {
            throw ValidationException::withMessages([
                'business_trip_id' => [
                    __('cash_advance_messages.business_trip.not_eligible'),
                ],
            ]);
        }

        return $tripId;
    }
    private function assertUserAccessAssignment(
        $user,
        int|string|null $branchId,
        int|string|null $departmentId,
    ): void {
        $branchId = (int) $branchId;
        $departmentId = (int) $departmentId;

        if ($branchId <= 0 || $departmentId <= 0) {
            throw ValidationException::withMessages([
                'access_assignment' => [
                    __('cash_advance_messages.access_assignment.branch_department_required'),
                ],
            ]);
        }

        $hasAnyAssignment = DB::table('user_access_assignments')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        /*
        | User lama yang belum punya assignment sama sekali tetap dilayani lewat
        | master user, supaya modul baru tidak memblokir mereka.
        */
        if (!$hasAnyAssignment) {
            if (
                (int) ($user->cabang_id ?? 0) === $branchId
                && (int) ($user->departemen_id ?? 0) === $departmentId
            ) {
                return;
            }

            throw ValidationException::withMessages([
                'access_assignment' => [
                    __('cash_advance_messages.access_assignment.no_access_branch_department'),
                ],
            ]);
        }

        $hasSelectedAssignment = DB::table('user_access_assignments')
            ->where('user_id', $user->id)
            ->where('branch_id', $branchId)
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->exists();

        if (!$hasSelectedAssignment) {
            throw ValidationException::withMessages([
                'access_assignment' => [
                    __('cash_advance_messages.access_assignment.no_access_create'),
                ],
            ]);
        }
    }

    private function getActiveUserAccessAssignments($user)
    {
        if (!$user) {
            return collect();
        }

        $assignments = DB::table('user_access_assignments')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->select(['branch_id', 'department_id'])
            ->get()
            ->map(fn($item) => [
                'branch_id' => (int) $item->branch_id,
                'department_id' => (int) $item->department_id,
            ])
            ->filter(fn($item) => $item['branch_id'] > 0 && $item['department_id'] > 0)
            ->unique(fn($item) => $item['branch_id'] . '-' . $item['department_id'])
            ->values();

        if ($assignments->isEmpty()) {
            $branchId = (int) ($user->cabang_id ?? 0);
            $departmentId = (int) ($user->departemen_id ?? 0);

            if ($branchId > 0 && $departmentId > 0) {
                $assignments->push([
                    'branch_id' => $branchId,
                    'department_id' => $departmentId,
                ]);
            }
        }

        return $assignments->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper: item
    |--------------------------------------------------------------------------
    | Item dikirim sebagai JSON string karena form memakai multipart agar bisa
    | membawa lampiran sekaligus.
    |--------------------------------------------------------------------------
    */
    private function decodeItems(mixed $rawItems): array
    {
        $items = json_decode((string) $rawItems, true);

        if (!is_array($items) || count($items) === 0) {
            throw ValidationException::withMessages([
                'items' => [__('cash_advance_messages.items.required')],
            ]);
        }

        $normalized = [];

        foreach ($items as $index => $item) {
            $description = $this->clean($item['description'] ?? '');

            if ($description === '') {
                throw ValidationException::withMessages([
                    'items' => [
                        __('cash_advance_messages.items.description_required', [
                            'row' => $index + 1,
                        ]),
                    ],
                ]);
            }

            $amount = (float) ($item['amount'] ?? 0);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'items' => [
                        __('cash_advance_messages.items.amount_required', [
                            'row' => $index + 1,
                        ]),
                    ],
                ]);
            }

            $date = trim((string) ($item['date'] ?? ''));

            if ($date === '') {
                throw ValidationException::withMessages([
                    'items' => [
                        __('cash_advance_messages.items.date_required', [
                            'row' => $index + 1,
                        ]),
                    ],
                ]);
            }

            /*
            | Id baris lama dibawa formulir supaya barisnya bisa diperbarui di
            | tempat, bukan dihapus lalu dibuat ulang. Baris baru mengirim null.
            */
            $id = (int) ($item['id'] ?? 0);

            $normalized[] = [
                'id' => $id > 0 ? $id : null,
                'date' => $date !== '' ? $date : null,
                'description' => $description,
                'amount' => round($amount, 2),
            ];
        }

        return $normalized;
    }

    /**
     * Menyelaraskan baris rincian dengan isi formulir.
     *
     * Baris lama diperbarui di tempat sehingga id-nya -- yang dipakai baris
     * lampiran dan nama folder di server -- tetap sama. Baris yang hilang dari
     * formulir dihapus beserta lampiran dan berkas fisiknya.
     *
     * @param  array<int, array{id:int|null, date:?string, description:string, amount:float}>  $items
     * @return array<int, int>  nomor urut baris pada formulir -> id baris
     */
    private function syncItems(CashAdvance $cashAdvance, array $items): array
    {
        $existingIds = CashAdvanceItem::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->pluck('id')
            ->all();

        $itemIdsByIndex = [];
        $keptIds = [];

        foreach ($items as $index => $item) {
            $id = $item['id'];

            /*
            | Id yang tidak dikenal diperlakukan sebagai baris baru, bukan
            | ditolak: kiriman formulir tidak boleh bisa menyentuh baris milik
            | dokumen lain hanya dengan menebak angka.
            */
            $row = $id !== null && in_array($id, $existingIds, true)
                ? CashAdvanceItem::query()->find($id)
                : null;

            if ($row) {
                $row->update([
                    'date' => $item['date'],
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                ]);
            } else {
                $row = CashAdvanceItem::create([
                    'cash_advance_id' => $cashAdvance->id,
                    'date' => $item['date'],
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                ]);
            }

            $itemIdsByIndex[(int) $index] = (int) $row->id;
            $keptIds[] = (int) $row->id;
        }

        $removedIds = array_values(array_diff($existingIds, $keptIds));

        if ($removedIds !== []) {
            $this->purgeItemAttachments($cashAdvance, $removedIds);

            CashAdvanceItem::query()->whereIn('id', $removedIds)->delete();
        }

        return $itemIdsByIndex;
    }

    /**
     * Membuang lampiran dan berkas fisik milik baris yang dihapus.
     *
     * Baris lampirannya dihapus di sini, tidak diserahkan pada cascade foreign
     * key: CashAdvanceItem memakai soft delete, sehingga barisnya sebenarnya
     * masih ada di tabel dan cascade tidak pernah terpicu. Berkas di disk juga
     * tidak ikut terhapus sendiri.
     *
     * @param  array<int, int>  $itemIds
     */
    private function purgeItemAttachments(CashAdvance $cashAdvance, array $itemIds): void
    {
        $attachments = CashAdvanceAttachment::query()
            ->whereIn('cash_advance_item_id', $itemIds)
            ->get();

        foreach ($attachments as $attachment) {
            if ($attachment->filepath && Storage::disk('public')->exists($attachment->filepath)) {
                Storage::disk('public')->delete($attachment->filepath);
            }

            $attachment->delete();
        }

        foreach ($itemIds as $itemId) {
            $folder = "syopv4/uploads/cash_advances/attachments/{$cashAdvance->id}/{$itemId}";

            if (Storage::disk('public')->exists($folder)) {
                Storage::disk('public')->deleteDirectory($folder);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helper: lampiran
    |--------------------------------------------------------------------------
    */
    /**
     * Menyimpan berkas unggahan menjadi baris lampiran.
     *
     * Dipakai dua jalur: lampiran pengajuan oleh pemohon, dan bukti transfer
     * oleh Finance saat pencairan. Keduanya berbagi tabel yang sama dan
     * dibedakan lewat $attachmentType, sementara berkas fisiknya dipisah ke
     * subfolder masing-masing agar mudah ditelusuri di server.
     */
    private function storeAttachments(
        Request $request,
        CashAdvance $cashAdvance,
        string $attachmentType = CashAdvanceAttachment::TYPE_DISBURSEMENT,
        string $inputName = 'attachments',
        ?int $itemId = null,
    ): array {
        if (!$request->hasFile($inputName)) {
            return [];
        }

        return $this->persistUploadedFiles(
            $request->file($inputName),
            $cashAdvance,
            $attachmentType,
            $itemId,
        );
    }

    /**
     * Menulis berkas unggahan ke disk beserta baris lampirannya.
     *
     * Berkas milik baris rincian masuk ke folder bernomor baris itu, sehingga
     * struktur di server mengikuti struktur dokumennya:
     *
     *   attachments/{cash_advance_id}/{cash_advance_item_id}/
     *
     * Bukti transfer tidak punya baris induk dan tetap di folder dokumen.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile|null>  $files
     */
    private function persistUploadedFiles(
        array $files,
        CashAdvance $cashAdvance,
        string $attachmentType,
        ?int $itemId,
    ): array {
        $storedPaths = [];

        $subfolder = match ($attachmentType) {
            CashAdvanceAttachment::TYPE_DISBURSEMENT => 'disbursements',
            CashAdvanceAttachment::TYPE_RECEIPT => 'receipts',
            default => 'attachments',
        };

        $folder = "syopv4/uploads/cash_advances/{$subfolder}/{$cashAdvance->id}";

        if ($itemId !== null) {
            $folder .= '/' . $itemId;
        }

        Storage::disk('public')->makeDirectory($folder);

        $fullFolderPath = storage_path('app/public/' . $folder);

        if (File::exists($fullFolderPath)) {
            @chmod($fullFolderPath, 0777);
        }

        foreach ($files as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }

            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $extension = strtolower($file->getClientOriginalExtension());

            $safeOriginalName = Str::slug($originalName) ?: 'file';

            $filename = str_replace('/', '-', (string) $cashAdvance->advance_number)
                . '_' . now()->format('YmdHis')
                . '_' . uniqid()
                . '_' . $safeOriginalName
                . '.' . $extension;

            $path = $file->storeAs($folder, $filename, 'public');

            $storedPaths[] = $path;

            $fullFilePath = storage_path('app/public/' . $path);

            if (File::exists($fullFilePath)) {
                @chmod($fullFilePath, 0777);
            }

            CashAdvanceAttachment::create([
                'cash_advance_id' => $cashAdvance->id,
                'cash_advance_item_id' => $itemId,
                'attachment_type' => $attachmentType,
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'filepath' => $path,
            ]);
        }

        return $storedPaths;
    }

    /**
     * Menyimpan lampiran per baris rincian.
     *
     * Frontend mengirim berkas dengan kunci nomor urut baris pada formulir,
     * bukan id -- baris baru belum punya id saat berkasnya dipilih. Pemetaan
     * nomor urut ke id dilakukan di sini, setelah barisnya tersimpan.
     *
     * @param  array<int, int>  $itemIdsByIndex
     */
    private function storeLineAttachments(
        Request $request,
        CashAdvance $cashAdvance,
        array $itemIdsByIndex,
    ): array {
        $grouped = $request->file('line_attachments');

        if (!is_array($grouped)) {
            return [];
        }

        $storedPaths = [];

        foreach ($grouped as $index => $files) {
            $itemId = $itemIdsByIndex[(int) $index] ?? null;

            if ($itemId === null || !is_array($files)) {
                continue;
            }

            $storedPaths = array_merge(
                $storedPaths,
                $this->persistUploadedFiles(
                    $files,
                    $cashAdvance,
                    CashAdvanceAttachment::TYPE_REQUEST,
                    $itemId,
                ),
            );
        }

        return $storedPaths;
    }

    /**
     * Memastikan setiap baris rincian punya minimal satu bukti.
     *
     * Dihitung dari gabungan berkas yang baru diunggah dan lampiran lama yang
     * tidak dihapus, supaya menyunting draft tanpa menyentuh lampiran tidak
     * memaksa user mengunggah ulang.
     *
     * @param  array<int, int>  $itemIdsByIndex  nomor urut baris -> id baris
     * @param  array<int, int>  $deletedIds
     *
     * @throws ValidationException
     */
    private function assertEveryLineHasAttachment(
        Request $request,
        array $itemIdsByIndex,
        array $deletedIds,
    ): void {
        $grouped = $request->file('line_attachments');
        $grouped = is_array($grouped) ? $grouped : [];

        $errors = [];

        foreach ($itemIdsByIndex as $index => $itemId) {
            $uploaded = collect($grouped[$index] ?? [])
                ->filter(fn($file) => $file && $file->isValid())
                ->count();

            $kept = CashAdvanceAttachment::query()
                ->where('cash_advance_item_id', $itemId)
                ->where('attachment_type', CashAdvanceAttachment::TYPE_REQUEST)
                ->when(
                    $deletedIds !== [],
                    fn($query) => $query->whereNotIn('id', $deletedIds),
                )
                ->count();

            if ($uploaded + $kept < 1) {
                $errors["line_attachments.{$index}"] = [
                    __('cash_advance_messages.items.attachment_required', [
                        'row' => (int) $index + 1,
                    ]),
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Id lampiran yang diminta dihapus, sudah dibersihkan.
     *
     * @return array<int, int>
     */
    private function requestedDeletedAttachmentIds(Request $request): array
    {
        $raw = $request->input('deleted_attachment_ids');

        if (blank($raw)) {
            return [];
        }

        $ids = json_decode((string) $raw, true);

        if (!is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->map(fn($id) => (int) $id)
            ->filter(fn(int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function deleteRequestedAttachments(Request $request, CashAdvance $cashAdvance): void
    {
        $raw = $request->input('deleted_attachment_ids');

        if (blank($raw)) {
            return;
        }

        $ids = json_decode((string) $raw, true);

        if (!is_array($ids)) {
            return;
        }

        $ids = collect($ids)
            ->map(fn($id) => (int) $id)
            ->filter(fn(int $id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        /*
        | Dibatasi pada lampiran pengajuan. Bukti transfer milik Finance tidak
        | boleh ikut terhapus lewat form sunting pemohon.
        */
        $attachments = CashAdvanceAttachment::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->where('attachment_type', CashAdvanceAttachment::TYPE_REQUEST)
            ->whereIn('id', $ids->all())
            ->get();

        foreach ($attachments as $attachment) {
            if ($attachment->filepath && Storage::disk('public')->exists($attachment->filepath)) {
                Storage::disk('public')->delete($attachment->filepath);
            }

            $attachment->delete();
        }
    }

    private function cleanupStoredFiles(array $storedPaths): void
    {
        foreach ($storedPaths as $path) {
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helper: transform response
    |--------------------------------------------------------------------------
    */
    /**
     * @param  array{step_order: int, label: string|null, approved_at: string|null}|null  $myTripApproval
     */
    private function transformDetail(
        CashAdvance $cashAdvance,
        ?CashAdvanceApproval $currentApproval,
        ?array $myTripApproval = null,
    ): array {
        return [
            'id' => $cashAdvance->id,
            'public_id' => $cashAdvance->encrypted_id,
            'advance_number' => $cashAdvance->advance_number,
            'date' => optional($cashAdvance->date)->toDateString(),
            'subject' => $cashAdvance->subject,

            'transaction_category_id' => $cashAdvance->transaction_category_id,
            'transaction_category' => $cashAdvance->transactionCategory?->name,
            'request_type' => $cashAdvance->request_type,

            /*
            | Perjalanan dinas yang mendasari FPU ini.
            |
            | Dibawa sampai ke detail, bukan hanya ke kotak konfirmasi Approve:
            | detail inilah yang dibaca sebelum memutuskan. Isinya konteks --
            | perdin tidak memuat nominal, jadi persetujuan perjalanan tidak
            | pernah bisa mewakili persetujuan jumlah uangnya.
            */
            'business_trip' => $cashAdvance->businessTrip
                ? array_merge(
                    /*
                    | Bentuknya sama persis dengan baris di halaman perdin,
                    | disusun penyaji yang sama, supaya modal rinciannya bisa
                    | memakai komponen yang sama -- bukan salinan yang lambat
                    | laun berbeda.
                    */
                    BusinessTripPresenter::row($cashAdvance->businessTrip),
                    [
                        /* Persetujuan perjalanan yang pernah diberikan pembacanya. */
                        'my_approval' => $myTripApproval,

                        /*
                        | Kalau perjalanannya belum disetujui, dananya memang
                        | belum bisa cair. Disebut apa adanya supaya penyetuju
                        | FPU tahu keadaannya, bukan menemukannya saat
                        | pencairan ditahan.
                        */
                        'disbursement_block' => $this->businessTripBlockingDisbursement($cashAdvance),
                    ],
                )
                : null,

            'branch' => $cashAdvance->branchData?->nama_cabang ?? '-',
            'branch_id' => $cashAdvance->branch,

            'department' => $cashAdvance->departmentData?->kode ?? '-',
            'department_name' => $cashAdvance->departmentData?->nama ?? '-',
            'department_id' => $cashAdvance->department_id,

            'total_amount' => (float) $cashAdvance->total_amount,
            'notes' => $cashAdvance->notes,
            'status' => $cashAdvance->status,

            'can_approve' => $currentApproval !== null,
            'approval_id' => $currentApproval?->id,
            'approval_step_order' => $currentApproval
                ? (int) $currentApproval->step_order
                : null,
            'approval_label' => $currentApproval?->label,

            'created_at' => $cashAdvance->created_at,
            'created_by' => $cashAdvance->created_by,
            'created_by_name' => $cashAdvance->creator?->name,

            'submitted_at' => $cashAdvance->submitted_at,
            'submitted_by_name' => $cashAdvance->submitter?->name,
            'requester_signature_path' => $cashAdvance->requester_signature_path,

            'final_approved_at' => $cashAdvance->final_approved_at,
            'final_approved_by_name' => $cashAdvance->finalApprover?->name,

            'rejected_at' => $cashAdvance->rejected_at,
            'rejected_by_name' => $cashAdvance->rejecter?->name,
            'rejection_notes' => $cashAdvance->rejection_notes,

            'cancelled_at' => $cashAdvance->cancelled_at,
            'cancelled_by_name' => $cashAdvance->canceller?->name,
            'cancellation_notes' => $cashAdvance->cancellation_notes,

            'received_at' => $cashAdvance->received_at,
            'scheduled_payment_date' => $cashAdvance->scheduled_payment_date,
            'can_pay_today' => $this->canPayToday(
                $cashAdvance->scheduled_payment_date,
                'FPU',
                $cashAdvance->transaction_category_id,
            ),
            'payment_days_text' => $this->paymentDaysText(
                'FPU',
                $cashAdvance->transaction_category_id,
            ),
            'payment_timing' => $this->paymentTiming(
                $cashAdvance->scheduled_payment_date,
                $cashAdvance->disbursed_at,
            ),
            'received_by_name' => $cashAdvance->receiver?->name,
            'receipt_notes' => $cashAdvance->receipt_notes,

            'disbursed_at' => $cashAdvance->disbursed_at,
            'disbursed_by_name' => $cashAdvance->disburser?->name,
            'disbursement_notes' => $cashAdvance->disbursement_notes,

            /*
            | Dipakai detail untuk menandai FPU yang dananya sudah keluar tetapi
            | belum dipertanggungjawabkan. Dihitung dari relasi, bukan disimpan,
            | supaya tidak pernah basi.
            */
            'has_realization' => $cashAdvance->realization()->exists(),

            'items' => $this->transformItems($cashAdvance),
            'attachments' => $this->transformAttachments($cashAdvance),

            /*
            | Berkas dari PIC saat menandai dokumen diterima, dan bukti
            | transfer dari Finance saat mencairkan. Keduanya dipisah dari
            | 'attachments' supaya form sunting pemohon tidak menganggapnya
            | berkas miliknya.
            */
            'receipt_attachments' => $this->transformReceiptAttachments($cashAdvance),
            'disbursement_attachments' => $this->transformDisbursementAttachments($cashAdvance),

            'approvals' => $cashAdvance->approvals
                ->map(fn(CashAdvanceApproval $approval): array => [
                    'id' => $approval->id,
                    'step_order' => (int) $approval->step_order,
                    'label' => $approval->label,
                    'approver_type' => $approval->approver_type,
                    'approver_id' => $approval->approver_id,
                    'approver_name' => $approval->approver_name_snapshot,
                    'approval_mode' => $approval->approval_mode,
                    'status' => $approval->status,
                    'signature_path' => $approval->signature_path,
                    'approved_at' => $approval->approved_at,
                    'rejected_at' => $approval->rejected_at,
                    'notes' => $approval->notes,
                ])
                ->values(),
        ];
    }

    private function transformItems(CashAdvance $cashAdvance)
    {
        /*
        | Lampiran dikelompokkan sekali di sini, bukan diambil per baris,
        | supaya jumlah baris rincian tidak berubah menjadi jumlah query.
        */
        $attachmentsByItem = $cashAdvance->attachments
            ->filter(
                fn(CashAdvanceAttachment $attachment): bool =>
                strtoupper(trim((string) $attachment->attachment_type))
                    === CashAdvanceAttachment::TYPE_REQUEST
                && $attachment->cash_advance_item_id !== null,
            )
            ->groupBy('cash_advance_item_id');

        return $cashAdvance->items
            ->map(fn(CashAdvanceItem $item): array => [
                'id' => $item->id,
                'date' => optional($item->date)->toDateString(),
                'description' => $item->description,
                'amount' => (float) $item->amount,

                'attachments' => collect($attachmentsByItem->get($item->id, []))
                    ->map(fn(CashAdvanceAttachment $attachment): array => [
                        'id' => $attachment->id,
                        'filename' => $attachment->filename,
                        'original_filename' => $attachment->original_filename,
                        'mime_type' => $attachment->mime_type,
                        'file_size' => $attachment->file_size,
                        'url' => $attachment->filepath
                            ? Storage::disk('public')->url($attachment->filepath)
                            : null,
                    ])
                    ->values(),
            ])
            ->values();
    }

    /**
     * Lampiran pengajuan yang tidak melekat pada baris rincian.
     *
     * Sejak lampiran dipindah ke tingkat baris, isinya hanya berkas lama dari
     * dokumen yang dibuat sebelum aturan itu berlaku. Ditampilkan terpisah
     * supaya dokumen lama tidak kehilangan buktinya, bukan karena masih ada
     * jalur yang membuat lampiran seperti ini.
     */
    private function transformAttachments(CashAdvance $cashAdvance)
    {
        return $this->mapAttachments(
            $cashAdvance,
            CashAdvanceAttachment::TYPE_REQUEST,
            documentLevelOnly: true,
        );
    }

    /** Berkas yang dilampirkan PIC saat menandai dokumen diterima. */
    private function transformReceiptAttachments(CashAdvance $cashAdvance)
    {
        return $this->mapAttachments(
            $cashAdvance,
            CashAdvanceAttachment::TYPE_RECEIPT,
        );
    }

    /** Bukti transfer yang dilampirkan Finance saat pencairan. */
    private function transformDisbursementAttachments(CashAdvance $cashAdvance)
    {
        return $this->mapAttachments(
            $cashAdvance,
            CashAdvanceAttachment::TYPE_DISBURSEMENT,
        );
    }

    private function mapAttachments(
        CashAdvance $cashAdvance,
        string $attachmentType,
        bool $documentLevelOnly = false,
    ) {
        return $cashAdvance->attachments
            ->filter(
                fn(CashAdvanceAttachment $attachment): bool =>
                strtoupper(trim((string) $attachment->attachment_type)) === $attachmentType
                && (!$documentLevelOnly || $attachment->cash_advance_item_id === null),
            )
            ->map(fn(CashAdvanceAttachment $attachment): array => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'original_filename' => $attachment->original_filename,
                'mime_type' => $attachment->mime_type,
                'file_size' => $attachment->file_size,
                'url' => $attachment->filepath
                    ? Storage::disk('public')->url($attachment->filepath)
                    : null,
            ])
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper kecil
    |--------------------------------------------------------------------------
    */

    /**
     * Membersihkan input teks dari tag HTML dan entity yang ter-encode berlapis.
     */
    private function clean(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = trim((string) $value);

        // Contoh entity berlapis: &amp;quot; -> &quot; -> "
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($decoded === $text) {
                break;
            }

            $text = $decoded;
        }

        $text = strip_tags($text);

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
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
            'can_submit' => false,
            'can_delete' => false,
            'can_cancel' => false,
            'can_receive' => false,
            'can_disburse' => false,
            'can_create_realization' => false,
        ];
    }
}
