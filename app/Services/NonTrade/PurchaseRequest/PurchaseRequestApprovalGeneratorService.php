<?php

namespace App\Services\NonTrade\PurchaseRequest;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestApproval;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PurchaseRequestApprovalGeneratorService
{
    public function generate(
        PurchaseRequest $purchaseRequest,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Cegah approval tergenerate dua kali
        |--------------------------------------------------------------------------
        */
        $alreadyExists = PurchaseRequestApproval::query()
            ->where(
                'purchase_request_id',
                $purchaseRequest->id,
            )
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Approval Purchase Request sudah pernah dibuat.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Hitung nominal PR
        |--------------------------------------------------------------------------
        */
        $totalAmount = $this->calculateTotalAmount(
            $purchaseRequest,
        );

        if ($totalAmount <= 0) {
            throw ValidationException::withMessages([
                'total_amount' => [
                    'Total nilai Purchase Request harus lebih besar dari 0.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Cari flow yang sesuai
        |--------------------------------------------------------------------------
        */
        $approvalFlow = $this->findMatchingFlow(
            $purchaseRequest,
            $totalAmount,
        );

        if (!$approvalFlow) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approval flow Purchase Request tidak ditemukan untuk nominal Rp %s.',
                        number_format(
                            $totalAmount,
                            0,
                            ',',
                            '.',
                        ),
                    ),
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Ambil seluruh step flow beserta mapping cabang tertentu
        |--------------------------------------------------------------------------
        */
        $flowSteps = ApprovalFlowStep::query()
            ->with('branchMappings')
            ->where(
                'approval_flow_id',
                $approvalFlow->id,
            )
            ->orderBy('step_order')
            ->orderBy('id')
            ->get();

        if ($flowSteps->isEmpty()) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Approval flow ditemukan, tetapi belum memiliki approver.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Tentukan step pertama
        |--------------------------------------------------------------------------
        */
        $firstStepOrder = (int) $flowSteps
            ->min('step_order');

        /*
        |--------------------------------------------------------------------------
        | Menyimpan logical step yang wajib memiliki approver
        |--------------------------------------------------------------------------
        */
        $requiredSteps = [];

        /*
        |--------------------------------------------------------------------------
        | Menghitung snapshot approver yang berhasil dibuat per logical step
        |--------------------------------------------------------------------------
        */
        $createdApproversPerStep = [];

        /*
        |--------------------------------------------------------------------------
        | Mencegah snapshot approver yang sama tergenerate dua kali pada step sama
        |--------------------------------------------------------------------------
        | Contoh:
        | - User dipilih langsung dan juga ter-resolve dari role.
        | - User mempunyai lebih dari satu role pada logical step yang sama.
        |--------------------------------------------------------------------------
        */
        $resolvedSnapshotKeys = [];

        foreach ($flowSteps as $flowStep) {
            $stepOrder = (int) $flowStep->step_order;

            $requiredSteps[$stepOrder] = $requiredSteps[$stepOrder]
                ?? ($flowStep->label ?: sprintf('Step %d', $stepOrder));

            $approverType = strtoupper(
                trim((string) $flowStep->approver_type),
            );

            $approverScope = strtoupper(
                trim(
                    (string) (
                        $flowStep->approver_scope
                        ?: ApprovalFlowStep::APPROVER_SCOPE_GLOBAL
                    ),
                ),
            );

            $approvalMode = strtoupper(
                trim(
                    (string) (
                        $flowStep->approval_mode
                        ?: ApprovalFlowStep::APPROVAL_MODE_ANY
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

            $status = $stepOrder === $firstStepOrder
                ? PurchaseRequestApproval::STATUS_WAITING
                : PurchaseRequestApproval::STATUS_PENDING;

            /*
            |--------------------------------------------------------------------------
            | SAME_BRANCH
            |--------------------------------------------------------------------------
            | USER: user hanya dipilih jika cabang akun sama dengan cabang PR.
            | ROLE: seluruh user dengan role tersebut pada cabang PR.
            |--------------------------------------------------------------------------
            */
            if (
                $approverScope
                === ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH
            ) {
                $resolvedUsers = $this->resolveSameBranchApprovers(
                    $flowStep,
                    $purchaseRequest,
                    $approverType,
                );

                $this->createResolvedUserSnapshots(
                    purchaseRequest: $purchaseRequest,
                    approvalFlow: $approvalFlow,
                    flowStep: $flowStep,
                    resolvedUsers: $resolvedUsers,
                    stepOrder: $stepOrder,
                    approvalMode: $approvalMode,
                    status: $status,
                    resolvedSnapshotKeys: $resolvedSnapshotKeys,
                    createdApproversPerStep: $createdApproversPerStep,
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | SELECTED_BRANCHES
            |--------------------------------------------------------------------------
            | Mapping menentukan cabang dokumen yang boleh menggunakan approver.
            | Cabang akun approver tidak digunakan sebagai filter.
            |
            | Contoh:
            | - Sony akun HO, mapping Palembang/Banjarmasin/Pontianak.
            | - Freza akun Samarinda, mapping cabang Sulawesi.
            |--------------------------------------------------------------------------
            */
            if (
                $approverScope
                === ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES
            ) {
                $resolvedUsers = $this->resolveSelectedBranchApprovers(
                    $flowStep,
                    $purchaseRequest,
                    $approverType,
                );

                $this->createResolvedUserSnapshots(
                    purchaseRequest: $purchaseRequest,
                    approvalFlow: $approvalFlow,
                    flowStep: $flowStep,
                    resolvedUsers: $resolvedUsers,
                    stepOrder: $stepOrder,
                    approvalMode: $approvalMode,
                    status: $status,
                    resolvedSnapshotKeys: $resolvedSnapshotKeys,
                    createdApproversPerStep: $createdApproversPerStep,
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | GLOBAL
            |--------------------------------------------------------------------------
            | Mempertahankan mekanisme existing:
            | - USER disimpan sebagai USER.
            | - ROLE disimpan sebagai ROLE.
            |--------------------------------------------------------------------------
            */
            if (
                $approverScope
                !== ApprovalFlowStep::APPROVER_SCOPE_GLOBAL
            ) {
                throw ValidationException::withMessages([
                    'approval_flow' => [
                        sprintf(
                            'Approver scope "%s" pada step "%s" tidak didukung.',
                            $approverScope ?: '-',
                            $flowStep->label ?? '-',
                        ),
                    ],
                ]);
            }

            $approverId = (int) $flowStep->approver_id;

            if ($approverId <= 0) {
                throw ValidationException::withMessages([
                    'approval_flow' => [
                        sprintf(
                            'Approver pada step "%s" belum dikonfigurasi.',
                            $flowStep->label ?? '-',
                        ),
                    ],
                ]);
            }

            if (
                !in_array(
                    $approverType,
                    [
                        PurchaseRequestApproval::APPROVER_TYPE_USER,
                        PurchaseRequestApproval::APPROVER_TYPE_ROLE,
                    ],
                    true,
                )
            ) {
                throw ValidationException::withMessages([
                    'approval_flow' => [
                        sprintf(
                            'Tipe approver "%s" pada step "%s" tidak didukung.',
                            $approverType ?: '-',
                            $flowStep->label ?? '-',
                        ),
                    ],
                ]);
            }

            $snapshotKey = sprintf(
                '%d-%s-%d',
                $stepOrder,
                $approverType,
                $approverId,
            );

            if (isset($resolvedSnapshotKeys[$snapshotKey])) {
                continue;
            }

            $resolvedSnapshotKeys[$snapshotKey] = true;

            PurchaseRequestApproval::create([
                'purchase_request_id' => $purchaseRequest->id,

                'approval_flow_id' => $approvalFlow->id,

                'approval_flow_step_id' => $flowStep->id,

                'step_order' => $stepOrder,

                'label' => $flowStep->label,

                'approver_type' => $approverType,

                'approver_id' => $approverId,

                'approver_name_snapshot'
                => $this->resolveApproverName(
                    $flowStep,
                ),

                'approval_mode' => $approvalMode,

                'status' => $status,
            ]);

            $createdApproversPerStep[$stepOrder] = (
                $createdApproversPerStep[$stepOrder]
                ?? 0
            ) + 1;
        }

        /*
        |--------------------------------------------------------------------------
        | Pastikan setiap logical step mempunyai minimal satu approver
        |--------------------------------------------------------------------------
        | Validasi dilakukan setelah semua kandidat pada step yang sama diperiksa.
        | Kandidat dari cabang lain cukup dilewati.
        |--------------------------------------------------------------------------
        */
        foreach (
            $requiredSteps as $requiredStepOrder => $requiredStepLabel
        ) {
            $approverCount = (int) (
                $createdApproversPerStep[$requiredStepOrder]
                ?? 0
            );

            if ($approverCount > 0) {
                continue;
            }

            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approver untuk step "%s" pada cabang Purchase Requisition belum ditemukan.',
                        $requiredStepLabel,
                    ),
                ],
            ]);
        }
    }

    /**
     * Membuat snapshot USER konkret dari hasil resolver scope cabang.
     */
    private function createResolvedUserSnapshots(
        PurchaseRequest $purchaseRequest,
        ApprovalFlow $approvalFlow,
        ApprovalFlowStep $flowStep,
        Collection $resolvedUsers,
        int $stepOrder,
        string $approvalMode,
        string $status,
        array &$resolvedSnapshotKeys,
        array &$createdApproversPerStep,
    ): void {
        foreach ($resolvedUsers as $resolvedUser) {
            $userId = (int) $resolvedUser->id;

            if ($userId <= 0) {
                continue;
            }

            $snapshotKey = sprintf(
                '%d-%s-%d',
                $stepOrder,
                PurchaseRequestApproval::APPROVER_TYPE_USER,
                $userId,
            );

            if (isset($resolvedSnapshotKeys[$snapshotKey])) {
                continue;
            }

            $resolvedSnapshotKeys[$snapshotKey] = true;

            PurchaseRequestApproval::create([
                'purchase_request_id' => $purchaseRequest->id,

                'approval_flow_id' => $approvalFlow->id,

                'approval_flow_step_id' => $flowStep->id,

                'step_order' => $stepOrder,

                'label' => $flowStep->label,

                /*
                |--------------------------------------------------------------------------
                | Hasil resolver disimpan sebagai USER konkret
                |--------------------------------------------------------------------------
                */
                'approver_type'
                => PurchaseRequestApproval::APPROVER_TYPE_USER,

                'approver_id'
                => $userId,

                'approver_name_snapshot'
                => $resolvedUser->name
                    ?? $resolvedUser->fullname
                    ?? null,

                'approval_mode'
                => $approvalMode,

                'status'
                => $status,
            ]);

            $createdApproversPerStep[$stepOrder] = (
                $createdApproversPerStep[$stepOrder]
                ?? 0
            ) + 1;
        }
    }

    private function resolveSameBranchApprovers(
        ApprovalFlowStep $flowStep,
        PurchaseRequest $purchaseRequest,
        string $approverType,
    ): Collection {
        /*
        |--------------------------------------------------------------------------
        | Cabang dokumen PR
        |--------------------------------------------------------------------------
        */
        $branchId = (int) $purchaseRequest->cabang;

        /*
        |--------------------------------------------------------------------------
        | ID dapat berisi user_id atau role_id, tergantung approver_type
        |--------------------------------------------------------------------------
        */
        $approverId = (int) $flowStep->approver_id;

        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'cabang' => [
                    'Cabang Purchase Requisition belum ditentukan.',
                ],
            ]);
        }

        if ($approverId <= 0) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approver untuk step "%s" belum dikonfigurasi.',
                        $flowStep->label ?? '-',
                    ),
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | USER + SAME_BRANCH
        |--------------------------------------------------------------------------
        | User hanya dikembalikan apabila cabangnya sama dengan cabang PR.
        | Jika berbeda cabang, hasilnya collection kosong dan user dilewati.
        |--------------------------------------------------------------------------
        */
        if (
            $approverType
            === PurchaseRequestApproval::APPROVER_TYPE_USER
        ) {
            return User::query()
                ->whereKey($approverId)
                ->where('users.cabang_id', $branchId)
                ->get();
        }

        /*
        |--------------------------------------------------------------------------
        | ROLE + SAME_BRANCH
        |--------------------------------------------------------------------------
        | Cari seluruh user dengan role tersebut pada cabang dokumen.
        |--------------------------------------------------------------------------
        */
        if (
            $approverType
            === PurchaseRequestApproval::APPROVER_TYPE_ROLE
        ) {
            return User::query()
                ->where('users.cabang_id', $branchId)
                ->whereHas(
                    'roles',
                    function ($roleQuery) use ($approverId) {
                        $roleQuery->where(
                            'roles.id',
                            $approverId,
                        );
                    },
                )
                ->get();
        }

        throw ValidationException::withMessages([
            'approval_flow' => [
                sprintf(
                    'Tipe approver "%s" pada step "%s" tidak didukung.',
                    $approverType ?: '-',
                    $flowStep->label ?? '-',
                ),
            ],
        ]);
    }

    private function resolveSelectedBranchApprovers(
        ApprovalFlowStep $flowStep,
        PurchaseRequest $purchaseRequest,
        string $approverType,
    ): Collection {
        $branchId = (int) $purchaseRequest->cabang;
        $approverId = (int) $flowStep->approver_id;

        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'cabang' => [
                    'Cabang Purchase Requisition belum ditentukan.',
                ],
            ]);
        }

        if ($approverId <= 0) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approver untuk step "%s" belum dikonfigurasi.',
                        $flowStep->label ?? '-',
                    ),
                ],
            ]);
        }

        $branchIds = $flowStep
            ->branchMappings
            ->pluck('cabang_id')
            ->map(
                fn($mappedBranchId) => (int) $mappedBranchId,
            )
            ->filter(
                fn(int $mappedBranchId) => $mappedBranchId > 0,
            )
            ->unique()
            ->values();

        if ($branchIds->isEmpty()) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Cabang yang ditangani untuk approver pada step "%s" belum dikonfigurasi.',
                        $flowStep->label ?? '-',
                    ),
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Cabang PR tidak termasuk cakupan approver
        |--------------------------------------------------------------------------
        | Bukan error. Kandidat ini cukup dilewati karena mungkin ada approver lain
        | pada logical step yang sama yang menangani cabang tersebut.
        |--------------------------------------------------------------------------
        */
        if (!$branchIds->contains($branchId)) {
            return collect();
        }

        /*
        |--------------------------------------------------------------------------
        | USER + SELECTED_BRANCHES
        |--------------------------------------------------------------------------
        | Cabang akun user tidak diperiksa. Mapping cabang pada flow step menjadi
        | sumber kebenaran cakupan dokumen.
        |--------------------------------------------------------------------------
        */
        if (
            $approverType
            === PurchaseRequestApproval::APPROVER_TYPE_USER
        ) {
            return User::query()
                ->whereKey($approverId)
                ->get();
        }

        /*
        |--------------------------------------------------------------------------
        | ROLE + SELECTED_BRANCHES
        |--------------------------------------------------------------------------
        | Ketika cabang PR termasuk mapping, resolve seluruh user dengan role itu.
        | Cabang akun user tidak digunakan sebagai filter.
        |--------------------------------------------------------------------------
        */
        if (
            $approverType
            === PurchaseRequestApproval::APPROVER_TYPE_ROLE
        ) {
            return User::query()
                ->whereHas(
                    'roles',
                    function ($roleQuery) use ($approverId) {
                        $roleQuery->where(
                            'roles.id',
                            $approverId,
                        );
                    },
                )
                ->get();
        }

        throw ValidationException::withMessages([
            'approval_flow' => [
                sprintf(
                    'Tipe approver "%s" pada step "%s" tidak didukung.',
                    $approverType ?: '-',
                    $flowStep->label ?? '-',
                ),
            ],
        ]);
    }

    private function calculateTotalAmount(
        PurchaseRequest $purchaseRequest,
    ): float {
        $purchaseRequest->loadMissing('items');

        /*
        |--------------------------------------------------------------------------
        | Gunakan total header jika tersedia
        |--------------------------------------------------------------------------
        */
        $headerTotal = (float) (
            $purchaseRequest->total_nilai
            ?? $purchaseRequest->grand_total
            ?? $purchaseRequest->total_amount
            ?? 0
        );

        if ($headerTotal > 0) {
            return $headerTotal;
        }

        /*
        |--------------------------------------------------------------------------
        | Gunakan subtotal item jika tersedia
        |--------------------------------------------------------------------------
        */
        $subtotal = (float) $purchaseRequest
            ->items
            ->sum(function ($item) {
                return (float) (
                    $item->subtotal
                    ?? $item->total
                    ?? 0
                );
            });

        if ($subtotal > 0) {
            return $subtotal;
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback qty × harga
        |--------------------------------------------------------------------------
        */
        return (float) $purchaseRequest
            ->items
            ->sum(function ($item) {
                $qty = (float) (
                    $item->qty
                    ?? $item->quantity
                    ?? 0
                );

                $price = (float) (
                    $item->harga
                    ?? $item->harga_satuan
                    ?? $item->unit_price
                    ?? 0
                );

                return $qty * $price;
            });
    }

    private function findMatchingFlow(
        PurchaseRequest $purchaseRequest,
        float $totalAmount,
    ): ?ApprovalFlow {
        /*
        |--------------------------------------------------------------------------
        | Tentukan area berdasarkan cabang requester
        |--------------------------------------------------------------------------
        | cabang ID 1 = HO
        | selain ID 1 = CABANG
        |--------------------------------------------------------------------------
        */
        $areaType = $purchaseRequest->getApprovalAreaType();

        /*
        |--------------------------------------------------------------------------
        | Department pembuat PR
        |--------------------------------------------------------------------------
        */
        $departmentId = $purchaseRequest->id_department;

        if (!$departmentId) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Department Purchase Request tidak tersedia untuk mencari approval flow.',
                ],
            ]);
        }

        Log::info('[PR Approval Generator] Matching flow parameters', [
            'purchase_request_id' => $purchaseRequest->id,
            'document_type' => 'PR',
            'cabang_id' => $purchaseRequest->cabang,
            'area_type' => $areaType,
            'creator_department_id' => (int) $departmentId,
            'total_amount' => $totalAmount,
        ]);

        $flow = ApprovalFlow::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)

            /*
            |--------------------------------------------------------------------------
            | Jenis dokumen
            |--------------------------------------------------------------------------
            */
            ->whereRaw(
                'UPPER(TRIM(document_type)) = ?',
                ['PR'],
            )

            /*
            |--------------------------------------------------------------------------
            | Area
            |--------------------------------------------------------------------------
            | Tidak membandingkan approval_flows.cabang.
            |
            | HO     = Kantor Pusat
            | CABANG = seluruh cabang selain HO
            |--------------------------------------------------------------------------
            */
            ->whereRaw(
                'UPPER(TRIM(area_type)) = ?',
                [$areaType],
            )

            /*
            |--------------------------------------------------------------------------
            | Department pembuat PR
            |--------------------------------------------------------------------------
            */
            ->where(
                'creator_department_id',
                (int) $departmentId,
            )

            /*
            |--------------------------------------------------------------------------
            | Batas minimum nominal
            |--------------------------------------------------------------------------
            */
            ->where(function ($query) use ($totalAmount) {
                $query
                    ->whereNull('min_amount')
                    ->orWhere(
                        'min_amount',
                        '<=',
                        $totalAmount,
                    );
            })

            /*
            |--------------------------------------------------------------------------
            | Batas maksimum nominal
            |--------------------------------------------------------------------------
            | null atau 0 berarti tidak memiliki batas maksimum.
            |--------------------------------------------------------------------------
            */
            ->where(function ($query) use ($totalAmount) {
                $query
                    ->whereNull('max_amount')
                    ->orWhere('max_amount', 0)
                    ->orWhere(
                        'max_amount',
                        '>=',
                        $totalAmount,
                    );
            })

            /*
            |--------------------------------------------------------------------------
            | Jika ada beberapa flow yang cocok
            |--------------------------------------------------------------------------
            | Prioritaskan rentang yang paling spesifik.
            |--------------------------------------------------------------------------
            */
            ->orderByDesc('min_amount')
            ->orderBy('max_amount')
            ->orderByDesc('id')
            ->first();

        Log::info('[PR Approval Generator] Matching flow result', [
            'purchase_request_id' => $purchaseRequest->id,
            'approval_flow_id' => $flow?->id,
            'approval_flow_name' => $flow?->name,
            'area_type' => $flow?->area_type,
            'creator_department_id' => $flow?->creator_department_id,
            'min_amount' => $flow?->min_amount,
            'max_amount' => $flow?->max_amount,
        ]);

        return $flow;
    }

    private function resolveApproverName(
        ApprovalFlowStep $flowStep,
    ): ?string {
        $approverType = strtoupper(
            trim(
                (string) $flowStep->approver_type,
            ),
        );

        if ($approverType === 'USER') {
            return User::query()
                ->whereKey($flowStep->approver_id)
                ->value('name');
        }

        if ($approverType === 'ROLE') {
            return Role::query()
                ->whereKey($flowStep->approver_id)
                ->value('nama');
        }

        return null;
    }
}
