<?php

namespace App\Http\Controllers\Api;

use App\Exports\PurchaseRequestExport;
use App\Http\Controllers\Controller;
use App\Models\ApprovalHistoryPR;
use App\Models\ApprovalMatrix;
use App\Models\ApprovalMatrixPR;
use App\Models\MasterMaterialGroup;
use App\Models\PrAttachment;
use App\Models\SpecialDocumentType;
use Illuminate\Http\Request;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestHistoryApproval;
use App\Models\PurchaseRequestItem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use DocumentHelper;
use App\Helpers\ApprovalHelper;
use App\Models\PurchaseRequestApproval;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Services\NonTrade\PurchaseRequest\PurchaseRequestApprovalGeneratorService;
use App\Services\NonTrade\PurchaseRequest\PurchaseRequestApprovalService;
use App\Services\NonTrade\PurchaseRequest\PurchaseRequestNotificationService;
use App\Services\NonTrade\PurchaseRequest\PurchaseRequestMailService;
use App\Services\NonTrade\PurchaseRequest\PurchaseRequestRollbackService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PurchaseRequestController extends Controller
{
    /**
     * GET /api/purchase-requests
     * Ambil semua data PR (optional: bisa ditambah pagination nanti)
     */

    private const MINIMUM_PR_AMOUNT = 1000000;

    private function validateMinimumPurchaseRequestAmount(
        float $totalAmount,
    ): void {
        if ($totalAmount < self::MINIMUM_PR_AMOUNT) {
            throw ValidationException::withMessages([
                'total_amount' => [
                    __('purchase_request_messages.minimum_amount'),
                ],
            ]);
        }
    }

    private function generateDraftPRNumber(): string
    {
        $year = (int) now()->format('Y');

        /*
        |--------------------------------------------------------------------------
        | Hitung seluruh PR tahun berjalan
        |--------------------------------------------------------------------------
        | withTrashed() memastikan data soft delete tetap ikut dihitung.
        */
        $nextNumber = PurchaseRequest::withTrashed()
            ->whereYear('created_at', $year)
            ->count() + 1;

        /*
        |--------------------------------------------------------------------------
        | Pastikan nomor belum pernah digunakan
        |--------------------------------------------------------------------------
        | Pengecekan juga mencakup PR yang sudah di-soft delete.
        */
        do {
            $draftNumber = sprintf(
                'DRAFT/PR/%d/%04d',
                $year,
                $nextNumber,
            );

            $alreadyExists = PurchaseRequest::withTrashed()
                ->where('nomor_pr', $draftNumber)
                ->exists();

            if ($alreadyExists) {
                $nextNumber++;
            }
        } while ($alreadyExists);

        return $draftNumber;
    }

    public function index(
        Request $request,
        PurchaseRequestApprovalService $approvalService,
    ): JsonResponse {
        $user = $request->user();

        try {
            $perPage = (int) $request->input('per_page', 10);
            $perPage = $perPage > 0 ? $perPage : 10;

            /*
            |--------------------------------------------------------------------------
            | Authentication
            |--------------------------------------------------------------------------
            */
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.user_not_authenticated'),
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                    ],
                    'abilities' => [
                        'can_view' => false,
                        'view_scope' => 'NONE',
                        'can_create' => false,
                        'can_update' => false,
                        'can_submit' => false,
                        'can_delete' => false,
                    ],
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | Permission Scope: Purchase Requisition View
            |--------------------------------------------------------------------------
            */
            $scope = strtoupper(
                trim(
                    (string) (
                        $user->getPermissionScope(
                            'purchase_request.view',
                        ) ?? 'NONE'
                    ),
                ),
            );

            $allowedScopes = [
                'NONE',
                'OWN_DATA',
                'OWN_DEPARTMENT',
                'OWN_CABANG',
                'ALL',
            ];

            if (!in_array($scope, $allowedScopes, true)) {
                $scope = 'NONE';
            }

            /*
            |--------------------------------------------------------------------------
            | Ambil seluruh role ID user
            |--------------------------------------------------------------------------
            */
            $userRoleIds = collect();

            if ($user->getAttribute('role_id')) {
                $userRoleIds->push(
                    (int) $user->getAttribute('role_id'),
                );
            }

            $activeRoleId = $user->getActiveRoleId();

            if ($activeRoleId) {
                $userRoleIds->push(
                    (int) $activeRoleId,
                );
            }

            $pivotRoleIds = DB::table('user_roles')
                ->where('user_id', $user->id)
                ->pluck('role_id');

            $userRoleIds = $userRoleIds
                ->merge($pivotRoleIds)
                ->filter(
                    fn($roleId) =>
                    $roleId !== null
                        && (int) $roleId > 0,
                )
                ->map(
                    fn($roleId) => (int) $roleId,
                )
                ->unique()
                ->values();

            /*
            |--------------------------------------------------------------------------
            | Filter khusus: hanya PR yang menunggu approval user login
            |--------------------------------------------------------------------------
            | Param dari FE:
            | waiting_my_approval=1
            |--------------------------------------------------------------------------
            */
            $waitingMyApproval = $request->boolean(
                'waiting_my_approval',
            );

            $canSubmit = $user->hasPermission(
                'purchase_request.submit',
            );

            /*
            |--------------------------------------------------------------------------
            | Abilities
            |--------------------------------------------------------------------------
            */
            $abilities = [
                'can_view' => $user->hasPermission(
                    'purchase_request.view',
                ),

                'view_scope' => $scope,

                'can_create' => $user->hasPermission(
                    'purchase_request.create',
                ),

                'can_update' => $user->hasPermission(
                    'purchase_request.update',
                ),

                'can_submit' => $canSubmit,

                'can_delete' => $user->hasPermission(
                    'purchase_request.delete',
                ),
            ];

            /*
            |--------------------------------------------------------------------------
            | Base query
            |--------------------------------------------------------------------------
            */
            $query = PurchaseRequest::query()
                ->with([
                    'cabangData',
                    'departmentData',
                    'recommendedVendor',
                    'items',
                    'specialDocumentType:id,code,name',

                    'approvals' => function ($approvalQuery) {
                        $approvalQuery
                            ->orderBy('step_order')
                            ->orderBy('id');
                    },

                    'purchaseOrders' => function ($purchaseOrderQuery) {
                        $purchaseOrderQuery
                            ->orderByDesc('purchase_orders.id');
                    },
                ]);

            /*
            |--------------------------------------------------------------------------
            | User Access Assignments
            |--------------------------------------------------------------------------
            | Digunakan untuk scope OWN_CABANG dan OWN_DEPARTMENT.
            |--------------------------------------------------------------------------
            */
            $userAccessAssignments = $this->getActiveUserAccessAssignments(
                $user,
            );

            $userAccessibleBranchIds = collect([
                (int) ($user->cabang_id ?? 0),
            ])
                ->merge(
                    $userAccessAssignments
                        ->pluck('branch_id')
                        ->map(fn($id) => (int) $id),
                )
                ->filter(fn($id) => $id > 0)
                ->unique()
                ->values();

            $userAccessibleDepartmentIds = collect([
                (int) ($user->departemen_id ?? 0),
            ])
                ->merge(
                    $userAccessAssignments
                        ->pluck('department_id')
                        ->map(fn($id) => (int) $id),
                )
                ->filter(fn($id) => $id > 0)
                ->unique()
                ->values();

            /*
            |--------------------------------------------------------------------------
            | Apply Visibility Scope
            |--------------------------------------------------------------------------
            | Logika scope dipakai bersama dengan exportExcel(). Jangan diduplikasi di
            | sana -- kalau keduanya berbeda, user bisa mengekspor data yang tidak boleh
            | dilihatnya lewat daftar.
            |--------------------------------------------------------------------------
            */
            $this->applyPurchaseRequestVisibilityScope(
                $query,
                $user,
                $scope,
                $userRoleIds,
                $userAccessibleBranchIds,
                $userAccessibleDepartmentIds,
            );

            /*
            |--------------------------------------------------------------------------
            | Filter: Menunggu Approval Saya
            |--------------------------------------------------------------------------
            | Harus dilakukan di query sebelum paginate.
            |
            | Syarat:
            | - PR status IN PROGRESS
            | - Ada approval dengan status WAITING
            | - Approval tersebut assigned ke user login atau role user login
            |--------------------------------------------------------------------------
            */
            if ($waitingMyApproval) {
                $query->where(
                    'purchase_requests.status',
                    PurchaseRequest::STATUS_IN_PROGRESS,
                );

                $query->whereHas(
                    'approvals',
                    function ($approvalQuery) use (
                        $user,
                        $userRoleIds,
                    ) {
                        $approvalQuery
                            ->where(
                                'purchase_request_approvals.status',
                                PurchaseRequestApproval::STATUS_WAITING,
                            )
                            ->where(function ($approverQuery) use (
                                $user,
                                $userRoleIds,
                            ) {
                                $approverQuery->where(function ($userQuery) use ($user) {
                                    $userQuery
                                        ->where(
                                            'purchase_request_approvals.approver_type',
                                            PurchaseRequestApproval::APPROVER_TYPE_USER,
                                        )
                                        ->where(
                                            'purchase_request_approvals.approver_id',
                                            $user->id,
                                        );
                                });

                                if ($userRoleIds->isNotEmpty()) {
                                    $approverQuery->orWhere(function ($roleQuery) use (
                                        $userRoleIds,
                                    ) {
                                        $roleQuery
                                            ->where(
                                                'purchase_request_approvals.approver_type',
                                                PurchaseRequestApproval::APPROVER_TYPE_ROLE,
                                            )
                                            ->whereIn(
                                                'purchase_request_approvals.approver_id',
                                                $userRoleIds->all(),
                                            );
                                    });
                                }
                            });
                    },
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Filter daftar
            |--------------------------------------------------------------------------
            | Search, rentang tanggal, status, status PO, department, dan cabang.
            | Dipakai bersama exportExcel() agar isi file selalu sama dengan daftar
            | yang sedang dilihat user.
            |--------------------------------------------------------------------------
            */
            $this->applyPurchaseRequestListFilters($query, $request);


            /*
            |--------------------------------------------------------------------------
            | Prioritas PR yang menunggu approval user login
            |--------------------------------------------------------------------------
            | Hanya mengubah urutan data, tidak mengubah:
            | - visibility scope
            | - filter
            | - search
            | - permission
            | - isi response
            |
            | PR yang sedang menunggu approval user/role login akan selalu tampil
            | paling atas. Setelah approval user tersebut selesai, PR kembali mengikuti
            | urutan normal berdasarkan ID terbaru.
            |--------------------------------------------------------------------------
            */
            $query->withExists([
                'approvals as is_waiting_my_approval' => function (
                    $approvalQuery,
                ) use (
                    $user,
                    $userRoleIds,
                ) {
                    $approvalQuery
                        /*
                        |--------------------------------------------------------------------------
                        | Hanya PR yang masih dalam proses approval
                        |--------------------------------------------------------------------------
                        */
                        ->whereRaw(
                            'purchase_requests.status = ?',
                            [
                                PurchaseRequest::STATUS_IN_PROGRESS,
                            ],
                        )

                        /*
                        |--------------------------------------------------------------------------
                        | Approval harus masih WAITING
                        |--------------------------------------------------------------------------
                        */
                        ->where(
                            'purchase_request_approvals.status',
                            PurchaseRequestApproval::STATUS_WAITING,
                        )

                        /*
                        |--------------------------------------------------------------------------
                        | Approval ditujukan langsung kepada user atau salah satu role user
                        |--------------------------------------------------------------------------
                        */
                        ->where(function ($approverQuery) use (
                            $user,
                            $userRoleIds,
                        ) {
                            /*
                            |--------------------------------------------------------------------------
                            | Approver berdasarkan user
                            |--------------------------------------------------------------------------
                            */
                            $approverQuery->where(function ($userQuery) use (
                                $user,
                            ) {
                                $userQuery
                                    ->where(
                                        'purchase_request_approvals.approver_type',
                                        PurchaseRequestApproval::APPROVER_TYPE_USER,
                                    )
                                    ->where(
                                        'purchase_request_approvals.approver_id',
                                        $user->id,
                                    );
                            });

                            /*
                            |--------------------------------------------------------------------------
                            | Approver berdasarkan role
                            |--------------------------------------------------------------------------
                            */
                            if ($userRoleIds->isNotEmpty()) {
                                $approverQuery->orWhere(function ($roleQuery) use (
                                    $userRoleIds,
                                ) {
                                    $roleQuery
                                        ->where(
                                            'purchase_request_approvals.approver_type',
                                            PurchaseRequestApproval::APPROVER_TYPE_ROLE,
                                        )
                                        ->whereIn(
                                            'purchase_request_approvals.approver_id',
                                            $userRoleIds->all(),
                                        );
                                });
                            }
                        });
                },
            ]);

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            | Prioritas:
            | 1. PR yang sedang menunggu approval user login
            | 2. Data terbaru berdasarkan ID seperti urutan sebelumnya
            |--------------------------------------------------------------------------
            */
            $prs = $query
                ->orderByDesc('is_waiting_my_approval')
                ->orderByDesc('purchase_requests.id')
                ->paginate($perPage);


            /*
            |--------------------------------------------------------------------------
            | Transform Response
            |--------------------------------------------------------------------------
            */
            $prs->through(
                function (PurchaseRequest $pr) use (
                    $user,
                    $approvalService,
                    $canSubmit,
                ): array {
                    $prStatus = strtoupper(
                        trim((string) $pr->status),
                    );

                    $isCreator = (int) $pr->created_by
                        === (int) $user->id;

                    $isSameDepartment = (
                        $user->departemen_id !== null
                        && (int) $pr->id_department
                        === (int) $user->departemen_id
                    );

                    $rowCanSubmit = (
                        $canSubmit
                        && $prStatus === PurchaseRequest::STATUS_DRAFT
                        && (
                            $isCreator
                            || $isSameDepartment
                        )
                    );

                    $currentApproval = $pr->approvals
                        ->first(
                            function (
                                PurchaseRequestApproval $approval,
                            ) use (
                                $approvalService,
                                $user,
                            ): bool {
                                return strtoupper(
                                    (string) $approval->status,
                                ) === PurchaseRequestApproval::STATUS_WAITING
                                    && $approvalService->userCanApprove(
                                        $approval,
                                        $user,
                                    );
                            },
                        );

                    return [
                        'id' => $pr->id,
                        'public_id' => $pr->encrypted_id,
                        'nomor_pr' => $pr->nomor_pr,
                        'tanggal_pr' => $pr->tanggal_pr,

                        'cabang' => $pr->cabangData?->nama_cabang
                            ?? '-',

                        'cabang_id' => $pr->cabang,

                        'department' => $pr->departmentData?->kode
                            ?? '-',

                        'department_name' => $pr->departmentData?->nama
                            ?? '-',

                        'department_id' => $pr->id_department,

                        'kategori' => $pr->kategori,

                        'pr_type' => $pr->pr_type,
                        'special_document_type_id' => $pr->special_document_type_id,
                        'special_document_type' => $pr->specialDocumentType
                            ? [
                                'id' => $pr->specialDocumentType->id,
                                'code' => $pr->specialDocumentType->code,
                                'name' => $pr->specialDocumentType->name,
                            ]
                            : null,

                        'notes' => $pr->notes,
                        'status' => $pr->status,
                        'status_po' => $pr->status_po,

                        'purchase_orders' => $pr->purchaseOrders
                            ->map(function (PurchaseOrder $po): array {
                                return [
                                    'public_id' => $po->encrypted_id,
                                    'nomor_po' => $po->nomor_po,
                                    'status' => $po->status,
                                ];
                            })
                            ->values(),

                        'can_submit' => $rowCanSubmit,
                        'can_approve' => $currentApproval !== null,

                        'approval_id' => $currentApproval?->id,

                        'approval_step_order' => $currentApproval
                            ? (int) $currentApproval->step_order
                            : null,

                        'approval_label' => $currentApproval?->label,

                        'approval_mode' => $currentApproval?->approval_mode,

                        'recommended_vendor_id'
                        => $pr->recommended_vendor_id,

                        'recommended_vendor'
                        => $pr->recommendedVendor
                            ? [
                                'id' => $pr->recommendedVendor->id,

                                'nama_vendor'
                                => $pr->recommendedVendor
                                    ->nama_vendor
                                    ?? '-',

                                'status_pkp'
                                => $pr->recommendedVendor
                                    ->status_pkp
                                    ?? '-',
                            ]
                            : null,

                        'total_amount' => $pr->total_amount
                            ?? $pr->items->sum(
                                fn($item) => (float) (
                                    $item->subtotal ?? 0
                                ),
                            ),

                        'created_at' => $pr->created_at,
                        'created_by' => $pr->created_by,
                        'created_by_name' => $pr->created_by_name
                            ?? null,

                        'submitted_at' => $pr->submitted_at,
                        'submitted_by' => $pr->submitted_by,
                        'submitted_by_name' => $pr->submitted_by_name
                            ?? null,

                        'items' => $pr->items
                            ->map(function ($item): array {
                                return [
                                    'id' => $item->id,
                                    'nama_item' => $item->nama_item,

                                    'master_material_group_id'
                                    => $item->master_material_group_id,

                                    'material_group' => [
                                        'id' => $item->materialGroup?->id,
                                        'code' => $item->materialGroup?->code ?? '-',
                                        'name' => $item->materialGroup?->name ?? '-',
                                    ],

                                    'qty' => $item->qty,
                                    'satuan' => $item->satuan,
                                    'spesifikasi' => $item->spesifikasi,
                                    'keterangan' => $item->keterangan,
                                    'harga_unit' => $item->harga_unit,
                                    'subtotal' => $item->subtotal,
                                ];
                            })
                            ->values(),
                    ];
                },
            );

            return response()->json([
                'success' => true,
                'message' => __('purchase_request_messages.index.loaded'),
                'data' => $prs->items(),
                'meta' => [
                    'current_page' => $prs->currentPage(),
                    'last_page' => $prs->lastPage(),
                    'per_page' => $prs->perPage(),
                    'total' => $prs->total(),
                ],
                'abilities' => $abilities,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $user?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.index.load_failed'),
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => (int) $request->input(
                        'per_page',
                        10,
                    ),
                    'total' => 0,
                ],
                'abilities' => [
                    'can_view' => false,
                    'view_scope' => 'NONE',
                    'can_create' => false,
                    'can_update' => false,
                    'can_delete' => false,
                    'can_submit' => false,
                ],
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    private function validateUserAccessAssignmentForPurchaseRequest(
        Request $request,
        int|string|null $branchId,
        int|string|null $departmentId,
    ): void {
        $user = $request->user();

        if (!$user) {
            throw ValidationException::withMessages([
                'user' => [
                    __('purchase_request_messages.user_not_authenticated'),
                ],
            ]);
        }

        $branchId = (int) $branchId;
        $departmentId = (int) $departmentId;

        if ($branchId <= 0 || $departmentId <= 0) {
            throw ValidationException::withMessages([
                'access_assignment' => [
                    __('purchase_request_messages.access_assignment.branch_department_required'),
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Cek apakah user sudah punya assignment aktif.
        |--------------------------------------------------------------------------
        */
        $hasAnyAssignment = DB::table('user_access_assignments')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        /*
        |--------------------------------------------------------------------------
        | Fallback untuk user lama jika belum ada assignment sama sekali.
        | Ini menjaga compatibility, tapi setelah backfill seharusnya semua user
        | sudah punya assignment.
        |--------------------------------------------------------------------------
        */
        if (!$hasAnyAssignment) {
            $defaultBranchId = (int) ($user->cabang_id ?? 0);
            $defaultDepartmentId = (int) ($user->departemen_id ?? 0);

            if (
                $defaultBranchId === $branchId
                && $defaultDepartmentId === $departmentId
            ) {
                return;
            }

            throw ValidationException::withMessages([
                'access_assignment' => [
                    __('purchase_request_messages.access_assignment.no_access_branch_department'),
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Jika user punya assignment, kombinasi cabang + department harus valid.
        |--------------------------------------------------------------------------
        */
        $hasSelectedAssignment = DB::table('user_access_assignments')
            ->where('user_id', $user->id)
            ->where('branch_id', $branchId)
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->exists();

        if (!$hasSelectedAssignment) {
            throw ValidationException::withMessages([
                'access_assignment' => [
                    __('purchase_request_messages.access_assignment.no_access_create'),
                ],
            ]);
        }
    }

    private function getActiveUserAccessAssignments(
        mixed $user,
    ) {
        if (!$user) {
            return collect();
        }

        $assignments = DB::table('user_access_assignments')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->select([
                'branch_id',
                'department_id',
            ])
            ->get()
            ->map(function ($item) {
                return [
                    'branch_id' => (int) $item->branch_id,
                    'department_id' => (int) $item->department_id,
                ];
            })
            ->filter(function ($item) {
                return $item['branch_id'] > 0
                    && $item['department_id'] > 0;
            })
            ->unique(function ($item) {
                return $item['branch_id'] . '-' . $item['department_id'];
            })
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Fallback user lama
        |--------------------------------------------------------------------------
        | Kalau user belum punya assignment sama sekali, pakai master user lama.
        |--------------------------------------------------------------------------
        */
        if ($assignments->isEmpty()) {
            $branchId = (int) (
                $user->cabang_id
                ?? $user->branch_id
                ?? 0
            );

            $departmentId = (int) (
                $user->departemen_id
                ?? $user->department_id
                ?? 0
            );

            if ($branchId > 0 && $departmentId > 0) {
                $assignments->push([
                    'branch_id' => $branchId,
                    'department_id' => $departmentId,
                ]);
            }
        }

        return $assignments->values();
    }

    /**
     * POST /api/purchase-request
     * Simpan data baru dari form (axios.post)
     */
    public function store(Request $request)
    {

        $user = $request->user();

        if (!$user || !$user->hasPermission('purchase_request.create')) {
            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.store.forbidden'),
            ], 403);
        }

        $storedPaths = [];

        DB::beginTransaction();
        try {
            $clean = static function (mixed $value): string {
                if ($value === null) {
                    return '';
                }

                $text = trim((string) $value);

                /*
            |--------------------------------------------------------------------------
            | Decode entity lama / double encoded
            |--------------------------------------------------------------------------
            | Contoh:
            | &amp;quot; -> &quot; -> "
            |--------------------------------------------------------------------------
            */
                for ($i = 0; $i < 3; $i++) {
                    $decoded = html_entity_decode(
                        $text,
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8',
                    );

                    if ($decoded === $text) {
                        break;
                    }

                    $text = $decoded;
                }

                /*
            |--------------------------------------------------------------------------
            | Buang tag HTML, tapi jangan encode lagi.
            |--------------------------------------------------------------------------
            */
                $text = strip_tags($text);

                /*
            |--------------------------------------------------------------------------
            | Rapihkan spasi berlebih.
            |--------------------------------------------------------------------------
            */
                $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

                return trim($text);
            };

            $request->validate([
                'tanggal_pr'             => ['required', 'date_format:Y-m-d'],
                'cabang'                 => ['required'],
                'id_department'          => ['required', 'integer'],
                'recommended_vendor_id'  => ['nullable', 'integer', 'exists:master_vendor,id'],
                'kategori'               => ['nullable', 'string'],
                'pr_type'                => ['required', 'string', 'max:50', 'in:Rutin,Non Rutin'],

                /*
                | NULL = dokumen biasa. Keabsahan tipe terhadap department pembuat
                | diperiksa terpisah lewat assertSpecialDocumentTypeAllowed().
                */
                'special_document_type_id' => ['nullable', 'integer'],
                'items'                  => ['required', 'string'],
                'lampiran_request.*'     => ['sometimes', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:3000'],
            ]);

            $this->validateUserAccessAssignmentForPurchaseRequest(
                $request,
                $request->cabang,
                $request->id_department,
            );

            /*
        |--------------------------------------------------------------------------
        | 1. Generate Nomor PR
        |--------------------------------------------------------------------------
        */
            $nomorPr = $this->generateDraftPRNumber();

            /*
        |--------------------------------------------------------------------------
        | 2. Decode & Validasi Items
        |--------------------------------------------------------------------------
        */
            $items = json_decode($request->items, true);

            if (!is_array($items) || count($items) === 0) {
                throw new \Exception('Data item tidak valid.');
            }

            foreach ($items as $item) {
                if (empty($item['nama_item'])) {
                    throw new \Exception('Nama item wajib diisi.');
                }

                if (empty($item['master_material_group_id'])) {
                    throw new \Exception('Material Group item wajib dipilih.');
                }

                if (empty($item['qty']) || (float) $item['qty'] <= 0) {
                    throw new \Exception('Qty item wajib diisi.');
                }

                if (empty($item['satuan'])) {
                    throw new \Exception('Satuan item wajib dipilih.');
                }

                if (!isset($item['harga_unit']) || (float) $item['harga_unit'] <= 0) {
                    throw new \Exception('Harga satuan item wajib diisi.');
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Pastikan Material Group benar-benar ada dan masih aktif
        |--------------------------------------------------------------------------
        | Dicek kolektif dalam satu query agar tidak menembak DB per item.
        |--------------------------------------------------------------------------
        */
            $this->assertMaterialGroupsAreValid($items);

            /*
        |--------------------------------------------------------------------------
        | 3. Hitung Subtotal Item
        |--------------------------------------------------------------------------
        | subtotal item = nilai sebelum PPN
        |--------------------------------------------------------------------------
        */
            $subTotalItems = 0;

            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? 0);
                $harga = (float) ($item['harga_unit'] ?? 0);

                $subTotalItems += $qty * $harga;
            }

            $subTotalItems = round((float) $subTotalItems, 2);

            /*
        |--------------------------------------------------------------------------
        | 3A. Snapshot Vendor dan Hitung Pajak PR
        |--------------------------------------------------------------------------
        | Mengikuti konsep PO:
        | - PKP     : dpp = subtotal * 11 / 12, ppn = dpp * 12%, total = subtotal + ppn
        | - NON PKP : dpp = 0, ppn = 0, total = subtotal
        |--------------------------------------------------------------------------
        */
            $recommendedVendorId = $request->filled('recommended_vendor_id')
                ? (int) $request->recommended_vendor_id
                : null;

            $vendor = $recommendedVendorId
                ? DB::table('master_vendor')
                ->where('id', $recommendedVendorId)
                ->first([
                    'id',
                    'status_pkp',
                    'jenis_pembayaran',
                    'top',
                ])
                : null;

            $vendorStatusPkpNormalized = strtoupper(
                trim((string) ($vendor->status_pkp ?? 'NON_PKP')),
            );

            $isPkpVendor = in_array(
                $vendorStatusPkpNormalized,
                [
                    'PKP',
                    '1',
                    'YA',
                    'YES',
                    'TRUE',
                ],
                true,
            );

            $statusPkpSnapshot = $isPkpVendor
                ? 'PKP'
                : 'NON_PKP';

            $jenisPembayaranSnapshot = $vendor
                ? trim((string) ($vendor->jenis_pembayaran ?? ''))
                : '';

            $jenisPembayaranSnapshot = $jenisPembayaranSnapshot !== ''
                ? strtoupper($jenisPembayaranSnapshot)
                : null;

            $topSnapshot = $vendor
                ? (int) ($vendor->top ?? 0)
                : 0;

            if ($isPkpVendor) {
                $dppAmount = round($subTotalItems * 11 / 12, 2);
                $ppnAmount = round($dppAmount * 0.12, 2);
                $totalAmount = round($subTotalItems + $ppnAmount, 2);
            } else {
                $dppAmount = 0.0;
                $ppnAmount = 0.0;
                $totalAmount = round($subTotalItems, 2);
            }

            /*
        |--------------------------------------------------------------------------
        | Validasi Minimal Nilai PR
        |--------------------------------------------------------------------------
        */
            $this->validateMinimumPurchaseRequestAmount(
                (float) $totalAmount,
            );

            /*
        |--------------------------------------------------------------------------
        | 4. Simpan Header PR
        |--------------------------------------------------------------------------
        */
            $user = $request->user();
            $pr = PurchaseRequest::create([
                'nomor_pr'              => $nomorPr,
                'tanggal_pr'            => $request->tanggal_pr,
                'cabang'                => $request->cabang,
                'id_department'         => (int) $request->id_department,
                'recommended_vendor_id' => $recommendedVendorId,
                'kategori'              => $request->kategori,
                'pr_type'               => $request->pr_type,

                /*
                | NULL untuk dokumen biasa. Helper menolak bila department
                | pembuat tidak berhak memakai tipe tersebut.
                */
                'special_document_type_id'
                => $this->assertSpecialDocumentTypeAllowed(
                    $request->input('special_document_type_id'),
                    (int) $request->id_department,
                ),

                'notes'                 => $clean($request->notes),
                'status'                => PurchaseRequest::STATUS_DRAFT,
                'total_amount'          => $totalAmount,
                'status_pkp'            => $statusPkpSnapshot,
                'jenis_pembayaran'      => $jenisPembayaranSnapshot,
                'top'                   => $topSnapshot,
                'dpp'                   => $dppAmount,
                'ppn'                   => $ppnAmount,
                'created_by'            => $user?->id,
                'updated_by'            => $user?->id,
            ]);

            /*
        |--------------------------------------------------------------------------
        | 5. Simpan Lampiran Request
        |--------------------------------------------------------------------------
        */
            if ($request->hasFile('lampiran_request')) {
                $folder = "syopv4/uploads/purchase_requests/lampiran/{$pr->id}";

                Storage::disk('public')->makeDirectory($folder);

                $fullFolderPath = storage_path('app/public/' . $folder);

                if (\Illuminate\Support\Facades\File::exists($fullFolderPath)) {
                    @chmod($fullFolderPath, 0777);
                }

                foreach ($request->file('lampiran_request') as $file) {
                    if (!$file || !$file->isValid()) {
                        continue;
                    }

                    $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $extension = strtolower($file->getClientOriginalExtension());

                    $safeOriginalName = \Illuminate\Support\Str::slug($originalName);

                    if ($safeOriginalName === '') {
                        $safeOriginalName = 'file';
                    }

                    $filename = str_replace('/', '-', $nomorPr)
                        . '_' . now()->format('YmdHis')
                        . '_' . uniqid()
                        . '_' . $safeOriginalName
                        . '.' . $extension;

                    $path = $file->storeAs($folder, $filename, 'public');

                    $storedPaths[] = $path;

                    $fullFilePath = storage_path('app/public/' . $path);

                    if (\Illuminate\Support\Facades\File::exists($fullFilePath)) {
                        @chmod($fullFilePath, 0777);
                    }

                    PrAttachment::create([
                        'purchase_request_id' => $pr->id,
                        'filename'            => $filename,
                        'original_filename'   => $file->getClientOriginalName(),
                        'mime_type'           => $file->getMimeType(),
                        'file_size'           => $file->getSize(),
                        'filepath'            => $path,
                    ]);
                }
            }

            /*
        |--------------------------------------------------------------------------
        | 6. Simpan Item PR
        |--------------------------------------------------------------------------
        */
            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? 0);
                $harga = (float) ($item['harga_unit'] ?? 0);
                $subtotal = round($qty * $harga, 2);

                PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id,
                    'nama_item'           => $clean($item['nama_item'] ?? ''),
                    'master_material_group_id'
                    => (int) $item['master_material_group_id'],
                    'qty'                 => $qty,
                    'qty_outstanding'     => $qty,
                    'satuan'              => $item['satuan'] ?? '',
                    'spesifikasi'         => $clean($item['spesifikasi'] ?? ''),
                    'keterangan'          => $clean($item['keterangan'] ?? ''),
                    'harga_unit'          => $harga,
                    'subtotal'            => $subtotal,
                ]);
            }

            DB::commit();

            return response()->json([
                'success'  => true,
                'message'  => __('purchase_request_messages.store.success'),
                'nomor_pr' => $nomorPr,
                'data'     => [
                    'id'        => $pr->id,
                    'public_id' => $pr->encrypted_id ?? null,
                    'nomor_pr'  => $pr->nomor_pr ?? $nomorPr,
                ],
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();

            foreach ($storedPaths as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? 'Data Purchase Requisition tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            foreach ($storedPaths as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            Log::error('[Purchase Requisition] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.store.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(
        Request $request,
        $publicId,
        PurchaseRequestApprovalService $approvalService,
    ) {
        try {
            $id = Crypt::decryptString($publicId);

            $pr = PurchaseRequest::with([
                'cabangData:id,nama_cabang,inisial_cabang',
                'departmentData:id,kode,nama',
                'recommendedVendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',
                'purchaseOrders:id,nomor_po,tanggal_po,status,total_nilai',
                'items.unit:id,kode,nama',
                'items.materialGroup:id,code,name',
                'specialDocumentType:id,code,name',
                'attachments',
                'approvalHistories',
                'creator',
                'submitter',

                /*
            |--------------------------------------------------------------------------
            | Snapshot approval Purchase Requisition
            |--------------------------------------------------------------------------
            | Semua status dimuat untuk kebutuhan History Approval:
            | WAITING, PENDING, APPROVED, REJECTED, SKIPPED, CANCELLED.
            |--------------------------------------------------------------------------
            */
                'approvals' => function ($approvalQuery) {
                    $approvalQuery
                        ->orderBy('step_order')
                        ->orderBy('id');
                },
            ])->findOrFail($id);

            /*
        |--------------------------------------------------------------------------
        | Item aktif PR
        |--------------------------------------------------------------------------
        | Item yang sudah soft delete tidak boleh ikut menghitung summary detail,
        | outstanding PO, maupun status nilai.
        |--------------------------------------------------------------------------
        */
            $items = $pr->getRelation('items')
                ->filter(function ($item) {
                    return empty($item->deleted_at);
                })
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Vendor PKP Snapshot
        |--------------------------------------------------------------------------
        | Gunakan snapshot di purchase_requests, bukan membaca ulang status vendor
        | terbaru, supaya nilai detail mengikuti kondisi saat PR dibuat/diubah.
        |--------------------------------------------------------------------------
        */
            $statusPkp = strtoupper(
                trim((string) ($pr->status_pkp ?? 'NON_PKP')),
            );

            $isPkp = $statusPkp === 'PKP';

            /*
        |--------------------------------------------------------------------------
        | Subtotal Item PR
        |--------------------------------------------------------------------------
        | Subtotal item adalah nilai sebelum PPN.
        |--------------------------------------------------------------------------
        */
            $subtotalItem = round(
                (float) $items->sum(function ($item) {
                    $subtotal = (float) ($item->subtotal ?? 0);

                    if ($subtotal > 0) {
                        return $subtotal;
                    }

                    return (float) ($item->qty ?? 0)
                        * (float) ($item->harga_unit ?? 0);
                }),
                2,
            );

            /*
        |--------------------------------------------------------------------------
        | DPP, PPN, dan Grand Total PR
        |--------------------------------------------------------------------------
        | Jika snapshot dpp/ppn sudah ada, gunakan snapshot.
        | Jika belum ada untuk data lama, fallback dihitung dari subtotal item.
        |--------------------------------------------------------------------------
        */
            $dppAmount = round(
                (float) ($pr->dpp ?? 0),
                2,
            );

            $ppnAmount = round(
                (float) ($pr->ppn ?? 0),
                2,
            );

            if ($isPkp && $dppAmount <= 0 && $subtotalItem > 0) {
                $dppAmount = round(
                    ($subtotalItem * 11) / 12,
                    2,
                );
            }

            if ($isPkp && $ppnAmount <= 0 && $dppAmount > 0) {
                $ppnAmount = round(
                    $dppAmount * 0.12,
                    2,
                );
            }

            if (!$isPkp) {
                $dppAmount = 0.0;
                $ppnAmount = 0.0;
            }

            $grandTotalPr = round(
                (float) ($pr->total_amount ?? 0),
                2,
            );

            if ($grandTotalPr <= 0) {
                $grandTotalPr = round(
                    $subtotalItem + $ppnAmount,
                    2,
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Purchase Order valid
        |--------------------------------------------------------------------------
        | DRAFT tetap dihitung karena sesuai rule sistem:
        | ketika PO draft dibuat, qty PR sudah dianggap digunakan.
        |
        | REJECTED / CANCELLED tidak dihitung.
        |--------------------------------------------------------------------------
        */
            $validPurchaseOrders = $pr->purchaseOrders
                ->filter(function ($po) {
                    return !in_array(
                        strtoupper(trim((string) $po->status)),
                        [
                            'REJECTED',
                            'CANCELLED',
                            'CANCELED',
                        ],
                        true,
                    );
                })
                ->values();

            $totalPo = round(
                (float) $validPurchaseOrders->sum(function ($po) {
                    return (float) ($po->total_nilai ?? 0);
                }),
                2,
            );

            /*
        |--------------------------------------------------------------------------
        | Outstanding PO berbasis quantity
        |--------------------------------------------------------------------------
        | Ini adalah sisa nilai proses PO, bukan selisih nilai PR vs PO.
        | Jadi kalau qty_outstanding item sudah 0, outstanding PO harus 0,
        | walaupun nilai PO lebih murah/mahal dari PR.
        |--------------------------------------------------------------------------
        */
            $outstandingSubtotal = round(
                (float) $items->sum(function ($item) {
                    return (float) ($item->qty_outstanding ?? 0)
                        * (float) ($item->harga_unit ?? 0);
                }),
                2,
            );

            $outstandingDpp = 0.0;
            $outstandingPpn = 0.0;
            $outstandingPoAmount = $outstandingSubtotal;

            if ($isPkp && $outstandingSubtotal > 0) {
                $outstandingDpp = round(
                    ($outstandingSubtotal * 11) / 12,
                    2,
                );

                $outstandingPpn = round(
                    $outstandingDpp * 0.12,
                    2,
                );

                $outstandingPoAmount = round(
                    $outstandingSubtotal + $outstandingPpn,
                    2,
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Selisih nilai PR vs PO
        |--------------------------------------------------------------------------
        | Ini bukan outstanding proses PO.
        |
        | - EFFICIENCY: nilai PO lebih rendah dari Grand Total PR.
        | - INCREASE  : nilai PO lebih tinggi dari Grand Total PR.
        | - NONE      : tidak ada selisih.
        |--------------------------------------------------------------------------
        */
            $rawValueDifference = round(
                $grandTotalPr - $totalPo,
                2,
            );

            $valueDifferenceAmount = round(
                abs($rawValueDifference),
                2,
            );

            if ($valueDifferenceAmount <= 0.009) {
                $valueDifferenceType = 'NONE';
                $valueDifferenceLabel = 'Tidak Ada Selisih';
                $valueDifferenceAmount = 0.0;
            } elseif ($rawValueDifference > 0) {
                $valueDifferenceType = 'EFFICIENCY';
                $valueDifferenceLabel = 'Efisiensi Nilai';
            } else {
                $valueDifferenceType = 'INCREASE';
                $valueDifferenceLabel = 'Kenaikan Nilai';
            }

            /*
        |--------------------------------------------------------------------------
        | Approval yang bisa diproses user login
        |--------------------------------------------------------------------------
        | Sama seperti index(), supaya tombol Approve/Reject di modal detail
        | punya sumber kebenaran yang identik dengan list.
        |--------------------------------------------------------------------------
        */
            $user = $request->user();

            $currentApproval = $pr->approvals
                ->first(
                    function (
                        PurchaseRequestApproval $approval,
                    ) use (
                        $approvalService,
                        $user,
                    ): bool {
                        return strtoupper(
                            (string) $approval->status,
                        ) === PurchaseRequestApproval::STATUS_WAITING
                            && $approvalService->userCanApprove(
                                $approval,
                                $user,
                            );
                    },
                );

            return response()->json([
                'success' => true,
                'message' => __('purchase_request_messages.show.loaded'),

                'data' => [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id,

                    'can_approve' => $currentApproval !== null,
                    'approval_id' => $currentApproval?->id,

                    'approval_step_order' => $currentApproval
                        ? (int) $currentApproval->step_order
                        : null,

                    'approval_label' => $currentApproval?->label,
                    'approval_mode' => $currentApproval?->approval_mode,
                    'nomor_pr' => $pr->nomor_pr,
                    'tanggal_pr' => $pr->tanggal_pr,

                    'cabang_id' => $pr->cabang,

                    'cabang' => $pr->cabangData
                        ? trim(
                            ($pr->cabangData->inisial_cabang ?? '-')
                                . ' - '
                                . ($pr->cabangData->nama_cabang ?? '-')
                        )
                        : '-',

                    'department_id' => $pr->id_department,

                    'department' => $pr->departmentData
                        ? trim(
                            ($pr->departmentData->kode ?? '-')
                                . ' - '
                                . ($pr->departmentData->nama ?? '-')
                        )
                        : '-',

                    'recommended_vendor_id' => $pr->recommended_vendor_id,

                    'recommended_vendor' => $pr->recommendedVendor
                        ? [
                            'id' => $pr->recommendedVendor->id,

                            'nama_vendor' => $pr->recommendedVendor
                                ->nama_vendor
                                ?? '-',

                            'status_pkp' => $pr->status_pkp
                                ?? 'NON_PKP',

                            'jenis_pembayaran' => $pr->jenis_pembayaran
                                ?? null,

                            'top' => $pr->top
                                ?? null,
                        ]
                        : null,

                    'status_pkp' => $pr->status_pkp ?? 'NON_PKP',
                    'jenis_pembayaran' => $pr->jenis_pembayaran ?? null,
                    'top' => $pr->top ?? null,

                    'subtotal_item' => $subtotalItem,
                    'dpp' => $dppAmount,
                    'ppn' => $ppnAmount,

                    'kategori' => $pr->kategori,
                    'pr_type' => $pr->pr_type,
                    'special_document_type_id' => $pr->special_document_type_id,
                    'special_document_type' => $pr->specialDocumentType
                        ? [
                            'id' => $pr->specialDocumentType->id,
                            'code' => $pr->specialDocumentType->code,
                            'name' => $pr->specialDocumentType->name,
                        ]
                        : null,
                    'notes' => $pr->notes,
                    'status' => $pr->status,
                    'status_po' => $pr->status_po,

                    /*
                |--------------------------------------------------------------------------
                | Purchase Order terkait
                |--------------------------------------------------------------------------
                */
                    /*
                |--------------------------------------------------------------------------
                | Purchase Order Terkait (tampilan)
                |--------------------------------------------------------------------------
                | Beda dengan $validPurchaseOrders yang dipakai untuk perhitungan
                | total_po/outstanding di atas. Untuk tampilan, seluruh PO yang
                | pernah terhubung ke PR ini ditampilkan apa adanya (termasuk
                | DRAFT, REJECTED, CANCELLED) supaya user bisa melihat riwayat
                | lengkapnya, masing-masing dengan status aslinya.
                |--------------------------------------------------------------------------
                */
                    'purchase_orders' => $pr->purchaseOrders
                        ->sortByDesc('id')
                        ->map(function ($po) {
                            return [
                                'id' => $po->id,
                                'public_id' => $po->encrypted_id,
                                'nomor_po' => $po->nomor_po,
                                'tanggal_po' => $po->tanggal_po,

                                'total_nilai' => (float) (
                                    $po->total_nilai ?? 0
                                ),

                                'status' => $po->status,
                            ];
                        })
                        ->values(),

                    /*
                |--------------------------------------------------------------------------
                | Audit
                |--------------------------------------------------------------------------
                */
                    'created_at' => $pr->created_at,
                    'created_by' => $pr->created_by,

                    'created_by_name' => $pr->creator?->name
                        ?? '-',

                    'submitted_at' => $pr->submitted_at,
                    'submitted_by' => $pr->submitted_by,

                    'submitted_by_name' => $pr->submitter?->name
                        ?? '-',

                    /*
                |--------------------------------------------------------------------------
                | Nilai
                |--------------------------------------------------------------------------
                */
                    'total_amount' => $grandTotalPr,

                    'total_po' => $totalPo,

                    /*
                |--------------------------------------------------------------------------
                | Backward compatibility
                |--------------------------------------------------------------------------
                | total_outstanding sekarang berarti outstanding PO berbasis qty,
                | bukan lagi Grand Total PR - Total PO.
                |--------------------------------------------------------------------------
                */
                    'total_outstanding' => $outstandingPoAmount,

                    'outstanding_po_subtotal' => $outstandingSubtotal,
                    'outstanding_po_dpp' => $outstandingDpp,
                    'outstanding_po_ppn' => $outstandingPpn,
                    'outstanding_po_amount' => $outstandingPoAmount,

                    'value_difference_amount' => $valueDifferenceAmount,
                    'value_difference_raw' => $rawValueDifference,
                    'value_difference_type' => $valueDifferenceType,
                    'value_difference_label' => $valueDifferenceLabel,

                    /*
                |--------------------------------------------------------------------------
                | Items
                |--------------------------------------------------------------------------
                */
                    'items' => $items
                        ->map(function ($item) {
                            $qty = (float) ($item->qty ?? 0);
                            $qtyPo = (float) ($item->qty_po ?? 0);

                            $qtyOutstanding = (float) (
                                $item->qty_outstanding ?? 0
                            );

                            $hargaUnit = (float) (
                                $item->harga_unit ?? 0
                            );

                            return [
                                'id' => $item->id,
                                'nama_item' => $item->nama_item,

                                'master_material_group_id'
                                => $item->master_material_group_id,

                                'material_group' => [
                                    'id' => $item->materialGroup?->id,
                                    'code' => $item->materialGroup?->code ?? '-',
                                    'name' => $item->materialGroup?->name ?? '-',
                                ],

                                'qty' => $item->qty,
                                'qty_po' => $item->qty_po,
                                'qty_outstanding' => $item->qty_outstanding,

                                'satuan_id' => $item->satuan,

                                'satuan' => [
                                    'id' => $item->unit?->id,
                                    'kode' => $item->unit?->kode ?? '-',
                                    'nama' => $item->unit?->nama ?? '-',
                                ],

                                'spesifikasi' => $item->spesifikasi,
                                'harga_unit' => $item->harga_unit,
                                'subtotal' => $item->subtotal,

                                'subtotal_po' => $qtyPo
                                    * $hargaUnit,

                                'subtotal_outstanding' => $qtyOutstanding
                                    * $hargaUnit,

                                'keterangan' => $item->keterangan,
                            ];
                        })
                        ->values(),

                    /*
                |--------------------------------------------------------------------------
                | Attachments
                |--------------------------------------------------------------------------
                */
                    'attachments' => $pr->attachments
                        ->map(function ($attachment) {
                            return [
                                'id' => $attachment->id,
                                'filename' => $attachment->filename,

                                'filepath' => asset(
                                    'storage/' . $attachment->filepath
                                ),

                                'file_size' => $attachment->file_size,
                                'mime_type' => $attachment->mime_type,

                                'original_filename' => $attachment
                                    ->original_filename,
                            ];
                        })
                        ->values(),

                    /*
                |--------------------------------------------------------------------------
                | Approval Histories existing
                |--------------------------------------------------------------------------
                | Tetap dipertahankan agar tidak merusak fitur existing.
                |--------------------------------------------------------------------------
                */
                    'approval_histories' => $pr->approvalHistories,

                    /*
                |--------------------------------------------------------------------------
                | Snapshot History Approval
                |--------------------------------------------------------------------------
                | Digunakan ApprovalHistoryPRDialog pada index.
                |
                | Jangan di-unique berdasarkan step_order karena mode ANY dapat
                | memiliki beberapa calon approver pada tahap yang sama.
                |--------------------------------------------------------------------------
                */
                    'approvals' => $pr->approvals
                        ->filter(function ($approval) {
                            return strtoupper(
                                trim((string) $approval->status)
                            ) !== PurchaseRequestApproval::STATUS_SKIPPED;
                        })
                        ->sortBy(function ($approval) {
                            return sprintf(
                                '%010d-%010d',
                                (int) $approval->step_order,
                                (int) $approval->id,
                            );
                        })
                        ->unique(function ($approval) {
                            $status = strtoupper(
                                trim((string) $approval->status)
                            );

                            if (in_array($status, [
                                PurchaseRequestApproval::STATUS_WAITING,
                                PurchaseRequestApproval::STATUS_PENDING,
                            ], true)) {
                                return 'STEP-' . (int) $approval->step_order;
                            }

                            return 'ROW-' . (int) $approval->id;
                        })

                        ->values()
                        ->map(function ($approval) {
                            return [
                                'id' => $approval->id,

                                'approval_flow_id' => $approval
                                    ->approval_flow_id,

                                'approval_flow_step_id' => $approval
                                    ->approval_flow_step_id,

                                'step_order' => (int) (
                                    $approval->step_order ?? 0
                                ),

                                'label' => $approval->label,

                                'approver_type' => $approval
                                    ->approver_type,

                                'approver_id' => $approval
                                    ->approver_id,

                                'approver_name_snapshot' => $approval
                                    ->approver_name_snapshot,

                                'approval_mode' => $approval
                                    ->approval_mode,

                                'status' => strtoupper(
                                    (string) $approval->status
                                ),

                                'signature_path' => $approval
                                    ->signature_path,

                                'signed_at' => $approval
                                    ->signed_at,

                                'approved_at' => $approval
                                    ->approved_at,

                                'rejected_at' => $approval
                                    ->rejected_at,

                                'notes' => $approval->notes,

                                'created_at' => $approval
                                    ->created_at,

                                'updated_at' => $approval
                                    ->updated_at,
                            ];
                        })
                        ->values(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] Show error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.show.load_failed'),
                'data' => null,

                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function update(string $publicId, Request $request)
    {

        $user = $request->user();

        if (!$user || !$user->hasPermission('purchase_request.update')) {
            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.update.forbidden'),
            ], 403);
        }

        $storedPaths = [];

        DB::beginTransaction();

        try {
            $clean = static function (mixed $value): string {
                if ($value === null) {
                    return '';
                }

                $text = trim((string) $value);

                /*
            |--------------------------------------------------------------------------
            | Decode entity lama / double encoded
            |--------------------------------------------------------------------------
            | Contoh:
            | &amp;quot; -> &quot; -> "
            |--------------------------------------------------------------------------
            */
                for ($i = 0; $i < 3; $i++) {
                    $decoded = html_entity_decode(
                        $text,
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8',
                    );

                    if ($decoded === $text) {
                        break;
                    }

                    $text = $decoded;
                }

                /*
            |--------------------------------------------------------------------------
            | Buang tag HTML, tapi jangan encode lagi.
            |--------------------------------------------------------------------------
            */
                $text = strip_tags($text);

                /*
            |--------------------------------------------------------------------------
            | Rapihkan spasi berlebih.
            |--------------------------------------------------------------------------
            */
                $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

                return trim($text);
            };

            $request->validate([
                'tanggal_pr'             => ['required', 'date_format:Y-m-d'],
                'cabang'                 => ['required'],
                'id_department'          => ['required', 'integer'],
                'recommended_vendor_id'  => ['nullable', 'integer', 'exists:master_vendor,id'],
                'kategori'               => ['nullable', 'string'],
                'pr_type'                => ['required', 'string', 'max:50', 'in:Rutin,Non Rutin'],

                /*
                | NULL = dokumen biasa. Keabsahan tipe terhadap department pembuat
                | diperiksa terpisah lewat assertSpecialDocumentTypeAllowed().
                */
                'special_document_type_id' => ['nullable', 'integer'],
                'items'                  => ['required', 'string'],
                'existing_attachment_ids' => ['nullable', 'string'],
                'lampiran_requests.*'    => ['sometimes', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:3000'],
            ]);

            $id = Crypt::decryptString($publicId);
            $pr = PurchaseRequest::findOrFail($id);

            $this->validateUserAccessAssignmentForPurchaseRequest(
                $request,
                $request->cabang,
                $request->id_department,
            );

            /*
        |--------------------------------------------------------------------------
        | 1. Proteksi Status
        |--------------------------------------------------------------------------
        */
            if ($pr->status === PurchaseRequest::STATUS_APPROVED) {
                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.update.already_approved'),
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | 2. Decode & Validasi Items
        |--------------------------------------------------------------------------
        */
            $items = json_decode($request->items, true);

            if (!is_array($items) || count($items) === 0) {
                throw new \Exception('Data item tidak valid.');
            }

            foreach ($items as $item) {
                if (empty($item['nama_item'])) {
                    throw new \Exception('Nama item wajib diisi.');
                }

                if (empty($item['master_material_group_id'])) {
                    throw new \Exception('Material Group item wajib dipilih.');
                }

                if (empty($item['qty']) || (float) $item['qty'] <= 0) {
                    throw new \Exception('Qty item wajib diisi.');
                }

                if (empty($item['satuan'])) {
                    throw new \Exception('Satuan item wajib dipilih.');
                }

                if (!isset($item['harga_unit']) || (float) $item['harga_unit'] <= 0) {
                    throw new \Exception('Harga satuan item wajib diisi.');
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Pastikan Material Group benar-benar ada dan masih aktif
        |--------------------------------------------------------------------------
        | Dicek kolektif dalam satu query agar tidak menembak DB per item.
        |--------------------------------------------------------------------------
        */
            $this->assertMaterialGroupsAreValid($items);

            /*
        |--------------------------------------------------------------------------
        | 3. Hitung Subtotal Item
        |--------------------------------------------------------------------------
        | subtotal item = nilai sebelum PPN
        |--------------------------------------------------------------------------
        */
            $subTotalItems = 0;

            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? 0);
                $harga = (float) ($item['harga_unit'] ?? 0);

                $subTotalItems += $qty * $harga;
            }

            $subTotalItems = round((float) $subTotalItems, 2);

            /*
        |--------------------------------------------------------------------------
        | 3A. Snapshot Vendor dan Hitung Pajak PR
        |--------------------------------------------------------------------------
        | Mengikuti konsep PO:
        | - PKP     : dpp = subtotal * 11 / 12, ppn = dpp * 12%, total = subtotal + ppn
        | - NON PKP : dpp = 0, ppn = 0, total = subtotal
        |--------------------------------------------------------------------------
        */
            $recommendedVendorId = $request->filled('recommended_vendor_id')
                ? (int) $request->recommended_vendor_id
                : null;

            $vendor = $recommendedVendorId
                ? DB::table('master_vendor')
                ->where('id', $recommendedVendorId)
                ->first([
                    'id',
                    'status_pkp',
                    'jenis_pembayaran',
                    'top',
                ])
                : null;

            $vendorStatusPkpNormalized = strtoupper(
                trim((string) ($vendor->status_pkp ?? 'NON_PKP')),
            );

            $isPkpVendor = in_array(
                $vendorStatusPkpNormalized,
                [
                    'PKP',
                    '1',
                    'YA',
                    'YES',
                    'TRUE',
                ],
                true,
            );

            $statusPkpSnapshot = $isPkpVendor
                ? 'PKP'
                : 'NON_PKP';

            $jenisPembayaranSnapshot = $vendor
                ? trim((string) ($vendor->jenis_pembayaran ?? ''))
                : '';

            $jenisPembayaranSnapshot = $jenisPembayaranSnapshot !== ''
                ? strtoupper($jenisPembayaranSnapshot)
                : null;

            $topSnapshot = $vendor
                ? (int) ($vendor->top ?? 0)
                : 0;

            if ($isPkpVendor) {
                $dppAmount = round($subTotalItems * 11 / 12, 2);
                $ppnAmount = round($dppAmount * 0.12, 2);
                $totalAmount = round($subTotalItems + $ppnAmount, 2);
            } else {
                $dppAmount = 0.0;
                $ppnAmount = 0.0;
                $totalAmount = round($subTotalItems, 2);
            }

            $this->validateMinimumPurchaseRequestAmount(
                (float) $totalAmount,
            );

            /*
        |--------------------------------------------------------------------------
        | 4. Update Header PR
        |--------------------------------------------------------------------------
        */
            $user = $request->user();
            $pr->update([
                'tanggal_pr'            => $request->tanggal_pr,
                'cabang'                => $request->cabang,
                'id_department'         => (int) $request->id_department,
                'recommended_vendor_id' => $recommendedVendorId,
                'kategori'              => $request->kategori,
                'pr_type'               => $request->pr_type,

                /*
                | NULL untuk dokumen biasa. Helper menolak bila department
                | pembuat tidak berhak memakai tipe tersebut.
                */
                'special_document_type_id'
                => $this->assertSpecialDocumentTypeAllowed(
                    $request->input('special_document_type_id'),
                    (int) $request->id_department,
                ),

                'notes'                 => $clean($request->notes),
                'total_amount'          => $totalAmount,
                'status_pkp'            => $statusPkpSnapshot,
                'jenis_pembayaran'      => $jenisPembayaranSnapshot,
                'top'                   => $topSnapshot,
                'dpp'                   => $dppAmount,
                'ppn'                   => $ppnAmount,
                'updated_by'            => $user?->id,
            ]);

            /*
        |--------------------------------------------------------------------------
        | 5. Sync Item PR
        | Cara aman: hapus item lama lalu insert ulang.
        |--------------------------------------------------------------------------
        */
            PurchaseRequestItem::where('purchase_request_id', $pr->id)->delete();

            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? 0);
                $harga = (float) ($item['harga_unit'] ?? 0);
                $subtotal = round($qty * $harga, 2);

                PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id,
                    'nama_item'           => $clean($item['nama_item'] ?? ''),
                    'master_material_group_id'
                    => (int) $item['master_material_group_id'],
                    'qty'                 => $qty,
                    'qty_outstanding'     => $qty,
                    'satuan'              => $clean($item['satuan'] ?? ''),
                    'spesifikasi'         => $clean($item['spesifikasi'] ?? ''),
                    'keterangan'          => $clean($item['keterangan'] ?? ''),
                    'harga_unit'          => $harga,
                    'subtotal'            => $subtotal,
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | 6. Existing Attachment IDs
        |--------------------------------------------------------------------------
        */
            $existingAttachmentIds = json_decode(
                $request->existing_attachment_ids ?? '[]',
                true
            );

            if (!is_array($existingAttachmentIds)) {
                $existingAttachmentIds = [];
            }

            /*
        |--------------------------------------------------------------------------
        | 7. Hapus Attachment Lama Yang Dihapus Di FE
        |--------------------------------------------------------------------------
        */
            $deletedAttachments = PrAttachment::where('purchase_request_id', $pr->id)
                ->when(count($existingAttachmentIds) > 0, function ($query) use ($existingAttachmentIds) {
                    $query->whereNotIn('id', $existingAttachmentIds);
                })
                ->when(count($existingAttachmentIds) === 0, function ($query) {
                    $query->whereRaw('1 = 1');
                })
                ->get();

            foreach ($deletedAttachments as $attachment) {
                if (
                    $attachment->filepath &&
                    Storage::disk('public')->exists($attachment->filepath)
                ) {
                    Storage::disk('public')->delete($attachment->filepath);
                }

                $attachment->delete();
            }

            /*
        |--------------------------------------------------------------------------
        | 8. Tambah Lampiran Baru
        |--------------------------------------------------------------------------
        */
            if ($request->hasFile('lampiran_requests')) {
                $nomorPr = $pr->nomor_pr;
                $folder = "syopv4/uploads/purchase_requests/lampiran/{$pr->id}";

                Storage::disk('public')->makeDirectory($folder);

                $fullFolderPath = storage_path('app/public/' . $folder);

                if (\Illuminate\Support\Facades\File::exists($fullFolderPath)) {
                    @chmod($fullFolderPath, 0777);
                }

                foreach ($request->file('lampiran_requests') as $file) {
                    if (!$file || !$file->isValid()) {
                        continue;
                    }

                    $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $extension = strtolower($file->getClientOriginalExtension());

                    $safeOriginalName = \Illuminate\Support\Str::slug($originalName);

                    if ($safeOriginalName === '') {
                        $safeOriginalName = 'file';
                    }

                    $filename = str_replace('/', '-', $nomorPr)
                        . '_' . now()->format('YmdHis')
                        . '_' . uniqid()
                        . '_' . $safeOriginalName
                        . '.' . $extension;

                    $path = $file->storeAs($folder, $filename, 'public');

                    $storedPaths[] = $path;

                    $fullFilePath = storage_path('app/public/' . $path);

                    if (\Illuminate\Support\Facades\File::exists($fullFilePath)) {
                        @chmod($fullFilePath, 0777);
                    }

                    PrAttachment::create([
                        'purchase_request_id' => $pr->id,
                        'filename'            => $filename,
                        'original_filename'   => $file->getClientOriginalName(),
                        'mime_type'           => $file->getMimeType(),
                        'file_size'           => $file->getSize(),
                        'filepath'            => $path,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('purchase_request_messages.update.success'),
                'data' => [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id ?? null,
                    'nomor_pr' => $pr->nomor_pr,
                ],
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();

            foreach ($storedPaths as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? 'Data Purchase Requisition tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            foreach ($storedPaths as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            Log::error('[Purchase Requisition] Update error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.update.failed'),
                'error'   => config('app.debug') ? $e->getMessage() : null,
                'line'    => config('app.debug') ? $e->getLine() : null,
            ], 500);
        }
    }

    /**
     * DELETE /api/purchase-request/{id}
     * Soft delete data
     */
    public function destroy(Request $request, $publicId)
    {

        $user = $request->user();

        if (!$user || !$user->hasPermission('purchase_request.delete')) {
            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.destroy.forbidden'),
            ], 403);
        }

        DB::beginTransaction();

        try {
            $id = Crypt::decryptString($publicId);

            $pr = PurchaseRequest::with([
                'items',
                'attachments',
                'approvalHistories',
            ])->find($id);

            if (!$pr) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.not_found'),
                ], 404);
            }

            if (strtolower($pr->status) !== 'draft') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.destroy.only_draft'),
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Hapus item PR
            |--------------------------------------------------------------------------
            */
            $pr->items()->delete();

            /*
            |--------------------------------------------------------------------------
            | Hapus attachment record
            | Kalau ingin hapus file fisiknya juga, aktifkan bagian delete storage.
            |--------------------------------------------------------------------------
            */
            foreach ($pr->attachments as $attachment) {
                if (
                    $attachment->filepath &&
                    Storage::disk('public')->exists($attachment->filepath)
                ) {
                    Storage::disk('public')->delete($attachment->filepath);
                }

                $attachment->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | Hapus approval histories
            |--------------------------------------------------------------------------
            */
            $pr->approvalHistories()->delete();

            /*
            |--------------------------------------------------------------------------
            | Hapus PR
            |--------------------------------------------------------------------------
            */
            $pr->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('purchase_request_messages.destroy.success'),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Requisition] Delete error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.destroy.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Export Excel Purchase Requisition
    |--------------------------------------------------------------------------
    | Menerima parameter filter yang sama dengan index() sehingga isi file
    | selalu sama dengan daftar yang sedang dilihat user, termasuk batasan
    | visibility scope-nya.
    |
    | Judul kolom mengikuti ?lang=id|en, mengikuti pola yang dipakai print.
    |--------------------------------------------------------------------------
    */
    public function exportExcel(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.user_not_authenticated'),
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | Bahasa file export
            |--------------------------------------------------------------------------
            */
            $lang = strtolower(trim((string) $request->query('lang', 'id')));

            if (!in_array($lang, ['id', 'en'], true)) {
                $lang = 'id';
            }

            app()->setLocale($lang);

            /*
            |--------------------------------------------------------------------------
            | Gerbang: permission export tersendiri
            |--------------------------------------------------------------------------
            | Permission ini tidak memakai scope. Yang menentukan ISI file adalah
            | visibility PR yang sama persis dengan daftar. Jadi permission export
            | hanya menjawab "boleh menarik data keluar atau tidak", bukan "boleh
            | melihat data siapa".
            |--------------------------------------------------------------------------
            */
            if (!$user->hasPermission('purchase_request.export')) {
                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.export.forbidden'),
                ], 403);
            }

            $context = $this->resolvePurchaseRequestViewContext($user);

            /*
            | Scope NONE berarti tidak ada satu pun PR yang terlihat olehnya,
            | sehingga export pun tidak ada isinya.
            */
            if ($context['scope'] === 'NONE') {
                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.export.forbidden'),
                ], 403);
            }

            $query = PurchaseRequest::query()
                ->with([
                    'cabangData',
                    'departmentData',
                    'items.unit',

                    'purchaseOrders' => function ($purchaseOrderQuery) {
                        $purchaseOrderQuery->orderByDesc('purchase_orders.id');
                    },
                ]);

            /*
            |--------------------------------------------------------------------------
            | Scope + filter yang sama persis dengan index()
            |--------------------------------------------------------------------------
            */
            $this->applyPurchaseRequestVisibilityScope(
                $query,
                $user,
                $context['scope'],
                $context['userRoleIds'],
                $context['userAccessibleBranchIds'],
                $context['userAccessibleDepartmentIds'],
            );

            $this->applyPurchaseRequestListFilters($query, $request);

            $data = $query
                ->orderByDesc('purchase_requests.id')
                ->get();

            $fileName = __('purchase_request_messages.export.filename')
                . '_' . now()->format('Ymd_His')
                . '.xlsx';

            return Excel::download(
                new PurchaseRequestExport($data),
                $fileName,
            );
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] Export excel error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.export.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function edit(Request $request, $publicId)
    {

        $user = $request->user();

        if (!$user || !$user->hasPermission('purchase_request.update')) {
            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.edit.forbidden'),
            ], 403);
        }

        try {
            $id = Crypt::decryptString($publicId);

            $pr = PurchaseRequest::with([
                'cabangData',
                'departmentData',
                'recommendedVendor',
                'items.unit',
                'items.materialGroup',
                'specialDocumentType',
                'attachments',
            ])->findOrFail($id);

            if (!$pr) {
                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.not_found'),
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => __('purchase_request_messages.edit.loaded'),
                'data' => [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id,
                    'nomor_pr' => $pr->nomor_pr,
                    'tanggal_pr' => $pr->tanggal_pr
                        ? \Carbon\Carbon::parse($pr->tanggal_pr)->format('Y-m-d')
                        : null,

                    'cabang_id' => $pr->cabang,
                    'cabang' => $pr->cabangData->nama_cabang ?? '-',

                    'department_id' => $pr->id_department,
                    'department' => $pr->departmentData
                        ? (($pr->departmentData->kode ?? '-') . ' - ' . ($pr->departmentData->nama ?? '-'))
                        : '-',

                    'recommended_vendor_id' => $pr->recommended_vendor_id,
                    'recommended_vendor' => $pr->recommendedVendor ? [
                        'id' => $pr->recommendedVendor->id,
                        'nama_vendor' => $pr->recommendedVendor->nama_vendor ?? '-',
                        'status_pkp' => $pr->recommendedVendor->status_pkp ?? 'NON_PKP',
                    ] : null,

                    'kategori' => $pr->kategori,
                    'pr_type' => $pr->pr_type,
                    'special_document_type_id' => $pr->special_document_type_id,
                    'special_document_type' => $pr->specialDocumentType
                        ? [
                            'id' => $pr->specialDocumentType->id,
                            'code' => $pr->specialDocumentType->code,
                            'name' => $pr->specialDocumentType->name,
                        ]
                        : null,
                    'notes' => $pr->notes,
                    'status' => $pr->status,
                    'total_amount' => $pr->total_amount,

                    'items' => $pr->getRelation('items')->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'nama_item' => $item->nama_item,

                            'master_material_group_id'
                            => $item->master_material_group_id,

                            'material_group' => [
                                'id' => $item->materialGroup?->id,
                                'code' => $item->materialGroup?->code ?? '-',
                                'name' => $item->materialGroup?->name ?? '-',
                            ],

                            'qty' => $item->qty,

                            'satuan_id' => $item->satuan,
                            'satuan' => [
                                'id' => $item->unit->id ?? null,
                                'kode' => $item->unit->kode ?? '-',
                                'nama' => $item->unit->nama ?? '-',
                            ],

                            'spesifikasi' => $item->spesifikasi,
                            'harga_unit' => $item->harga_unit,
                            'subtotal' => $item->subtotal,
                            'keterangan' => $item->keterangan,
                        ];
                    })->values(),

                    'attachments' => $pr->attachments->map(function ($a) {
                        return [
                            'id' => $a->id,
                            'filename' => $a->filename,
                            'original_filename' => $a->original_filename,
                            'filepath' => asset('storage/' . $a->filepath),
                            'file_size' => $a->file_size,
                            'mime_type' => $a->mime_type,
                        ];
                    })->values(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] Edit error', [
                'public_id' => $publicId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.edit.load_failed'),
                'data' => null,
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function deleteDokumen($id)
    {
        try {
            // 1️⃣ Cari attachment
            $attachment = PrAttachment::findOrFail($id);

            // 2️⃣ Ambil parent PR
            $pr = PurchaseRequest::find($attachment->purchase_request_id);
            if (!$pr) {
                return response()->json([
                    'message' => __('purchase_request_messages.not_found')
                ], 404);
            }

            // 3️⃣ Proteksi status
            if (in_array($pr->status, ['APPROVED', 'IN PROGRESS'])) {
                return response()->json([
                    'message' => __('purchase_request_messages.attachment.already_approved')
                ], 403);
            }

            // 4️⃣ PATH ASLI (JANGAN DIUBAH)
            $path = $attachment->filepath;

            // 5️⃣ Hapus file fisik
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            // 6️⃣ Hapus DB
            $attachment->delete();

            return response()->json([
                'message' => __('purchase_request_messages.attachment.deleted')
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('[Purchase Requisition] Delete attachment - not found', [
                'message' => $e->getMessage(),
                'attachment_id' => $id,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'message' => __('purchase_request_messages.attachment.not_found')
            ], 404);
        } catch (\Exception $e) {
            Log::error('[Purchase Requisition] Delete attachment error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'attachment_id' => $id,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'message' => __('purchase_request_messages.attachment.delete_failed'),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function approve(
        string $publicId,
        Request $request,
        PurchaseRequestApprovalService $approvalService,
        PurchaseRequestNotificationService $notificationService,
        PurchaseRequestMailService $mailService,
    ): JsonResponse {
        $validated = $request->validate([
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            if (!$user) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.user_not_authenticated'),
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $purchaseRequest = PurchaseRequest::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $purchaseRequest->status)
                !== PurchaseRequest::STATUS_IN_PROGRESS
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.approve.not_in_progress'),
                ], 422);
            }

            $result = $approvalService->approveCurrentStep(
                $purchaseRequest,
                $user,
                $validated['notes'] ?? null,
            );

            DB::commit();

            $purchaseRequest->refresh();

            /*
        |--------------------------------------------------------------------------
        | Setelah commit: notifikasi dan email
        |--------------------------------------------------------------------------
        */
            try {
                /*
            |--------------------------------------------------------------------------
            | Notifikasi aplikasi creator
            |--------------------------------------------------------------------------
            | Tetap dibuat setiap ada proses approval.
            |--------------------------------------------------------------------------
            */
                $notificationService->notifyApprovalStep(
                    $purchaseRequest,
                    $user,
                    $result['approval'],
                    $result['has_pending_approval'],
                );

                /*
            |--------------------------------------------------------------------------
            | Email creator hanya saat final approved
            |--------------------------------------------------------------------------
            */
                if ($result['is_final_approved']) {
                    $mailService->sendApprovalStep(
                        $purchaseRequest,
                        $user,
                        false,
                    );
                }
            } catch (\Throwable $notifyError) {
                Log::error(
                    '[Purchase Request] Notify approval result gagal',
                    [
                        'purchase_request_id' => $purchaseRequest->id,
                        'is_final_approved' => $result['is_final_approved'],
                        'message' => $notifyError->getMessage(),
                    ],
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Jika step berikutnya aktif, kirim ke approver berikutnya
        |--------------------------------------------------------------------------
        */
            if (
                $result['step_completed']
                && $result['has_pending_approval']
                && $result['next_step_order'] !== null
            ) {
                try {
                    $notificationService->notifyApprovalRequest(
                        $purchaseRequest,
                    );

                    $mailService->sendApprovalRequest(
                        $purchaseRequest,
                    );
                } catch (\Throwable $nextApproverError) {
                    Log::error(
                        '[Purchase Request] Notify next approver gagal',
                        [
                            'purchase_request_id' => $purchaseRequest->id,
                            'next_step_order' => $result['next_step_order'],
                            'message' => $nextApproverError->getMessage(),
                        ],
                    );
                }
            }

            return response()->json([
                'success' => true,

                'message' => $result['is_final_approved']
                    ? 'Purchase Requisition berhasil disetujui secara final.'
                    : (
                        $result['step_completed']
                        ? 'Tahap approval Purchase Requisition berhasil disetujui.'
                        : 'Approval Anda berhasil disimpan dan masih menunggu approver lain pada tahap yang sama.'
                    ),

                'data' => [
                    'id' => $purchaseRequest->id,
                    'public_id' => $purchaseRequest->encrypted_id,
                    'nomor_pr' => $purchaseRequest->nomor_pr,
                    'status' => $purchaseRequest->status,
                    'status_po' => $purchaseRequest->status_po,
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
                'message' => collect($e->errors())
                    ->flatten()
                    ->first()
                    ?? 'Approval tidak dapat diproses.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Request] Approve error', [
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.approve.failed'),
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function reject(
        string $publicId,
        Request $request,
        PurchaseRequestApprovalService $approvalService,
        PurchaseRequestNotificationService $notificationService,
        PurchaseRequestMailService $mailService,
    ): JsonResponse {
        $validated = $request->validate([
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            if (!$user) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.user_not_authenticated'),
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $purchaseRequest = PurchaseRequest::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if (
                strtoupper((string) $purchaseRequest->status)
                !== PurchaseRequest::STATUS_IN_PROGRESS
            ) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.reject.not_in_progress'),
                ], 422);
            }

            $approval = $approvalService->rejectCurrentStep(
                $purchaseRequest,
                $user,
                $validated['notes'] ?? null,
            );

            DB::commit();

            $purchaseRequest->refresh();

            try {
                $notificationService->notifyRejected(
                    $purchaseRequest,
                    $user,
                );

                $mailService->sendRejected(
                    $purchaseRequest,
                    $user,
                    $validated['notes'] ?? null,
                );
            } catch (\Throwable $notifyError) {
                Log::error(
                    '[Purchase Request] Notify reject gagal',
                    [
                        'purchase_request_id' => $purchaseRequest->id,
                        'message' => $notifyError->getMessage(),
                    ],
                );
            }

            return response()->json([
                'success' => true,
                'message' => __('purchase_request_messages.reject.success'),
                'data' => [
                    'id' => $purchaseRequest->id,
                    'public_id' => $purchaseRequest->encrypted_id,
                    'nomor_pr' => $purchaseRequest->nomor_pr,
                    'status' => $purchaseRequest->status,
                    'approval_id' => $approval->id,
                    'rejected_at' => $approval->rejected_at,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())
                    ->flatten()
                    ->first()
                    ?? 'Reject tidak dapat diproses.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Request] Reject error', [
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.reject.failed'),
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function submit(
        string $publicId,
        Request $request,
        PurchaseRequestApprovalGeneratorService $approvalGenerator,
        PurchaseRequestNotificationService $notificationService,
        PurchaseRequestMailService $mailService,
    ): JsonResponse {
        DB::beginTransaction();

        try {
            $user = $request->user();

            if (!$user) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.user_not_authenticated'),
                ], 401);
            }

            $id = Crypt::decryptString($publicId);

            $pr = PurchaseRequest::with([
                'items',
            ])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($pr->status !== PurchaseRequest::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.submit.only_draft'),
                ], 422);
            }

            if ($pr->items->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.submit.items_unavailable'),
                ], 422);
            }

            /*
        |--------------------------------------------------------------------------
        | Ambil tanda tangan requester
        |--------------------------------------------------------------------------
        | Sesuaikan signature_path dengan nama kolom tanda tangan pada tabel users.
        |--------------------------------------------------------------------------
        */

            // BARU
            $requesterSignaturePath = $user->signature_path;

            // BARU
            if (blank($requesterSignaturePath)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.submit.signature_missing'),
                ], 422);
            }

            if (str_starts_with((string) $pr->nomor_pr, 'DRAFT/')) {
                $pr->nomor_pr = generatePRNumber($pr);
            }

            /*
        |--------------------------------------------------------------------------
        | Generate snapshot approval PR
        |--------------------------------------------------------------------------
        */
            $approvalGenerator->generate($pr);

            /*
        |--------------------------------------------------------------------------
        | Update status PR
        |--------------------------------------------------------------------------
        | Tidak menggunakan current_level lagi.
        |--------------------------------------------------------------------------
        */

            // BARU: gunakan waktu yang sama untuk submit dan tanda tangan requester
            $submittedAt = now();

            $pr->status = PurchaseRequest::STATUS_IN_PROGRESS;
            $pr->submitted_at = $submittedAt;
            $pr->submitted_by = $user->id;

            /*
        |--------------------------------------------------------------------------
        | Snapshot tanda tangan requester
        |--------------------------------------------------------------------------
        */

            // BARU
            $pr->requester_signed_by = $user->id;
            $pr->requester_signature_path = $requesterSignaturePath;
            $pr->requester_signed_at = $submittedAt;

            $pr->save();

            DB::commit();

            /*
        |--------------------------------------------------------------------------
        | Notifikasi aplikasi setelah transaksi berhasil
        |--------------------------------------------------------------------------
        */
            $pr->refresh();
            $pr->loadMissing('items');

            try {
                $notificationService->notifyApprovalRequest($pr);
            } catch (\Throwable $notificationError) {
                Log::error(
                    '[Purchase Requisition] Notifikasi approver gagal dibuat',
                    [
                        'purchase_request_id' => $pr->id,
                        'nomor_pr' => $pr->nomor_pr,
                        'message' => $notificationError->getMessage(),
                    ],
                );
            }

            try {
                $mailService->sendApprovalRequest($pr);
            } catch (\Throwable $mailError) {
                Log::error(
                    '[Purchase Requisition] Email approver gagal dikirim',
                    [
                        'purchase_request_id' => $pr->id,
                        'nomor_pr' => $pr->nomor_pr,
                        'message' => $mailError->getMessage(),
                    ],
                );
            }

            return response()->json([
                'success' => true,
                'message' => __('purchase_request_messages.submit.success'),
                'data' => [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id
                        ?? Crypt::encryptString((string) $pr->id),
                    'nomor_pr' => $pr->nomor_pr,
                    'status' => $pr->status,
                    'submitted_at' => $pr->submitted_at,
                    'submitted_by' => $pr->submitted_by,

                    // BARU: opsional agar mudah dicek dari response
                    'requester_signed_by' => $pr->requester_signed_by,
                    'requester_signature_path' => $pr->requester_signature_path,
                    'requester_signed_at' => $pr->requester_signed_at,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => collect($e->errors())
                    ->flatten()
                    ->first()
                    ?? 'Konfigurasi approval tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[Purchase Requisition] Submit error', [
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.submit.failed'),
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function generatePrintUrl(
        Request $request,
        string $publicId,
    ): JsonResponse {
        try {
            $lang = strtolower(
                trim(
                    (string) $request->query(
                        'lang',
                        'id',
                    ),
                ),
            );

            if (!in_array($lang, ['id', 'en'], true)) {
                $lang = 'id';
            }

            $id = (int) Crypt::decryptString(
                $publicId,
            );

            $pr = PurchaseRequest::query()
                ->findOrFail($id);

            /*
            |--------------------------------------------------------------------------
            | Validasi permission print PR jika ada
            |--------------------------------------------------------------------------
            */
            // abort_unless($userCanPrint, 403);

            $relativeUrl = URL::temporarySignedRoute(
                'transaction.purchase-request.print-signed',
                now()->addMinutes(10),
                [
                    'publicId' => $publicId,
                    'lang' => $lang,
                ],
                false,
            );

            $url = rtrim(config('app.url'), '/') . $relativeUrl;

            return response()
                ->json([
                    'success' => true,
                    'url' => $url,
                ])
                ->header('Content-Type', 'application/json; charset=UTF-8');
        } catch (DecryptException | ModelNotFoundException $e) {
            Log::warning('[Purchase Requisition] Generate print URL - invalid id', [
                'message' => $e->getMessage(),
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.print.not_found'),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] Generate print URL error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.print.url_failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function printSigned(
        Request $request,
        string $publicId,
    ) {
        try {
            return $this->print(
                $request,
                $publicId,
            );
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] Print signed error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.print.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function print(
        Request $request,
        string $publicId,
    ) {
        $lang = 'id';

        try {
            /*
        |--------------------------------------------------------------------------
        | Bahasa cetakan
        |--------------------------------------------------------------------------
        */
            $lang = strtolower(
                trim(
                    (string) $request->query(
                        'lang',
                        'id',
                    ),
                ),
            );

            if (!in_array($lang, ['id', 'en'], true)) {
                $lang = 'id';
            }

            app()->setLocale($lang);

            /*
        |--------------------------------------------------------------------------
        | Load Purchase Requisition
        |--------------------------------------------------------------------------
        */
            $id = Crypt::decryptString(
                $publicId,
            );

            $pr = PurchaseRequest::with([
                'cabangData:id,nama_cabang,inisial_cabang',

                'departmentData:id,kode,nama',

                'recommendedVendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',

                'items',

                'items.unit:id,kode,nama',

                'items.materialGroup:id,code,name',
                'specialDocumentType:id,code,name',

                'creator:id,name',

                'submitter:id,name',

                'approvals' => function ($query) {
                    $query
                        ->orderBy('step_order')
                        ->orderBy('id');
                },
            ])->findOrFail($id);

            /*
        |--------------------------------------------------------------------------
        | Hanya PR final approved yang boleh dicetak
        |--------------------------------------------------------------------------
        */
            $currentStatus = strtoupper(
                trim(
                    (string) $pr->status,
                ),
            );

            if (
                $currentStatus
                !== PurchaseRequest::STATUS_APPROVED
            ) {
                return response()->json([
                    'success' => false,

                    'message' => $lang === 'en'
                        ? 'Purchase Requisition can only be printed after final approval.'
                        : 'Purchase Requisition hanya dapat dicetak setelah final approval.',
                ], 422);
            }

            $items = $pr->getRelation('items');

            /*
        |--------------------------------------------------------------------------
        | Total amount
        |--------------------------------------------------------------------------
        */
            $totalAmount = (float) (
                $pr->total_amount
                ?? $items->sum(function ($item) {
                    $subtotal = (float) (
                        $item->subtotal
                        ?? 0
                    );

                    if ($subtotal > 0) {
                        return $subtotal;
                    }

                    return (float) (
                        $item->qty
                        ?? 0
                    ) * (float) (
                        $item->harga_unit
                        ?? 0
                    );
                })
            );

            /*
        |--------------------------------------------------------------------------
        | Terbilang
        |--------------------------------------------------------------------------
        */
            $terbilang = $lang === 'en'
                ? $this->terbilangRupiahEnglish(
                    $totalAmount,
                )
                : $this->terbilangRupiah(
                    $totalAmount,
                );

            /*
        |--------------------------------------------------------------------------
        | Approval yang sudah disetujui
        |--------------------------------------------------------------------------
        */
            $approvedApprovals = $pr->approvals
                ->filter(function ($approval) {
                    return strtoupper(
                        trim(
                            (string) $approval->status,
                        ),
                    ) === PurchaseRequestApproval::STATUS_APPROVED;
                })
                ->sortBy(function ($approval) {
                    return sprintf(
                        '%010d-%010d',
                        (int) $approval->step_order,
                        (int) $approval->id,
                    );
                })
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Requester
        |--------------------------------------------------------------------------
        */
            $requesterSigner = collect([
                (object) [
                    'type' => 'REQUESTER',

                    'label' => $lang === 'en'
                        ? 'Requester'
                        : 'Pemohon',

                    'name' => $pr->submitter?->name
                        ?? $pr->creator?->name
                        ?? '-',

                    'signature_path'
                    => $pr->requester_signature_path,

                    'signed_at'
                    => $pr->requester_signed_at
                        ?? $pr->submitted_at,
                ],
            ]);

            /*
        |--------------------------------------------------------------------------
        | Approvers
        |--------------------------------------------------------------------------
        */
            $approvalSigners = $approvedApprovals
                ->map(function ($approval) use ($lang) {
                    return (object) [
                        'type' => 'APPROVER',

                        'label' => $approval->label
                            ?? (
                                $lang === 'en'
                                ? 'Approver'
                                : 'Penyetuju'
                            ),

                        'name' => $approval
                            ->approver_name_snapshot
                            ?? '-',

                        'signature_path'
                        => $approval->signature_path,

                        'signed_at'
                        => $approval->approved_at
                            ?? $approval->signed_at,
                    ];
                });

            $signers = $requesterSigner
                ->concat($approvalSigners)
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Generate PDF
        |--------------------------------------------------------------------------
        */
            $pdf = Pdf::loadView(
                'pdf.purchase-request',
                [
                    'pr' => $pr,
                    'totalAmount' => $totalAmount,
                    'terbilang' => $terbilang,
                    'approvedApprovals' => $approvedApprovals,
                    'signers' => $signers,
                    'lang' => $lang,
                ],
            )->setPaper(
                'a4',
                'portrait',
            );

            /*
        |--------------------------------------------------------------------------
        | Nama file
        |--------------------------------------------------------------------------
        */
            $fileName = preg_replace(
                '/[^A-Za-z0-9._-]+/',
                '-',
                (string) $pr->nomor_pr,
            );

            $fileName = trim(
                (string) $fileName,
                '-',
            );

            if ($fileName === '') {
                $fileName = (string) $pr->id;
            }

            $downloadFileName = sprintf(
                'PR-%s-%s.pdf',
                $fileName,
                strtoupper($lang),
            );

            /*
        |--------------------------------------------------------------------------
        | Render PDF
        |--------------------------------------------------------------------------
        */
            $pdfOutput = $pdf->output();

            /*
        |--------------------------------------------------------------------------
        | Buat response PDF
        |--------------------------------------------------------------------------
        */
            $response = response()->make(
                $pdfOutput,
                200,
            );

            $response->headers->set(
                'Content-Type',
                'application/pdf',
            );

            $response->headers->set(
                'Content-Disposition',
                'inline; filename="' . $downloadFileName . '"',
            );

            $response->headers->set(
                'Content-Length',
                (string) strlen($pdfOutput),
            );

            $response->headers->set(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate, max-age=0',
            );

            $response->headers->set(
                'Pragma',
                'no-cache',
            );

            $response->headers->set(
                'Expires',
                '0',
            );

            $response->headers->set(
                'X-Content-Type-Options',
                'nosniff',
            );

            /*
        |--------------------------------------------------------------------------
        | Debug sementara
        |--------------------------------------------------------------------------
        | Digunakan untuk memastikan request menjalankan controller terbaru.
        */
            $response->headers->set(
                'X-PDF-Debug',
                'pr-controller-v4',
            );

            /*
        |--------------------------------------------------------------------------
        | Cek apakah PHP sudah mengirim output/header lebih dahulu
        |--------------------------------------------------------------------------
        */
            $headersSentFile = '';
            $headersSentLine = 0;

            $headersAlreadySent = headers_sent(
                $headersSentFile,
                $headersSentLine,
            );

            return $response;
        } catch (
            DecryptException |
            ModelNotFoundException $e
        ) {
            Log::warning('[Purchase Requisition] Print - invalid id', [
                'message' => $e->getMessage(),
                'public_id' => $publicId,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,

                'message' => $lang === 'en'
                    ? 'Purchase Requisition was not found.'
                    : 'Purchase Requisition tidak ditemukan.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error(
                '[Purchase Requisition] Print error',
                [
                    'public_id' => $publicId,
                    'lang' => $lang,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'user_id' => $request->user()?->id,
                ],
            );

            return response()->json([
                'success' => false,

                'message' => $lang === 'en'
                    ? 'Failed to print Purchase Requisition.'
                    : 'Gagal mencetak Purchase Requisition.',

                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    private function terbilangRupiahEnglish(
        float $amount,
    ): string {
        $number = (int) round($amount);

        if ($number === 0) {
            return 'Zero Rupiah';
        }

        return $this->numberToWordsEnglish(
            $number,
        ) . ' Rupiah';
    }

    private function numberToWordsEnglish(
        int $number,
    ): string {
        if ($number < 0) {
            return 'Minus '
                . $this->numberToWordsEnglish(
                    abs($number),
                );
        }

        $ones = [
            0 => '',
            1 => 'One',
            2 => 'Two',
            3 => 'Three',
            4 => 'Four',
            5 => 'Five',
            6 => 'Six',
            7 => 'Seven',
            8 => 'Eight',
            9 => 'Nine',
            10 => 'Ten',
            11 => 'Eleven',
            12 => 'Twelve',
            13 => 'Thirteen',
            14 => 'Fourteen',
            15 => 'Fifteen',
            16 => 'Sixteen',
            17 => 'Seventeen',
            18 => 'Eighteen',
            19 => 'Nineteen',
        ];

        $tens = [
            2 => 'Twenty',
            3 => 'Thirty',
            4 => 'Forty',
            5 => 'Fifty',
            6 => 'Sixty',
            7 => 'Seventy',
            8 => 'Eighty',
            9 => 'Ninety',
        ];

        if ($number < 20) {
            return $ones[$number];
        }

        if ($number < 100) {
            $tenValue = intdiv(
                $number,
                10,
            );

            $remainder = $number % 10;

            return trim(
                $tens[$tenValue]
                    . (
                        $remainder > 0
                        ? ' ' . $ones[$remainder]
                        : ''
                    ),
            );
        }

        if ($number < 1_000) {
            $hundreds = intdiv(
                $number,
                100,
            );

            $remainder = $number % 100;

            return trim(
                $ones[$hundreds]
                    . ' Hundred'
                    . (
                        $remainder > 0
                        ? ' '
                        . $this->numberToWordsEnglish(
                            $remainder,
                        )
                        : ''
                    ),
            );
        }

        $scales = [
            1_000_000_000_000 => 'Trillion',
            1_000_000_000 => 'Billion',
            1_000_000 => 'Million',
            1_000 => 'Thousand',
        ];

        foreach ($scales as $scaleValue => $scaleName) {
            if ($number >= $scaleValue) {
                $mainNumber = intdiv(
                    $number,
                    $scaleValue,
                );

                $remainder = $number % $scaleValue;

                return trim(
                    $this->numberToWordsEnglish(
                        $mainNumber,
                    )
                        . ' '
                        . $scaleName
                        . (
                            $remainder > 0
                            ? ' '
                            . $this->numberToWordsEnglish(
                                $remainder,
                            )
                            : ''
                        ),
                );
            }
        }

        return '';
    }

    /**
     * Pastikan seluruh master_material_group_id pada item benar-benar ada
     * dan masih aktif.
     *
     * Dijalankan sebagai satu query untuk semua item agar jumlah item yang
     * banyak tidak menimbulkan query N+1. Nilai yang sudah tidak aktif ikut
     * ditolak supaya PR baru tidak bisa memakai grup yang telah dinonaktifkan.
     */
    /*
    |--------------------------------------------------------------------------
    | Tipe Dokumen Khusus hanya boleh dipakai department yang berhak
    |--------------------------------------------------------------------------
    | Pembatasan di UI (pilihan tidak dimunculkan) hanya soal tampilan.
    | Pemeriksaan di sini yang menjadi penjaga sebenarnya, agar tipe tidak bisa
    | dipasang lewat pemanggilan API langsung oleh department yang tidak berhak.
    |
    | Mengembalikan ID yang sudah tervalidasi, atau NULL untuk dokumen biasa.
    |--------------------------------------------------------------------------
    */
    private function assertSpecialDocumentTypeAllowed(
        $specialDocumentTypeId,
        ?int $departmentId,
    ): ?int {
        $typeId = (int) ($specialDocumentTypeId ?? 0);

        if ($typeId <= 0) {
            return null;
        }

        $type = SpecialDocumentType::query()
            ->whereKey($typeId)
            ->first();

        if (!$type || !$type->isUsableByDepartment($departmentId)) {
            throw ValidationException::withMessages([
                'special_document_type_id' => [
                    __('purchase_request_messages.special_document_type.not_allowed'),
                ],
            ]);
        }

        return $type->id;
    }

    private function assertMaterialGroupsAreValid(array $items): void
    {
        $materialGroupIds = collect($items)
            ->pluck('master_material_group_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        if ($materialGroupIds->isEmpty()) {
            return;
        }

        $validIds = MasterMaterialGroup::query()
            ->whereIn('id', $materialGroupIds)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn($id) => (int) $id);

        $invalidIds = $materialGroupIds->diff($validIds);

        if ($invalidIds->isNotEmpty()) {
            throw new \Exception(
                'Material Group tidak valid atau sudah tidak aktif.'
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Visibility Scope Purchase Requisition
    |--------------------------------------------------------------------------
    | Dipakai bersama oleh index() dan exportExcel().
    |
    | Sengaja diekstrak agar tidak ada dua salinan aturan visibilitas. Kalau
    | keduanya sempat berbeda, user bisa mengekspor PR yang tidak boleh ia
    | lihat pada daftar -- kebocoran data yang sulit terdeteksi.
    |--------------------------------------------------------------------------
    */
    private function applyPurchaseRequestVisibilityScope(
        $query,
        $user,
        string $scope,
        $userRoleIds,
        $userAccessibleBranchIds,
        $userAccessibleDepartmentIds,
    ): void {
        if ($scope !== 'ALL') {
            $query->where(function ($visibilityQuery) use (
                $scope,
                $user,
                $userRoleIds,
                $userAccessibleBranchIds,
                $userAccessibleDepartmentIds,
            ) {
                /*
                |--------------------------------------------------------------------------
                | Scope data normal
                |--------------------------------------------------------------------------
                */
                $visibilityQuery->where(function ($scopeQuery) use (
                    $scope,
                    $user,
                    $userAccessibleBranchIds,
                    $userAccessibleDepartmentIds,
                ) {
                    /*
                    |--------------------------------------------------------------------------
                    | OWN_DATA
                    |--------------------------------------------------------------------------
                    | Tetap berdasarkan creator.
                    |--------------------------------------------------------------------------
                    */
                    if ($scope === 'OWN_DATA') {
                        if ($user->id) {
                            $scopeQuery->where(
                                'purchase_requests.created_by',
                                $user->id,
                            );
                        } else {
                            $scopeQuery->whereRaw('1 = 0');
                        }

                        return;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | OWN_DEPARTMENT
                    |--------------------------------------------------------------------------
                    | Scope department berarti user bisa melihat seluruh PR dari department
                    | yang sama, lintas semua cabang.
                    |
                    | Department diambil dari master user + access assignment tambahan.
                    |--------------------------------------------------------------------------
                    */
                    if ($scope === 'OWN_DEPARTMENT') {
                        if ($userAccessibleDepartmentIds->isEmpty()) {
                            $scopeQuery->whereRaw('1 = 0');

                            return;
                        }

                        $scopeQuery->whereIn(
                            'purchase_requests.id_department',
                            $userAccessibleDepartmentIds->all(),
                        );

                        return;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | OWN_CABANG
                    |--------------------------------------------------------------------------
                    | Sekarang berdasarkan semua branch dari assignment user.
                    |--------------------------------------------------------------------------
                    */
                    if ($scope === 'OWN_CABANG') {
                        if ($userAccessibleBranchIds->isEmpty()) {
                            $scopeQuery->whereRaw('1 = 0');

                            return;
                        }

                        $scopeQuery->whereIn(
                            'purchase_requests.cabang',
                            $userAccessibleBranchIds
                                ->map(fn($id) => (string) $id)
                                ->all(),
                        );

                        return;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | NONE / scope tidak valid
                    |--------------------------------------------------------------------------
                    */
                    $scopeQuery->whereRaw('1 = 0');
                });

                /*
                |--------------------------------------------------------------------------
                | Dokumen yang terkait dengan approval user
                |--------------------------------------------------------------------------
                | Tetap dipertahankan agar approver masih bisa melihat dokumen yang
                | ada dalam flow approval dirinya.
                |--------------------------------------------------------------------------
                */
                $visibilityQuery->orWhereHas(
                    'approvals',
                    function ($approvalQuery) use (
                        $user,
                        $userRoleIds,
                    ) {
                        $approvalQuery->where(function ($approverQuery) use (
                            $user,
                            $userRoleIds,
                        ) {
                            $approverQuery->where(function ($userQuery) use ($user) {
                                $userQuery
                                    ->where(
                                        'purchase_request_approvals.approver_type',
                                        PurchaseRequestApproval::APPROVER_TYPE_USER,
                                    )
                                    ->where(
                                        'purchase_request_approvals.approver_id',
                                        $user->id,
                                    );
                            });

                            if ($userRoleIds->isNotEmpty()) {
                                $approverQuery->orWhere(function ($roleQuery) use (
                                    $userRoleIds,
                                ) {
                                    $roleQuery
                                        ->where(
                                            'purchase_request_approvals.approver_type',
                                            PurchaseRequestApproval::APPROVER_TYPE_ROLE,
                                        )
                                        ->whereIn(
                                            'purchase_request_approvals.approver_id',
                                            $userRoleIds->all(),
                                        );
                                });
                            }
                        });
                    },
                );
            });
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Konteks visibilitas user
    |--------------------------------------------------------------------------
    | Mengembalikan scope permission beserta role dan cabang/department yang
    | boleh diakses user. Dipakai bersama index() dan exportExcel().
    |--------------------------------------------------------------------------
    */
    private function resolvePurchaseRequestViewContext($user): array
    {
        $scope = strtoupper(
            trim(
                (string) (
                    $user->getPermissionScope(
                        'purchase_request.view',
                    ) ?? 'NONE'
                ),
            ),
        );

        $allowedScopes = [
            'NONE',
            'OWN_DATA',
            'OWN_DEPARTMENT',
            'OWN_CABANG',
            'ALL',
        ];

        if (!in_array($scope, $allowedScopes, true)) {
            $scope = 'NONE';
        }

        $userRoleIds = collect();

        if ($user->getAttribute('role_id')) {
            $userRoleIds->push(
                (int) $user->getAttribute('role_id'),
            );
        }

        $activeRoleId = $user->getActiveRoleId();

        if ($activeRoleId) {
            $userRoleIds->push(
                (int) $activeRoleId,
            );
        }

        $pivotRoleIds = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->pluck('role_id');

        $userRoleIds = $userRoleIds
            ->merge($pivotRoleIds)
            ->filter(
                fn($roleId) =>
                $roleId !== null
                    && (int) $roleId > 0,
            )
            ->map(
                fn($roleId) => (int) $roleId,
            )
            ->unique()
            ->values();

        $userAccessAssignments = $this->getActiveUserAccessAssignments(
            $user,
        );

        $userAccessibleBranchIds = collect([
            (int) ($user->cabang_id ?? 0),
        ])
            ->merge(
                $userAccessAssignments
                    ->pluck('branch_id')
                    ->map(fn($id) => (int) $id),
            )
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        $userAccessibleDepartmentIds = collect([
            (int) ($user->departemen_id ?? 0),
        ])
            ->merge(
                $userAccessAssignments
                    ->pluck('department_id')
                    ->map(fn($id) => (int) $id),
            )
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        return [
            'scope' => $scope,
            'userRoleIds' => $userRoleIds,
            'userAccessibleBranchIds' => $userAccessibleBranchIds,
            'userAccessibleDepartmentIds' => $userAccessibleDepartmentIds,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Filter daftar Purchase Requisition
    |--------------------------------------------------------------------------
    | Dipakai bersama index() dan exportExcel() supaya hasil export selalu
    | sama persis dengan apa yang sedang dilihat user pada daftar.
    |--------------------------------------------------------------------------
    */
    private function applyPurchaseRequestListFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            if ($search !== '') {
                $searchLike = "%{$search}%";

                $query->where(function ($q) use ($searchLike) {
                    $q->where('purchase_requests.nomor_pr', 'ILIKE', $searchLike)
                        ->orWhere('purchase_requests.kategori', 'ILIKE', $searchLike)
                        ->orWhere('purchase_requests.notes', 'ILIKE', $searchLike)
                        ->orWhereHas('purchaseOrders', function ($poQuery) use ($searchLike) {
                            $poQuery->where('purchase_orders.nomor_po', 'ILIKE', $searchLike);
                        })
                        ->orWhereHas('departmentData', function ($deptQuery) use ($searchLike) {
                            $deptQuery->where('kode', 'ILIKE', $searchLike)
                                ->orWhere('nama', 'ILIKE', $searchLike);
                        })
                        ->orWhereExists(function ($cabangQuery) use ($searchLike) {
                            $cabangQuery->select(DB::raw(1))
                                ->from('cabang')
                                ->whereRaw('CAST(cabang.id AS VARCHAR) = purchase_requests.cabang')
                                ->where(function ($sub) use ($searchLike) {
                                    $sub->where('cabang.nama_cabang', 'ILIKE', $searchLike)
                                        ->orWhere('cabang.inisial_cabang', 'ILIKE', $searchLike);
                                });
                        });
                });
            }
        }

        if ($request->filled('tanggal_mulai')) {
            $query->whereDate(
                'purchase_requests.tanggal_pr',
                '>=',
                $request->input('tanggal_mulai'),
            );
        }

        if ($request->filled('tanggal_selesai')) {
            $query->whereDate(
                'purchase_requests.tanggal_pr',
                '<=',
                $request->input('tanggal_selesai'),
            );
        }

        if ($request->filled('status')) {
            $status = strtoupper(trim((string) $request->input('status')));

            if ($status !== '' && !in_array($status, ['ALL', 'SEMUA'], true)) {
                $query->where('purchase_requests.status', $status);
            }
        }

        if ($request->filled('status_po')) {
            $statusPo = strtoupper(trim((string) $request->input('status_po')));

            if ($statusPo !== '' && !in_array($statusPo, ['ALL', 'SEMUA'], true)) {
                if (in_array($statusPo, ['NULL', 'NONE', 'BELUM_PO', 'BELUM PO'], true)) {
                    $query->whereNull('purchase_requests.status_po');
                } else {
                    $query->where('purchase_requests.status_po', $statusPo);
                }
            }
        }

        if ($request->filled('id_department')) {
            $query->where(
                'purchase_requests.id_department',
                (int) $request->input('id_department'),
            );
        }

        if ($request->filled('cabang')) {
            $query->where(
                'purchase_requests.cabang',
                (string) $request->input('cabang'),
            );
        }
    }

    private function terbilangRupiah(float $angka): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->terbilang($angka))) . ' Rupiah';
    }

    private function terbilang($angka): string
    {
        $angka = abs((int) $angka);
        $huruf = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];

        if ($angka < 12) {
            return $huruf[$angka];
        }

        if ($angka < 20) {
            return $this->terbilang($angka - 10) . ' Belas';
        }

        if ($angka < 100) {
            return $this->terbilang($angka / 10) . ' Puluh ' . $this->terbilang($angka % 10);
        }

        if ($angka < 200) {
            return 'Seratus ' . $this->terbilang($angka - 100);
        }

        if ($angka < 1000) {
            return $this->terbilang($angka / 100) . ' Ratus ' . $this->terbilang($angka % 100);
        }

        if ($angka < 2000) {
            return 'Seribu ' . $this->terbilang($angka - 1000);
        }

        if ($angka < 1000000) {
            return $this->terbilang($angka / 1000) . ' Ribu ' . $this->terbilang($angka % 1000);
        }

        if ($angka < 1000000000) {
            return $this->terbilang($angka / 1000000) . ' Juta ' . $this->terbilang($angka % 1000000);
        }

        return $this->terbilang($angka / 1000000000) . ' Miliar ' . $this->terbilang($angka % 1000000000);
    }

    public function prByVendor($vendorId)
    {
        try {
            $prs = PurchaseRequest::where('status', 'APPROVED')
                ->whereHas('vendors', function ($q) use ($vendorId) {
                    $q->where('vendor_id', $vendorId);
                })
                ->with([
                    'vendors' => function ($q) use ($vendorId) {
                        $q->where('vendor_id', $vendorId)
                            ->select(
                                'id',
                                'purchase_request_id',
                                'vendor_id',
                                'is_selected',
                                'dpp',
                                'ppn',
                                'price_offer'
                            )
                            ->with([
                                'items' => function ($qi) {
                                    $qi->select(
                                        'id',
                                        'pr_vendor_id',
                                        'nama_item',
                                        'qty',
                                        'satuan',
                                        'keterangan',
                                        'harga_unit',
                                        'subtotal'
                                    );
                                }
                            ]);
                    }
                ])
                ->orderBy('created_at', 'desc')
                ->get([
                    'id',
                    'nomor_pr',
                    'tanggal_pr',
                    'cabang',
                    'id_department',
                    'total_amount'
                ]);

            return response()->json($prs);
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] PR by vendor error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'vendor_id' => $vendorId,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.vendor.load_failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function dropdownApproved(
        Request $request,
    ): JsonResponse {
        try {
            $user = $request->user();

            /*
        |--------------------------------------------------------------------------
        | Permission Create PO
        |--------------------------------------------------------------------------
        */
            if (
                !$user
                || !$user->hasPermission(
                    'purchase_order.create',
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.dropdown_approved.forbidden'),
                    'data' => [],
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */
            $validated = $request->validate([
                'cabang' => [
                    'required',
                    'integer',
                    'min:1',
                ],

                'id_department' => [
                    'required',
                    'integer',
                    'min:1',
                ],
            ]);

            $cabangId = (int) $validated['cabang'];
            $departmentId = (int) $validated['id_department'];

            /*
        |--------------------------------------------------------------------------
        | Department Scope Validation
        |--------------------------------------------------------------------------
        | Cabang tidak dibatasi.
        | Department wajib sesuai scope purchase_order.create.
        |--------------------------------------------------------------------------
        */
            if (
                !$user->canAccessDepartmentForPermission(
                    'purchase_order.create',
                    $departmentId,
                )
            ) {
                return response()->json([
                    'success' => false,
                    'message' => __('purchase_request_messages.dropdown_approved.department_forbidden'),
                    'data' => [],
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Approved Purchase Requests
        |--------------------------------------------------------------------------
        */
            $prs = PurchaseRequest::query()
                ->with([
                    'cabangData:id,nama_cabang,inisial_cabang',

                    'departmentData:id,kode,nama',

                    'recommendedVendor:id,nama_vendor,status_pkp,jenis_pembayaran,top',

                    'attachments',

                    'items' => function ($query) {
                        $query
                            ->with(
                                'unit:id,kode,nama',
                            )
                            ->whereNull('deleted_at')
                            ->whereRaw(
                                '(COALESCE(qty_outstanding, qty - COALESCE(qty_po, 0)) > 0)',
                            );
                    },
                ])
                ->where(
                    'cabang',
                    $cabangId,
                )
                ->where(
                    'id_department',
                    $departmentId,
                )
                ->whereRaw(
                    'UPPER(status) = ?',
                    ['APPROVED'],
                )
                ->where(function ($query) {
                    $query
                        ->whereNull('status_po')
                        ->orWhereRaw(
                            'UPPER(status_po) IN (?, ?)',
                            [
                                'OPEN',
                                'PARTIAL',
                            ],
                        );
                })
                ->whereHas(
                    'items',
                    function ($query) {
                        $query
                            ->whereNull('deleted_at')
                            ->whereRaw(
                                '(COALESCE(qty_outstanding, qty - COALESCE(qty_po, 0)) > 0)',
                            );
                    },
                )
                ->orderByDesc('id')
                ->get();

            $data = $prs
                ->map(function (
                    PurchaseRequest $pr,
                ) {
                    $items = $pr->items
                        ->filter(function ($item) {
                            $qty = (float) (
                                $item->qty ?? 0
                            );

                            $qtyPo = (float) (
                                $item->qty_po ?? 0
                            );

                            $qtyOutstanding
                                = $item->qty_outstanding !== null
                                ? (float) $item->qty_outstanding
                                : $qty - $qtyPo;

                            return $qtyOutstanding > 0;
                        })
                        ->map(function ($item) {
                            $qty = (float) (
                                $item->qty ?? 0
                            );

                            $qtyPo = (float) (
                                $item->qty_po ?? 0
                            );

                            $hargaUnit = (float) (
                                $item->harga_unit ?? 0
                            );

                            $qtyOutstanding
                                = $item->qty_outstanding !== null
                                ? (float) $item->qty_outstanding
                                : $qty - $qtyPo;

                            $qtyOutstanding = max(
                                $qtyOutstanding,
                                0,
                            );

                            return [
                                'id' => (int) $item->id,

                                'purchase_request_id'
                                => (int) $item->purchase_request_id,

                                'nama_item'
                                => $item->nama_item,

                                'qty' => $qty,

                                'qty_po' => $qtyPo,

                                'qty_outstanding'
                                => $qtyOutstanding,

                                'satuan_id'
                                => $item->satuan
                                    ? (int) $item->satuan
                                    : null,

                                'satuan' => [
                                    'id' => $item->unit?->id
                                        ? (int) $item->unit->id
                                        : null,

                                    'kode'
                                    => $item->unit?->kode
                                        ?? '-',

                                    'nama'
                                    => $item->unit?->nama
                                        ?? '-',
                                ],

                                'harga_unit'
                                => $hargaUnit,

                                'subtotal'
                                => (float) (
                                    $item->subtotal
                                    ?? 0
                                ),

                                'subtotal_po'
                                => $qtyPo
                                    * $hargaUnit,

                                'subtotal_outstanding'
                                => $qtyOutstanding
                                    * $hargaUnit,

                                'keterangan'
                                => $item->keterangan,
                            ];
                        })
                        ->values();

                    $totalOutstanding = $items->sum(
                        'subtotal_outstanding',
                    );

                    return [
                        'id' => (int) $pr->id,

                        'public_id'
                        => $pr->encrypted_id,

                        'nomor_pr'
                        => $pr->nomor_pr,

                        'tanggal_pr'
                        => $pr->tanggal_pr,

                        'cabang_id'
                        => (int) $pr->cabang,

                        'cabang'
                        => $pr->cabangData
                            ? (
                                $pr->cabangData
                                ->inisial_cabang
                                ?? '-'
                            )
                            : '-',

                        'department_id'
                        => (int) $pr->id_department,

                        'department'
                        => $pr->departmentData
                            ? (
                                $pr->departmentData
                                ->kode
                                ?? '-'
                            )
                            : '-',

                        'status'
                        => $pr->status,

                        'status_po'
                        => $pr->status_po,

                        'total_amount'
                        => (float) (
                            $pr->total_amount
                            ?? 0
                        ),

                        'total_outstanding'
                        => (float) $totalOutstanding,

                        'attachments'
                        => $pr->attachments
                            ->map(function ($attachment) {
                                return [
                                    'id'
                                    => (int) $attachment->id,

                                    'filename'
                                    => $attachment->filename,

                                    'original_filename'
                                    => $attachment
                                        ->original_filename,

                                    'filepath'
                                    => asset(
                                        'storage/'
                                            . $attachment->filepath,
                                    ),

                                    'file_size'
                                    => $attachment->file_size,

                                    'mime_type'
                                    => $attachment->mime_type,
                                ];
                            })
                            ->values(),

                        'items' => $items,

                        'recommended_vendor_id'
                        => $pr->recommended_vendor_id
                            ? (int) $pr
                                ->recommended_vendor_id
                            : null,

                        'recommended_vendor'
                        => $pr->recommendedVendor
                            ? [
                                'id'
                                => (int) $pr
                                    ->recommendedVendor
                                    ->id,

                                'nama_vendor'
                                => $pr
                                    ->recommendedVendor
                                    ->nama_vendor
                                    ?? '-',

                                'status_pkp'
                                => $pr
                                    ->recommendedVendor
                                    ->status_pkp
                                    ?? 'NON_PKP',

                                'jenis_pembayaran'
                                => $pr
                                    ->recommendedVendor
                                    ->jenis_pembayaran,

                                'top'
                                => $pr
                                    ->recommendedVendor
                                    ->top,
                            ]
                            : null,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'message' => __('purchase_request_messages.dropdown_approved.loaded'),
                'data' => $data,
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error(
                '[Purchase Requisition] dropdownApproved error',
                [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'request' => $request->all(),
                    'user_id' => $request->user()?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.dropdown_approved.load_failed'),
                'data' => [],
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function cancel(
        Request $request,
        string $publicId,
        PurchaseRequestRollbackService $rollbackService,
    ): JsonResponse {
        $user = $request->user();

        if (!$user || !$user->hasPermission('purchase_request.cancel')) {
            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.cancel.forbidden'),
            ], 403);
        }

        try {
            $validated = $request->validate([
                'cancel_notes' => ['required', 'string', 'max:2000'],
            ]);

            $id = Crypt::decryptString($publicId);

            $pr = PurchaseRequest::with(['purchaseOrders'])->findOrFail($id);

            $rollbackService->cancel(
                $pr,
                $user,
                $validated['cancel_notes'],
            );

            $pr->refresh();

            return response()->json([
                'success' => true,
                'message' => __('purchase_request_messages.cancel.success'),

                'data' => [
                    'id' => $pr->id,
                    'public_id' => $pr->encrypted_id,
                    'nomor_pr' => $pr->nomor_pr,
                    'status' => $pr->status,
                    'cancelled_by' => $pr->cancelled_by,
                    'cancelled_at' => $pr->cancelled_at,
                    'cancel_notes' => $pr->cancel_notes,
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first()
                    ?? __('purchase_request_messages.cancel.failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (DecryptException $e) {
            Log::warning('[Purchase Requisition] Cancel - invalid id', [
                'message' => $e->getMessage(),
                'public_id' => $publicId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.cancel.invalid_id'),
            ], 422);
        } catch (ModelNotFoundException $e) {
            Log::warning('[Purchase Requisition] Cancel - not found', [
                'message' => $e->getMessage(),
                'public_id' => $publicId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.cancel.not_found'),
            ], 404);
        } catch (\Exception $e) {
            Log::warning('[Purchase Requisition] Cancel - invalid status', [
                'message' => $e->getMessage(),
                'public_id' => $publicId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[Purchase Requisition] Cancel error', [
                'public_id' => $publicId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('purchase_request_messages.cancel.failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
