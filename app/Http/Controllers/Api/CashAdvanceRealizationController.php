<?php

namespace App\Http\Controllers\Api;

use App\Exports\CashAdvanceRealizationExport;
use App\Exceptions\BulkDocumentActionException;
use App\Http\Controllers\Concerns\ProcessesBulkDocumentAction;
use App\Http\Controllers\Concerns\SchedulesPayment;
use App\Http\Controllers\Controller;
use App\Models\CashAdvance;
use App\Models\CashAdvanceRealization;
use App\Models\CashAdvanceRealizationApproval;
use App\Models\CashAdvanceRealizationAttachment;
use App\Models\CashAdvanceRealizationItem;
use App\Services\FundRequest\Realization\CashAdvanceRealizationApprovalGeneratorService;
use App\Services\FundRequest\Realization\CashAdvanceRealizationApprovalService;
use App\Services\FundRequest\Realization\CashAdvanceRealizationMailService;
use App\Services\FundRequest\Realization\CashAdvanceRealizationNotificationService;
use App\Services\FundRequest\Realization\CashAdvanceRealizationNumberService;
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
| Realisasi FPU
|--------------------------------------------------------------------------
| Pertanggungjawaban atas uang yang sudah dicairkan lewat FPU.
|
|   DRAFT -> IN PROGRESS -> APPROVED -> SETTLED
|                        \-> REJECTED
|             (APPROVED) \-> CANCELLED
|
| Hanya bisa dibuat dari FPU berstatus DISBURSED, dan satu FPU hanya boleh
| punya satu realisasi yang hidup.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationController extends Controller
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

    private const PERMISSION_PREFIX = 'cash_advance_realization';

    /*
    |--------------------------------------------------------------------------
    | Daftar
    |--------------------------------------------------------------------------
    */
    public function index(
        Request $request,
        CashAdvanceRealizationApprovalService $approvalService,
    ): JsonResponse {
        $user = $request->user();

        $perPage = (int) $request->input('per_page', 10);
        $perPage = $perPage > 0 ? $perPage : 10;

        try {
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.user_not_authenticated'),
                    'data' => [],
                    'meta' => $this->emptyMeta($perPage),
                    'abilities' => $this->emptyAbilities(),
                ], 401);
            }

            $context = $this->resolveViewContext($user);

            $canSubmit = $user->hasPermission(self::PERMISSION_PREFIX . '.submit');

            $abilities = [
                'can_view' => $user->hasPermission(self::PERMISSION_PREFIX . '.view'),
                'view_scope' => $context['scope'],
                'can_create' => $user->hasPermission(self::PERMISSION_PREFIX . '.create'),
                'can_update' => $user->hasPermission(self::PERMISSION_PREFIX . '.update'),
                'can_submit' => $canSubmit,
                'can_delete' => $user->hasPermission(self::PERMISSION_PREFIX . '.delete'),
                'can_cancel' => $user->hasPermission(self::PERMISSION_PREFIX . '.cancel'),

                /*
                | Penerimaan mendahului penyelesaian: berkasnya diterima dulu, baru
                | uangnya berpindah.
                */
                'can_receive' => $user->hasPermission(self::PERMISSION_PREFIX . '.receive'),

                /*
                | Dua wewenang terpisah mengikuti arah perpindahan uangnya.
                */
                'can_return' => $user->hasPermission(self::PERMISSION_PREFIX . '.return'),
                'can_reimburse' => $user->hasPermission(self::PERMISSION_PREFIX . '.reimburse'),
            ];

            $query = CashAdvanceRealization::query()
                ->with([
                    'cashAdvance:id,advance_number',
                    'branchData',
                    'departmentData',
                    'transactionCategory',
                    'approvals' => function ($approvalQuery) {
                        $approvalQuery->orderBy('step_order')->orderBy('id');
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
                    ->where(
                        'cash_advance_realizations.status',
                        CashAdvanceRealization::STATUS_IN_PROGRESS,
                    )
                    ->whereHas('approvals', function ($approvalQuery) use ($context, $user) {
                        $approvalQuery->where(
                            'cash_advance_realization_approvals.status',
                            CashAdvanceRealizationApproval::STATUS_WAITING,
                        );

                        $this->applyApproverMatch($approvalQuery, $user, $context['userRoleIds']);
                    });
            }

            $this->applyListFilters($query, $request);

            $query->withExists([
                'approvals as is_waiting_my_approval' => function ($approvalQuery) use (
                    $context,
                    $user,
                ) {
                    $approvalQuery
                        ->whereRaw(
                            'cash_advance_realizations.status = ?',
                            [CashAdvanceRealization::STATUS_IN_PROGRESS],
                        )
                        ->where(
                            'cash_advance_realization_approvals.status',
                            CashAdvanceRealizationApproval::STATUS_WAITING,
                        );

                    $this->applyApproverMatch($approvalQuery, $user, $context['userRoleIds']);
                },
            ]);

            $records = $query
                ->orderByDesc('is_waiting_my_approval')
                ->orderByDesc('cash_advance_realizations.id')
                ->paginate($perPage);

            $records->through(
                function (CashAdvanceRealization $realization) use (
                    $user,
                    $approvalService,
                    $canSubmit,
                ): array {
                    $status = strtoupper(trim((string) $realization->status));

                    $isCreator = (int) $realization->created_by === (int) $user->id;

                    $isSameDepartment = (
                        $user->departemen_id !== null
                        && (int) $realization->department_id === (int) $user->departemen_id
                    );

                    $rowCanSubmit = (
                        $canSubmit
                        && $status === CashAdvanceRealization::STATUS_DRAFT
                        && ($isCreator || $isSameDepartment)
                    );

                    $currentApproval = $realization->approvals->first(
                        fn(CashAdvanceRealizationApproval $approval): bool =>
                        strtoupper((string) $approval->status)
                            === CashAdvanceRealizationApproval::STATUS_WAITING
                            && $approvalService->userCanApprove($approval, $user),
                    );

                    return [
                        'id' => $realization->id,
                        'public_id' => $realization->encrypted_id,
                        'realization_number' => $realization->realization_number,
                        'date' => optional($realization->date)->toDateString(),

                        'cash_advance_id' => $realization->cash_advance_id,
                        'advance_number' => $realization->cashAdvance?->advance_number,

                        'branch' => $realization->branchData?->nama_cabang ?? '-',
                        'department' => $realization->departmentData?->kode ?? '-',
                        'transaction_category' => $realization->transactionCategory?->name,

                        'total_advance_amount' => (float) $realization->total_advance_amount,
                        'total_realization_amount' => (float) $realization->total_realization_amount,
                        'difference_amount' => (float) $realization->difference_amount,
                        'difference_type' => $realization->difference_type,

                        'status' => $realization->status,

                        'can_submit' => $rowCanSubmit,
                        'can_approve' => $currentApproval !== null,
                        'approval_label' => $currentApproval?->label,

                        'created_at' => $realization->created_at,
                        'created_by' => $realization->created_by,
                        'received_at' => $realization->received_at,
                        'scheduled_payment_date' => $realization->scheduled_payment_date,
                        'can_pay_today' => $this->canPayToday(
                            $realization->scheduled_payment_date,
                            'REALISASI',
                            $realization->transaction_category_id,
                        ),
                        'payment_timing' => $this->paymentTiming(
                            $realization->scheduled_payment_date,
                            $realization->settled_at,
                        ),
                        'settled_at' => $realization->settled_at,
                    ];
                },
            );

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_realization_messages.index.loaded'),
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
                'payment_days_text' => $this->paymentDaysText('REALISASI'),

                /* Angkanya juga, supaya layar bisa merangkai namanya sendiri. */
                'payment_days' => $this->paymentDays('REALISASI'),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Realisasi FPU] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $user?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.index.load_failed'),
                'data' => [],
                'meta' => $this->emptyMeta($perPage),
                'abilities' => $this->emptyAbilities(),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FPU yang siap direalisasi
    |--------------------------------------------------------------------------
    | Hanya FPU berstatus DISBURSED dan belum punya realisasi hidup.
    |--------------------------------------------------------------------------
    */
    public function realizableCashAdvances(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.create')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.store.forbidden'),
                'data' => [],
            ], 403);
        }

        try {
            /*
            | Batasi ke FPU yang boleh dilihat user, memakai scope FPU-nya
            | sendiri -- bukan scope Realisasi. Realisasi dibuat dari dokumen
            | FPU, jadi haknya mengikuti hak atas FPU tersebut.
            */
            $context = $this->resolveViewContext($user, 'cash_advance.view');

            $query = CashAdvance::query()
                ->with(['branchData', 'departmentData', 'transactionCategory', 'items'])
                ->where('status', CashAdvance::STATUS_DISBURSED)
                ->whereDoesntHave('realization');

            $this->applyCashAdvanceVisibilityScope(
                $query,
                $user,
                $context['scope'],
                $context['branchIds'],
                $context['departmentIds'],
            );

            if ($request->filled('search')) {
                $search = '%' . trim((string) $request->input('search')) . '%';

                $query->where(function ($q) use ($search) {
                    $q->where('advance_number', 'ILIKE', $search)
                        ->orWhere('subject', 'ILIKE', $search);
                });
            }

            $records = $query
                ->orderByDesc('disbursed_at')
                ->limit(100)
                ->get();

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_realization_messages.realizable.loaded'),
                'data' => $records
                    ->map(fn(CashAdvance $cashAdvance) => [
                        'id' => $cashAdvance->id,
                        'public_id' => $cashAdvance->encrypted_id,
                        'advance_number' => $cashAdvance->advance_number,
                        'title' => $cashAdvance->advance_number
                            . ' — ' . $cashAdvance->subject,
                        'date' => optional($cashAdvance->date)->toDateString(),
                        'subject' => $cashAdvance->subject,

                        'branch' => $cashAdvance->branchData?->nama_cabang ?? '-',
                        'branch_id' => $cashAdvance->branch,
                        'department' => $cashAdvance->departmentData?->kode ?? '-',
                        'department_id' => $cashAdvance->department_id,

                        'transaction_category' => $cashAdvance->transactionCategory?->name,
                        'transaction_category_id' => $cashAdvance->transaction_category_id,

                        'request_type' => $cashAdvance->request_type,
                        'total_amount' => (float) $cashAdvance->total_amount,
                        'disbursed_at' => $cashAdvance->disbursed_at,

                        /*
                         * Baris FPU dikirim sekalian supaya form realisasi bisa
                         * langsung menyalinnya tanpa permintaan kedua.
                         */
                        'items' => $cashAdvance->items
                            ->map(fn($item) => [
                                'id' => $item->id,
                                'date' => optional($item->date)->toDateString(),
                                'description' => $item->description,
                                'amount' => (float) $item->amount,
                            ])
                            ->values(),
                    ])
                    ->values(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Realisasi FPU] Realizable list error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.realizable.failed'),
                'data' => [],
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Simpan
    |--------------------------------------------------------------------------
    */
    public function store(
        Request $request,
        CashAdvanceRealizationNumberService $numberService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.create')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.store.forbidden'),
            ], 403);
        }

        $storedPaths = [];

        DB::beginTransaction();

        try {
            $request->validate([
                'cash_advance_public_id' => ['required', 'string'],
                'date' => ['required', 'date_format:Y-m-d'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'items' => ['required', 'string'],

                /*
                | Bukti kini dikirim per baris rincian: line_attachments[i][].
                */
                'line_attachments.*.*' => [
                    'file',
                    'mimes:' . self::ATTACHMENT_MIMES,
                    'max:' . self::ATTACHMENT_MAX_KB,
                ],
            ]);

            $cashAdvance = $this->resolveRealizableCashAdvance(
                $request->input('cash_advance_public_id'),
            );

            $items = $this->decodeItems($request->input('items'), $cashAdvance);

            $totals = $this->calculateTotals($cashAdvance, $items);

            $realization = CashAdvanceRealization::create([
                // Nomor final dibentuk saat submit, bukan saat draft dibuat.
                'realization_number' => $numberService->generateDraftNumber(),

                'cash_advance_id' => $cashAdvance->id,
                'date' => $request->input('date'),
                'notes' => $this->clean($request->input('notes')) ?: null,

                /*
                | Disalin dari FPU: dipakai mesin approval untuk memilih flow,
                | sekaligus menjadi snapshot dokumen.
                */
                'branch' => $cashAdvance->branch,
                'department_id' => $cashAdvance->department_id,
                'transaction_category_id' => $cashAdvance->transaction_category_id,

                'total_advance_amount' => $totals['advance'],
                'total_realization_amount' => $totals['realization'],
                'difference_amount' => $totals['difference'],
                'difference_type' => $totals['difference_type'],

                'status' => CashAdvanceRealization::STATUS_DRAFT,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $itemIdsByIndex = $this->writeItems($realization, $items);

            $this->assertEveryLineHasAttachment($request, $itemIdsByIndex, []);

            $storedPaths = $this->storeLineAttachments(
                $request,
                $realization,
                $itemIdsByIndex,
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_realization_messages.store.success'),
                'data' => [
                    'id' => $realization->id,
                    'public_id' => $realization->encrypted_id,
                    'realization_number' => $realization->realization_number,
                ],
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('cash_advance_realization_messages.store.invalid'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            Log::error('[Realisasi FPU] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.store.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Detail
    |--------------------------------------------------------------------------
    */
    public function show(
        string $publicId,
        Request $request,
        CashAdvanceRealizationApprovalService $approvalService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.user_not_authenticated'),
            ], 401);
        }

        try {
            $realization = $this->findVisibleRealization($publicId, $user);

            if (!$realization) {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.show.not_found'),
                ], 404);
            }

            $realization->load([
                'cashAdvance',
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
                'settler:id,name',
                'approvals' => function ($approvalQuery) {
                    $approvalQuery->orderBy('step_order')->orderBy('id');
                },
            ]);

            $currentApproval = $realization->approvals->first(
                fn(CashAdvanceRealizationApproval $approval): bool =>
                strtoupper((string) $approval->status)
                    === CashAdvanceRealizationApproval::STATUS_WAITING
                    && $approvalService->userCanApprove($approval, $user),
            );

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_realization_messages.show.loaded'),
                'data' => $this->transformDetail($realization, $currentApproval),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Realisasi FPU] Show error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.show.failed'),
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

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.update')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.update.forbidden'),
            ], 403);
        }

        try {
            $realization = $this->findVisibleRealization($publicId, $user);

            if (!$realization) {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.show.not_found'),
                ], 404);
            }

            $realization->load([
                'cashAdvance',
                'branchData',
                'departmentData',
                'transactionCategory',
                'items',
                'attachments',
            ]);

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_realization_messages.show.loaded'),
                'data' => [
                    'id' => $realization->id,
                    'public_id' => $realization->encrypted_id,
                    'realization_number' => $realization->realization_number,
                    'date' => optional($realization->date)->toDateString(),
                    'notes' => $realization->notes,
                    'status' => $realization->status,

                    'cash_advance_public_id' => $realization->cashAdvance?->encrypted_id,
                    'advance_number' => $realization->cashAdvance?->advance_number,
                    'subject' => $realization->cashAdvance?->subject,

                    'branch' => $realization->branchData?->nama_cabang ?? '-',
                    'department' => $realization->departmentData?->kode ?? '-',
                    'transaction_category' => $realization->transactionCategory?->name,

                    'total_advance_amount' => (float) $realization->total_advance_amount,
                    'total_realization_amount' => (float) $realization->total_realization_amount,
                    'difference_amount' => (float) $realization->difference_amount,
                    'difference_type' => $realization->difference_type,

                    'items' => $this->transformItems($realization),
                    'attachments' => $this->transformAttachments($realization),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Realisasi FPU] Edit error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.show.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Ubah
    |--------------------------------------------------------------------------
    | Hanya berlaku pada dokumen DRAFT. FPU induknya tidak boleh berpindah:
    | mengganti induk akan membuat nomor, cabang, dan department dokumen ini
    | tidak lagi cocok dengan isinya.
    |--------------------------------------------------------------------------
    */
    public function update(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.update')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.update.forbidden'),
            ], 403);
        }

        $storedPaths = [];

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $realization = CashAdvanceRealization::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $realization->status)
                !== CashAdvanceRealization::STATUS_DRAFT
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.update.only_draft'),
                ], 422);
            }

            $request->validate([
                'date' => ['required', 'date_format:Y-m-d'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'items' => ['required', 'string'],
                'deleted_attachment_ids' => ['nullable', 'string'],

                /*
                | Bukti kini dikirim per baris rincian: line_attachments[i][].
                */
                'line_attachments.*.*' => [
                    'file',
                    'mimes:' . self::ATTACHMENT_MIMES,
                    'max:' . self::ATTACHMENT_MAX_KB,
                ],
            ]);

            $cashAdvance = CashAdvance::findOrFail($realization->cash_advance_id);

            $items = $this->decodeItems($request->input('items'), $cashAdvance);

            $totals = $this->calculateTotals($cashAdvance, $items);

            $realization->update([
                'date' => $request->input('date'),
                'notes' => $this->clean($request->input('notes')) ?: null,

                'total_advance_amount' => $totals['advance'],
                'total_realization_amount' => $totals['realization'],
                'difference_amount' => $totals['difference'],
                'difference_type' => $totals['difference_type'],

                'updated_by' => $user->id,
            ]);

            /*
            | Baris rincian TIDAK lagi ditulis ulang seluruhnya.
            |
            | Sejak bukti melekat pada baris, id baris menjadi identitas yang
            | dipakai baris lampiran maupun nama folder di server. Menghapus lalu
            | membuat ulang akan memutus keduanya setiap kali draft disunting.
            */
            $itemIdsByIndex = $this->syncItems($realization, $items);

            $deletedIds = $this->requestedDeletedAttachmentIds($request);

            $this->assertEveryLineHasAttachment($request, $itemIdsByIndex, $deletedIds);

            $this->deleteRequestedAttachments($request, $realization);

            $storedPaths = $this->storeLineAttachments(
                $request,
                $realization,
                $itemIdsByIndex,
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_realization_messages.update.success'),
                'data' => [
                    'id' => $realization->id,
                    'public_id' => $realization->encrypted_id,
                    'realization_number' => $realization->realization_number,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('cash_advance_realization_messages.store.invalid'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->cleanupStoredFiles($storedPaths);

            Log::error('[Realisasi FPU] Update error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.update.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
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

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.delete')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.destroy.forbidden'),
            ], 403);
        }

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $realization = CashAdvanceRealization::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $realization->status)
                !== CashAdvanceRealization::STATUS_DRAFT
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.destroy.only_draft'),
                ], 422);
            }

            $realization->items()->delete();
            $realization->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_realization_messages.destroy.success'),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Realisasi FPU] Destroy error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.destroy.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Submit
    |--------------------------------------------------------------------------
    */
    public function submit(
        string $publicId,
        Request $request,
        CashAdvanceRealizationNumberService $numberService,
        CashAdvanceRealizationApprovalGeneratorService $approvalGenerator,
        CashAdvanceRealizationNotificationService $notificationService,
        CashAdvanceRealizationMailService $mailService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.submit')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.submit.forbidden'),
            ], 403);
        }

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $realization = CashAdvanceRealization::with(['items'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $realization->status)
                !== CashAdvanceRealization::STATUS_DRAFT
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.submit.only_draft'),
                ], 422);
            }

            if ($realization->items->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.submit.items_unavailable'),
                ], 422);
            }

            $requesterSignaturePath = $user->signature_path;

            if (blank($requesterSignaturePath)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.submit.signature_missing'),
                ], 422);
            }

            /*
            | Nomor final baru dibentuk di sini supaya deret nomor tidak habis
            | terpakai oleh draft yang batal diajukan.
            */
            if (str_starts_with((string) $realization->realization_number, 'DRAFT/')) {
                $realization->realization_number = $numberService
                    ->generateFinalNumber($realization);
            }

            $approvalGenerator->generate($realization);

            $submittedAt = now();

            $realization->status = CashAdvanceRealization::STATUS_IN_PROGRESS;
            $realization->submitted_by = $user->id;
            $realization->submitted_at = $submittedAt;

            $realization->requester_signed_by = $user->id;
            $realization->requester_signature_path = $requesterSignaturePath;
            $realization->requester_signed_at = $submittedAt;

            $realization->save();

            DB::commit();

            $realization->refresh();

            try {
                $notificationService->notifyApprovalRequest($realization);
            } catch (\Throwable $notificationError) {
                Log::error('[Realisasi FPU] Notifikasi approver gagal dibuat', [
                    'cash_advance_realization_id' => $realization->id,
                    'message' => $notificationError->getMessage(),
                ]);
            }

            /*
            | Email dipisah dari notifikasi in-app supaya kegagalan salah satu
            | tidak ikut membatalkan yang lain.
            */
            try {
                $mailService->sendApprovalRequest($realization);
            } catch (\Throwable $mailError) {
                Log::error('[Realisasi FPU] Email approver gagal dikirim', [
                    'cash_advance_realization_id' => $realization->id,
                    'realization_number' => $realization->realization_number,
                    'message' => $mailError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_realization_messages.submit.success'),
                'data' => [
                    'id' => $realization->id,
                    'public_id' => $realization->encrypted_id,
                    'realization_number' => $realization->realization_number,
                    'status' => $realization->status,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('cash_advance_realization_messages.submit.failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Realisasi FPU] Submit error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.submit.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Approve
    |--------------------------------------------------------------------------
    */
    public function approve(
        string $publicId,
        Request $request,
        CashAdvanceRealizationApprovalService $approvalService,
        CashAdvanceRealizationNotificationService $notificationService,
        CashAdvanceRealizationMailService $mailService,
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
                    'message' => __('cash_advance_realization_messages.user_not_authenticated'),
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $realization = CashAdvanceRealization::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $realization->status)
                !== CashAdvanceRealization::STATUS_IN_PROGRESS
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.approve.not_in_progress'),
                ], 422);
            }

            $result = $approvalService->approveCurrentStep(
                $realization,
                $user,
                $validated['notes'] ?? null,
            );

            DB::commit();

            $realization->refresh();

            try {
                $notificationService->notifyApprovalStep(
                    $realization,
                    $user,
                    $result['approval'],
                    $result['has_pending_approval'],
                );

                /*
                | Pemohon hanya diemail saat approval tuntas; tahap tengah cukup
                | lewat notifikasi in-app.
                */
                if ($result['is_final_approved']) {
                    $mailService->sendApprovalStep($realization, $user, false);

                    /*
                    | Approval tuntas berarti dokumennya berpindah ke meja PIC
                    | penerimaan -- bukan langsung ke pemegang penyelesaian.
                    | Pemberitahuannya berada di luar approval flow, jadi
                    | penerimanya pemegang permission penerimaan.
                    |
                    | Permintaan penyelesaian selisih menyusul kemudian, saat
                    | berkasnya ditandai diterima.
                    */
                    $notificationService->notifyReceiptRequest($realization);

                    $mailService->sendReceiptRequest($realization);
                }

                if (
                    $result['step_completed']
                    && $result['has_pending_approval']
                    && $result['next_step_order'] !== null
                ) {
                    $notificationService->notifyApprovalRequest($realization);

                    $mailService->sendApprovalRequest($realization);
                }
            } catch (\Throwable $notifyError) {
                Log::error('[Realisasi FPU] Notify approval gagal', [
                    'cash_advance_realization_id' => $realization->id,
                    'message' => $notifyError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,

                'message' => $result['is_final_approved']
                    ? __('cash_advance_realization_messages.approve.final_success')
                    : (
                        $result['step_completed']
                        ? __('cash_advance_realization_messages.approve.step_success')
                        : __('cash_advance_realization_messages.approve.waiting_others')
                    ),

                'data' => [
                    'id' => $realization->id,
                    'public_id' => $realization->encrypted_id,
                    'realization_number' => $realization->realization_number,
                    'status' => $realization->status,
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
                    ?? __('cash_advance_realization_messages.approve.failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Realisasi FPU] Approve error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.approve.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reject
    |--------------------------------------------------------------------------
    */
    public function reject(
        string $publicId,
        Request $request,
        CashAdvanceRealizationApprovalService $approvalService,
        CashAdvanceRealizationNotificationService $notificationService,
        CashAdvanceRealizationMailService $mailService,
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
                    'message' => __('cash_advance_realization_messages.user_not_authenticated'),
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $realization = CashAdvanceRealization::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $realization->status)
                !== CashAdvanceRealization::STATUS_IN_PROGRESS
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.reject.not_in_progress'),
                ], 422);
            }

            $approval = $approvalService->rejectCurrentStep(
                $realization,
                $user,
                $validated['notes'] ?? null,
            );

            DB::commit();

            $realization->refresh();

            try {
                $notificationService->notifyRejected($realization, $user);

                $mailService->sendRejected(
                    $realization,
                    $user,
                    $validated['notes'] ?? null,
                );
            } catch (\Throwable $notifyError) {
                Log::error('[Realisasi FPU] Notify reject gagal', [
                    'cash_advance_realization_id' => $realization->id,
                    'message' => $notifyError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_realization_messages.reject.success'),
                'data' => [
                    'id' => $realization->id,
                    'public_id' => $realization->encrypted_id,
                    'status' => $realization->status,
                    'approval_id' => $approval->id,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('cash_advance_realization_messages.reject.failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Realisasi FPU] Reject error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.reject.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Batalkan
    |--------------------------------------------------------------------------
    */
    public function cancel(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.cancel')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.cancel.forbidden'),
            ], 403);
        }

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $realization = CashAdvanceRealization::query()
                ->lockForUpdate()
                ->findOrFail($id);

            /*
            | Dokumen yang sudah diterima tetapi belum tuntas masih boleh
            | dibatalkan -- sama seperti FPU.
            */
            if (
                !in_array(
                    strtoupper((string) $realization->status),
                    [
                        CashAdvanceRealization::STATUS_APPROVED,
                        CashAdvanceRealization::STATUS_RECEIVED,
                    ],
                    true,
                )
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.cancel.only_approved'),
                ], 422);
            }

            $realization->update([
                'status' => CashAdvanceRealization::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_notes' => $this->clean($validated['notes']),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_realization_messages.cancel.success'),
                'data' => [
                    'id' => $realization->id,
                    'public_id' => $realization->encrypted_id,
                    'status' => $realization->status,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Realisasi FPU] Cancel error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.cancel.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Export Excel Realisasi FPU
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
                    'message' => __('cash_advance_realization_messages.user_not_authenticated'),
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
            if (!$user->hasPermission(self::PERMISSION_PREFIX . '.export')) {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.export.forbidden'),
                ], 403);
            }

            $context = $this->resolveViewContext($user);

            if ($context['scope'] === 'NONE') {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.export.forbidden'),
                ], 403);
            }

            $query = CashAdvanceRealization::query()
                ->with([
                    'items',
                    'branchData',
                    'departmentData',
                    'transactionCategory',

                    /*
                    | Dipakai untuk kolom referensi Nomor FPU dan perihalnya.
                    | Berbeda dengan arah sebaliknya, relasi ini selalu ada.
                    */
                    'cashAdvance',
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
                ->orderByDesc('cash_advance_realizations.id')
                ->get();

            $fileName = __('cash_advance_realization_messages.export.filename')
                . '_' . now()->format('Ymd_His')
                . '.xlsx';

            return Excel::download(
                new CashAdvanceRealizationExport($data),
                $fileName,
            );
        } catch (\Throwable $e) {
            Log::error('[Realisasi FPU] Export excel error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.export.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Penyelesaian selisih
    |--------------------------------------------------------------------------
    | Dua peristiwa yang berbeda, bukan satu aksi generik:
    |
    | - RETURN    : realisasi lebih kecil dari yang dicairkan, sisanya harus
    |               dikembalikan pemohon ke Finance. Bukti transfer WAJIB,
    |               karena yang menyerahkan uang adalah pihak yang juga membuat
    |               dokumennya -- tanpa bukti, tidak ada yang memverifikasi.
    |
    | - REIMBURSE : realisasi melebihi yang dicairkan, Finance wajib membayar
    |               kekurangannya kepada pemohon. Bukti opsional, karena yang
    |               mencatat adalah Finance sendiri dan pemohon akan protes bila
    |               uangnya tidak benar-benar diterima.
    |
    | Realisasi yang nominalnya pas tidak punya aksi apa pun: tidak ada uang
    | yang berpindah, jadi APPROVED sudah merupakan akhir dokumennya.
    |
    | Keduanya hanya bisa dijalankan setelah approval realisasi selesai.
    |--------------------------------------------------------------------------
    */

    /** Pemohon mengembalikan sisa dana ke Finance. Bukti wajib. */
    /*
    |--------------------------------------------------------------------------
    | Penyelesaian selisih atas banyak dokumen sekaligus
    |--------------------------------------------------------------------------
    | Nominal yang diserahkan tidak dikirim pemanggil, melainkan diambil dari
    | selisih tiap dokumennya. Pada tindakan massal memang tidak ada tempat
    | untuk mengetiknya satu per satu -- dan nilainya toh selalu sama dengan
    | selisih dokumen, sebagaimana pada versi satuan.
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
    /**
     * Jadwal pembayaran untuk sebuah Realisasi.
     *
     * Hanya berlaku bila perusahaan yang harus membayar kekurangannya.
     * Pengembalian sisa dana adalah uang MASUK dari pemohon, jadi tidak ada
     * pembayaran Finance yang perlu dijadwalkan.
     */
    private function jadwalBayarRealisasi(CashAdvanceRealization $realization, $diterimaPada): ?string
    {
        $jenis = strtoupper(trim((string) $realization->difference_type));

        if ($jenis !== CashAdvanceRealization::DIFFERENCE_REIMBURSE) {
            return null;
        }

        return $this->freezePaymentDate(
            'REALISASI',
            $diterimaPada,
            $realization->transaction_category_id,
        );
    }

    public function receive(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('cash_advance_realization.receive')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.receive.forbidden'),
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

            $realization = CashAdvanceRealization::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (strtoupper((string) $realization->status) !== CashAdvanceRealization::STATUS_APPROVED) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.receive.only_approved'),
                ], 422);
            }

            $diterimaPada = now();

            $realization->update([
                'status' => CashAdvanceRealization::STATUS_RECEIVED,
                'received_by' => $user->id,
                'received_at' => $diterimaPada,
                'receipt_notes' => $this->clean($validated['notes'] ?? '') ?: null,
                'scheduled_payment_date' => $this->jadwalBayarRealisasi($realization, $diterimaPada),
            ]);

            $storedPaths = $this->storeAttachments(
                $request,
                $realization,
                CashAdvanceRealizationAttachment::TYPE_RECEIPT,
            );

            DB::commit();

            $realization->refresh();

            $this->notifyReceiptStage($realization, $user);

            return response()->json([
                'success' => true,
                'message' => __('cash_advance_realization_messages.receive.success'),
                'data' => [
                    'id' => $realization->id,
                    'public_id' => $realization->encrypted_id,
                    'status' => $realization->status,
                    'received_at' => $realization->received_at,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            $this->cleanupStoredFiles($storedPaths);

            Log::error('[Realisasi FPU] Receive error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.receive.failed'),
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

        if (!$user || !$user->hasPermission('cash_advance_realization.receive')) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.receive.forbidden'),
            ], 403);
        }

        $validated = $request->validate($this->bulkDocumentRules());

        $hasil = $this->processBulkDocuments(
            $validated['public_ids'],
            '[Realisasi FPU] Bulk receive',
            function (string $publicId) use ($user): string {
                $dokumen = DB::transaction(function () use ($publicId, $user): CashAdvanceRealization {
                    $terlihat = $this->findVisibleRealization($publicId, $user);

                    if (!$terlihat) {
                        throw new BulkDocumentActionException('-', __('bulk_action.not_found'));
                    }

                    $terkunci = CashAdvanceRealization::query()->lockForUpdate()->find($terlihat->id);

                    if (strtoupper((string) $terkunci->status) !== CashAdvanceRealization::STATUS_APPROVED) {
                        throw new BulkDocumentActionException(
                            (string) $terkunci->realization_number,
                            __('cash_advance_realization_messages.receive.only_approved'),
                        );
                    }

                    $diterimaPada = now();

                    $terkunci->update([
                        'status' => CashAdvanceRealization::STATUS_RECEIVED,
                        'received_by' => $user->id,
                        'received_at' => $diterimaPada,
                        'scheduled_payment_date' => $this->jadwalBayarRealisasi($terkunci, $diterimaPada),
                    ]);

                    return $terkunci;
                });

                $this->notifyReceiptStage($dokumen, $user);

                return (string) $dokumen->realization_number;
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
    private function notifyReceiptStage(CashAdvanceRealization $dokumen, $user): void
    {
        try {
            $notificationService = app(CashAdvanceRealizationNotificationService::class);
            $mailService = app(CashAdvanceRealizationMailService::class);

            $notificationService->notifyReceived($dokumen, $user);
            $mailService->sendReceived($dokumen, $user);

            $notificationService->notifyDifferenceSettlementRequest($dokumen);
                    $mailService->sendDifferenceSettlementRequest($dokumen);
        } catch (\Throwable $notifyError) {
            Log::error('[Realisasi FPU] Notify received gagal', [
                'cash_advance_realization_id' => $dokumen->id,
                'message' => $notifyError->getMessage(),
            ]);
        }
    }

    public function bulkReturnDifference(Request $request): JsonResponse
    {
        return $this->processBulkDifference(
            $request,
            CashAdvanceRealization::DIFFERENCE_RETURN,
            self::PERMISSION_PREFIX . '.return',
            'return',
            '[Realisasi FPU] Bulk return',
        );
    }

    public function bulkReimburseDifference(Request $request): JsonResponse
    {
        return $this->processBulkDifference(
            $request,
            CashAdvanceRealization::DIFFERENCE_REIMBURSE,
            self::PERMISSION_PREFIX . '.reimburse',
            'reimburse',
            '[Realisasi FPU] Bulk reimburse',
        );
    }

    /** Inti kedua tindakan massal penyelesaian selisih. */
    private function processBulkDifference(
        Request $request,
        string $expectedDifferenceType,
        string $permission,
        string $messageKey,
        string $logTag,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => __("cash_advance_realization_messages.{$messageKey}.forbidden"),
            ], 403);
        }

        $validated = $request->validate($this->bulkDocumentRules());

        $hasil = $this->processBulkDocuments(
            $validated['public_ids'],
            $logTag,
            function (string $publicId) use ($user, $expectedDifferenceType, $messageKey): string {
                $realization = DB::transaction(function () use (
                    $publicId,
                    $user,
                    $expectedDifferenceType,
                    $messageKey,
                ): CashAdvanceRealization {
                    $terlihat = $this->findVisibleRealization($publicId, $user);

                    if (!$terlihat) {
                        throw new BulkDocumentActionException('-', __('bulk_action.not_found'));
                    }

                    $terkunci = CashAdvanceRealization::query()
                        ->lockForUpdate()
                        ->find($terlihat->id);

                    if (
                        strtoupper((string) $terkunci->status)
                        !== CashAdvanceRealization::STATUS_RECEIVED
                    ) {
                        throw new BulkDocumentActionException(
                            (string) $terkunci->realization_number,
                            __("cash_advance_realization_messages.{$messageKey}.only_received"),
                        );
                    }

                    /* Pembayaran hanya boleh jatuh pada hari pembayaran. */
                    if (!$this->canPayToday(
                        $terkunci->scheduled_payment_date,
                        'REALISASI',
                        $terkunci->transaction_category_id,
                    )) {
                        throw new BulkDocumentActionException(
                            (string) $terkunci->realization_number,
                            __('payment_schedule_messages.not_payment_day', [
                                'days' => $this->paymentDaysText('REALISASI', $terkunci->transaction_category_id),
                            ]),
                        );
                    }

                    /*
                    | Jenis selisih harus cocok dengan tindakannya, sama seperti
                    | pada versi satuan: kekurangan tidak boleh ditutup lewat
                    | jalur pengembalian, dan sebaliknya.
                    */
                    if (
                        strtoupper((string) $terkunci->difference_type)
                        !== $expectedDifferenceType
                    ) {
                        throw new BulkDocumentActionException(
                            (string) $terkunci->realization_number,
                            __("cash_advance_realization_messages.{$messageKey}.wrong_difference"),
                        );
                    }

                    $terkunci->update([
                        'status' => CashAdvanceRealization::STATUS_SETTLED,
                        'settled_by' => $user->id,
                        'settled_at' => now(),
                        'settlement_amount' => round(abs((float) $terkunci->difference_amount), 2),
                    ]);

                    return $terkunci;
                });

                try {
                    app(CashAdvanceRealizationNotificationService::class)
                        ->notifySettled($realization, $user);

                    app(CashAdvanceRealizationMailService::class)
                        ->sendSettled($realization, $user);
                } catch (\Throwable $notifyError) {
                    Log::error('[Realisasi FPU] Notify bulk settled gagal', [
                        'realization_id' => $realization->id,
                        'message' => $notifyError->getMessage(),
                    ]);
                }

                return (string) $realization->realization_number;
            },
        );

        return response()->json([
            'success' => $hasil['succeeded'] !== [],
            'message' => $this->bulkResultMessage($hasil),
            'data' => $hasil,
        ], 200);
    }
    public function returnDifference(
        string $publicId,
        Request $request,
        CashAdvanceRealizationNotificationService $notificationService,
        CashAdvanceRealizationMailService $mailService,
    ): JsonResponse {
        return $this->closeDifference(
            $publicId,
            $request,
            $notificationService,
            $mailService,
            CashAdvanceRealization::DIFFERENCE_RETURN,
            self::PERMISSION_PREFIX . '.return',
            'return',

            /*
            | Tidak lagi wajib sejak unggahan bukti dihilangkan dari layar.
            | Kalau tetap diwajibkan di sini, pengembalian dana menjadi mustahil
            | dilakukan -- tidak ada lagi jalan untuk mengirim berkasnya.
            |
            | Kolom lampirannya sendiri tetap ada dan endpoint ini tetap
            | menerima berkas, jadi kewajibannya bisa dihidupkan kembali kapan
            | saja tanpa mengubah apa pun selain baris ini.
            */
            attachmentRequired: false,
        );
    }

    /** Finance membayar kekurangan kepada pemohon. Bukti opsional. */
    public function reimburseDifference(
        string $publicId,
        Request $request,
        CashAdvanceRealizationNotificationService $notificationService,
        CashAdvanceRealizationMailService $mailService,
    ): JsonResponse {
        return $this->closeDifference(
            $publicId,
            $request,
            $notificationService,
            $mailService,
            CashAdvanceRealization::DIFFERENCE_REIMBURSE,
            self::PERMISSION_PREFIX . '.reimburse',
            'reimburse',
            attachmentRequired: false,
        );
    }

    /**
     * Inti kedua aksi penyelesaian selisih.
     *
     * Alurnya identik -- yang berbeda hanya jenis selisih yang dilayani,
     * permission-nya, dan wajib-tidaknya lampiran. Disatukan supaya aturan
     * status dan penanganan berkasnya tidak berpeluang menyimpang satu sama
     * lain seiring waktu.
     */
    private function closeDifference(
        string $publicId,
        Request $request,
        CashAdvanceRealizationNotificationService $notificationService,
        CashAdvanceRealizationMailService $mailService,
        string $expectedDifferenceType,
        string $permission,
        string $messageKey,
        bool $attachmentRequired,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => __("cash_advance_realization_messages.{$messageKey}.forbidden"),
            ], 403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],

            /*
            | Nominal yang benar-benar diserahkan atau dibayarkan. Kecocokannya
            | dengan selisih dokumen diperiksa setelah dokumennya dikunci, bukan
            | di sini, supaya pembandingnya tidak terbaca dari data basi.
            */
            'settlement_amount' => ['required', 'numeric', 'min:0'],

            /*
            | Aturan berkasnya sengaja sama persis dengan bukti pengeluaran
            | supaya user tidak menghadapi dua batasan berbeda di satu modul.
            */
            'attachments' => [$attachmentRequired ? 'required' : 'nullable', 'array'],
            'attachments.*' => [
                'file',
                'mimes:' . self::ATTACHMENT_MIMES,
                'max:' . self::ATTACHMENT_MAX_KB,
            ],
        ], [
            'attachments.required' => __(
                "cash_advance_realization_messages.{$messageKey}.attachment_required",
            ),
        ]);

        DB::beginTransaction();

        $storedPaths = [];

        try {
            $id = Crypt::decryptString($publicId);

            $realization = CashAdvanceRealization::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $realization->status)
                !== CashAdvanceRealization::STATUS_RECEIVED
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __("cash_advance_realization_messages.{$messageKey}.only_received"),
                ], 422);
            }

            /*
            | Hari ini harus hari pembayaran. Lebih awal maupun terlambat tetap
            | boleh, tetapi tetap harus jatuh pada hari kerja kasirnya.
            */
            if (!$this->canPayToday(
                $realization->scheduled_payment_date,
                'REALISASI',
                $realization->transaction_category_id,
            )) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('payment_schedule_messages.not_payment_day', [
                        'days' => $this->paymentDaysText('REALISASI', $realization->transaction_category_id),
                    ]),
                ], 422);
            }

            /*
            | Jenis selisih dokumen harus cocok dengan aksinya. Tanpa penjagaan
            | ini, kekurangan bisa ditutup lewat jalur pengembalian dan arah
            | perpindahan uangnya tercatat terbalik.
            */
            if (
                strtoupper((string) $realization->difference_type)
                !== $expectedDifferenceType
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __("cash_advance_realization_messages.{$messageKey}.wrong_difference"),
                ], 422);
            }

            /*
            | Nominal yang diserahkan harus sama persis dengan selisih dokumen.
            | Dibandingkan setelah pembulatan 2 desimal supaya galat titik
            | mengambang dari sisi klien tidak menolak angka yang sebenarnya
            | benar.
            |
            | Pembayaran sebagian sengaja tidak diizinkan: dokumennya langsung
            | ditutup begitu aksi ini berhasil, jadi sisa yang belum lunas tidak
            | akan punya tempat untuk dicatat.
            */
            $expectedAmount = round(abs((float) $realization->difference_amount), 2);
            $paidAmount = round((float) $validated['settlement_amount'], 2);

            if ($paidAmount !== $expectedAmount) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __(
                        "cash_advance_realization_messages.{$messageKey}.amount_mismatch",
                        [
                            'expected' => number_format($expectedAmount, 0, ',', '.'),
                            'given' => number_format($paidAmount, 0, ',', '.'),
                        ],
                    ),
                    'errors' => [
                        'settlement_amount' => [
                            __(
                                "cash_advance_realization_messages.{$messageKey}.amount_mismatch",
                                [
                                    'expected' => number_format($expectedAmount, 0, ',', '.'),
                                    'given' => number_format($paidAmount, 0, ',', '.'),
                                ],
                            ),
                        ],
                    ],
                ], 422);
            }

            $realization->update([
                'status' => CashAdvanceRealization::STATUS_SETTLED,
                'settled_by' => $user->id,
                'settled_at' => now(),
                'settlement_notes' => $this->clean($validated['notes'] ?? '') ?: null,
                'settlement_amount' => $paidAmount,
            ]);

            $storedPaths = $this->storeAttachments(
                $request,
                $realization,
                CashAdvanceRealizationAttachment::TYPE_SETTLEMENT,
            );

            DB::commit();

            $realization->refresh();

            try {
                $notificationService->notifySettled($realization, $user);

                $mailService->sendSettled($realization, $user);
            } catch (\Throwable $notifyError) {
                Log::error('[Realisasi FPU] Notify settled gagal', [
                    'cash_advance_realization_id' => $realization->id,
                    'message' => $notifyError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __("cash_advance_realization_messages.{$messageKey}.success"),
                'data' => [
                    'id' => $realization->id,
                    'public_id' => $realization->encrypted_id,
                    'status' => $realization->status,
                    'settled_at' => $realization->settled_at,

                    'settlement_attachments' => $this->transformSettlementAttachments(
                        $realization->load('attachments'),
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

            Log::error('[Realisasi FPU] Penyelesaian selisih error', [
                'public_id' => $publicId,
                'difference_type' => $expectedDifferenceType,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __("cash_advance_realization_messages.{$messageKey}.failed"),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cetakan PDF
    |--------------------------------------------------------------------------
    | Mengikuti pola PR, PO, dan FPU: frontend meminta signed URL berumur
    | pendek, lalu membuka URL itu di tab baru.
    |
    | Cetakan hanya berbahasa Indonesia -- dokumen internal.
    |--------------------------------------------------------------------------
    */
    public function generatePrintUrl(Request $request, string $publicId): JsonResponse
    {
        try {
            $id = (int) Crypt::decryptString($publicId);

            CashAdvanceRealization::query()->findOrFail($id);

            $relativeUrl = URL::temporarySignedRoute(
                'fund-request.cash-advance-realization.print-signed',
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
                'message' => __('cash_advance_realization_messages.print.not_found'),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[Realisasi FPU] Generate print URL error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.print.url_failed'),
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

            $realization = CashAdvanceRealization::with([
                'cashAdvance:id,advance_number,date,subject,request_type',
                'branchData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'transactionCategory:id,code,name',
                'items',
                'creator:id,name',
                'submitter:id,name',
                'receiver:id,name',
                'settler:id,name',
                'approvals' => function ($query) {
                    $query->orderBy('step_order')->orderBy('id');
                },
            ])->findOrFail($id);

            /*
            | Draft dan dokumen yang masih berjalan belum boleh dicetak:
            | tanda tangan penyetujunya belum lengkap, sehingga hasil cetak
            | bisa disalahpahami sebagai dokumen sah.
            */
            $status = strtoupper(trim((string) $realization->status));

            if (
                !in_array(
                    $status,
                    [
                        CashAdvanceRealization::STATUS_APPROVED,
                        CashAdvanceRealization::STATUS_RECEIVED,
                        CashAdvanceRealization::STATUS_SETTLED,
                    ],
                    true,
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' => __('cash_advance_realization_messages.print.not_printable'),
                ], 422);
            }

            $pdf = Pdf::loadView('pdf.cash-advance-realization', [
                'realization' => $realization,
                'terbilang' => RupiahWords::of(
                    (float) $realization->total_realization_amount,
                ),
                'requester' => $this->buildRequesterSigner($realization),
                'approvers' => $this->buildApproverSigners($realization),
            ])->setPaper('a4', 'portrait');

            /*
            | Nomor realisasi sudah diawali RLS, jadi tidak perlu diberi
            | awalan lagi -- kalau tidak, namanya menjadi RLS-RLS-...
            */
            return $this->pdfResponse(
                $pdf->output(),
                $this->safeFileName(
                    (string) $realization->realization_number,
                    'RLS-' . $realization->id,
                ),
            );
        } catch (DecryptException | ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.print.not_found'),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[Realisasi FPU] Print error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('cash_advance_realization_messages.print.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Blok tanda tangan "Dibuat Oleh".
     */
    private function buildRequesterSigner(CashAdvanceRealization $realization): object
    {
        return (object) [
            'name' => $realization->submitter?->name ?? $realization->creator?->name ?? '-',
            'signature_file' => $this->signatureFile($realization->requester_signature_path),
            'signed_at' => $realization->requester_signed_at ?? $realization->submitted_at,
        ];
    }

    /**
     * Blok tanda tangan "Disetujui Oleh".
     *
     * Hanya baris yang benar-benar APPROVED yang ikut dicetak; baris SKIPPED
     * pada mode ANY tidak pernah ditandatangani siapa pun.
     */
    private function buildApproverSigners(CashAdvanceRealization $realization)
    {
        return $realization->approvals
            ->filter(
                fn(CashAdvanceRealizationApproval $approval) => strtoupper(
                    trim((string) $approval->status),
                ) === CashAdvanceRealizationApproval::STATUS_APPROVED,
            )
            ->sortBy(
                fn(CashAdvanceRealizationApproval $approval) => sprintf(
                    '%010d-%010d',
                    (int) $approval->step_order,
                    (int) $approval->id,
                ),
            )
            ->map(fn(CashAdvanceRealizationApproval $approval) => (object) [
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

        // inline supaya PDF terbuka langsung di tab, bukan terunduh.
        $response->headers->set(
            'Content-Disposition',
            'inline; filename="' . $fileName . '.pdf"',
        );

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper: FPU induk
    |--------------------------------------------------------------------------
    */
    private function resolveRealizableCashAdvance(string $publicId): CashAdvance
    {
        try {
            $id = Crypt::decryptString($publicId);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'cash_advance_public_id' => [
                    __('cash_advance_realization_messages.source.not_found'),
                ],
            ]);
        }

        $cashAdvance = CashAdvance::with('items')->find($id);

        if (!$cashAdvance) {
            throw ValidationException::withMessages([
                'cash_advance_public_id' => [
                    __('cash_advance_realization_messages.source.not_found'),
                ],
            ]);
        }

        if (
            strtoupper((string) $cashAdvance->status)
            !== CashAdvance::STATUS_DISBURSED
        ) {
            throw ValidationException::withMessages([
                'cash_advance_public_id' => [
                    __('cash_advance_realization_messages.source.not_disbursed'),
                ],
            ]);
        }

        /*
        | Dijaga juga oleh unique index parsial pada tabel, tapi diperiksa di
        | sini supaya pesannya jelas alih-alih error constraint.
        */
        $alreadyRealized = CashAdvanceRealization::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->exists();

        if ($alreadyRealized) {
            throw ValidationException::withMessages([
                'cash_advance_public_id' => [
                    __('cash_advance_realization_messages.source.already_realized'),
                ],
            ]);
        }

        return $cashAdvance;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper: baris realisasi
    |--------------------------------------------------------------------------
    */
    private function decodeItems(mixed $rawItems, CashAdvance $cashAdvance): array
    {
        $items = json_decode((string) $rawItems, true);

        if (!is_array($items) || count($items) === 0) {
            throw ValidationException::withMessages([
                'items' => [__('cash_advance_realization_messages.items.required')],
            ]);
        }

        /*
        | Baris FPU yang sah, dipakai memverifikasi cash_advance_item_id dan
        | mengambil nominal pengajuannya. Nilai advance_amount tidak diambil
        | dari payload supaya tidak bisa dimanipulasi dari frontend.
        */
        $advanceItems = $cashAdvance->items->keyBy('id');

        $normalized = [];
        $usedAdvanceItemIds = [];

        foreach ($items as $index => $item) {
            $description = $this->clean($item['description'] ?? '');

            if ($description === '') {
                throw ValidationException::withMessages([
                    'items' => [
                        __('cash_advance_realization_messages.items.description_required', [
                            'row' => $index + 1,
                        ]),
                    ],
                ]);
            }

            $realizationAmount = round((float) ($item['realization_amount'] ?? 0), 2);

            if ($realizationAmount < 0) {
                throw ValidationException::withMessages([
                    'items' => [
                        __('cash_advance_realization_messages.items.amount_invalid', [
                            'row' => $index + 1,
                        ]),
                    ],
                ]);
            }

            $advanceItemId = isset($item['cash_advance_item_id'])
                && $item['cash_advance_item_id'] !== null
                && $item['cash_advance_item_id'] !== ''
                ? (int) $item['cash_advance_item_id']
                : null;

            $advanceAmount = 0.0;

            if ($advanceItemId !== null) {
                if (!$advanceItems->has($advanceItemId)) {
                    throw ValidationException::withMessages([
                        'items' => [
                            __('cash_advance_realization_messages.items.unknown_source', [
                                'row' => $index + 1,
                            ]),
                        ],
                    ]);
                }

                if (isset($usedAdvanceItemIds[$advanceItemId])) {
                    throw ValidationException::withMessages([
                        'items' => [
                            __('cash_advance_realization_messages.items.duplicate_source', [
                                'row' => $index + 1,
                            ]),
                        ],
                    ]);
                }

                $usedAdvanceItemIds[$advanceItemId] = true;

                $advanceAmount = round(
                    (float) $advanceItems->get($advanceItemId)->amount,
                    2,
                );
            }

            $date = trim((string) ($item['date'] ?? ''));

            if ($date === '') {
                throw ValidationException::withMessages([
                    'items' => [
                        __('cash_advance_realization_messages.items.date_required', [
                            'row' => $index + 1,
                        ]),
                    ],
                ]);
            }

            /*
            | Id baris realisasi lama dibawa formulir supaya barisnya bisa
            | diperbarui di tempat, bukan dihapus lalu dibuat ulang -- id itu
            | dipakai baris lampiran dan nama folder di server.
            */
            $rowId = (int) ($item['id'] ?? 0);

            $normalized[] = [
                'id' => $rowId > 0 ? $rowId : null,
                'cash_advance_item_id' => $advanceItemId,
                'date' => $date !== '' ? $date : null,
                'description' => $description,
                'advance_amount' => $advanceAmount,
                'realization_amount' => $realizationAmount,
                'notes' => $this->clean($item['notes'] ?? '') ?: null,
            ];
        }

        return $normalized;
    }

    /**
     * Menulis baris rincian pada dokumen baru.
     *
     * @return array<int, int>  nomor urut baris pada formulir -> id baris
     */
    private function writeItems(CashAdvanceRealization $realization, array $items): array
    {
        $itemIdsByIndex = [];

        foreach ($items as $index => $item) {
            unset($item['id']);

            $row = CashAdvanceRealizationItem::create(
                $item + ['cash_advance_realization_id' => $realization->id],
            );

            $itemIdsByIndex[(int) $index] = (int) $row->id;
        }

        return $itemIdsByIndex;
    }

    /**
     * Menyelaraskan baris rincian dengan isi formulir.
     *
     * Baris lama diperbarui di tempat sehingga id-nya -- yang dipakai baris
     * lampiran dan nama folder di server -- tetap sama. Baris yang hilang dari
     * formulir dihapus beserta lampiran dan berkas fisiknya.
     *
     * @return array<int, int>  nomor urut baris pada formulir -> id baris
     */
    private function syncItems(CashAdvanceRealization $realization, array $items): array
    {
        $existingIds = CashAdvanceRealizationItem::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->pluck('id')
            ->all();

        $itemIdsByIndex = [];
        $keptIds = [];

        foreach ($items as $index => $item) {
            $id = $item['id'];

            unset($item['id']);

            /*
            | Id yang tidak dikenal diperlakukan sebagai baris baru, bukan
            | ditolak: kiriman formulir tidak boleh bisa menyentuh baris milik
            | dokumen lain hanya dengan menebak angka.
            */
            $row = $id !== null && in_array($id, $existingIds, true)
                ? CashAdvanceRealizationItem::query()->find($id)
                : null;

            if ($row) {
                $row->update($item);
            } else {
                $row = CashAdvanceRealizationItem::create(
                    $item + ['cash_advance_realization_id' => $realization->id],
                );
            }

            $itemIdsByIndex[(int) $index] = (int) $row->id;
            $keptIds[] = (int) $row->id;
        }

        $removedIds = array_values(array_diff($existingIds, $keptIds));

        if ($removedIds !== []) {
            $this->purgeItemAttachments($realization, $removedIds);

            CashAdvanceRealizationItem::query()->whereIn('id', $removedIds)->delete();
        }

        return $itemIdsByIndex;
    }

    /**
     * Membuang lampiran dan berkas fisik milik baris yang dihapus.
     *
     * Baris lampirannya dihapus di sini, tidak diserahkan pada cascade foreign
     * key: baris rincian memakai soft delete, sehingga barisnya sebenarnya
     * masih ada di tabel dan cascade tidak pernah terpicu.
     *
     * @param  array<int, int>  $itemIds
     */
    private function purgeItemAttachments(CashAdvanceRealization $realization, array $itemIds): void
    {
        $attachments = CashAdvanceRealizationAttachment::query()
            ->whereIn('cash_advance_realization_item_id', $itemIds)
            ->get();

        foreach ($attachments as $attachment) {
            if ($attachment->filepath && Storage::disk('public')->exists($attachment->filepath)) {
                Storage::disk('public')->delete($attachment->filepath);
            }

            $attachment->delete();
        }

        foreach ($itemIds as $itemId) {
            $folder = "syopv4/uploads/cash_advance_realizations/attachments/{$realization->id}/{$itemId}";

            if (Storage::disk('public')->exists($folder)) {
                Storage::disk('public')->deleteDirectory($folder);
            }
        }
    }

    /**
     * Menghitung total dan selisih.
     *
     * total_advance_amount diambil dari nilai FPU yang dicairkan, bukan dari
     * penjumlahan baris -- baris tambahan di luar rencana tidak boleh
     * menambah nilai yang dianggap sudah diterima pemohon.
     *
     * @return array{advance: float, realization: float, difference: float, difference_type: string}
     */
    private function calculateTotals(CashAdvance $cashAdvance, array $items): array
    {
        $advance = round((float) $cashAdvance->total_amount, 2);

        $realization = round(
            array_sum(array_map(
                fn(array $item) => (float) $item['realization_amount'],
                $items,
            )),
            2,
        );

        $difference = round($advance - $realization, 2);

        return [
            'advance' => $advance,
            'realization' => $realization,
            'difference' => $difference,
            'difference_type' => CashAdvanceRealization::resolveDifferenceType($difference),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper: visibilitas
    |--------------------------------------------------------------------------
    */
    private function resolveViewContext($user, string $permission = 'cash_advance_realization.view'): array
    {
        $scope = strtoupper(
            trim((string) ($user->getPermissionScope($permission) ?? 'NONE')),
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
                DB::table('user_roles')->where('user_id', $user->id)->pluck('role_id'),
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

    /**
     * Pembatasan data Realisasi. Approver selalu bisa melihat dokumen yang
     * masuk ke flow-nya, meskipun di luar cabang atau department-nya.
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
                        ? $scopeQuery->where('cash_advance_realizations.created_by', $user->id)
                        : $scopeQuery->whereRaw('1 = 0');

                    return;
                }

                if ($scope === 'OWN_DEPARTMENT') {
                    $departmentIds->isEmpty()
                        ? $scopeQuery->whereRaw('1 = 0')
                        : $scopeQuery->whereIn(
                            'cash_advance_realizations.department_id',
                            $departmentIds->all(),
                        );

                    return;
                }

                if ($scope === 'OWN_CABANG') {
                    $branchIds->isEmpty()
                        ? $scopeQuery->whereRaw('1 = 0')
                        : $scopeQuery->whereIn(
                            'cash_advance_realizations.branch',
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
     * Pembatasan data FPU, dipakai daftar FPU yang siap direalisasi.
     */
    private function applyCashAdvanceVisibilityScope(
        $query,
        $user,
        string $scope,
        $branchIds,
        $departmentIds,
    ): void {
        if ($scope === 'ALL') {
            return;
        }

        if ($scope === 'OWN_DATA') {
            $query->where('cash_advances.created_by', $user->id);

            return;
        }

        if ($scope === 'OWN_DEPARTMENT') {
            $departmentIds->isEmpty()
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('cash_advances.department_id', $departmentIds->all());

            return;
        }

        if ($scope === 'OWN_CABANG') {
            $branchIds->isEmpty()
                ? $query->whereRaw('1 = 0')
                : $query->whereIn(
                    'cash_advances.branch',
                    $branchIds->map(fn($id) => (string) $id)->all(),
                );

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function applyApproverMatch($approvalQuery, $user, $userRoleIds): void
    {
        $approvalQuery->where(function ($approverQuery) use ($user, $userRoleIds) {
            $approverQuery->where(function ($userQuery) use ($user) {
                $userQuery
                    ->where(
                        'cash_advance_realization_approvals.approver_type',
                        CashAdvanceRealizationApproval::APPROVER_TYPE_USER,
                    )
                    ->where('cash_advance_realization_approvals.approver_id', $user->id);
            });

            if ($userRoleIds->isNotEmpty()) {
                $approverQuery->orWhere(function ($roleQuery) use ($userRoleIds) {
                    $roleQuery
                        ->where(
                            'cash_advance_realization_approvals.approver_type',
                            CashAdvanceRealizationApproval::APPROVER_TYPE_ROLE,
                        )
                        ->whereIn(
                            'cash_advance_realization_approvals.approver_id',
                            $userRoleIds->all(),
                        );
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
                    $q->where('cash_advance_realizations.realization_number', 'ILIKE', $searchLike)
                        ->orWhere('cash_advance_realizations.notes', 'ILIKE', $searchLike)
                        ->orWhereHas('cashAdvance', function ($advanceQuery) use ($searchLike) {
                            $advanceQuery
                                ->where('advance_number', 'ILIKE', $searchLike)
                                ->orWhere('subject', 'ILIKE', $searchLike);
                        });
                });
            }
        }

        if ($request->filled('start_date')) {
            $query->whereDate('cash_advance_realizations.date', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('cash_advance_realizations.date', '<=', $request->input('end_date'));
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
            && $request->user()?->hasPermission('cash_advance_realization.reimburse')
        ) {
            $query->whereDate(
                'cash_advance_realizations.scheduled_payment_date',
                '=',
                $request->input('scheduled_payment_date'),
            );
        }

        if ($request->filled('status')) {
            $status = strtoupper(trim((string) $request->input('status')));

            if ($status !== '' && !in_array($status, ['ALL', 'SEMUA'], true)) {
                $query->where('cash_advance_realizations.status', $status);
            }
        }

        if ($request->filled('difference_type')) {
            $type = strtoupper(trim((string) $request->input('difference_type')));

            if ($type !== '' && !in_array($type, ['ALL', 'SEMUA'], true)) {
                $query->where('cash_advance_realizations.difference_type', $type);
            }
        }

        if ($request->filled('department_id')) {
            $query->where(
                'cash_advance_realizations.department_id',
                (int) $request->input('department_id'),
            );
        }

        if ($request->filled('branch')) {
            $query->where(
                'cash_advance_realizations.branch',
                (string) $request->input('branch'),
            );
        }

        $this->applyPendingActionFilter($query, $request);
    }

    /*
    |--------------------------------------------------------------------------
    | Filter "butuh aksi"
    |--------------------------------------------------------------------------
    | Selisih baru boleh diselesaikan setelah approval tuntas, jadi penyaringnya
    | selalu memasangkan status APPROVED dengan arah selisihnya. Definisinya
    | disamakan dengan penanda pada daftar supaya baris yang tersaring persis
    | baris yang bertanda.
    |
    | Tidak dijaga permission di sini: penyaringnya hanya mempersempit baris
    | yang memang sudah boleh dilihat user. Yang dijaga permission adalah
    | kemunculan pilihannya di layar.
    |--------------------------------------------------------------------------
    */
    private function applyPendingActionFilter($query, Request $request): void
    {
        if (!$request->filled('pending_action')) {
            return;
        }

        $action = strtoupper(trim((string) $request->input('pending_action')));

        $differenceType = match ($action) {
            'RETURN' => CashAdvanceRealization::DIFFERENCE_RETURN,
            'REIMBURSE' => CashAdvanceRealization::DIFFERENCE_REIMBURSE,
            default => null,
        };

        if (!$differenceType) {
            return;
        }

        $query
            ->where('cash_advance_realizations.status', CashAdvanceRealization::STATUS_RECEIVED)
            ->where('cash_advance_realizations.difference_type', $differenceType);
    }

    private function findVisibleRealization(string $publicId, $user): ?CashAdvanceRealization
    {
        $id = Crypt::decryptString($publicId);

        $context = $this->resolveViewContext($user);

        $query = CashAdvanceRealization::query()->whereKey($id);

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
    | Helper: lampiran
    |--------------------------------------------------------------------------
    */
    /**
     * Menyimpan berkas unggahan menjadi baris lampiran.
     *
     * Dipakai dua jalur: bukti pengeluaran dari pemohon, dan bukti penyelesaian
     * selisih dari Finance. Keduanya berbagi tabel yang sama dan dibedakan lewat
     * $attachmentType, sementara berkas fisiknya dipisah ke subfolder
     * masing-masing agar mudah ditelusuri di server.
     */
    private function storeAttachments(
        Request $request,
        CashAdvanceRealization $realization,
        string $attachmentType = CashAdvanceRealizationAttachment::TYPE_SETTLEMENT,
        string $inputName = 'attachments',
        ?int $itemId = null,
    ): array {
        if (!$request->hasFile($inputName)) {
            return [];
        }

        return $this->persistUploadedFiles(
            $request->file($inputName),
            $realization,
            $attachmentType,
            $itemId,
        );
    }

    /**
     * Menulis berkas unggahan ke disk beserta baris lampirannya.
     *
     * Berkas milik baris rincian masuk ke folder bernomor baris itu:
     *
     *   attachments/{cash_advance_realization_id}/{item_id}/
     *
     * Bukti penyelesaian selisih tidak punya baris induk dan tetap di folder
     * dokumen.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile|null>  $files
     */
    private function persistUploadedFiles(
        array $files,
        CashAdvanceRealization $realization,
        string $attachmentType,
        ?int $itemId,
    ): array {
        $storedPaths = [];

        $subfolder = $attachmentType === CashAdvanceRealizationAttachment::TYPE_SETTLEMENT
            ? 'settlements'
            : 'attachments';

        $folder = "syopv4/uploads/cash_advance_realizations/{$subfolder}/{$realization->id}";

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

            $filename = str_replace('/', '-', (string) $realization->realization_number)
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

            CashAdvanceRealizationAttachment::create([
                'cash_advance_realization_id' => $realization->id,
                'cash_advance_realization_item_id' => $itemId,
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
     * bukan id -- baris baru belum punya id saat berkasnya dipilih.
     *
     * @param  array<int, int>  $itemIdsByIndex
     */
    private function storeLineAttachments(
        Request $request,
        CashAdvanceRealization $realization,
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
                    $realization,
                    CashAdvanceRealizationAttachment::TYPE_REQUEST,
                    $itemId,
                ),
            );
        }

        return $storedPaths;
    }

    /**
     * Memastikan setiap baris rincian punya minimal satu bukti.
     *
     * Dihitung dari gabungan berkas baru dan bukti lama yang tidak dihapus.
     *
     * @param  array<int, int>  $itemIdsByIndex
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

            $kept = CashAdvanceRealizationAttachment::query()
                ->where('cash_advance_realization_item_id', $itemId)
                ->where('attachment_type', CashAdvanceRealizationAttachment::TYPE_REQUEST)
                ->when(
                    $deletedIds !== [],
                    fn($query) => $query->whereNotIn('id', $deletedIds),
                )
                ->count();

            if ($uploaded + $kept < 1) {
                $errors["line_attachments.{$index}"] = [
                    __('cash_advance_realization_messages.items.attachment_required', [
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

    private function deleteRequestedAttachments(
        Request $request,
        CashAdvanceRealization $realization,
    ): void {
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
        | Dibatasi pada bukti pengeluaran milik pemohon. Bukti penyelesaian
        | dari Finance tidak boleh ikut terhapus lewat form sunting.
        */
        $attachments = CashAdvanceRealizationAttachment::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->where('attachment_type', CashAdvanceRealizationAttachment::TYPE_REQUEST)
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
    | Helper: transform
    |--------------------------------------------------------------------------
    */
    private function transformDetail(
        CashAdvanceRealization $realization,
        ?CashAdvanceRealizationApproval $currentApproval,
    ): array {
        return [
            'id' => $realization->id,
            'public_id' => $realization->encrypted_id,
            'realization_number' => $realization->realization_number,
            'date' => optional($realization->date)->toDateString(),
            'notes' => $realization->notes,
            'status' => $realization->status,

            'cash_advance_public_id' => $realization->cashAdvance?->encrypted_id,
            'advance_number' => $realization->cashAdvance?->advance_number,
            'subject' => $realization->cashAdvance?->subject,
            'request_type' => $realization->cashAdvance?->request_type,
            'advance_disbursed_at' => $realization->cashAdvance?->disbursed_at,

            'branch' => $realization->branchData?->nama_cabang ?? '-',
            'department' => $realization->departmentData?->kode ?? '-',
            'department_name' => $realization->departmentData?->nama ?? '-',
            'transaction_category' => $realization->transactionCategory?->name,

            'total_advance_amount' => (float) $realization->total_advance_amount,
            'total_realization_amount' => (float) $realization->total_realization_amount,
            'difference_amount' => (float) $realization->difference_amount,
            'difference_type' => $realization->difference_type,

            'can_approve' => $currentApproval !== null,
            'approval_label' => $currentApproval?->label,

            'created_at' => $realization->created_at,
            'created_by_name' => $realization->creator?->name,

            'submitted_at' => $realization->submitted_at,
            'submitted_by_name' => $realization->submitter?->name,
            'requester_signature_path' => $realization->requester_signature_path,

            'final_approved_at' => $realization->final_approved_at,
            'final_approved_by_name' => $realization->finalApprover?->name,

            'rejected_at' => $realization->rejected_at,
            'rejected_by_name' => $realization->rejecter?->name,
            'rejection_notes' => $realization->rejection_notes,

            'cancelled_at' => $realization->cancelled_at,
            'cancelled_by_name' => $realization->canceller?->name,
            'cancellation_notes' => $realization->cancellation_notes,

            'received_at' => $realization->received_at,
            'scheduled_payment_date' => $realization->scheduled_payment_date,
            'can_pay_today' => $this->canPayToday(
                $realization->scheduled_payment_date,
                'REALISASI',
                $realization->transaction_category_id,
            ),
            'payment_days_text' => $this->paymentDaysText(
                'REALISASI',
                $realization->transaction_category_id,
            ),
            'payment_timing' => $this->paymentTiming(
                $realization->scheduled_payment_date,
                $realization->settled_at,
            ),
            'received_by_name' => $realization->receiver?->name,
            'receipt_notes' => $realization->receipt_notes,

            'settled_at' => $realization->settled_at,
            'settled_by_name' => $realization->settler?->name,
            'settlement_notes' => $realization->settlement_notes,
            'settlement_amount' => $realization->settlement_amount !== null
                ? (float) $realization->settlement_amount
                : null,

            'items' => $this->transformItems($realization),
            'attachments' => $this->transformAttachments($realization),

            /*
            | Bukti penyelesaian dari Finance. Dipisah dari 'attachments' supaya
            | form sunting pemohon tidak menganggapnya berkas miliknya.
            */
            'settlement_attachments' => $this->transformSettlementAttachments($realization),

            'approvals' => $realization->approvals
                ->map(fn(CashAdvanceRealizationApproval $approval): array => [
                    'id' => $approval->id,
                    'step_order' => (int) $approval->step_order,
                    'label' => $approval->label,
                    'approver_name' => $approval->approver_name_snapshot,
                    'approval_mode' => $approval->approval_mode,
                    'status' => $approval->status,
                    'approved_at' => $approval->approved_at,
                    'rejected_at' => $approval->rejected_at,
                    'notes' => $approval->notes,
                ])
                ->values(),
        ];
    }

    private function transformItems(CashAdvanceRealization $realization)
    {
        /*
        | Lampiran dikelompokkan sekali di sini, bukan diambil per baris,
        | supaya jumlah baris rincian tidak berubah menjadi jumlah query.
        */
        $attachmentsByItem = $realization->attachments
            ->filter(
                fn(CashAdvanceRealizationAttachment $attachment): bool =>
                strtoupper(trim((string) $attachment->attachment_type))
                    === CashAdvanceRealizationAttachment::TYPE_REQUEST
                && $attachment->cash_advance_realization_item_id !== null,
            )
            ->groupBy('cash_advance_realization_item_id');

        return $realization->items
            ->map(fn(CashAdvanceRealizationItem $item): array => [
                'id' => $item->id,
                'cash_advance_item_id' => $item->cash_advance_item_id,
                'date' => optional($item->date)->toDateString(),
                'description' => $item->description,
                'advance_amount' => (float) $item->advance_amount,
                'realization_amount' => (float) $item->realization_amount,
                'difference_amount' => $item->difference_amount,
                'notes' => $item->notes,

                'attachments' => collect($attachmentsByItem->get($item->id, []))
                    ->map(fn(CashAdvanceRealizationAttachment $attachment): array => [
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
     * Bukti pengeluaran milik pemohon.
     *
     * Bukti penyelesaian dari Finance sengaja tidak ikut supaya form sunting
     * pemohon tidak menampilkannya sebagai berkas yang bisa dihapus.
     */
    private function transformAttachments(CashAdvanceRealization $realization)
    {
        return $this->mapAttachments(
            $realization,
            CashAdvanceRealizationAttachment::TYPE_REQUEST,
            documentLevelOnly: true,
        );
    }

    /** Bukti penyelesaian selisih yang dilampirkan Finance. */
    private function transformSettlementAttachments(CashAdvanceRealization $realization)
    {
        return $this->mapAttachments(
            $realization,
            CashAdvanceRealizationAttachment::TYPE_SETTLEMENT,
        );
    }

    private function mapAttachments(
        CashAdvanceRealization $realization,
        string $attachmentType,
        bool $documentLevelOnly = false,
    ) {
        return $realization->attachments
            ->filter(
                fn(CashAdvanceRealizationAttachment $attachment): bool =>
                strtoupper(trim((string) $attachment->attachment_type)) === $attachmentType
                && (!$documentLevelOnly || $attachment->cash_advance_realization_item_id === null),
            )
            ->map(fn(CashAdvanceRealizationAttachment $attachment): array => [
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
            'can_return' => false,
            'can_reimburse' => false,
        ];
    }
}
