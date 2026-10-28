<?php

namespace App\Services\FundRequest\Realization;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\CashAdvanceRealization;
use App\Models\CashAdvanceRealizationApproval;
use App\Models\Role;
use App\Models\User;
use App\Services\FundRequest\ApprovalStepRuleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Generator snapshot approval Realisasi FPU
|--------------------------------------------------------------------------
| Mengikuti mekanisme FPU: satu flow yang paling spesifik dipilih, lalu
| seluruh step-nya dibekukan menjadi baris cash_advance_realization_approvals.
| Notifikasi membaca dari baris hasil generator ini, bukan dari flow.
|
| Cabang, department, dan keterangan transaksi diwarisi dari FPU induk,
| sehingga flow Realisasi bisa diatur sedetail flow FPU.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationApprovalGeneratorService
{
    public function generate(CashAdvanceRealization $realization): void
    {
        $alreadyExists = CashAdvanceRealizationApproval::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Approval Realisasi FPU sudah pernah dibuat.',
                ],
            ]);
        }

        $totalAmount = $this->calculateTotalAmount($realization);

        if ($totalAmount <= 0) {
            throw ValidationException::withMessages([
                'total_amount' => [
                    'Total nilai realisasi harus lebih besar dari 0.',
                ],
            ]);
        }

        $approvalFlow = $this->findMatchingFlow($realization, $totalAmount);

        if (!$approvalFlow) {
            /*
            | Keempat kriteria disebut sekaligus supaya admin tahu bagian mana
            | dari matriks yang perlu dilengkapi. Menyebut nominal saja membuat
            | orang mengubah rentang nominal padahal yang belum cocok justru
            | keterangan transaksinya.
            */
            $realization->loadMissing(['transactionCategory', 'departmentData']);

            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approval flow Realisasi FPU tidak ditemukan untuk kombinasi area %s,'
                        . ' department %s, keterangan transaksi %s, dan nominal Rp %s.'
                        . ' Lengkapi matriks approval Realisasi FPU untuk kombinasi tersebut.',
                        $realization->getApprovalAreaType() ?: '-',
                        $realization->departmentData?->nama
                            ?? $realization->departmentData?->name
                            ?? '-',
                        $realization->transactionCategory?->name ?? '-',
                        number_format($totalAmount, 0, ',', '.'),
                    ),
                ],
            ]);
        }

        $flowSteps = ApprovalFlowStep::query()
            ->with(['branchMappings', 'specialApprovers'])
            ->where('approval_flow_id', $approvalFlow->id)
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
        | Rencana rantainya, sebelum satu baris pun ditulis.
        |
        | Step bisa gugur karena dua sebab baru: role pemohon masuk daftar
        | pengecualian step itu, atau step boleh disetujui rekan setingkat
        | dan tidak ada rekan lain yang tersisa. Keduanya harus diketahui
        | SEKARANG: status "menunggu" melekat pada step pertama yang
        | tersisa, dan step pertama yang gugur di tengah jalan akan
        | meninggalkan dokumen tanpa satu pun baris yang menunggu.
        |
        | Tanpa sifat baru itu, rencananya kosong dan tidak ada yang
        | berubah.
        */
        $aturanStep = app(ApprovalStepRuleService::class);

        $pemohonId = (int) ($realization->submitted_by ?? $realization->created_by ?? 0);

        $rencanaStep = $aturanStep->planSteps(
            $flowSteps,
            $pemohonId,
            $aturanStep->roleIdsOf($pemohonId),

            /*
            | Step bercakupan cabang dicairkan lebih dulu supaya keputusan
            | "pemohon ada di dalamnya atau tidak" diambil atas orang yang
            | benar-benar akan menjadi approver dokumen ini -- bukan atas
            | role pada kertasnya, yang pemegangnya bisa berada di cabang
            | lain dan memang tidak pernah ikut.
            */
            function (ApprovalFlowStep $step) use ($realization) {
                $scope = strtoupper(trim(
                    (string) ($step->approver_scope ?: ApprovalFlowStep::APPROVER_SCOPE_GLOBAL),
                ));

                $tipe = strtoupper(trim((string) $step->approver_type));

                if ($scope === ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH) {
                    return $this->resolveSameBranchApprovers($step, $realization, $tipe);
                }

                if ($scope === ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES) {
                    return $this->resolveSelectedBranchApprovers($step, $realization, $tipe);
                }

                return null;
            },
        );

        /*
        | Tingkat yang bertahan tetapi tidak punya seorang pun di dalamnya.
        |
        | Ditahan di sini, bukan dibiarkan tersimpan: dokumen yang masuk ke
        | tingkat tanpa approver akan berstatus menunggu selamanya, tanpa
        | pesan, dan baru ketahuan ketika seseorang bertanya kenapa
        | dokumennya tidak maju-maju.
        */
        $aturanStep->assertApproverAda($rencanaStep, 'Realisasi');

        $stepHidup = $flowSteps->reject(
            fn (ApprovalFlowStep $satu): bool => isset($rencanaStep['skipped'][(int) $satu->id]),
        );

        if ($stepHidup->isEmpty()) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Tidak ada approver yang tersisa pada approval flow Realisasi ini untuk Anda.'
                    . ' Hubungi admin untuk memeriksa matriks approval-nya.',
                ],
            ]);
        }

        $firstStepOrder = (int) $stepHidup->min('step_order');

        // Logical step yang wajib menghasilkan minimal satu approver.
        $requiredSteps = [];

        // Jumlah snapshot approver yang berhasil dibuat per logical step.
        $createdApproversPerStep = [];

        /*
        | Mencegah snapshot ganda pada step yang sama, misalnya ketika seorang
        | user dipilih langsung sekaligus ter-resolve lewat role.
        */
        $resolvedSnapshotKeys = [];

        foreach ($flowSteps as $flowStep) {
            $stepOrder = (int) $flowStep->step_order;

            /*
            | Dilewati SEBELUM didaftarkan sebagai step yang wajib berisi.
            | Kalau didaftarkan lebih dulu, step yang sengaja dilewati akan
            | menolak dokumennya sendiri karena tidak punya approver.
            */
            if (isset($rencanaStep['skipped'][(int) $flowStep->id])) {
                Log::info('[Realisasi Approval Generator] Step dilewati', [
                    'step_order' => $stepOrder,
                    'label' => $flowStep->label,
                    'sebab' => $rencanaStep['skipped'][(int) $flowStep->id],
                ]);

                continue;
            }

            $requiredSteps[$stepOrder] = $requiredSteps[$stepOrder]
                ?? ($flowStep->label ?: sprintf('Step %d', $stepOrder));

            $approverType = strtoupper(trim((string) $flowStep->approver_type));

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
                ? CashAdvanceRealizationApproval::STATUS_WAITING
                : CashAdvanceRealizationApproval::STATUS_PENDING;

            /*
            | SAME_BRANCH -- approver hanya berlaku bila cabang akunnya sama
            | dengan cabang dokumen.
            */
            if ($approverScope === ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH) {
                $this->createResolvedUserSnapshots(
                    realization: $realization,
                    approvalFlow: $approvalFlow,
                    flowStep: $flowStep,
                    resolvedUsers: $this->resolveSameBranchApprovers(
                        $flowStep,
                        $realization,
                        $approverType,
                    ),
                    stepOrder: $stepOrder,
                    approvalMode: $approvalMode,
                    status: $status,
                    resolvedSnapshotKeys: $resolvedSnapshotKeys,
                    createdApproversPerStep: $createdApproversPerStep,
                );

                continue;
            }

            /*
            | SELECTED_BRANCHES -- mapping pada step menentukan cabang dokumen
            | mana yang ditangani approver ini. Cabang akun approver diabaikan.
            */
            if ($approverScope === ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES) {
                $this->createResolvedUserSnapshots(
                    realization: $realization,
                    approvalFlow: $approvalFlow,
                    flowStep: $flowStep,
                    resolvedUsers: $this->resolveSelectedBranchApprovers(
                        $flowStep,
                        $realization,
                        $approverType,
                    ),
                    stepOrder: $stepOrder,
                    approvalMode: $approvalMode,
                    status: $status,
                    resolvedSnapshotKeys: $resolvedSnapshotKeys,
                    createdApproversPerStep: $createdApproversPerStep,
                );

                continue;
            }

            /*
            | Approver yang sudah dicairkan rencana di atas: step bertipe
            | DEPARTMENT, atau step yang pemohonnya baru saja dikeluarkan.
            |
            | Hanya untuk scope GLOBAL. Kedua sifat itu bekerja pada daftar
            | orang, sementara scope cabang menyaring daftar yang sama dengan
            | aturan lain -- menggabungkan keduanya tanpa memikirkannya akan
            | menghasilkan daftar yang tidak pernah diminta siapa pun.
            */
            $rencanaBaris = $rencanaStep['users'][(int) $flowStep->id] ?? null;

            if ($rencanaBaris !== null) {
                if ($approverScope !== ApprovalFlowStep::APPROVER_SCOPE_GLOBAL) {
                    throw ValidationException::withMessages([
                        'approval_flow' => [
                            sprintf(
                                'Step "%s" memakai department atau aturan rekan setingkat,'
                                . ' sehingga scope-nya harus Global.',
                                $flowStep->label ?? '-',
                            ),
                        ],
                    ]);
                }

                $this->createResolvedUserSnapshots(
                    realization: $realization,
                    approvalFlow: $approvalFlow,
                    flowStep: $flowStep,
                    resolvedUsers: $rencanaBaris,
                    stepOrder: $stepOrder,
                    approvalMode: $approvalMode,
                    status: $status,
                    resolvedSnapshotKeys: $resolvedSnapshotKeys,
                    createdApproversPerStep: $createdApproversPerStep,
                );

                continue;
            }

            /*
            | GLOBAL -- USER disimpan sebagai USER, ROLE tetap sebagai ROLE.
            */
            if ($approverScope !== ApprovalFlowStep::APPROVER_SCOPE_GLOBAL) {
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
                        CashAdvanceRealizationApproval::APPROVER_TYPE_USER,
                        CashAdvanceRealizationApproval::APPROVER_TYPE_ROLE,
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

            /*
            | Step yang menunjuk satu akun tertentu, dan akun itu sudah
            | nonaktif. Dilewati seperti kandidat nonaktif lainnya; bila
            | tingkatnya jadi tanpa penyetuju sama sekali, pemeriksaan
            | "setiap step wajib punya approver" yang akan melaporkannya.
            */
            if (
                $approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_USER
                && !$this->akunAktif($approverId)
            ) {
                continue;
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

            CashAdvanceRealizationApproval::create([
                'cash_advance_realization_id' => $realization->id,
                'approval_flow_id' => $approvalFlow->id,
                'approval_flow_step_id' => $flowStep->id,
                'step_order' => $stepOrder,
                'label' => $flowStep->label,
                'approver_type' => $approverType,
                'approver_id' => $approverId,
                'approver_name_snapshot' => $this->resolveApproverName($flowStep),
                'approval_mode' => $approvalMode,
                'status' => $status,
            ]);

            $createdApproversPerStep[$stepOrder] =
                ($createdApproversPerStep[$stepOrder] ?? 0) + 1;
        }

        /*
        | Setiap logical step wajib punya minimal satu approver. Diperiksa
        | setelah seluruh kandidat step tersebut selesai diproses, karena
        | kandidat dari cabang lain memang sengaja dilewati.
        */
        foreach ($requiredSteps as $requiredStepOrder => $requiredStepLabel) {
            if ((int) ($createdApproversPerStep[$requiredStepOrder] ?? 0) > 0) {
                continue;
            }

            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approver untuk step "%s" pada cabang Realisasi FPU belum ditemukan.',
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
        CashAdvanceRealization $realization,
        ApprovalFlow $approvalFlow,
        ApprovalFlowStep $flowStep,
        Collection $resolvedUsers,
        int $stepOrder,
        string $approvalMode,
        string $status,
        array &$resolvedSnapshotKeys,
        array &$createdApproversPerStep,
    ): void {
        $idAktif = $this->saringAkunAktif($resolvedUsers);

        foreach ($resolvedUsers as $resolvedUser) {
            $userId = (int) $resolvedUser->id;

            if ($userId <= 0) {
                continue;
            }

            /*
            | Akun nonaktif tidak masuk ke rantai approval: orang yang sudah
            | tidak lagi memegang akun tidak akan pernah menyetujui apa pun.
            */
            if (!in_array($userId, $idAktif, true)) {
                continue;
            }

            $snapshotKey = sprintf(
                '%d-%s-%d',
                $stepOrder,
                CashAdvanceRealizationApproval::APPROVER_TYPE_USER,
                $userId,
            );

            if (isset($resolvedSnapshotKeys[$snapshotKey])) {
                continue;
            }

            $resolvedSnapshotKeys[$snapshotKey] = true;

            CashAdvanceRealizationApproval::create([
                'cash_advance_realization_id' => $realization->id,
                'approval_flow_id' => $approvalFlow->id,
                'approval_flow_step_id' => $flowStep->id,
                'step_order' => $stepOrder,
                'label' => $flowStep->label,

                // Hasil resolver selalu disimpan sebagai USER konkret.
                'approver_type' => CashAdvanceRealizationApproval::APPROVER_TYPE_USER,
                'approver_id' => $userId,
                'approver_name_snapshot' => $resolvedUser->name
                    ?? $resolvedUser->fullname
                    ?? null,

                'approval_mode' => $approvalMode,
                'status' => $status,
            ]);

            $createdApproversPerStep[$stepOrder] =
                ($createdApproversPerStep[$stepOrder] ?? 0) + 1;
        }
    }

    /**
     * Id akun yang masih aktif di antara kandidat yang diberikan.
     *
     * Diperiksa sekali di muara, bukan di tiap resolver: sebagian jalur
     * membawa model User utuh, sebagian lagi hanya id dan nama. Membaca
     * $user->is_active langsung akan menghasilkan null pada jalur kedua, dan
     * null berarti seluruh kandidatnya terbuang diam-diam.
     */
    private function saringAkunAktif(Collection $kandidat): array
    {
        $ids = $kandidat
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids->all())
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function akunAktif(int $userId): bool
    {
        return $userId > 0
            && User::query()->whereKey($userId)->where('is_active', true)->exists();
    }

    private function resolveSameBranchApprovers(
        ApprovalFlowStep $flowStep,
        CashAdvanceRealization $realization,
        string $approverType,
    ): Collection {
        $branchId = (int) $realization->branch;
        $approverId = (int) $flowStep->approver_id;

        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'branch' => [
                    'Cabang Realisasi FPU belum ditentukan.',
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
        | USER: dikembalikan hanya bila cabangnya sama. Bila berbeda, hasilnya
        | kosong dan kandidat ini dilewati.
        */
        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($approverId)
                ->where('users.cabang_id', $branchId)
                ->get();
        }

        // ROLE: seluruh user dengan role tersebut pada cabang dokumen.
        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_ROLE) {
            return User::query()
                ->where('users.cabang_id', $branchId)
                ->whereHas('roles', function ($roleQuery) use ($approverId) {
                    $roleQuery->where('roles.id', $approverId);
                })
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
        CashAdvanceRealization $realization,
        string $approverType,
    ): Collection {
        $branchId = (int) $realization->branch;
        $approverId = (int) $flowStep->approver_id;

        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'branch' => [
                    'Cabang Realisasi FPU belum ditentukan.',
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
            ->map(fn($mappedBranchId) => (int) $mappedBranchId)
            ->filter(fn(int $mappedBranchId) => $mappedBranchId > 0)
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
        | Cabang dokumen di luar cakupan approver ini bukan error -- mungkin ada
        | approver lain pada step yang sama yang menanganinya.
        */
        if (!$branchIds->contains($branchId)) {
            return collect();
        }

        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($approverId)
                ->get();
        }

        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_ROLE) {
            return User::query()
                ->whereHas('roles', function ($roleQuery) use ($approverId) {
                    $roleQuery->where('roles.id', $approverId);
                })
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

    /**
     * Nilai yang dipakai memilih flow adalah total realisasinya, bukan nilai
     * FPU yang dicairkan -- yang disetujui pada dokumen ini adalah angka
     * pengeluaran yang benar-benar terjadi.
     */
    private function calculateTotalAmount(CashAdvanceRealization $realization): float
    {
        $realization->loadMissing('items');

        $headerTotal = (float) ($realization->total_realization_amount ?? 0);

        if ($headerTotal > 0) {
            return $headerTotal;
        }

        return (float) $realization
            ->items
            ->sum(fn($item) => (float) ($item->realization_amount ?? 0));
    }

    private function findMatchingFlow(
        CashAdvanceRealization $realization,
        float $totalAmount,
    ): ?ApprovalFlow {
        $areaType = $realization->getApprovalAreaType();

        $departmentId = $realization->department_id;

        if (!$departmentId) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Department Realisasi FPU tidak tersedia untuk mencari approval flow.',
                ],
            ]);
        }

        $transactionCategoryId = $realization->transaction_category_id
            ? (int) $realization->transaction_category_id
            : null;

        Log::info('[Realisasi FPU Approval Generator] Matching flow parameters', [
            'cash_advance_realization_id' => $realization->id,
            'document_type' => ApprovalFlow::DOCUMENT_TYPE_REALISASI,
            'branch_id' => $realization->branch,
            'area_type' => $areaType,
            'creator_department_id' => (int) $departmentId,
            'transaction_category_id' => $transactionCategoryId,
            'total_amount' => $totalAmount,
        ]);

        $flow = ApprovalFlow::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereRaw('UPPER(TRIM(document_type)) = ?', [ApprovalFlow::DOCUMENT_TYPE_REALISASI])

            /*
            | Area dokumen: HO = kantor pusat, CABANG = selain HO.
            | approval_flows.cabang sengaja tidak ikut dibandingkan.
            */
            ->whereRaw('UPPER(TRIM(area_type)) = ?', [$areaType])

            /*
            | Department: flow "Semua Divisi" cocok untuk department mana pun.
            | Selain itu dicocokkan ke tabel pivot, dengan creator_department_id
            | sebagai cadangan bagi flow lama yang belum punya baris pivot.
            */
            ->where(function ($query) use ($departmentId) {
                $query
                    ->where('all_departments', true)
                    ->orWhereHas(
                        'departments',
                        fn($departmentQuery) => $departmentQuery->where(
                            'departments.id',
                            (int) $departmentId,
                        ),
                    )
                    ->orWhere(function ($legacyQuery) use ($departmentId) {
                        $legacyQuery
                            ->where('creator_department_id', (int) $departmentId)
                            ->whereDoesntHave('departments');
                    });
            })

            /*
            | Keterangan transaksi: flow yang tidak dibatasi kategori cocok
            | untuk semuanya. Dokumen tanpa kategori hanya cocok dengan flow
            | seperti itu.
            */
            ->where(function ($query) use ($transactionCategoryId) {
                $query->where('all_transaction_categories', true);

                if ($transactionCategoryId) {
                    $query->orWhereHas(
                        'transactionCategories',
                        fn($categoryQuery) => $categoryQuery->where(
                            'fund_request_transaction_categories.id',
                            $transactionCategoryId,
                        ),
                    );
                }
            })

            ->where(function ($query) use ($totalAmount) {
                $query
                    ->whereNull('min_amount')
                    ->orWhere('min_amount', '<=', $totalAmount);
            })

            // max_amount null atau 0 berarti tidak berbatas.
            ->where(function ($query) use ($totalAmount) {
                $query
                    ->whereNull('max_amount')
                    ->orWhere('max_amount', 0)
                    ->orWhere('max_amount', '>=', $totalAmount);
            })

            /*
            | Bila beberapa flow cocok, yang paling spesifik menang.
            |
            | Urutan kekhususan: keterangan transaksi tertentu mengalahkan
            | "semua kategori", department tertentu mengalahkan "Semua Divisi",
            | lalu rentang nominal yang paling sempit.
            |
            | false diurutkan lebih dulu daripada true pada PostgreSQL, jadi
            | ASC berarti "yang dibatasi lebih dulu".
            */
            ->orderBy('all_transaction_categories')
            ->orderBy('all_departments')

            /*
            | COALESCE dipakai karena PostgreSQL menempatkan NULL di urutan
            | pertama pada ORDER BY DESC -- tanpa itu, flow "semua nilai"
            | justru menang atas flow yang rentangnya lebih sempit.
            */
            ->orderByRaw('COALESCE(min_amount, 0) DESC')
            ->orderByRaw('COALESCE(NULLIF(max_amount, 0), 999999999999999999) ASC')
            ->orderByDesc('id')
            ->first();

        Log::info('[Realisasi FPU Approval Generator] Matching flow result', [
            'cash_advance_realization_id' => $realization->id,
            'approval_flow_id' => $flow?->id,
            'approval_flow_name' => $flow?->name,
            'area_type' => $flow?->area_type,
            'all_departments' => $flow?->all_departments,
            'department_ids' => $flow?->resolvedDepartmentIds(),
            'all_transaction_categories' => $flow?->all_transaction_categories,
            'min_amount' => $flow?->min_amount,
            'max_amount' => $flow?->max_amount,
        ]);

        return $flow;
    }

    private function resolveApproverName(ApprovalFlowStep $flowStep): ?string
    {
        $approverType = strtoupper(trim((string) $flowStep->approver_type));

        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($flowStep->approver_id)
                ->value('name');
        }

        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_ROLE) {
            return Role::query()
                ->whereKey($flowStep->approver_id)
                ->value('nama');
        }

        return null;
    }
}
