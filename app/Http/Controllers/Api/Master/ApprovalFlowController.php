<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Models\PermissionModule;
use App\Services\ApprovalFlow\ApprovalDocumentTypeService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;

class ApprovalFlowController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Daftar jenis dokumen
    |--------------------------------------------------------------------------
    | Dibaca dari permission_modules, bukan ditulis di kode. Disimpan sebagai
    | properti supaya cache-nya berlaku untuk seluruh request.
    |--------------------------------------------------------------------------
    */
    public function __construct(
        private ApprovalDocumentTypeService $documentTypes,
    ) {
    }

    /**
     * GET /master/approval-flows/document-types
     *
     * Sumber isi dropdown "Jenis Dokumen" di frontend.
     */
    public function documentTypeOptions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Jenis dokumen approval flow berhasil dimuat.',
            'data' => $this->documentTypes->toOptions(),
        ], 200);
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 10);
            $perPage = $perPage > 0 ? $perPage : 10;
            $perPage = $perPage > 100 ? 100 : $perPage;

            $search = trim((string) $request->input('search', ''));
            $documentType = strtoupper(trim((string) $request->input('document_type', '')));
            $status = strtolower(trim((string) $request->input('status', 'all')));

            if ($status === '' || $status === 'semua') {
                $status = 'all';
            }

            $query = ApprovalFlow::query()
                ->with([
                    'steps.role',
                    'steps.user',
                    'steps.branchMappings',
                    'steps.specialApprovers.specialDocumentType',
                    'steps.specialApprovers.approverUser:id,name',
                    'steps.specialApprovers.approverRole',
                    'creatorDepartment',
                    'departments',
                    'transactionCategories',
                    'permissionModule',
                ])
                ->when(
                    $documentType !== ''
                        && $documentType !== 'ALL'
                        && $documentType !== 'SEMUA',
                    function ($query) use ($documentType) {
                        $query->whereRaw(
                            'UPPER(TRIM(document_type)) = ?',
                            [$documentType],
                        );
                    },
                )
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where(
                                'module_name',
                                'ILIKE',
                                "%{$search}%",
                            )
                            ->orWhereHas(
                                'permissionModule',
                                function ($moduleQuery) use ($search) {
                                    $moduleQuery
                                        ->where(
                                            'code',
                                            'ILIKE',
                                            "%{$search}%",
                                        )
                                        ->orWhere(
                                            'name',
                                            'ILIKE',
                                            "%{$search}%",
                                        );
                                },
                            )
                            ->orWhere('document_type', 'ILIKE', "%{$search}%")
                            ->orWhere('name', 'ILIKE', "%{$search}%")
                            ->orWhere('description', 'ILIKE', "%{$search}%")
                            ->orWhereHas('steps', function ($stepQuery) use ($search) {
                                $stepQuery
                                    ->where('label', 'ILIKE', "%{$search}%")
                                    ->orWhere('approver_type', 'ILIKE', "%{$search}%")
                                    ->orWhereHas('role', function ($roleQuery) use ($search) {
                                        $roleQuery->where('name', 'ILIKE', "%{$search}%");
                                    })
                                    ->orWhereHas('user', function ($userQuery) use ($search) {
                                        $userQuery->where('name', 'ILIKE', "%{$search}%");
                                    });
                            });
                    });
                });

            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }

            if ($request->filled('area_type')) {
                $query->where('area_type', strtoupper((string) $request->input('area_type')));
            }

            /*
            | Filter department ikut menampilkan flow "Semua Divisi", karena
            | flow seperti itu memang berlaku untuk department yang dipilih.
            */
            if ($request->filled('creator_department_id')) {
                $departmentId = (int) $request->input('creator_department_id');

                $query->where(function ($scopeQuery) use ($departmentId) {
                    $scopeQuery
                        ->where('all_departments', true)
                        ->orWhereHas(
                            'departments',
                            fn($d) => $d->where('departments.id', $departmentId),
                        )
                        ->orWhere(function ($legacy) use ($departmentId) {
                            $legacy
                                ->where('creator_department_id', $departmentId)
                                ->whereDoesntHave('departments');
                        });
                });
            }

            $approvalFlows = $query
                ->orderBy('document_type')
                ->orderByRaw('COALESCE(min_amount, 0) ASC')
                ->orderByRaw('COALESCE(max_amount, 999999999999999999) ASC')
                ->orderBy('id')
                ->paginate($perPage);

            $approvalFlows->getCollection()->transform(function (ApprovalFlow $flow) {
                $steps = $flow->steps->map(function ($step) {
                    $approverType = strtoupper((string) $step->approver_type);

                    $approverName = '-';

                    if ($approverType === 'ROLE') {
                        $approverName = $step->role?->kode
                            ?? $step->approverRole?->kode
                            ?? $step->role?->kode
                            ?? $step->approverRole?->kode
                            ?? null;
                    } elseif ($approverType === 'USER') {
                        $approverName = $step->user?->fullname
                            ?? $step->user?->name
                            ?? $step->approverUser?->fullname
                            ?? $step->approverUser?->name
                            ?? null;
                    } else {
                        $approverName = null;
                    }

                    return [
                        'id' => $step->id,
                        'public_id' => Crypt::encrypt($step->id),
                        'step_order' => $step->step_order,
                        'sequence' => $step->step_order,
                        'approver_type' => $approverType,
                        'approver_id' => $step->approver_id,
                        'approver_public_id' => Crypt::encrypt($step->approver_id),
                        'approver_name' => $approverName,
                        'approval_role_name' => $approverName,
                        'role_name' => $approverName,
                        'label' => $step->label,
                        'approval_mode' => $step->approval_mode ?? 'ANY',
                        'is_required' => (bool) $step->is_required,
                        'approver_scope' => $step->approver_scope
                            ?? ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                        'branch_ids' => $step
                            ->branchMappings
                            ->pluck('cabang_id')
                            ->map(
                                fn($branchId) => (int) $branchId,
                            )
                            ->values()
                            ->all(),

                        /*
                        | Approver pengganti per Tipe Dokumen Khusus. Array kosong
                        | untuk step biasa.
                        */
                        'special_approvers' => $step
                            ->specialApprovers
                            ->map(function ($special) {
                                return [
                                    'special_document_type_id'
                                    => (int) $special->special_document_type_id,

                                    'special_document_type_name'
                                    => $special->specialDocumentType?->name,

                                    'approver_type'
                                    => strtoupper((string) $special->approver_type),

                                    'approver_id' => (int) $special->approver_id,

                                    'approver_name'
                                    => $special->resolveApproverName(),
                                ];
                            })
                            ->values()
                            ->all(),
                    ];
                })->values();

                $firstStep = $steps->first();

                return [
                    'id' => $flow->id,
                    'public_id' => Crypt::encrypt($flow->id),

                    'permission_module_id'
                    => $flow->permission_module_id,

                    'permission_module'
                    => $this->getPermissionModuleData(
                        $flow,
                    ),

                    'module'
                    => $this->getModuleDisplayName($flow),

                    'module_name'
                    => $this->getModuleDisplayName($flow),

                    'legacy_module_name'
                    => $flow->module_name,

                    'document_type' => $flow->document_type,
                    'document_type_label' => $this->getDocumentTypeLabel($flow->document_type),

                    'name' => $flow->name,
                    'approval_name' => $flow->name,

                    'min_amount' => $flow->min_amount !== null ? (float) $flow->min_amount : null,
                    'max_amount' => $flow->max_amount !== null ? (float) $flow->max_amount : null,

                    /**
                     * Untuk compatibility dengan index.vue sebelumnya.
                     * Karena data asli sekarang ada di steps.
                     */
                    'sequence' => $firstStep['step_order'] ?? null,
                    'approval_order' => $firstStep['step_order'] ?? null,
                    'approval_role_name' => $firstStep['approver_name'] ?? '-',
                    'role_name' => $firstStep['approver_name'] ?? '-',

                    'area_type' => $flow->area_type,
                    'cabang' => $flow->cabang,
                    'creator_department_id' => $flow->creator_department_id,
                    'creator_department_name' => $flow->creatorDepartment?->nama
                        ?? $flow->creatorDepartment?->name
                        ?? $flow->creatorDepartment?->kode
                        ?? null,
                    'creator_department_code' => $flow->creatorDepartment?->kode ?? null,

                    ...$this->flowScopePayload($flow),

                    'steps_count' => $steps
                        ->pluck('step_order')
                        ->unique()
                        ->count(),
                    'steps' => $steps,

                    'description' => $flow->description,
                    'notes' => $flow->description,

                    'is_active' => (bool) $flow->is_active,
                    'status' => $flow->is_active ? 'ACTIVE' : 'INACTIVE',

                    'created_at' => optional($flow->created_at)->toDateTimeString(),
                    'updated_at' => optional($flow->updated_at)->toDateTimeString(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Data approval flow berhasil dimuat.',
                'data' => $approvalFlows,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Approval Flow] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function toggleStatus(Request $request, $publicId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decrypt($publicId);

            $flow = ApprovalFlow::with(['steps'])->findOrFail($id);

            $flow->update([
                'is_active' => !$flow->is_active,
                'updated_by' => $request->user()->id ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $flow->is_active
                    ? 'Approval flow berhasil diaktifkan.'
                    : 'Approval flow berhasil dinonaktifkan.',
                'data' => [
                    'id' => $flow->id,
                    'public_id' => $flow->encrypted_id ?? Crypt::encryptString((string) $flow->id),
                    'is_active' => (bool) $flow->is_active,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Approval Flow] Toggle status error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah status approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'document_type' => ['required', 'string', 'max:50'],
                'module_name' => ['nullable', 'string', 'max:100'],
                'permission_module_id' => [
                    'nullable',
                    'integer',
                    'exists:permission_modules,id',
                ],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],

                'min_amount' => ['nullable', 'numeric', 'min:0'],
                'max_amount' => ['nullable', 'numeric', 'min:0'],
                'is_active' => ['nullable', 'boolean'],

                /*
            |--------------------------------------------------------------------------
            | PR Matrix Condition
            |--------------------------------------------------------------------------
            */
                'area_type' => ['nullable', 'string', 'max:50'],
                'cabang' => ['nullable', 'string', 'max:100'],
                'creator_department_id' => ['nullable', 'integer'],

                /*
            |--------------------------------------------------------------------------
            | Cakupan department dan keterangan transaksi
            |--------------------------------------------------------------------------
            | Satu flow dapat mencakup beberapa department sekaligus, atau
            | seluruhnya lewat all_departments. Sama halnya untuk keterangan
            | transaksi pada dokumen yang memakainya.
            |--------------------------------------------------------------------------
            */
                'all_departments' => ['nullable', 'boolean'],
                'department_ids' => ['nullable', 'array'],
                'department_ids.*' => ['integer', 'exists:departments,id'],

                'all_transaction_categories' => ['nullable', 'boolean'],
                'transaction_category_ids' => ['nullable', 'array'],
                'transaction_category_ids.*' => [
                    'integer',
                    'exists:fund_request_transaction_categories,id',
                ],

                /*
            |--------------------------------------------------------------------------
            | Nested Steps
            |--------------------------------------------------------------------------
            */
                'steps' => ['required', 'array', 'min:1'],
                'steps.*.step_order' => ['required', 'integer', 'min:1'],
                'steps.*.label' => ['nullable', 'string', 'max:255'],
                'steps.*.approval_mode' => ['nullable', 'string', 'in:ANY,ALL'],

                'steps.*.approvers' => ['required', 'array', 'min:1'],
                'steps.*.approvers.*.approver_type' => [
                    'required',
                    'string',
                    'in:ROLE,USER',
                ],

                'steps.*.approvers.*.approver_id' => [
                    'required',
                    'integer',
                ],

                /*
            |--------------------------------------------------------------------------
            | Scope per approver
            |--------------------------------------------------------------------------
            */
                'steps.*.approvers.*.approver_scope' => [
                    'nullable',
                    'string',
                    Rule::in([
                        ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                        ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH,
                        ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES,
                    ]),
                ],

                /*
            |--------------------------------------------------------------------------
            | Daftar cabang khusus per approver
            |--------------------------------------------------------------------------
            */

                'steps.*.approvers.*.branch_ids' => [
                    'nullable',
                    'array',
                ],

                'steps.*.approvers.*.branch_ids.*' => [
                    'integer',
                ],

                /*
            |--------------------------------------------------------------------------
            | Approver pengganti untuk Tipe Dokumen Khusus (opsional)
            |--------------------------------------------------------------------------
            */
                'steps.*.approvers.*.special_approvers' => ['nullable', 'array'],
                'steps.*.approvers.*.special_approvers.*.special_document_type_id' => [
                    'required',
                    'integer',
                    'exists:special_document_types,id',
                ],
                'steps.*.approvers.*.special_approvers.*.approver_type' => [
                    'required',
                    'string',
                    'in:ROLE,USER',
                ],
                'steps.*.approvers.*.special_approvers.*.approver_id' => [
                    'required',
                    'integer',
                    'min:1',
                ],

                /*
            |--------------------------------------------------------------------------
            | Compatibility payload frontend lama
            |--------------------------------------------------------------------------
            | Boleh dipertahankan sementara sampai frontend selesai direvisi.
            |--------------------------------------------------------------------------
            */
                'steps.*.approver_scope' => [
                    'nullable',
                    'string',
                    Rule::in([
                        ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                        ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH,
                        ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES,
                    ]),
                ],
            ]);

            $id = Crypt::decrypt($publicId);

            $flow = ApprovalFlow::with(['steps'])
                ->lockForUpdate()
                ->findOrFail($id);

            /*
        |--------------------------------------------------------------------------
        | Normalize Document Type
        |--------------------------------------------------------------------------
        | Jangan dibatasi hanya PO / PR, karena Vendor juga akan masuk.
        |--------------------------------------------------------------------------
        */
            $documentType = $this->normalizeDocumentType(
                (string) $request->input('document_type'),
            );

            $documentTypeUpper = strtoupper(
                $documentType,
            );

            $permissionModule = $this->resolvePermissionModule(
                request: $request,
                documentType: $documentType,
            );

            /*
        |--------------------------------------------------------------------------
        | Module Name
        |--------------------------------------------------------------------------
        */
            $moduleName = $request->filled('module_name')
                ? trim((string) $request->input('module_name'))
                : 'Procurement';

            if ($moduleName === '') {
                $moduleName = 'Procurement';
            }

            /*
        |--------------------------------------------------------------------------
        | Amount
        |--------------------------------------------------------------------------
        */
            $minAmount = $request->filled('min_amount')
                ? (float) $request->input('min_amount')
                : null;

            $maxAmount = $request->filled('max_amount')
                ? (float) $request->input('max_amount')
                : null;

            if (
                $minAmount !== null
                && $maxAmount !== null
                && $maxAmount > 0
                && $maxAmount < $minAmount
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Maksimal nilai tidak boleh lebih kecil dari minimal nilai.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Kondisi matriks area
        |--------------------------------------------------------------------------
        */
            $areaType = $request->filled('area_type')
                ? strtoupper(trim((string) $request->input('area_type')))
                : null;

            $scope = $this->resolveFlowScope($request, $documentTypeUpper);

            $creatorDepartmentId = $scope['creator_department_id'];

            if ($this->documentTypeUsesAreaMatrix($documentTypeUpper)) {
                if (!in_array($areaType, ['HO', 'CABANG'], true)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => sprintf(
                            'Area type wajib dipilih untuk approval %s.',
                            $documentTypeUpper,
                        ),
                    ], 422);
                }
            } else {
                $areaType = null;
                $creatorDepartmentId = null;
            }

            /*
        |--------------------------------------------------------------------------
        | Cabang
        |--------------------------------------------------------------------------
        | Untuk konsep sekarang:
        | HO     = area_type HO, cabang null
        | CABANG = area_type CABANG, cabang null, artinya semua cabang
        |--------------------------------------------------------------------------
        */
            $cabang = null;

            $steps = $this->normalizeApprovalSteps(
                $request->input('steps', []),
            );

            if ($steps->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Minimal harus ada 1 step approval.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi Duplicate Approver dalam Step yang Sama
        |--------------------------------------------------------------------------
        | ROLE-10 dan USER-10 dianggap berbeda.
        |--------------------------------------------------------------------------
        */
            // foreach ($steps as $stepIndex => $step) {
            //     $approvers = collect($step['approvers'] ?? [])->values();

            //     if ($approvers->isEmpty()) {
            //         DB::rollBack();

            //         return response()->json([
            //             'success' => false,
            //             'message' => 'Step ' . ($stepIndex + 1) . ' minimal memiliki 1 approver.',
            //         ], 422);
            //     }

            //     $usedApproversInStep = [];

            //     foreach ($approvers as $approver) {
            //         $approverType = strtoupper((string) ($approver['approver_type'] ?? ''));
            //         $approverId = (int) ($approver['approver_id'] ?? 0);

            //         $approverKey = $approverType . '-' . $approverId;

            //         if (in_array($approverKey, $usedApproversInStep, true)) {
            //             DB::rollBack();

            //             return response()->json([
            //                 'success' => false,
            //                 'message' => 'Approver pada step ' . ($stepIndex + 1) . ' duplikat.',
            //             ], 422);
            //         }

            //         $usedApproversInStep[] = $approverKey;
            //     }
            // }

            /*
        |--------------------------------------------------------------------------
        | Update Approval Flow Header
        |--------------------------------------------------------------------------
        */
            $flow->update([
                'document_type' => $documentType,

                'permission_module_id'
                => $permissionModule->id,

                'module_name' => $this->getLegacyModuleName(
                    documentType: $documentType,
                    permissionModule: $permissionModule,
                ),
                'name' => $request->input('name'),
                'description' => $request->input('description'),

                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'is_active' => $request->has('is_active')
                    ? (bool) $request->boolean('is_active')
                    : (bool) $flow->is_active,

                'area_type' => $areaType,
                'cabang' => $cabang,
                'creator_department_id' => $creatorDepartmentId,
                'all_departments' => $scope['all_departments'],
                'all_transaction_categories' => $scope['all_transaction_categories'],

                'updated_by' => $request->user()->id ?? null,
            ]);

            $this->syncFlowScope($flow, $scope);

            /*
        |--------------------------------------------------------------------------
        | Replace Approval Flow Steps
        |--------------------------------------------------------------------------
        | Hapus step lama, lalu insert ulang dari payload nested.
        | Aman karena ini master flow. Transaksi lama sudah punya snapshot approval sendiri.
        |--------------------------------------------------------------------------
        */
            // DB::table('approval_flow_steps')
            //     ->where('approval_flow_id', $flow->id)
            //     ->delete();

            // foreach ($steps as $stepIndex => $step) {
            //     $stepOrder = (int) ($step['step_order'] ?? ($stepIndex + 1));
            //     $label = $step['label'] ?? null;

            //     $approvalMode = strtoupper((string) ($step['approval_mode'] ?? 'ANY'));

            //     if (!in_array($approvalMode, ['ANY', 'ALL'], true)) {
            //         $approvalMode = 'ANY';
            //     }

            //     $approvers = collect($step['approvers'] ?? [])->values();

            //     foreach ($approvers as $approver) {
            //         DB::table('approval_flow_steps')->insert([
            //             'approval_flow_id' => $flow->id,
            //             'step_order' => $stepOrder,
            //             'approver_type' => strtoupper((string) $approver['approver_type']),
            //             'approver_id' => (int) $approver['approver_id'],
            //             'label' => $label,
            //             'approval_mode' => $approvalMode,
            //             'approver_scope' => strtoupper(
            //                 trim(
            //                     (string) (
            //                         $step['approver_scope']
            //                         ?? ApprovalFlowStep::APPROVER_SCOPE_GLOBAL
            //                     )
            //                 )
            //             ),
            //             'is_required' => true,
            //             'created_at' => now(),
            //             'updated_at' => now(),
            //         ]);
            //     }
            // }

            /*
        |--------------------------------------------------------------------------
        | Hapus konfigurasi lama
        |--------------------------------------------------------------------------
        | Mapping approval_flow_step_branches ikut terhapus karena cascadeOnDelete.
        |--------------------------------------------------------------------------
        */
            $flow->steps()->delete();

            /*
            |--------------------------------------------------------------------------
            | Simpan konfigurasi baru
            |--------------------------------------------------------------------------
            */
            $this->createApprovalFlowSteps(
                $flow,
                $steps,
            );

            DB::commit();

            $flow->load([
                'steps.role',
                'steps.user',
                'steps.branchMappings',
                    'steps.specialApprovers.specialDocumentType',
                    'steps.specialApprovers.approverUser:id,name',
                    'steps.specialApprovers.approverRole',
                'creatorDepartment',
                'departments',
                'transactionCategories',
                'permissionModule',
            ]);

            $stepsResponse = $flow->steps
                ->sortBy([
                    ['step_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->map(function ($step) {
                    $approverType = strtoupper((string) $step->approver_type);

                    if ($approverType === 'ROLE') {
                        $approverName = $step->role?->name
                            ?? $step->role?->nama
                            ?? $step->approverRole?->name
                            ?? $step->approverRole?->nama
                            ?? null;
                    } elseif ($approverType === 'USER') {
                        $approverName = $step->user?->fullname
                            ?? $step->user?->name
                            ?? $step->approverUser?->fullname
                            ?? $step->approverUser?->name
                            ?? null;
                    } else {
                        $approverName = null;
                    }

                    return [
                        'id' => $step->id,
                        'public_id' => Crypt::encrypt($step->id),
                        'step_order' => $step->step_order,
                        'sequence' => $step->step_order,
                        'approver_type' => $approverType,
                        'approver_id' => $step->approver_id,
                        'approver_public_id' => Crypt::encrypt($step->approver_id),

                        'approver_name' => $approverName,
                        'approval_role_name' => $approverName,
                        'role_name' => $approverName,

                        'label' => $step->label,
                        'approval_mode' => $step->approval_mode ?? 'ANY',
                        'is_required' => (bool) $step->is_required,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Approval flow berhasil diperbarui.',
                'data' => [
                    'id' => $flow->id,
                    'public_id' => Crypt::encrypt($flow->id),

                    'permission_module_id'
                    => $flow->permission_module_id,

                    'permission_module'
                    => $this->getPermissionModuleData(
                        $flow,
                    ),

                    'module'
                    => $this->getModuleDisplayName($flow),

                    'module_name'
                    => $this->getModuleDisplayName($flow),

                    'legacy_module_name'
                    => $flow->module_name,

                    'document_type' => $flow->document_type,
                    'document_type_label' => $this->getDocumentTypeLabel($flow->document_type),

                    'name' => $flow->name,
                    'approval_name' => $flow->name,

                    'description' => $flow->description,
                    'notes' => $flow->description,

                    'min_amount' => $flow->min_amount !== null ? (float) $flow->min_amount : null,
                    'max_amount' => $flow->max_amount !== null ? (float) $flow->max_amount : null,

                    'area_type' => $flow->area_type,
                    'cabang' => $flow->cabang,
                    'creator_department_id' => $flow->creator_department_id,
                    'creator_department_name' => $flow->creatorDepartment?->nama
                        ?? $flow->creatorDepartment?->name
                        ?? null,
                    'creator_department_code' => $flow->creatorDepartment?->kode ?? null,

                    ...$this->flowScopePayload($flow),

                    'is_active' => (bool) $flow->is_active,
                    'status' => $flow->is_active ? 'ACTIVE' : 'INACTIVE',

                    'steps_count' => $stepsResponse->count(),
                    'steps' => $stepsResponse,

                    'created_at' => optional($flow->created_at)->toDateTimeString(),
                    'updated_at' => optional($flow->updated_at)->toDateTimeString(),
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Approval Flow] Update error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function destroy(Request $request, $publicId)
    {
        DB::beginTransaction();

        try {
            $id = Crypt::decrypt($publicId);

            $flow = ApprovalFlow::with(['steps'])->findOrFail($id);

            /*
            |--------------------------------------------------------------------------
            | Soft delete approval flow
            |--------------------------------------------------------------------------
            | Data PO lama aman, karena PO yang sudah submit sudah punya snapshot
            | approval di table purchase_order_approvals.
            |--------------------------------------------------------------------------
            */

            $flow->update([
                'updated_by' => $request->user()->id ?? null,
            ]);

            $flow->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Approval flow berhasil dihapus.',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Approval Flow] Destroy error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'document_type' => ['required', 'string', 'max:50'],
                'module_name' => ['nullable', 'string', 'max:100'],
                'permission_module_id' => [
                    'nullable',
                    'integer',
                    'exists:permission_modules,id',
                ],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],

                'min_amount' => ['nullable', 'numeric', 'min:0'],
                'max_amount' => ['nullable', 'numeric', 'min:0'],
                'is_active' => ['nullable', 'boolean'],

                /*
            |--------------------------------------------------------------------------
            | PR Matrix Condition
            |--------------------------------------------------------------------------
            | Field ini hanya wajib kalau document_type = PR.
            |--------------------------------------------------------------------------
            */
                'area_type' => ['nullable', 'string', 'max:50'],
                'cabang' => ['nullable', 'string', 'max:100'],
                'creator_department_id' => ['nullable', 'integer'],

                /*
            |--------------------------------------------------------------------------
            | Cakupan department dan keterangan transaksi
            |--------------------------------------------------------------------------
            | Satu flow dapat mencakup beberapa department sekaligus, atau
            | seluruhnya lewat all_departments. Sama halnya untuk keterangan
            | transaksi pada dokumen yang memakainya.
            |--------------------------------------------------------------------------
            */
                'all_departments' => ['nullable', 'boolean'],
                'department_ids' => ['nullable', 'array'],
                'department_ids.*' => ['integer', 'exists:departments,id'],

                'all_transaction_categories' => ['nullable', 'boolean'],
                'transaction_category_ids' => ['nullable', 'array'],
                'transaction_category_ids.*' => [
                    'integer',
                    'exists:fund_request_transaction_categories,id',
                ],

                /*
            |--------------------------------------------------------------------------
            | Nested Steps
            |--------------------------------------------------------------------------
            | FE mengirim:
            | steps[].approvers[]
            |--------------------------------------------------------------------------
            */
                'steps' => ['required', 'array', 'min:1'],
                'steps.*.step_order' => ['required', 'integer', 'min:1'],
                'steps.*.label' => ['nullable', 'string', 'max:255'],
                'steps.*.approval_mode' => ['nullable', 'string', 'in:ANY,ALL'],

                'steps.*.approvers' => ['required', 'array', 'min:1'],
                'steps.*.approvers.*.approver_type' => ['required', 'string', 'in:ROLE,USER'],
                'steps.*.approvers.*.approver_id' => ['required', 'integer'],

                /*
            |--------------------------------------------------------------------------
            | Approver pengganti untuk Tipe Dokumen Khusus (opsional)
            |--------------------------------------------------------------------------
            */
                'steps.*.approvers.*.special_approvers' => ['nullable', 'array'],
                'steps.*.approvers.*.special_approvers.*.special_document_type_id' => [
                    'required',
                    'integer',
                    'exists:special_document_types,id',
                ],
                'steps.*.approvers.*.special_approvers.*.approver_type' => [
                    'required',
                    'string',
                    'in:ROLE,USER',
                ],
                'steps.*.approvers.*.special_approvers.*.approver_id' => [
                    'required',
                    'integer',
                    'min:1',
                ],
                'steps.*.approver_scope' => [
                    'nullable',
                    'string',
                    Rule::in([
                        ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                        ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH,
                    ]),
                ],
            ]);

            $documentType = $this->normalizeDocumentType(
                (string) $request->input('document_type'),
            );

            $documentTypeUpper = strtoupper(
                $documentType,
            );

            $permissionModule = $this->resolvePermissionModule(
                request: $request,
                documentType: $documentType,
            );

            $minAmount = $request->filled('min_amount')
                ? (float) $request->input('min_amount')
                : null;

            $maxAmount = $request->filled('max_amount')
                ? (float) $request->input('max_amount')
                : null;

            if (
                $minAmount !== null
                && $maxAmount !== null
                && $maxAmount > 0
                && $maxAmount < $minAmount
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Maksimal nilai tidak boleh lebih kecil dari minimal nilai.',
                ], 422);
            }

            $areaType = $request->filled('area_type')
                ? strtoupper(trim((string) $request->input('area_type')))
                : null;

            $scope = $this->resolveFlowScope($request, $documentTypeUpper);

            $creatorDepartmentId = $scope['creator_department_id'];

            /*
        |--------------------------------------------------------------------------
        | Validasi dokumen bermatriks area
        |--------------------------------------------------------------------------
        | Untuk dokumen bermatriks area, area_type wajib. Cakupan department
        | sudah divalidasi resolveFlowScope(). Cabang tidak wajib karena
        | CABANG berarti semua cabang.
        |--------------------------------------------------------------------------
        */
            if ($this->documentTypeUsesAreaMatrix($documentTypeUpper)) {
                if (!in_array($areaType, ['HO', 'CABANG'], true)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => sprintf(
                            'Area type wajib dipilih untuk approval %s.',
                            $documentTypeUpper,
                        ),
                    ], 422);
                }
            } else {
                $areaType = null;
                $creatorDepartmentId = null;
            }

            /*
        |--------------------------------------------------------------------------
        | Cabang
        |--------------------------------------------------------------------------
        | Untuk konsep saat ini:
        | - HO = cabang null
        | - CABANG = cabang null, artinya semua cabang
        |--------------------------------------------------------------------------
        */
            $cabang = null;

            $steps = $this->normalizeApprovalSteps(
                $request->input('steps', []),
            );

            if ($steps->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Minimal harus ada 1 step approval.',
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi duplicate approver dalam step yang sama
        |--------------------------------------------------------------------------
        | ROLE-10 dan USER-10 dianggap berbeda.
        | Approver yang sama boleh muncul di step berbeda? Sebaiknya tidak.
        | Tapi untuk aman terhadap existing flow, kita validasi per step dulu.
        |--------------------------------------------------------------------------
        */
            // foreach ($steps as $stepIndex => $step) {
            //     $approvers = collect($step['approvers'] ?? [])->values();

            //     if ($approvers->isEmpty()) {
            //         DB::rollBack();

            //         return response()->json([
            //             'success' => false,
            //             'message' => 'Step ' . ($stepIndex + 1) . ' minimal memiliki 1 approver.',
            //         ], 422);
            //     }

            //     $usedApproversInStep = [];

            //     foreach ($approvers as $approverIndex => $approver) {
            //         $approverType = strtoupper((string) ($approver['approver_type'] ?? ''));
            //         $approverId = (int) ($approver['approver_id'] ?? 0);

            //         $approverKey = $approverType . '-' . $approverId;

            //         if (in_array($approverKey, $usedApproversInStep, true)) {
            //             DB::rollBack();

            //             return response()->json([
            //                 'success' => false,
            //                 'message' => 'Approver pada step ' . ($stepIndex + 1) . ' duplikat.',
            //             ], 422);
            //         }

            //         $usedApproversInStep[] = $approverKey;
            //     }
            // }

            /*
        |--------------------------------------------------------------------------
        | Create Approval Flow Header
        |--------------------------------------------------------------------------
        */
            $flow = ApprovalFlow::create([
                'document_type' => $documentType,
                'permission_module_id'
                => $permissionModule->id,
                'module_name' => $this->getLegacyModuleName(
                    documentType: $documentType,
                    permissionModule: $permissionModule,
                ),
                'name' => $request->input('name'),
                'description' => $request->input('description'),

                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'is_active' => $request->has('is_active')
                    ? (bool) $request->boolean('is_active')
                    : true,

                'area_type' => $areaType,
                'cabang' => $cabang,
                'creator_department_id' => $creatorDepartmentId,
                'all_departments' => $scope['all_departments'],
                'all_transaction_categories' => $scope['all_transaction_categories'],

                'created_by' => $request->user()->id ?? null,
                'updated_by' => $request->user()->id ?? null,
            ]);

            $this->syncFlowScope($flow, $scope);

            $this->createApprovalFlowSteps(
                $flow,
                $steps,
            );

            /*
        |--------------------------------------------------------------------------
        | Create Approval Flow Steps
        |--------------------------------------------------------------------------
        | Nested payload di-flatten.
        | Kalau step 1 punya Adm / ADH, akan insert 2 row dengan step_order = 1.
        |--------------------------------------------------------------------------
        */
            // foreach ($steps as $stepIndex => $step) {
            //     $stepOrder = (int) ($step['step_order'] ?? ($stepIndex + 1));
            //     $label = $step['label'] ?? null;
            //     $approvalMode = strtoupper((string) ($step['approval_mode'] ?? 'ANY'));

            //     if (!in_array($approvalMode, ['ANY', 'ALL'], true)) {
            //         $approvalMode = 'ANY';
            //     }

            //     $approvers = collect($step['approvers'] ?? [])->values();

            //     foreach ($approvers as $approver) {
            //         DB::table('approval_flow_steps')->insert([
            //             'approval_flow_id' => $flow->id,
            //             'step_order' => $stepOrder,
            //             'approver_type' => strtoupper((string) $approver['approver_type']),
            //             'approver_id' => (int) $approver['approver_id'],
            //             'label' => $label,
            //             'approval_mode' => $approvalMode,
            //             'approver_scope' => strtoupper(
            //                 trim(
            //                     (string) (
            //                         $step['approver_scope']
            //                         ?? ApprovalFlowStep::APPROVER_SCOPE_GLOBAL
            //                     )
            //                 )
            //             ),
            //             'is_required' => true,
            //             'created_at' => now(),
            //             'updated_at' => now(),
            //         ]);
            //     }
            // }

            DB::commit();

            $flow->load([
                'steps.role',
                'steps.user',
                'steps.branchMappings',
                    'steps.specialApprovers.specialDocumentType',
                    'steps.specialApprovers.approverUser:id,name',
                    'steps.specialApprovers.approverRole',
                'creatorDepartment',
                'departments',
                'transactionCategories',
                'permissionModule',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Approval flow berhasil dibuat.',
                'data' => [
                    'id' => $flow->id,
                    'public_id' => Crypt::encrypt($flow->id),

                    'permission_module_id'
                    => $flow->permission_module_id,

                    'permission_module'
                    => $this->getPermissionModuleData(
                        $flow,
                    ),

                    'module'
                    => $this->getModuleDisplayName($flow),

                    'module_name'
                    => $this->getModuleDisplayName($flow),

                    'legacy_module_name'
                    => $flow->module_name,

                    'document_type' => $flow->document_type,
                    'document_type_label' => $this->getDocumentTypeLabel($flow->document_type),

                    'name' => $flow->name,
                    'description' => $flow->description,

                    'min_amount' => $flow->min_amount !== null ? (float) $flow->min_amount : null,
                    'max_amount' => $flow->max_amount !== null ? (float) $flow->max_amount : null,

                    'area_type' => $flow->area_type,
                    'cabang' => $flow->cabang,
                    'creator_department_id' => $flow->creator_department_id,

                    'is_active' => (bool) $flow->is_active,
                    'status' => $flow->is_active ? 'ACTIVE' : 'INACTIVE',

                    'steps' => $flow->steps
                        ->sortBy([
                            ['step_order', 'asc'],
                            ['id', 'asc'],
                        ])
                        ->values()
                        ->map(function ($step) {
                            $approverType = strtoupper((string) $step->approver_type);

                            if ($approverType === 'ROLE') {
                                $approverName = $step->role?->kode
                                    ?? $step->approverRole?->kode
                                    ?? $step->role?->kode
                                    ?? $step->approverRole?->kode
                                    ?? null;
                            } elseif ($approverType === 'USER') {
                                $approverName = $step->user?->fullname
                                    ?? $step->user?->name
                                    ?? $step->approverUser?->fullname
                                    ?? $step->approverUser?->name
                                    ?? null;
                            } else {
                                $approverName = null;
                            }

                            return [
                                'id' => $step->id,
                                'public_id' => Crypt::encrypt($step->id),
                                'step_order' => $step->step_order,
                                'sequence' => $step->step_order,
                                'approver_type' => $approverType,
                                'approver_id' => $step->approver_id,
                                'approver_public_id' => Crypt::encrypt($step->approver_id),

                                'approver_name' => $approverName,
                                'approval_role_name' => $approverName,
                                'role_name' => $approverName,

                                'label' => $step->label,
                                'approval_mode' => $step->approval_mode ?? 'ANY',
                                'is_required' => (bool) $step->is_required,
                            ];
                        }),
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Approval Flow] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        try {
            $id = Crypt::decrypt($publicId);

            $flow = ApprovalFlow::query()
                ->with([
                    'steps.role',
                    'steps.user',
                    'steps.branchMappings',
                    'steps.specialApprovers.specialDocumentType',
                    'steps.specialApprovers.approverUser:id,name',
                    'steps.specialApprovers.approverRole',
                    'creatorDepartment',
                    'departments',
                    'transactionCategories',
                    'permissionModule',
                ])
                ->findOrFail($id);

            $steps = $flow->steps
                ->sortBy([
                    ['step_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->map(function ($step) {
                    $approverType = strtoupper((string) $step->approver_type);

                    if ($approverType === 'ROLE') {
                        $approverName = $step->role?->name
                            ?? $step->role?->nama
                            ?? $step->approverRole?->name
                            ?? $step->approverRole?->nama
                            ?? null;
                    } elseif ($approverType === 'USER') {
                        $approverName = $step->user?->fullname
                            ?? $step->user?->name
                            ?? $step->approverUser?->fullname
                            ?? $step->approverUser?->name
                            ?? null;
                    } else {
                        $approverName = null;
                    }

                    return [
                        'id' => $step->id,
                        'public_id' => Crypt::encrypt($step->id),
                        'step_order' => $step->step_order,
                        'sequence' => $step->step_order,
                        'approver_type' => $approverType,
                        'approver_id' => $step->approver_id,
                        'approver_public_id' => Crypt::encrypt($step->approver_id),
                        'approver_name' => $approverName,
                        'approval_role_name' => $approverName,
                        'role_name' => $approverName,
                        'label' => $step->label,
                        'approval_mode' => $step->approval_mode ?? 'ANY',
                        'is_required' => (bool) $step->is_required,
                        'approver_scope' => $step->approver_scope
                            ?? ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                        'branch_ids' => $step
                            ->branchMappings
                            ->pluck('cabang_id')
                            ->map(
                                fn($branchId) => (int) $branchId,
                            )
                            ->values()
                            ->all(),

                        /*
                        | Approver pengganti per Tipe Dokumen Khusus. Array kosong
                        | untuk step biasa.
                        */
                        'special_approvers' => $step
                            ->specialApprovers
                            ->map(function ($special) {
                                return [
                                    'special_document_type_id'
                                    => (int) $special->special_document_type_id,

                                    'special_document_type_name'
                                    => $special->specialDocumentType?->name,

                                    'approver_type'
                                    => strtoupper((string) $special->approver_type),

                                    'approver_id' => (int) $special->approver_id,

                                    'approver_name'
                                    => $special->resolveApproverName(),
                                ];
                            })
                            ->values()
                            ->all(),
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Detail approval flow berhasil dimuat.',
                'data' => [
                    'id' => $flow->id,
                    'public_id' => Crypt::encrypt($flow->id),

                    'permission_module_id'
                    => $flow->permission_module_id,

                    'permission_module'
                    => $this->getPermissionModuleData(
                        $flow,
                    ),

                    'module'
                    => $this->getModuleDisplayName($flow),

                    'module_name'
                    => $this->getModuleDisplayName($flow),

                    'legacy_module_name'
                    => $flow->module_name,

                    'document_type' => $flow->document_type,
                    'document_type_label' => $this->getDocumentTypeLabel($flow->document_type),

                    'name' => $flow->name,
                    'approval_name' => $flow->name,

                    'description' => $flow->description,
                    'notes' => $flow->description,

                    'min_amount' => $flow->min_amount !== null ? (float) $flow->min_amount : null,
                    'max_amount' => $flow->max_amount !== null ? (float) $flow->max_amount : null,

                    'area_type' => $flow->area_type,
                    'cabang' => $flow->cabang,
                    'creator_department_id' => $flow->creator_department_id,
                    'creator_department_name' => $flow->creatorDepartment?->nama
                        ?? $flow->creatorDepartment?->name
                        ?? null,
                    'creator_department_code' => $flow->creatorDepartment?->kode ?? null,

                    ...$this->flowScopePayload($flow),

                    'is_active' => (bool) $flow->is_active,
                    'status' => $flow->is_active ? 'ACTIVE' : 'INACTIVE',

                    'steps_count' => $steps->count(),
                    'steps' => $steps,

                    'created_at' => optional($flow->created_at)->toDateTimeString(),
                    'updated_at' => optional($flow->updated_at)->toDateTimeString(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Approval Flow] Show error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail approval flow.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Apakah flow jenis dokumen ini dibedakan per area + department.
     *
     * Dokumen seperti ini dipilih berdasarkan kombinasi area, department
     * pembuat, dan rentang nominal, sehingga kedua kolom itu wajib terisi.
     * Jenis dokumen lain tidak memakainya dan kolomnya dikosongkan.
     *
     * Penentunya adalah permission_modules.approval_uses_area_matrix.
     */
    private function documentTypeUsesAreaMatrix(
        string $documentType,
    ): bool {
        return $this->documentTypes->usesAreaMatrix($documentType);
    }

    /*
    |--------------------------------------------------------------------------
    | Cakupan flow: department dan keterangan transaksi
    |--------------------------------------------------------------------------
    | Satu flow bisa berlaku untuk beberapa department sekaligus atau untuk
    | seluruhnya, sesuai matriks yang memuat baris "GA - IT - LOG" maupun
    | "Semua Divisi". Hal yang sama berlaku untuk keterangan transaksi pada
    | jenis dokumen yang memakainya.
    |
    | @return array{
    |     all_departments: bool,
    |     department_ids: array<int, int>,
    |     creator_department_id: int|null,
    |     all_transaction_categories: bool,
    |     transaction_category_ids: array<int, int>
    | }
    |
    | @throws ValidationException bila cakupan wajib tidak terisi.
    |--------------------------------------------------------------------------
    */
    private function resolveFlowScope(
        Request $request,
        string $documentTypeUpper,
    ): array {
        $usesAreaMatrix = $this->documentTypeUsesAreaMatrix($documentTypeUpper);

        $usesTransactionCategory = $this->documentTypes
            ->usesTransactionCategory($documentTypeUpper);

        $toIdList = static fn($value) => collect(
            is_array($value) ? $value : [],
        )
            ->map(fn($id) => (int) $id)
            ->filter(fn(int $id) => $id > 0)
            ->unique()
            ->values();

        /*
        |----------------------------------------------------------------------
        | Department
        |----------------------------------------------------------------------
        */
        $allDepartments = false;
        $departmentIds = collect();

        if ($usesAreaMatrix) {
            $allDepartments = $request->boolean('all_departments');

            $departmentIds = $toIdList($request->input('department_ids'));

            /*
            | Kompatibilitas: form lama hanya mengirim creator_department_id
            | tunggal. Tanpa penanganan ini, menyimpan lewat versi frontend
            | lama akan menghapus cakupan department flow tersebut.
            */
            if (
                !$allDepartments
                && $departmentIds->isEmpty()
                && $request->filled('creator_department_id')
            ) {
                $departmentIds = collect([
                    (int) $request->input('creator_department_id'),
                ]);
            }

            if ($allDepartments) {
                $departmentIds = collect();
            } elseif ($departmentIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'department_ids' => [
                        sprintf(
                            'Department wajib dipilih untuk approval %s.',
                            $documentTypeUpper,
                        ),
                    ],
                ]);
            }
        }

        /*
        |----------------------------------------------------------------------
        | Keterangan transaksi
        |----------------------------------------------------------------------
        | Jenis dokumen yang tidak memakainya selalu tersimpan sebagai "semua",
        | supaya pencocokan flow-nya tidak pernah tersaring kolom ini.
        |----------------------------------------------------------------------
        */
        $allTransactionCategories = true;
        $transactionCategoryIds = collect();

        if ($usesTransactionCategory) {
            $allTransactionCategories = $request->boolean(
                'all_transaction_categories',
            );

            $transactionCategoryIds = $toIdList(
                $request->input('transaction_category_ids'),
            );

            if ($allTransactionCategories) {
                $transactionCategoryIds = collect();
            } elseif ($transactionCategoryIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'transaction_category_ids' => [
                        sprintf(
                            'Keterangan transaksi wajib dipilih untuk approval %s.',
                            $documentTypeUpper,
                        ),
                    ],
                ]);
            }
        }

        return [
            'all_departments' => $allDepartments,
            'department_ids' => $departmentIds->all(),

            /*
            | Tetap diisi department pertama demi kompatibilitas kode lama
            | yang masih membaca kolom tunggal ini.
            */
            'creator_department_id' => $departmentIds->first(),

            'all_transaction_categories' => $allTransactionCategories,
            'transaction_category_ids' => $transactionCategoryIds->all(),
        ];
    }

    /**
     * Menyimpan cakupan flow ke tabel pivot.
     */
    private function syncFlowScope(ApprovalFlow $flow, array $scope): void
    {
        $flow->departments()->sync($scope['department_ids']);
        $flow->transactionCategories()->sync($scope['transaction_category_ids']);
    }

    /**
     * Cakupan flow untuk dikirim ke frontend.
     *
     * Wajib ikut pada setiap response yang dibaca form edit. Tanpa ini pilihan
     * multi-nilai tidak pernah kembali terisi saat flow dibuka, dan penyimpanan
     * berikutnya malah menghapus isi pivot-nya -- tersimpan, tapi tampak hilang.
     */
    private function flowScopePayload(ApprovalFlow $flow): array
    {
        $departments = $flow->relationLoaded('departments')
            ? $flow->departments
            : $flow->departments()->get();

        $categories = $flow->relationLoaded('transactionCategories')
            ? $flow->transactionCategories
            : $flow->transactionCategories()->get();

        return [
            'all_departments' => (bool) $flow->all_departments,

            'department_ids' => $departments
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->all(),

            'department_names' => $departments
                ->map(fn($department) => $department->nama
                    ?? $department->name
                    ?? $department->kode)
                ->filter()
                ->values()
                ->all(),

            'all_transaction_categories' => (bool) $flow->all_transaction_categories,

            'transaction_category_ids' => $categories
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->all(),

            'transaction_category_names' => $categories
                ->pluck('name')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function getDocumentTypeLabel(
        ?string $documentType,
    ): string {
        return $this->documentTypes->label($documentType);
    }

    /**
     * Menormalkan document type yang diterima API.
     */
    private function normalizeDocumentType(
        string $documentType,
    ): string {
        return $this->documentTypes->normalize($documentType);
    }

    /**
     * Menentukan code permission_modules berdasarkan document type.
     */
    private function getPermissionModuleCode(
        string $documentType,
    ): string {
        return $this->documentTypes->permissionModuleCode($documentType);
    }

    /**
     * Mengambil module yang sesuai dengan jenis dokumen.
     *
     * permission_module_id dari frontend tetap diverifikasi.
     * Frontend tidak dapat mengirim module Vendor untuk dokumen PR.
     */
    private function resolvePermissionModule(
        Request $request,
        string $documentType,
    ): PermissionModule {
        $expectedCode = $this->getPermissionModuleCode(
            $documentType,
        );

        $query = PermissionModule::query()
            ->where('code', $expectedCode)
            ->where('is_active', true);

        /*
     * Selama masa transisi, permission_module_id dibuat
     * opsional. Jika dikirim frontend, ID-nya harus sesuai
     * dengan code yang diharapkan.
     */
        if ($request->filled('permission_module_id')) {
            $query->whereKey(
                (int) $request->input(
                    'permission_module_id',
                ),
            );
        }

        $permissionModule = $query->first();

        if (!$permissionModule) {
            throw ValidationException::withMessages([
                'permission_module_id' => [
                    sprintf(
                        'Module aktif dengan code "%s" tidak ditemukan atau tidak sesuai dengan jenis dokumen.',
                        $expectedCode,
                    ),
                ],
            ]);
        }

        return $permissionModule;
    }

    /**
     * Nilai module_name lama tetap disimpan sementara
     * untuk kompatibilitas code existing.
     *
     * Sumber utama modul tetap permission_module_id.
     *
     * Daftar di bawah bukan daftar jenis dokumen, melainkan penambal untuk
     * tiga jenis dokumen yang sudah terlanjur punya module_name berbeda dari
     * nama permission module-nya. Jenis dokumen baru jatuh ke default dan
     * tidak perlu ditambahkan ke sini.
     */
    private function getLegacyModuleName(
        string $documentType,
        PermissionModule $permissionModule,
    ): string {
        return match (strtoupper(trim($documentType))) {
            /*
         * PR dan PO sebelumnya berada dalam kelompok Procurement.
         */
            'PR', 'PO' => 'Procurement',

            'VENDOR' => 'Master Vendor',

            default => $permissionModule->name,
        };
    }

    /**
     * Format module untuk response API.
     */
    private function getPermissionModuleData(
        ApprovalFlow $flow,
    ): ?array {
        if (!$flow->permissionModule) {
            return null;
        }

        return [
            'id' => $flow->permissionModule->id,
            'code' => $flow->permissionModule->code,
            'name' => $flow->permissionModule->name,
            'is_active' => (bool) $flow
                ->permissionModule
                ->is_active,
        ];
    }

    /**
     * Nama module yang ditampilkan frontend.
     */
    private function getModuleDisplayName(
        ApprovalFlow $flow,
    ): string {
        return $flow->permissionModule?->name
            ?? $flow->module_name
            ?? '-';
    }

    private function normalizeApprovalSteps(
        array $rawSteps,
    ): Collection {
        $steps = collect($rawSteps)->values();

        if ($steps->isEmpty()) {
            throw ValidationException::withMessages([
                'steps' => [
                    'Minimal harus ada 1 step approval.',
                ],
            ]);
        }

        $validScopes = [
            ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
            ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH,
            ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES,
        ];

        return $steps->map(
            function (
                array $step,
                int $stepIndex,
            ) use ($validScopes): array {
                $stepNumber = $stepIndex + 1;

                $stepOrder = (int) (
                    $step['step_order']
                    ?? $stepNumber
                );

                $label = isset($step['label'])
                    ? trim((string) $step['label'])
                    : null;

                $approvalMode = strtoupper(
                    trim(
                        (string) (
                            $step['approval_mode']
                            ?? ApprovalFlowStep::APPROVAL_MODE_ANY
                        ),
                    ),
                );

                if (
                    !in_array(
                        $approvalMode,
                        [
                            ApprovalFlowStep::APPROVAL_MODE_ANY,
                            ApprovalFlowStep::APPROVAL_MODE_ALL,
                        ],
                        true,
                    )
                ) {
                    $approvalMode = ApprovalFlowStep::APPROVAL_MODE_ANY;
                }

                /*
            |--------------------------------------------------------------------------
            | Fallback scope dari struktur frontend lama
            |--------------------------------------------------------------------------
            */
                $defaultScope = strtoupper(
                    trim(
                        (string) (
                            $step['approver_scope']
                            ?? ApprovalFlowStep::APPROVER_SCOPE_GLOBAL
                        ),
                    ),
                );

                if (!in_array($defaultScope, $validScopes, true)) {
                    throw ValidationException::withMessages([
                        "steps.{$stepIndex}.approver_scope" => [
                            sprintf(
                                'Approver scope pada Step %d tidak valid.',
                                $stepNumber,
                            ),
                        ],
                    ]);
                }

                $approvers = collect(
                    $step['approvers']
                        ?? [],
                )->values();

                if ($approvers->isEmpty()) {
                    throw ValidationException::withMessages([
                        "steps.{$stepIndex}.approvers" => [
                            sprintf(
                                'Step %d minimal memiliki 1 approver.',
                                $stepNumber,
                            ),
                        ],
                    ]);
                }

                $usedApprovers = [];

                $normalizedApprovers = $approvers->map(
                    function (
                        array $approver,
                        int $approverIndex,
                    ) use (
                        &$usedApprovers,
                        $stepIndex,
                        $stepNumber,
                        $defaultScope,
                        $validScopes,
                    ): array {
                        $approverType = strtoupper(
                            trim(
                                (string) (
                                    $approver['approver_type']
                                    ?? ''
                                ),
                            ),
                        );

                        $approverId = (int) (
                            $approver['approver_id']
                            ?? 0
                        );

                        $approverKey = sprintf(
                            '%s-%d',
                            $approverType,
                            $approverId,
                        );

                        if (isset($usedApprovers[$approverKey])) {
                            throw ValidationException::withMessages([
                                "steps.{$stepIndex}.approvers.{$approverIndex}.approver_id" => [
                                    sprintf(
                                        'Approver pada Step %d duplikat.',
                                        $stepNumber,
                                    ),
                                ],
                            ]);
                        }

                        $usedApprovers[$approverKey] = true;

                        /*
                    |--------------------------------------------------------------------------
                    | Scope milik approver
                    |--------------------------------------------------------------------------
                    | Scope per approver diprioritaskan.
                    | Scope level step hanya menjadi fallback payload lama.
                    |--------------------------------------------------------------------------
                    */
                        $approverScope = strtoupper(
                            trim(
                                (string) (
                                    $approver['approver_scope']
                                    ?? $defaultScope
                                ),
                            ),
                        );

                        if (
                            !in_array(
                                $approverScope,
                                $validScopes,
                                true,
                            )
                        ) {
                            throw ValidationException::withMessages([
                                "steps.{$stepIndex}.approvers.{$approverIndex}.approver_scope" => [
                                    sprintf(
                                        'Approver scope pada Step %d tidak valid.',
                                        $stepNumber,
                                    ),
                                ],
                            ]);
                        }

                        $branchIds = collect(
                            $approver['branch_ids']
                                ?? [],
                        )
                            ->map(
                                fn($branchId) => (int) $branchId,
                            )
                            ->filter(
                                fn(int $branchId) => $branchId > 0,
                            )
                            ->unique()
                            ->values()
                            ->all();

                        /*
                    |--------------------------------------------------------------------------
                    | SELECTED_BRANCHES wajib memilih cabang
                    |--------------------------------------------------------------------------
                    */
                        if (
                            $approverScope
                            === ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES
                            && empty($branchIds)
                        ) {
                            throw ValidationException::withMessages([
                                "steps.{$stepIndex}.approvers.{$approverIndex}.branch_ids" => [
                                    sprintf(
                                        'Cabang yang ditangani wajib dipilih untuk approver ke-%d pada Step %d.',
                                        $approverIndex + 1,
                                        $stepNumber,
                                    ),
                                ],
                            ]);
                        }

                        /*
                    |--------------------------------------------------------------------------
                    | Mapping hanya digunakan SELECTED_BRANCHES
                    |--------------------------------------------------------------------------
                    */
                        if (
                            $approverScope
                            !== ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES
                        ) {
                            $branchIds = [];
                        }

                        /*
                    |--------------------------------------------------------------------------
                    | Approver pengganti untuk Tipe Dokumen Khusus
                    |--------------------------------------------------------------------------
                    | Opsional. Bila kosong, approver di atas berlaku untuk semua
                    | dokumen -- persis perilaku sebelum fitur ini ada.
                    |--------------------------------------------------------------------------
                    */
                        $specialApprovers = collect(
                            $approver['special_approvers'] ?? [],
                        )
                            ->map(function ($special) {
                                $typeId = (int) (
                                    $special['special_document_type_id'] ?? 0
                                );

                                $approverIdSpecial = (int) (
                                    $special['approver_id'] ?? 0
                                );

                                $approverTypeSpecial = strtoupper(
                                    trim(
                                        (string) (
                                            $special['approver_type'] ?? ''
                                        ),
                                    ),
                                );

                                if (
                                    $typeId <= 0
                                    || $approverIdSpecial <= 0
                                    || !in_array(
                                        $approverTypeSpecial,
                                        [
                                            ApprovalFlowStep::APPROVER_TYPE_ROLE,
                                            ApprovalFlowStep::APPROVER_TYPE_USER,
                                        ],
                                        true,
                                    )
                                ) {
                                    return null;
                                }

                                return [
                                    'special_document_type_id' => $typeId,
                                    'approver_type' => $approverTypeSpecial,
                                    'approver_id' => $approverIdSpecial,
                                ];
                            })
                            ->filter()
                            /*
                            | Satu tipe dokumen hanya boleh punya satu pengganti
                            | pada approver ini, agar tidak ada aturan kembar.
                            */
                            ->unique('special_document_type_id')
                            ->values()
                            ->all();

                        return [
                            'approver_type' => $approverType,
                            'approver_id' => $approverId,
                            'approver_scope' => $approverScope,
                            'branch_ids' => $branchIds,
                            'special_approvers' => $specialApprovers,
                        ];
                    },
                );

                return [
                    'step_order' => $stepOrder,
                    'label' => $label,
                    'approval_mode' => $approvalMode,
                    'approvers' => $normalizedApprovers
                        ->values()
                        ->all(),
                ];
            },
        );
    }

    private function createApprovalFlowSteps(
        ApprovalFlow $flow,
        Collection $steps,
    ): void {
        foreach ($steps as $step) {
            foreach ($step['approvers'] as $approver) {
                /*
            |--------------------------------------------------------------------------
            | Satu approver menjadi satu row approval_flow_steps
            |--------------------------------------------------------------------------
            */
                $flowStep = ApprovalFlowStep::create([
                    'approval_flow_id' => $flow->id,

                    'step_order' => (int) $step['step_order'],

                    'approver_type'
                    => $approver['approver_type'],

                    'approver_id'
                    => (int) $approver['approver_id'],

                    'label'
                    => $step['label'],

                    'approval_mode'
                    => $step['approval_mode'],

                    'approver_scope'
                    => $approver['approver_scope'],

                    'is_required'
                    => true,
                ]);

                /*
            |--------------------------------------------------------------------------
            | Simpan approver pengganti untuk Tipe Dokumen Khusus
            |--------------------------------------------------------------------------
            | Kosong untuk step biasa, sehingga tidak ada baris yang dibuat dan
            | perilaku flow tidak berubah sama sekali.
            |--------------------------------------------------------------------------
            */
                $specialApprovers = collect(
                    $approver['special_approvers'] ?? [],
                )
                    ->map(
                        fn(array $special): array => [
                            'special_document_type_id'
                            => $special['special_document_type_id'],

                            'approver_type' => $special['approver_type'],
                            'approver_id' => $special['approver_id'],
                        ],
                    )
                    ->values()
                    ->all();

                if ($specialApprovers) {
                    $flowStep
                        ->specialApprovers()
                        ->createMany($specialApprovers);
                }

                /*
            |--------------------------------------------------------------------------
            | Simpan mapping cabang khusus
            |--------------------------------------------------------------------------
            */
                if (
                    $approver['approver_scope']
                    !== ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES
                ) {
                    continue;
                }

                $branchMappings = collect(
                    $approver['branch_ids'],
                )
                    ->map(
                        fn(int $branchId): array => [
                            'cabang_id' => $branchId,
                        ],
                    )
                    ->values()
                    ->all();

                $flowStep
                    ->branchMappings()
                    ->createMany($branchMappings);
            }
        }
    }
}
