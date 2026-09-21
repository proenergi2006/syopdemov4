<?php

namespace App\Http\Controllers\Api;

use App\Exports\ClaimExport;
use App\Exceptions\BulkDocumentActionException;
use App\Http\Controllers\Concerns\ProcessesBulkDocumentAction;
use App\Http\Controllers\Concerns\SchedulesPayment;
use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\ClaimApproval;
use App\Models\ClaimAttachment;
use App\Models\ClaimItem;
use App\Services\FundRequest\Claim\ClaimApprovalGeneratorService;
use App\Services\FundRequest\Claim\ClaimApprovalService;
use App\Services\FundRequest\Claim\ClaimMailService;
use App\Services\FundRequest\Claim\ClaimNotificationService;
use App\Services\FundRequest\Claim\ClaimNumberService;
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
| Claim
|--------------------------------------------------------------------------
| Modul Pengajuan Dana. Kebalikan FPU: uangnya sudah lebih dulu dikeluarkan
| pemohon, lalu diminta penggantiannya -- sehingga bukti pengeluaran sudah
| menempel sejak dokumen dibuat dan tidak ada dokumen realisasi.
|
| Alur dokumen:
|
|   DRAFT -> IN PROGRESS -> APPROVED -> PAID
|                        \-> REJECTED
|             (APPROVED) \-> CANCELLED
|
| Yang sudah dibangun: CRUD, submit, dan approval. Tahap Finance (menandai
| penggantian sudah dibayarkan), pembatalan, export, dan cetak menyusul.
|--------------------------------------------------------------------------
*/
class ClaimController extends Controller
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
    | Daftar Claim
    |--------------------------------------------------------------------------
    */
    public function index(
        Request $request,
        ClaimApprovalService $approvalService,
    ): JsonResponse {
        $user = $request->user();

        $perPage = (int) $request->input('per_page', 10);
        $perPage = $perPage > 0 ? $perPage : 10;

        try {
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.user_not_authenticated'),
                    'data' => [],
                    'meta' => $this->emptyMeta($perPage),
                    'abilities' => $this->emptyAbilities(),
                ], 401);
            }

            $context = $this->resolveViewContext($user);

            $canSubmit = $user->hasPermission('claim.submit');

            $abilities = [
                'can_view' => $user->hasPermission('claim.view'),
                'view_scope' => $context['scope'],
                'can_create' => $user->hasPermission('claim.create'),
                'can_update' => $user->hasPermission('claim.update'),
                'can_submit' => $canSubmit,
                'can_delete' => $user->hasPermission('claim.delete'),
                'can_cancel' => $user->hasPermission('claim.cancel'),
                'can_receive' => $user->hasPermission('claim.receive'),
                'can_pay' => $user->hasPermission('claim.pay'),
            ];

            $query = Claim::query()
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
                    ->where('claims.status', Claim::STATUS_IN_PROGRESS)
                    ->whereHas('approvals', function ($approvalQuery) use ($context, $user) {
                        $approvalQuery->where(
                            'claim_approvals.status',
                            ClaimApproval::STATUS_WAITING,
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
                        ->whereRaw('claims.status = ?', [Claim::STATUS_IN_PROGRESS])
                        ->where(
                            'claim_approvals.status',
                            ClaimApproval::STATUS_WAITING,
                        );

                    $this->applyApproverMatch($approvalQuery, $user, $context['userRoleIds']);
                },
            ]);

            $records = $query
                ->orderByDesc('is_waiting_my_approval')
                ->orderByDesc('claims.id')
                ->paginate($perPage);

            $records->through(
                function (Claim $claim) use (
                    $user,
                    $approvalService,
                    $canSubmit,
                ): array {
                    $status = strtoupper(trim((string) $claim->status));

                    $isCreator = (int) $claim->created_by === (int) $user->id;

                    $isSameDepartment = (
                        $user->departemen_id !== null
                        && (int) $claim->department_id === (int) $user->departemen_id
                    );

                    $rowCanSubmit = (
                        $canSubmit
                        && $status === Claim::STATUS_DRAFT
                        && ($isCreator || $isSameDepartment)
                    );

                    $currentApproval = $claim->approvals->first(
                        fn(ClaimApproval $approval): bool =>
                        strtoupper((string) $approval->status) === ClaimApproval::STATUS_WAITING
                            && $approvalService->userCanApprove($approval, $user),
                    );

                    return [
                        'id' => $claim->id,
                        'public_id' => $claim->encrypted_id,
                        'claim_number' => $claim->claim_number,
                        'date' => optional($claim->date)->toDateString(),
                        'subject' => $claim->subject,

                        'transaction_category_id' => $claim->transaction_category_id,
                        'transaction_category' => $claim->transactionCategory?->name,

                        'branch' => $claim->branchData?->nama_cabang ?? '-',
                        'branch_id' => $claim->branch,

                        'department' => $claim->departmentData?->kode ?? '-',
                        'department_name' => $claim->departmentData?->nama ?? '-',
                        'department_id' => $claim->department_id,

                        'total_amount' => (float) $claim->total_amount,
                        'notes' => $claim->notes,
                        'status' => $claim->status,

                        'can_submit' => $rowCanSubmit,
                        'can_approve' => $currentApproval !== null,

                        'approval_id' => $currentApproval?->id,
                        'approval_step_order' => $currentApproval
                            ? (int) $currentApproval->step_order
                            : null,
                        'approval_label' => $currentApproval?->label,
                        'approval_mode' => $currentApproval?->approval_mode,

                        'item_count' => $claim->items->count(),

                        'created_at' => $claim->created_at,
                        'created_by' => $claim->created_by,

                        'submitted_at' => $claim->submitted_at,
                        'submitted_by' => $claim->submitted_by,

                        'received_at' => $claim->received_at,
                        'scheduled_payment_date' => $claim->scheduled_payment_date,
                        'can_pay_today' => $this->canPayToday(
                            $claim->scheduled_payment_date,
                            'CLAIM',
                            $claim->transaction_category_id,
                        ),
                        'payment_timing' => $this->paymentTiming(
                            $claim->scheduled_payment_date,
                            $claim->paid_at,
                        ),
                        'paid_at' => $claim->paid_at,
                    ];
                },
            );

            return response()->json([
                'success' => true,
                'message' => __('claim_messages.index.loaded'),
                'data' => $records->items(),
                'meta' => [
                    'current_page' => $records->currentPage(),
                    'last_page' => $records->lastPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                ],
                'abilities' => $abilities,

                /*
                | Hari kerja kasir, dikirim sekali untuk seluruh daftar -- dipakai
                | layar menjelaskan kenapa tombol bayarnya mati hari ini.
                */
                'payment_days_text' => $this->paymentDaysText('CLAIM'),

                /* Angkanya juga, supaya layar bisa merangkai namanya sendiri. */
                'payment_days' => $this->paymentDays('CLAIM'),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Claim] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $user?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.index.load_failed'),
                'data' => [],
                'meta' => $this->emptyMeta($perPage),
                'abilities' => $this->emptyAbilities(),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Simpan Claim baru
    |--------------------------------------------------------------------------
    */
    public function store(
        Request $request,
        ClaimNumberService $numberService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission('claim.create')) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.store.forbidden'),
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

            $claim = Claim::create([
                // Nomor final dibentuk saat submit, bukan saat draft dibuat.
                'claim_number' => $numberService->generateDraftNumber(),

                'date' => $request->input('date'),
                'subject' => $this->clean($request->input('subject')),

                'transaction_category_id'
                => (int) $request->input('transaction_category_id'),

                'branch' => (string) $request->input('branch'),
                'department_id' => (int) $request->input('department_id'),
                'total_amount' => $totalAmount,
                'notes' => $this->clean($request->input('notes')) ?: null,
                'status' => Claim::STATUS_DRAFT,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $itemIdsByIndex = [];

            foreach ($items as $index => $item) {
                $row = ClaimItem::create([
                    'claim_id' => $claim->id,
                    'date' => $item['date'],
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                ]);

                $itemIdsByIndex[(int) $index] = (int) $row->id;
            }

            $this->assertEveryLineHasAttachment($request, $itemIdsByIndex, []);

            $storedPaths = $this->storeLineAttachments(
                $request,
                $claim,
                $itemIdsByIndex,
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('claim_messages.store.success'),
                'data' => [
                    'id' => $claim->id,
                    'public_id' => $claim->encrypted_id,
                    'claim_number' => $claim->claim_number,
                ],
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('claim_messages.store.invalid'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            Log::error('[Claim] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.store.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Detail Claim
    |--------------------------------------------------------------------------
    */
    public function show(
        string $publicId,
        Request $request,
        ClaimApprovalService $approvalService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.user_not_authenticated'),
            ], 401);
        }

        try {
            $claim = $this->findVisibleClaim($publicId, $user);

            if (!$claim) {
                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.show.not_found'),
                ], 404);
            }

            $claim->load([
                'branchData',
                'departmentData',
                'transactionCategory',
                'items',
                'attachments',
                'creator:id,name',
                'submitter:id,name',
                'finalApprover:id,name',
                'rejecter:id,name',
                'canceller:id,name',
                'receiver:id,name',
                'payer:id,name',
                'approvals' => function ($approvalQuery) {
                    $approvalQuery->orderBy('step_order')->orderBy('id');
                },
            ]);

            $currentApproval = $claim->approvals->first(
                fn(ClaimApproval $approval): bool =>
                strtoupper((string) $approval->status) === ClaimApproval::STATUS_WAITING
                    && $approvalService->userCanApprove($approval, $user),
            );

            return response()->json([
                'success' => true,
                'message' => __('claim_messages.show.loaded'),
                'data' => $this->transformDetail($claim, $currentApproval),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Claim] Show error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.show.failed'),
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

        if (!$user || !$user->hasPermission('claim.update')) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.update.forbidden'),
            ], 403);
        }

        try {
            $claim = $this->findVisibleClaim($publicId, $user);

            if (!$claim) {
                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.show.not_found'),
                ], 404);
            }

            $claim->load(['items', 'attachments']);

            return response()->json([
                'success' => true,
                'message' => __('claim_messages.show.loaded'),
                'data' => [
                    'id' => $claim->id,
                    'public_id' => $claim->encrypted_id,
                    'claim_number' => $claim->claim_number,
                    'date' => optional($claim->date)->toDateString(),
                    'subject' => $claim->subject,
                    'transaction_category_id' => $claim->transaction_category_id,
                    'branch' => $claim->branch,
                    'department_id' => $claim->department_id,
                    'total_amount' => (float) $claim->total_amount,
                    'notes' => $claim->notes,
                    'status' => $claim->status,
                    'items' => $this->transformItems($claim),
                    'attachments' => $this->transformAttachments($claim),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Claim] Edit error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.show.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Ubah Claim
    |--------------------------------------------------------------------------
    | Hanya berlaku pada dokumen DRAFT. Setelah disubmit, isi dokumen sudah
    | menjadi dasar approval sehingga tidak boleh berubah.
    |--------------------------------------------------------------------------
    */
    public function update(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('claim.update')) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.update.forbidden'),
            ], 403);
        }

        $storedPaths = [];

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $claim = Claim::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $claim->status) !== Claim::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.update.only_draft'),
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

            $claim->update([
                'date' => $request->input('date'),
                'subject' => $this->clean($request->input('subject')),

                'transaction_category_id'
                => (int) $request->input('transaction_category_id'),

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
            $itemIdsByIndex = $this->syncItems($claim, $items);

            $deletedIds = $this->requestedDeletedAttachmentIds($request);

            $this->assertEveryLineHasAttachment($request, $itemIdsByIndex, $deletedIds);

            $this->deleteRequestedAttachments($request, $claim);

            $storedPaths = $this->storeLineAttachments(
                $request,
                $claim,
                $itemIdsByIndex,
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('claim_messages.update.success'),
                'data' => [
                    'id' => $claim->id,
                    'public_id' => $claim->encrypted_id,
                    'claim_number' => $claim->claim_number,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('claim_messages.store.invalid'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            Log::error('[Claim] Update error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.update.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Hapus Claim
    |--------------------------------------------------------------------------
    | Hanya draft yang boleh dihapus. Dokumen yang sudah masuk approval harus
    | melalui reject atau cancel supaya jejaknya tetap ada.
    |--------------------------------------------------------------------------
    */
    public function destroy(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('claim.delete')) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.destroy.forbidden'),
            ], 403);
        }

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $claim = Claim::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $claim->status) !== Claim::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.destroy.only_draft'),
                ], 422);
            }

            $claim->items()->delete();
            $claim->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('claim_messages.destroy.success'),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Claim] Destroy error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.destroy.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Submit ke proses approval
    |--------------------------------------------------------------------------
    */
    public function submit(
        string $publicId,
        Request $request,
        ClaimNumberService $numberService,
        ClaimApprovalGeneratorService $approvalGenerator,
        ClaimNotificationService $notificationService,
        ClaimMailService $mailService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission('claim.submit')) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.submit.forbidden'),
            ], 403);
        }

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $claim = Claim::with(['items'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $claim->status) !== Claim::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.submit.only_draft'),
                ], 422);
            }

            if ($claim->items->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.submit.items_unavailable'),
                ], 422);
            }

            $requesterSignaturePath = $user->signature_path;

            if (blank($requesterSignaturePath)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.submit.signature_missing'),
                ], 422);
            }

            /*
            | Nomor final baru dibentuk di sini supaya deret nomor tidak habis
            | terpakai oleh draft yang batal diajukan.
            */
            if (str_starts_with((string) $claim->claim_number, 'DRAFT/')) {
                $claim->claim_number = $numberService->generateFinalNumber($claim);
            }

            $approvalGenerator->generate($claim);

            $submittedAt = now();

            $claim->status = Claim::STATUS_IN_PROGRESS;
            $claim->submitted_by = $user->id;
            $claim->submitted_at = $submittedAt;

            $claim->requester_signed_by = $user->id;
            $claim->requester_signature_path = $requesterSignaturePath;
            $claim->requester_signed_at = $submittedAt;

            $claim->save();

            DB::commit();

            $claim->refresh();

            try {
                $notificationService->notifyApprovalRequest($claim);
            } catch (\Throwable $notificationError) {
                Log::error('[Claim] Notifikasi approver gagal dibuat', [
                    'claim_id' => $claim->id,
                    'claim_number' => $claim->claim_number,
                    'message' => $notificationError->getMessage(),
                ]);
            }

            /*
            | Email dipisah dari notifikasi in-app supaya kegagalan salah satu
            | tidak ikut membatalkan yang lain.
            */
            try {
                $mailService->sendApprovalRequest($claim);
            } catch (\Throwable $mailError) {
                Log::error('[Claim] Email approver gagal dikirim', [
                    'claim_id' => $claim->id,
                    'claim_number' => $claim->claim_number,
                    'message' => $mailError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __('claim_messages.submit.success'),
                'data' => [
                    'id' => $claim->id,
                    'public_id' => $claim->encrypted_id,
                    'claim_number' => $claim->claim_number,
                    'status' => $claim->status,
                    'submitted_at' => $claim->submitted_at,
                    'submitted_by' => $claim->submitted_by,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('claim_messages.submit.failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Claim] Submit error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.submit.failed'),
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
        ClaimApprovalService $approvalService,
        ClaimNotificationService $notificationService,
        ClaimMailService $mailService,
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
                    'message' => __('claim_messages.user_not_authenticated'),
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $claim = Claim::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $claim->status)
                !== Claim::STATUS_IN_PROGRESS
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.approve.not_in_progress'),
                ], 422);
            }

            $result = $approvalService->approveCurrentStep(
                $claim,
                $user,
                $validated['notes'] ?? null,
            );

            DB::commit();

            $claim->refresh();

            try {
                $notificationService->notifyApprovalStep(
                    $claim,
                    $user,
                    $result['approval'],
                    $result['has_pending_approval'],
                );

                /*
                | Pemohon hanya diemail saat approval tuntas; tahap tengah cukup
                | lewat notifikasi in-app.
                */
                if ($result['is_final_approved']) {
                    $mailService->sendApprovalStep($claim, $user, false);

                    /*
                    | Approval tuntas berarti dokumennya berpindah ke meja PIC
                    | penerimaan -- bukan langsung ke Finance. Pemberitahuannya
                    | berada di luar approval flow, jadi penerimanya pemegang
                    | permission penerimaan.
                    */
                    $notificationService->notifyReceiptRequest($claim);

                    $mailService->sendReceiptRequest($claim);
                }
            } catch (\Throwable $notifyError) {
                Log::error('[Claim] Notify approval result gagal', [
                    'claim_id' => $claim->id,
                    'message' => $notifyError->getMessage(),
                ]);
            }

            if (
                $result['step_completed']
                && $result['has_pending_approval']
                && $result['next_step_order'] !== null
            ) {
                try {
                    $notificationService->notifyApprovalRequest($claim);

                    $mailService->sendApprovalRequest($claim);
                } catch (\Throwable $nextApproverError) {
                    Log::error('[Claim] Notify next approver gagal', [
                        'claim_id' => $claim->id,
                        'next_step_order' => $result['next_step_order'],
                        'message' => $nextApproverError->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,

                'message' => $result['is_final_approved']
                    ? __('claim_messages.approve.final_success')
                    : (
                        $result['step_completed']
                        ? __('claim_messages.approve.step_success')
                        : __('claim_messages.approve.waiting_others')
                    ),

                'data' => [
                    'id' => $claim->id,
                    'public_id' => $claim->encrypted_id,
                    'claim_number' => $claim->claim_number,
                    'status' => $claim->status,
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
                    ?? __('claim_messages.approve.failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Claim] Approve error', [
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.approve.failed'),
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
        ClaimApprovalService $approvalService,
        ClaimNotificationService $notificationService,
        ClaimMailService $mailService,
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
                    'message' => __('claim_messages.user_not_authenticated'),
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $claim = Claim::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $claim->status)
                !== Claim::STATUS_IN_PROGRESS
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.reject.not_in_progress'),
                ], 422);
            }

            $approval = $approvalService->rejectCurrentStep(
                $claim,
                $user,
                $validated['notes'] ?? null,
            );

            DB::commit();

            $claim->refresh();

            try {
                $notificationService->notifyRejected($claim, $user);

                $mailService->sendRejected(
                    $claim,
                    $user,
                    $validated['notes'] ?? null,
                );
            } catch (\Throwable $notifyError) {
                Log::error('[Claim] Notify reject gagal', [
                    'claim_id' => $claim->id,
                    'message' => $notifyError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __('claim_messages.reject.success'),
                'data' => [
                    'id' => $claim->id,
                    'public_id' => $claim->encrypted_id,
                    'claim_number' => $claim->claim_number,
                    'status' => $claim->status,
                    'approval_id' => $approval->id,
                    'rejected_at' => $approval->rejected_at,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('claim_messages.reject.failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Claim] Reject error', [
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.reject.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pembayaran oleh Finance
    |--------------------------------------------------------------------------
    | Tahap terakhir Claim. Berada di luar approval flow: yang berwenang adalah
    | pemegang permission claim.pay, bukan approver dokumen ini.
    |
    | Tidak ada realisasi setelahnya -- bukti pengeluarannya sudah menempel
    | sejak dokumen dibuat, jadi PAID adalah akhir alurnya.
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | Pembayaran banyak Claim sekaligus
    |--------------------------------------------------------------------------
    | Finance lazimnya membayar sekumpulan claim dalam satu kali proses, bukan
    | satu per satu. Tindakannya sama persis dengan pembayaran satuan --
    | hanya tanpa catatan dan lampiran, yang memang sudah tidak diisi dari
    | layar mana pun.
    |
    | Tiap dokumen berdiri sendiri: satu yang gagal tidak menggagalkan yang
    | lain. Lihat ProcessesBulkDocumentAction untuk alasannya.
    |--------------------------------------------------------------------------
    */
    /**
     * Menandai dokumen yang sudah disetujui sebagai sudah diterima.
     *
     * Tahap antara approval dan tahap penutup: berkasnya dinyatakan sudah di
     * tangan, baru setelah itu uangnya berpindah. Sama seperti FPU.
     *
     * Catatan dan lampiran masih diterima di sini walau layarnya tidak lagi
     * mengirimnya -- jalurnya sengaja dibiarkan utuh.
     */
    public function receive(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('claim.receive')) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.receive.forbidden'),
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

            $claim = Claim::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $claim->status) !== Claim::STATUS_APPROVED) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.receive.only_approved'),
                ], 422);
            }

            $diterimaPada = now();

            $claim->update([
                'status' => Claim::STATUS_RECEIVED,
                'received_by' => $user->id,
                'received_at' => $diterimaPada,
                'receipt_notes' => $this->clean($validated['notes'] ?? '') ?: null,
                'scheduled_payment_date' => $this->freezePaymentDate(
                    'CLAIM',
                    $diterimaPada,
                    $claim->transaction_category_id,
                ),
            ]);

            $storedPaths = $request->hasFile('attachments')
                ? $this->persistUploadedFiles(
                    $request->file('attachments'),
                    $claim,
                    ClaimAttachment::TYPE_RECEIPT,
                    null,
                )
                : [];

            DB::commit();

            $claim->refresh();

            $this->notifyReceiptStage($claim, $user);

            return response()->json([
                'success' => true,
                'message' => __('claim_messages.receive.success'),
                'data' => [
                    'id' => $claim->id,
                    'public_id' => $claim->encrypted_id,
                    'status' => $claim->status,
                    'received_at' => $claim->received_at,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            $this->cleanupStoredFiles($storedPaths);

            Log::error('[Claim] Receive error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.receive.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Menandai banyak dokumen sekaligus sebagai sudah diterima.
     */
    public function bulkReceive(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('claim.receive')) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.receive.forbidden'),
            ], 403);
        }

        $validated = $request->validate($this->bulkDocumentRules());

        $hasil = $this->processBulkDocuments(
            $validated['public_ids'],
            '[Claim] Bulk receive',
            function (string $publicId) use ($user): string {
                $dokumen = DB::transaction(function () use ($publicId, $user): Claim {
                    $terlihat = $this->findVisibleClaim($publicId, $user);

                    if (!$terlihat) {
                        throw new BulkDocumentActionException('-', __('bulk_action.not_found'));
                    }

                    $terkunci = Claim::query()->lockForUpdate()->find($terlihat->id);

                    if (strtoupper((string) $terkunci->status) !== Claim::STATUS_APPROVED) {
                        throw new BulkDocumentActionException(
                            (string) $terkunci->claim_number,
                            __('claim_messages.receive.only_approved'),
                        );
                    }

                    $diterimaPada = now();

                    $terkunci->update([
                        'status' => Claim::STATUS_RECEIVED,
                        'received_by' => $user->id,
                        'received_at' => $diterimaPada,
                        'scheduled_payment_date' => $this->freezePaymentDate(
                            'CLAIM',
                            $diterimaPada,
                            $terkunci->transaction_category_id,
                        ),
                    ]);

                    return $terkunci;
                });

                $this->notifyReceiptStage($dokumen, $user);

                return (string) $dokumen->claim_number;
            },
        );

        return response()->json([
            'success' => $hasil['succeeded'] !== [],
            'message' => $this->bulkResultMessage($hasil),
            'data' => $hasil,
        ], 200);
    }

    /**
     * Pemberitahuan setelah dokumen ditandai diterima.
     *
     * Dua arah: pemohon diberi tahu berkasnya sudah diterima, dan giliran
     * pemegang tahap penutup dimulai di sini -- bukan lagi sejak approval
     * tuntas. Kegagalannya dicatat saja, tidak membatalkan yang sudah
     * tersimpan.
     */
    private function notifyReceiptStage(Claim $dokumen, $user): void
    {
        try {
            $notificationService = app(ClaimNotificationService::class);
            $mailService = app(ClaimMailService::class);

            $notificationService->notifyReceived($dokumen, $user);
            $mailService->sendReceived($dokumen, $user);

            $notificationService->notifyPaymentRequest($dokumen);
                    $mailService->sendPaymentRequest($dokumen);
        } catch (\Throwable $notifyError) {
            Log::error('[Claim] Notify received gagal', [
                'claim_id' => $dokumen->id,
                'message' => $notifyError->getMessage(),
            ]);
        }
    }

    public function bulkPay(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('claim.pay')) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.pay.forbidden'),
            ], 403);
        }

        $validated = $request->validate($this->bulkDocumentRules());

        $hasil = $this->processBulkDocuments(
            $validated['public_ids'],
            '[Claim] Bulk pay',
            function (string $publicId) use ($user): string {
                $claim = DB::transaction(function () use ($publicId, $user): Claim {
                    /*
                    | findVisibleClaim ikut menerapkan batas akses, jadi dokumen
                    | di luar wewenang user tidak bisa ikut terbawa hanya karena
                    | id-nya dikirim.
                    */
                    $claim = $this->findVisibleClaim($publicId, $user);

                    if (!$claim) {
                        throw new BulkDocumentActionException('-', __('bulk_action.not_found'));
                    }

                    $terkunci = Claim::query()->lockForUpdate()->find($claim->id);

                    if (strtoupper((string) $terkunci->status) !== Claim::STATUS_RECEIVED) {
                        throw new BulkDocumentActionException(
                            (string) $terkunci->claim_number,
                            __('claim_messages.pay.only_received'),
                        );
                    }

                    /* Pembayaran hanya boleh jatuh pada hari pembayaran. */
                    if (!$this->canPayToday(
                        $terkunci->scheduled_payment_date,
                        'CLAIM',
                        $terkunci->transaction_category_id,
                    )) {
                        throw new BulkDocumentActionException(
                            (string) $terkunci->claim_number,
                            __('payment_schedule_messages.not_payment_day', [
                                'days' => $this->paymentDaysText('CLAIM', $terkunci->transaction_category_id),
                            ]),
                        );
                    }

                    $terkunci->update([
                        'status' => Claim::STATUS_PAID,
                        'paid_by' => $user->id,
                        'paid_at' => now(),
                    ]);

                    return $terkunci;
                });

                /*
                | Pemberitahuan dikirim setelah transaksinya selesai, dan
                | kegagalannya tidak boleh membatalkan pembayaran yang sudah
                | tercatat.
                */
                try {
                    app(ClaimNotificationService::class)->notifyPaid($claim, $user);

                    app(ClaimMailService::class)->sendPaid($claim, $user);
                } catch (\Throwable $notifyError) {
                    Log::error('[Claim] Notify bulk paid gagal', [
                        'claim_id' => $claim->id,
                        'message' => $notifyError->getMessage(),
                    ]);
                }

                return (string) $claim->claim_number;
            },
        );

        return response()->json([
            'success' => $hasil['succeeded'] !== [],
            'message' => $this->bulkResultMessage($hasil),
            'data' => $hasil,
        ], 200);
    }
    public function pay(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('claim.pay')) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.pay.forbidden'),
            ], 403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],

            /*
            | Aturan berkasnya sengaja sama persis dengan bukti pengeluaran
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

            $claim = Claim::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $claim->status) !== Claim::STATUS_RECEIVED) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.pay.only_received'),
                ], 422);
            }

            /*
            | Hari ini harus hari pembayaran. Lebih awal maupun terlambat tetap
            | boleh, tetapi tetap harus jatuh pada hari kerja kasirnya.
            */
            if (!$this->canPayToday(
                $claim->scheduled_payment_date,
                'CLAIM',
                $claim->transaction_category_id,
            )) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('payment_schedule_messages.not_payment_day', [
                        'days' => $this->paymentDaysText('CLAIM', $claim->transaction_category_id),
                    ]),
                ], 422);
            }

            $claim->update([
                'status' => Claim::STATUS_PAID,
                'paid_by' => $user->id,
                'paid_at' => now(),
                'payment_notes' => $this->clean($validated['notes'] ?? '') ?: null,
            ]);

            $storedPaths = $this->storePaymentAttachments($request, $claim);

            DB::commit();

            $claim->refresh();

            try {
                app(ClaimNotificationService::class)->notifyPaid($claim, $user);

                app(ClaimMailService::class)->sendPaid($claim, $user);
            } catch (\Throwable $notifyError) {
                Log::error('[Claim] Notify paid gagal', [
                    'claim_id' => $claim->id,
                    'message' => $notifyError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __('claim_messages.pay.success'),
                'data' => [
                    'id' => $claim->id,
                    'public_id' => $claim->encrypted_id,
                    'status' => $claim->status,
                    'paid_at' => $claim->paid_at,

                    'payment_attachments' => $this->transformPaymentAttachments(
                        $claim->load('attachments'),
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

            Log::error('[Claim] Pay error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.pay.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Batalkan Claim yang sudah approved
    |--------------------------------------------------------------------------
    | Hanya status APPROVED yang boleh dibatalkan:
    |
    |  - DRAFT       : cukup dihapus, tidak perlu dibatalkan.
    |  - IN PROGRESS : approver masih bisa menolaknya lewat jalur reject.
    |  - PAID        : uang penggantinya sudah diterima pemohon, jadi tidak
    |                  bisa ditarik kembali lewat pembatalan.
    |
    | Tidak ada pemeriksaan dokumen lanjutan seperti pada FPU -- Claim selesai
    | pada dirinya sendiri.
    |--------------------------------------------------------------------------
    */
    public function cancel(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('claim.cancel')) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.cancel.forbidden'),
            ], 403);
        }

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $claim = Claim::query()
                ->lockForUpdate()
                ->findOrFail($id);

            /*
            | Dokumen yang sudah diterima tetapi belum dibayarkan masih boleh
            | dibatalkan -- sama seperti FPU.
            */
            if (
                !in_array(
                    strtoupper((string) $claim->status),
                    [Claim::STATUS_APPROVED, Claim::STATUS_RECEIVED],
                    true,
                )
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.cancel.only_approved'),
                ], 422);
            }

            $claim->update([
                'status' => Claim::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_notes' => $this->clean($validated['notes']),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('claim_messages.cancel.success'),
                'data' => [
                    'id' => $claim->id,
                    'public_id' => $claim->encrypted_id,
                    'status' => $claim->status,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Claim] Cancel error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.cancel.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Export Excel Claim
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
                    'message' => __('claim_messages.user_not_authenticated'),
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
            if (!$user->hasPermission('claim.export')) {
                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.export.forbidden'),
                ], 403);
            }

            $context = $this->resolveViewContext($user);

            /*
            | Scope NONE berarti tidak ada satu pun Claim yang terlihat olehnya,
            | sehingga export pun tidak akan ada isinya.
            */
            if ($context['scope'] === 'NONE') {
                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.export.forbidden'),
                ], 403);
            }

            $query = Claim::query()
                ->with([
                    'items',
                    'branchData',
                    'departmentData',
                    'transactionCategory',
                    'creator',

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
                ->orderByDesc('claims.id')
                ->get();

            $fileName = __('claim_messages.export.filename')
                . '_' . now()->format('Ymd_His')
                . '.xlsx';

            return Excel::download(
                new ClaimExport($data),
                $fileName,
            );
        } catch (\Throwable $e) {
            Log::error('[Claim] Export excel error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.export.failed'),
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
    | Cetakan Claim hanya berbahasa Indonesia -- dokumen internal.
    |--------------------------------------------------------------------------
    */
    public function generatePrintUrl(Request $request, string $publicId): JsonResponse
    {
        try {
            $id = (int) Crypt::decryptString($publicId);

            Claim::query()->findOrFail($id);

            $relativeUrl = URL::temporarySignedRoute(
                'fund-request.claim.print-signed',
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
                'message' => __('claim_messages.print.not_found'),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[Claim] Generate print URL error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.print.url_failed'),
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

            $claim = Claim::with([
                'branchData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'transactionCategory:id,code,name',
                'items',
                'creator:id,name',
                'submitter:id,name',
                'receiver:id,name',
                'payer:id,name',
                'approvals' => function ($query) {
                    $query->orderBy('step_order')->orderBy('id');
                },
            ])->findOrFail($id);

            /*
            | Draft dan dokumen yang masih berjalan belum boleh dicetak:
            | tanda tangan penyetujunya belum lengkap, sehingga hasil cetak
            | bisa disalahpahami sebagai dokumen sah.
            */
            $status = strtoupper(trim((string) $claim->status));

            if (
                !in_array(
                    $status,
                    [Claim::STATUS_APPROVED, Claim::STATUS_RECEIVED, Claim::STATUS_PAID],
                    true,
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' => __('claim_messages.print.not_printable'),
                ], 422);
            }

            $totalAmount = (float) (
                $claim->total_amount
                ?: $claim->items->sum(fn($item) => (float) ($item->amount ?? 0))
            );

            $pdf = Pdf::loadView('pdf.claim', [
                'claim' => $claim,
                'totalAmount' => $totalAmount,
                'terbilang' => RupiahWords::of($totalAmount),
                'requester' => $this->buildRequesterSigner($claim),
                'approvers' => $this->buildApproverSigners($claim),
            ])->setPaper('a4', 'portrait');

            return $this->pdfResponse(
                $pdf->output(),
                'Claim-' . $this->safeFileName((string) $claim->claim_number, (string) $claim->id),
            );
        } catch (DecryptException | ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => __('claim_messages.print.not_found'),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[Claim] Print error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('claim_messages.print.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Blok tanda tangan "Dibuat Oleh".
     */
    private function buildRequesterSigner(Claim $claim): object
    {
        return (object) [
            'name' => $claim->submitter?->name ?? $claim->creator?->name ?? '-',
            'signature_file' => $this->signatureFile($claim->requester_signature_path),
            'signed_at' => $claim->requester_signed_at ?? $claim->submitted_at,
        ];
    }

    /**
     * Blok tanda tangan "Disetujui Oleh".
     *
     * Hanya baris yang benar-benar APPROVED yang ikut dicetak; baris SKIPPED
     * pada mode ANY tidak pernah ditandatangani siapa pun.
     */
    private function buildApproverSigners(Claim $claim)
    {
        return $claim->approvals
            ->filter(
                fn(ClaimApproval $approval) => strtoupper(trim((string) $approval->status))
                    === ClaimApproval::STATUS_APPROVED,
            )
            ->sortBy(
                fn(ClaimApproval $approval) => sprintf(
                    '%010d-%010d',
                    (int) $approval->step_order,
                    (int) $approval->id,
                ),
            )
            ->map(fn(ClaimApproval $approval) => (object) [
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
            trim((string) ($user->getPermissionScope('claim.view') ?? 'NONE')),
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
                        ? $scopeQuery->where('claims.created_by', $user->id)
                        : $scopeQuery->whereRaw('1 = 0');

                    return;
                }

                if ($scope === 'OWN_DEPARTMENT') {
                    $departmentIds->isEmpty()
                        ? $scopeQuery->whereRaw('1 = 0')
                        : $scopeQuery->whereIn('claims.department_id', $departmentIds->all());

                    return;
                }

                if ($scope === 'OWN_CABANG') {
                    $branchIds->isEmpty()
                        ? $scopeQuery->whereRaw('1 = 0')
                        : $scopeQuery->whereIn(
                            'claims.branch',
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
                        'claim_approvals.approver_type',
                        ClaimApproval::APPROVER_TYPE_USER,
                    )
                    ->where('claim_approvals.approver_id', $user->id);
            });

            if ($userRoleIds->isNotEmpty()) {
                $approverQuery->orWhere(function ($roleQuery) use ($userRoleIds) {
                    $roleQuery
                        ->where(
                            'claim_approvals.approver_type',
                            ClaimApproval::APPROVER_TYPE_ROLE,
                        )
                        ->whereIn('claim_approvals.approver_id', $userRoleIds->all());
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
                    $q->where('claims.claim_number', 'ILIKE', $searchLike)
                        ->orWhere('claims.subject', 'ILIKE', $searchLike)
                        ->orWhere('claims.notes', 'ILIKE', $searchLike)
                        ->orWhereHas('departmentData', function ($deptQuery) use ($searchLike) {
                            $deptQuery->where('kode', 'ILIKE', $searchLike)
                                ->orWhere('nama', 'ILIKE', $searchLike);
                        })
                        ->orWhereExists(function ($branchQuery) use ($searchLike) {
                            $branchQuery->select(DB::raw(1))
                                ->from('cabang')
                                ->whereRaw('CAST(cabang.id AS VARCHAR) = claims.branch')
                                ->where(function ($sub) use ($searchLike) {
                                    $sub->where('cabang.nama_cabang', 'ILIKE', $searchLike)
                                        ->orWhere('cabang.inisial_cabang', 'ILIKE', $searchLike);
                                });
                        });
                });
            }
        }

        if ($request->filled('start_date')) {
            $query->whereDate('claims.date', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('claims.date', '<=', $request->input('end_date'));
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
            && $request->user()?->hasPermission('claim.pay')
        ) {
            $query->whereDate(
                'claims.scheduled_payment_date',
                '=',
                $request->input('scheduled_payment_date'),
            );
        }

        if ($request->filled('status')) {
            $status = strtoupper(trim((string) $request->input('status')));

            if ($status !== '' && !in_array($status, ['ALL', 'SEMUA'], true)) {
                $query->where('claims.status', $status);
            }
        }

        if ($request->filled('department_id')) {
            $query->where('claims.department_id', (int) $request->input('department_id'));
        }

        if ($request->filled('branch')) {
            $query->where('claims.branch', (string) $request->input('branch'));
        }

        $this->applyPendingActionFilter($query, $request);
    }

    /*
    |--------------------------------------------------------------------------
    | Filter "butuh aksi"
    |--------------------------------------------------------------------------
    | Menyaring dokumen yang masih menunggu tindakan Finance. Definisinya
    | disamakan dengan penanda pada daftar supaya jumlah baris hasil filter
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

        if ($action === 'NOT_PAID') {
            $query->where('claims.status', Claim::STATUS_RECEIVED);
        }
    }

    /**
     * Mengambil dokumen sekaligus memastikan user memang boleh melihatnya.
     */
    private function findVisibleClaim(string $publicId, $user): ?Claim
    {
        $id = Crypt::decryptString($publicId);

        $context = $this->resolveViewContext($user);

        $query = Claim::query()->whereKey($id);

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
                    __('claim_messages.access_assignment.branch_department_required'),
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
                    __('claim_messages.access_assignment.no_access_branch_department'),
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
                    __('claim_messages.access_assignment.no_access_create'),
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
                'items' => [__('claim_messages.items.required')],
            ]);
        }

        $normalized = [];

        foreach ($items as $index => $item) {
            $description = $this->clean($item['description'] ?? '');

            if ($description === '') {
                throw ValidationException::withMessages([
                    'items' => [
                        __('claim_messages.items.description_required', [
                            'row' => $index + 1,
                        ]),
                    ],
                ]);
            }

            $amount = (float) ($item['amount'] ?? 0);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'items' => [
                        __('claim_messages.items.amount_required', [
                            'row' => $index + 1,
                        ]),
                    ],
                ]);
            }

            $date = trim((string) ($item['date'] ?? ''));

            if ($date === '') {
                throw ValidationException::withMessages([
                    'items' => [
                        __('claim_messages.items.date_required', [
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
    private function syncItems(Claim $claim, array $items): array
    {
        $existingIds = ClaimItem::query()
            ->where('claim_id', $claim->id)
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
                ? ClaimItem::query()->find($id)
                : null;

            if ($row) {
                $row->update([
                    'date' => $item['date'],
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                ]);
            } else {
                $row = ClaimItem::create([
                    'claim_id' => $claim->id,
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
            $this->purgeItemAttachments($claim, $removedIds);

            ClaimItem::query()->whereIn('id', $removedIds)->delete();
        }

        return $itemIdsByIndex;
    }

    /**
     * Membuang lampiran dan berkas fisik milik baris yang dihapus.
     *
     * Baris lampirannya dihapus di sini, tidak diserahkan pada cascade foreign
     * key: ClaimItem memakai soft delete, sehingga barisnya sebenarnya
     * masih ada di tabel dan cascade tidak pernah terpicu. Berkas di disk juga
     * tidak ikut terhapus sendiri.
     *
     * @param  array<int, int>  $itemIds
     */
    private function purgeItemAttachments(Claim $claim, array $itemIds): void
    {
        $attachments = ClaimAttachment::query()
            ->whereIn('claim_item_id', $itemIds)
            ->get();

        foreach ($attachments as $attachment) {
            if ($attachment->filepath && Storage::disk('public')->exists($attachment->filepath)) {
                Storage::disk('public')->delete($attachment->filepath);
            }

            $attachment->delete();
        }

        foreach ($itemIds as $itemId) {
            $folder = "syopv4/uploads/claims/attachments/{$claim->id}/{$itemId}";

            if (Storage::disk('public')->exists($folder)) {
                Storage::disk('public')->deleteDirectory($folder);
            }
        }
    }

    /**
     * Menulis berkas unggahan ke disk beserta baris lampirannya.
     *
     * Berkas milik baris rincian masuk ke folder bernomor baris itu, sehingga
     * struktur di server mengikuti struktur dokumennya:
     *
     *   attachments/{claim_id}/{claim_item_id}/
     *
     * Bukti pembayaran tidak punya baris induk dan tetap di folder dokumen.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile|null>  $files
     */
    private function persistUploadedFiles(
        array $files,
        Claim $claim,
        string $attachmentType,
        ?int $itemId,
    ): array {
        $storedPaths = [];

        $subfolder = $attachmentType === ClaimAttachment::TYPE_PAYMENT
            ? 'payments'
            : 'attachments';

        $folder = "syopv4/uploads/claims/{$subfolder}/{$claim->id}";

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

            $filename = str_replace('/', '-', (string) $claim->claim_number)
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

            ClaimAttachment::create([
                'claim_id' => $claim->id,
                'claim_item_id' => $itemId,
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
        Claim $claim,
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
                    $claim,
                    ClaimAttachment::TYPE_REQUEST,
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

            $kept = ClaimAttachment::query()
                ->where('claim_item_id', $itemId)
                ->where('attachment_type', ClaimAttachment::TYPE_REQUEST)
                ->when(
                    $deletedIds !== [],
                    fn($query) => $query->whereNotIn('id', $deletedIds),
                )
                ->count();

            if ($uploaded + $kept < 1) {
                $errors["line_attachments.{$index}"] = [
                    __('claim_messages.items.attachment_required', [
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

    private function deleteRequestedAttachments(Request $request, Claim $claim): void
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
        $attachments = ClaimAttachment::query()
            ->where('claim_id', $claim->id)
            ->where('attachment_type', ClaimAttachment::TYPE_REQUEST)
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
    private function transformDetail(
        Claim $claim,
        ?ClaimApproval $currentApproval = null,
    ): array {
        return [
            'id' => $claim->id,
            'public_id' => $claim->encrypted_id,
            'claim_number' => $claim->claim_number,
            'date' => optional($claim->date)->toDateString(),
            'subject' => $claim->subject,

            'transaction_category_id' => $claim->transaction_category_id,
            'transaction_category' => $claim->transactionCategory?->name,

            'branch' => $claim->branchData?->nama_cabang ?? '-',
            'branch_id' => $claim->branch,

            'department' => $claim->departmentData?->kode ?? '-',
            'department_name' => $claim->departmentData?->nama ?? '-',
            'department_id' => $claim->department_id,

            'total_amount' => (float) $claim->total_amount,
            'notes' => $claim->notes,
            'status' => $claim->status,

            'can_approve' => $currentApproval !== null,
            'approval_id' => $currentApproval?->id,
            'approval_step_order' => $currentApproval
                ? (int) $currentApproval->step_order
                : null,
            'approval_label' => $currentApproval?->label,

            'created_at' => $claim->created_at,
            'created_by' => $claim->created_by,
            'created_by_name' => $claim->creator?->name,

            'submitted_at' => $claim->submitted_at,
            'submitted_by_name' => $claim->submitter?->name,
            'requester_signature_path' => $claim->requester_signature_path,

            'final_approved_at' => $claim->final_approved_at,
            'final_approved_by_name' => $claim->finalApprover?->name,

            'rejected_at' => $claim->rejected_at,
            'rejected_by_name' => $claim->rejecter?->name,
            'rejection_notes' => $claim->rejection_notes,

            'cancelled_at' => $claim->cancelled_at,
            'cancelled_by_name' => $claim->canceller?->name,
            'cancellation_notes' => $claim->cancellation_notes,

            'received_at' => $claim->received_at,
            'scheduled_payment_date' => $claim->scheduled_payment_date,
            'can_pay_today' => $this->canPayToday(
                $claim->scheduled_payment_date,
                'CLAIM',
                $claim->transaction_category_id,
            ),
            'payment_days_text' => $this->paymentDaysText(
                'CLAIM',
                $claim->transaction_category_id,
            ),
            'payment_timing' => $this->paymentTiming(
                $claim->scheduled_payment_date,
                $claim->paid_at,
            ),
            'received_by_name' => $claim->receiver?->name,
            'receipt_notes' => $claim->receipt_notes,

            'paid_at' => $claim->paid_at,
            'paid_by_name' => $claim->payer?->name,
            'payment_notes' => $claim->payment_notes,

            'items' => $this->transformItems($claim),
            'attachments' => $this->transformAttachments($claim),

            /*
            | Bukti transfer dari Finance. Dipisah dari 'attachments' supaya
            | form sunting pemohon tidak menganggapnya berkas miliknya.
            */
            'payment_attachments' => $this->transformPaymentAttachments($claim),

            'approvals' => $claim->approvals
                ->map(fn(ClaimApproval $approval): array => [
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

    private function transformItems(Claim $claim)
    {
        /*
        | Lampiran dikelompokkan sekali di sini, bukan diambil per baris,
        | supaya jumlah baris rincian tidak berubah menjadi jumlah query.
        */
        $attachmentsByItem = $claim->attachments
            ->filter(
                fn(ClaimAttachment $attachment): bool =>
                strtoupper(trim((string) $attachment->attachment_type))
                    === ClaimAttachment::TYPE_REQUEST
                && $attachment->claim_item_id !== null,
            )
            ->groupBy('claim_item_id');

        return $claim->items
            ->map(fn(ClaimItem $item): array => [
                'id' => $item->id,
                'date' => optional($item->date)->toDateString(),
                'description' => $item->description,
                'amount' => (float) $item->amount,

                'attachments' => collect($attachmentsByItem->get($item->id, []))
                    ->map(fn(ClaimAttachment $attachment): array => [
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
    /**
     * Bukti transfer dari Finance. Melekat pada dokumen, bukan pada baris.
     */
    private function storePaymentAttachments(Request $request, Claim $claim): array
    {
        if (!$request->hasFile('attachments')) {
            return [];
        }

        return $this->persistUploadedFiles(
            $request->file('attachments'),
            $claim,
            ClaimAttachment::TYPE_PAYMENT,
            null,
        );
    }

    private function transformPaymentAttachments(Claim $claim)
    {
        return $this->mapAttachments($claim, ClaimAttachment::TYPE_PAYMENT);
    }
    private function transformAttachments(Claim $claim)
    {
        return $this->mapAttachments(
            $claim,
            ClaimAttachment::TYPE_REQUEST,
            documentLevelOnly: true,
        );
    }

    private function mapAttachments(
        Claim $claim,
        string $attachmentType,
        bool $documentLevelOnly = false,
    ) {
        return $claim->attachments
            ->filter(
                fn(ClaimAttachment $attachment): bool =>
                strtoupper(trim((string) $attachment->attachment_type)) === $attachmentType
                && (!$documentLevelOnly || $attachment->claim_item_id === null),
            )
            ->map(fn(ClaimAttachment $attachment): array => [
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
            'can_pay' => false,
        ];
    }
}
