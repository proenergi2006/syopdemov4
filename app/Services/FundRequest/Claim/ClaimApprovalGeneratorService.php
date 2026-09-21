<?php

namespace App\Services\FundRequest\Claim;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Claim;
use App\Models\ClaimApproval;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Generator snapshot approval Claim
|--------------------------------------------------------------------------
| Mengikuti pola FPU: satu flow yang paling spesifik dipilih, lalu seluruh
| step-nya dibekukan menjadi baris claim_approvals. Notifikasi dan email
| membaca dari baris hasil generator ini, bukan dari flow.
|
| Dua hal yang membedakannya dari FPU:
|
| 1. Nilai dan keterangan transaksi TIDAK ikut menentukan flow. Claim dipilih
|    murni dari area (HO / Cabang) dan department pemohon -- berapa pun
|    nilainya, approver-nya sama. Kolom min_amount/max_amount pada flow Claim
|    sengaja diabaikan, supaya isian yang tak sengaja terisi tidak diam-diam
|    membuat dokumen kehilangan flow.
|
| 2. Pemohon tidak boleh menyetujui pengajuannya sendiri, dan approval-nya
|    naik satu tingkat. Lihat applySelfApprovalRule().
|--------------------------------------------------------------------------
*/
class ClaimApprovalGeneratorService
{
    public function generate(Claim $claim): void
    {
        $alreadyExists = ClaimApproval::query()
            ->where('claim_id', $claim->id)
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Approval Claim sudah pernah dibuat.',
                ],
            ]);
        }

        $requesterId = $this->resolveRequesterId($claim);

        $approvalFlow = $this->findMatchingFlow($claim);

        if (!$approvalFlow) {
            $claim->loadMissing('departmentData');

            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approval flow Claim tidak ditemukan untuk area %s dan department %s.'
                        . ' Lengkapi matriks approval Claim untuk kombinasi tersebut.',
                        $claim->getApprovalAreaType() ?: '-',
                        $claim->departmentData?->nama ?? '-',
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
        | Kandidat dikumpulkan lebih dulu di memori, baru disimpan.
        |
        | Aturan "pemohon tidak boleh approve sendiri" perlu melihat seluruh
        | rantai sebelum memutuskan step mana yang gugur, jadi tidak bisa
        | ditulis sambil jalan seperti pada FPU.
        */
        $candidates = $this->buildCandidates($claim, $approvalFlow, $flowSteps);

        $candidates = $this->applySelfApprovalRule($claim, $candidates, $requesterId);

        $this->assertEveryStepHasApprover($candidates, $flowSteps);

        $this->persist($candidates);
    }

    /*
    |--------------------------------------------------------------------------
    | Pengumpulan kandidat
    |--------------------------------------------------------------------------
    | Hasilnya array baris siap simpan, dikelompokkan per step_order. Status
    | belum diisi -- baru ditentukan setelah diketahui step mana yang tersisa.
    |
    | @return array<int, array<int, array<string, mixed>>>
    */
    private function buildCandidates(
        Claim $claim,
        ApprovalFlow $approvalFlow,
        Collection $flowSteps,
    ): array {
        $candidates = [];

        // Mencegah baris ganda pada step yang sama.
        $seen = [];

        foreach ($flowSteps as $flowStep) {
            $stepOrder = (int) $flowStep->step_order;

            $approverType = strtoupper(trim((string) $flowStep->approver_type));

            $approverScope = strtoupper(
                trim(
                    (string) ($flowStep->approver_scope ?: ApprovalFlowStep::APPROVER_SCOPE_GLOBAL),
                ),
            );

            $approvalMode = strtoupper(
                trim(
                    (string) ($flowStep->approval_mode ?: ApprovalFlowStep::APPROVAL_MODE_ANY),
                ),
            );

            if (
                !in_array(
                    $approvalMode,
                    [ApprovalFlowStep::APPROVAL_MODE_ANY, ApprovalFlowStep::APPROVAL_MODE_ALL],
                    true,
                )
            ) {
                $approvalMode = ApprovalFlowStep::APPROVAL_MODE_ANY;
            }

            $this->assertApproverTypeSupported($approverType, $flowStep);

            $dasar = [
                'claim_id' => $claim->id,
                'approval_flow_id' => $approvalFlow->id,
                'approval_flow_step_id' => $flowStep->id,
                'step_order' => $stepOrder,
                'label' => $flowStep->label,
                'approval_mode' => $approvalMode,
            ];

            /*
            | Scope cabang di-resolve menjadi USER konkret. GLOBAL disimpan apa
            | adanya: ROLE tetap ROLE, sehingga pergantian pemegang role masih
            | terbawa sampai dokumen disetujui.
            */
            if ($approverScope === ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH) {
                $resolved = $this->resolveSameBranchApprovers($flowStep, $claim, $approverType);
            } elseif ($approverScope === ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES) {
                $resolved = $this->resolveSelectedBranchApprovers($flowStep, $claim, $approverType);
            } else {
                $resolved = null;
            }

            if ($resolved !== null) {
                foreach ($resolved as $user) {
                    $userId = (int) $user->id;

                    if ($userId <= 0) {
                        continue;
                    }

                    $key = sprintf('%d-USER-%d', $stepOrder, $userId);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;

                    $candidates[$stepOrder][] = $dasar + [
                        'approver_type' => ClaimApproval::APPROVER_TYPE_USER,
                        'approver_id' => $userId,
                        'approver_name_snapshot' => $user->name ?? null,
                    ];
                }

                continue;
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

            $key = sprintf('%d-%s-%d', $stepOrder, $approverType, $approverId);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $candidates[$stepOrder][] = $dasar + [
                'approver_type' => $approverType,
                'approver_id' => $approverId,
                'approver_name_snapshot' => $this->resolveApproverName($flowStep),
            ];
        }

        ksort($candidates);

        return $candidates;
    }

    /*
    |--------------------------------------------------------------------------
    | Aturan: pemohon tidak boleh menyetujui pengajuannya sendiri
    |--------------------------------------------------------------------------
    | Bunyi aturannya: "Bila pemohon adalah salah satu atasan, approval naik
    | satu tingkat di atasnya. Tidak boleh menyetujui pengajuan sendiri.
    | Jumlah tetap maksimal 3."
    |
    | Penerapannya: cari step TERTINGGI tempat pemohon muncul, lalu buang step
    | itu beserta semua step di bawahnya. Yang tersisa hanyalah tingkat di atas
    | pemohon.
    |
    | Dua hal yang membuat pembuangannya tidak berhenti pada baris pemohon saja:
    |
    | - Rekan setingkat bukan atasan. Bila satu step berisi pemohon dan rekan
    |   sejawatnya, membiarkan rekan itu menyetujui berarti approval tidak
    |   benar-benar naik. Karena itu seluruh step digugurkan, bukan hanya
    |   barisnya.
    |
    | - Step di bawahnya adalah bawahan. Bila hanya step pemohon yang dibuang,
    |   step yang lebih rendah tetap hidup dan pengajuan justru disetujui
    |   bawahannya sendiri.
    |
    | PENTING: aturan ini bersandar pada step_order sebagai urutan senioritas
    | (step 1 paling junior). Flow Claim wajib disusun dari bawah ke atas --
    | mis. ADH, lalu atasan 1, lalu atasan 2. Bila urutannya dibalik, aturan
    | ini akan membuang tingkat yang salah.
    |
    | @param  array<int, array<int, array<string, mixed>>>  $candidates
    | @return array<int, array<int, array<string, mixed>>>
    */
    private function applySelfApprovalRule(
        Claim $claim,
        array $candidates,
        int $requesterId,
    ): array {
        if ($requesterId <= 0 || $candidates === []) {
            return $candidates;
        }

        $requesterRoleIds = $this->resolveUserRoleIds($requesterId);

        $highestSelfStep = null;

        foreach ($candidates as $stepOrder => $rows) {
            foreach ($rows as $row) {
                if (!$this->rowRefersToUser($row, $requesterId, $requesterRoleIds)) {
                    continue;
                }

                $highestSelfStep = max($highestSelfStep ?? $stepOrder, $stepOrder);

                break;
            }
        }

        if ($highestSelfStep === null) {
            return $candidates;
        }

        $sisa = array_filter(
            $candidates,
            fn(int $stepOrder): bool => $stepOrder > $highestSelfStep,
            ARRAY_FILTER_USE_KEY,
        );

        Log::info('[Claim Approval Generator] Pemohon adalah approver, rantai dinaikkan', [
            'claim_id' => $claim->id,
            'claim_number' => $claim->claim_number,
            'requester_id' => $requesterId,
            'step_pemohon' => $highestSelfStep,
            'step_dibuang' => array_values(array_diff(array_keys($candidates), array_keys($sisa))),
            'step_tersisa' => array_keys($sisa),
        ]);

        if ($sisa === []) {
            $claim->loadMissing('departmentData');

            /*
            | Tidak ada tingkat di atas pemohon. Dokumen ditahan di sini, bukan
            | diloloskan tanpa approver -- meloloskannya berarti pemohon
            | menyetujui pengajuannya sendiri secara diam-diam.
            */
            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Anda adalah approver tertinggi pada approval flow Claim untuk area %s'
                        . ' dan department %s, sehingga tidak ada atasan yang dapat menyetujui'
                        . ' pengajuan ini. Hubungi admin untuk menambahkan tingkat approval'
                        . ' di atas Anda pada matriks approval Claim.',
                        $claim->getApprovalAreaType() ?: '-',
                        $claim->departmentData?->nama ?? '-',
                    ),
                ],
            ]);
        }

        return $sisa;
    }

    /**
     * Apakah satu baris kandidat menunjuk pemohon.
     *
     * Baris hasil resolve scope cabang selalu berupa USER konkret, sedangkan
     * baris GLOBAL bisa berupa ROLE -- keduanya harus diperiksa, kalau tidak
     * pemohon yang masuk lewat role akan lolos.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, int>  $requesterRoleIds
     */
    private function rowRefersToUser(array $row, int $requesterId, array $requesterRoleIds): bool
    {
        $type = strtoupper(trim((string) ($row['approver_type'] ?? '')));
        $id = (int) ($row['approver_id'] ?? 0);

        if ($type === ClaimApproval::APPROVER_TYPE_USER) {
            return $id === $requesterId;
        }

        if ($type === ClaimApproval::APPROVER_TYPE_ROLE) {
            return in_array($id, $requesterRoleIds, true);
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    private function resolveUserRoleIds(int $userId): array
    {
        return User::query()
            ->whereKey($userId)
            ->first()
            ?->roles
            ->pluck('id')
            ->map(fn($id): int => (int) $id)
            ->all() ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | Penyimpanan
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $candidates
     */
    private function persist(array $candidates): void
    {
        $firstStepOrder = min(array_keys($candidates));

        foreach ($candidates as $stepOrder => $rows) {
            foreach ($rows as $row) {
                ClaimApproval::create($row + [
                    'status' => $stepOrder === $firstStepOrder
                        ? ClaimApproval::STATUS_WAITING
                        : ClaimApproval::STATUS_PENDING,
                ]);
            }
        }
    }

    /**
     * Setiap step yang tersisa wajib punya minimal satu approver.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $candidates
     */
    private function assertEveryStepHasApprover(array $candidates, Collection $flowSteps): void
    {
        foreach ($candidates as $stepOrder => $rows) {
            if ($rows !== []) {
                continue;
            }

            $label = $flowSteps
                ->firstWhere('step_order', $stepOrder)
                ?->label ?? sprintf('Step %d', $stepOrder);

            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approver untuk step "%s" pada cabang Claim ini belum ditemukan.',
                        $label,
                    ),
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pemilihan flow
    |--------------------------------------------------------------------------
    */

    private function findMatchingFlow(Claim $claim): ?ApprovalFlow
    {
        $areaType = $claim->getApprovalAreaType();
        $departmentId = $claim->department_id;

        if (!$departmentId) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Department Claim tidak tersedia untuk mencari approval flow.',
                ],
            ]);
        }

        Log::info('[Claim Approval Generator] Matching flow parameters', [
            'claim_id' => $claim->id,
            'document_type' => ApprovalFlow::DOCUMENT_TYPE_CLAIM,
            'branch_id' => $claim->branch,
            'area_type' => $areaType,
            'creator_department_id' => (int) $departmentId,
        ]);

        $flow = ApprovalFlow::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereRaw('UPPER(TRIM(document_type)) = ?', [ApprovalFlow::DOCUMENT_TYPE_CLAIM])

            /*
            | Area dokumen: HO = kantor pusat, CABANG = selain HO.
            |
            | approval_flows.cabang sengaja tidak dibandingkan. Perbedaan antar
            | cabang sudah ditangani approver_scope SAME_BRANCH pada step-nya,
            | sehingga satu flow melayani seluruh cabang.
            */
            ->whereRaw('UPPER(TRIM(area_type)) = ?', [$areaType])

            /*
            | Department pemohon. Flow "Semua Divisi" cocok untuk department
            | mana pun; creator_department_id dipakai sebagai cadangan bagi
            | flow lama yang belum punya baris pivot.
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
            | Department tertentu mengalahkan "Semua Divisi". Nominal dan
            | keterangan transaksi tidak ikut diurutkan karena tidak dipakai
            | sama sekali pada Claim.
            */
            ->orderBy('all_departments')
            ->orderByDesc('id')
            ->first();

        Log::info('[Claim Approval Generator] Matching flow result', [
            'claim_id' => $claim->id,
            'approval_flow_id' => $flow?->id,
            'approval_flow_name' => $flow?->name,
            'area_type' => $flow?->area_type,
            'all_departments' => $flow?->all_departments,
            'department_ids' => $flow?->resolvedDepartmentIds(),
        ]);

        return $flow;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolver approver
    |--------------------------------------------------------------------------
    */

    private function resolveSameBranchApprovers(
        ApprovalFlowStep $flowStep,
        Claim $claim,
        string $approverType,
    ): Collection {
        $branchId = (int) $claim->branch;
        $approverId = (int) $flowStep->approver_id;

        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'branch' => ['Cabang Claim belum ditentukan.'],
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
        if ($approverType === ClaimApproval::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($approverId)
                ->where('users.cabang_id', $branchId)
                ->get();
        }

        // ROLE: seluruh user dengan role tersebut pada cabang dokumen.
        return User::query()
            ->where('users.cabang_id', $branchId)
            ->whereHas('roles', fn($roleQuery) => $roleQuery->where('roles.id', $approverId))
            ->get();
    }

    private function resolveSelectedBranchApprovers(
        ApprovalFlowStep $flowStep,
        Claim $claim,
        string $approverType,
    ): Collection {
        $branchId = (int) $claim->branch;
        $approverId = (int) $flowStep->approver_id;

        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'branch' => ['Cabang Claim belum ditentukan.'],
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
            ->map(fn($mappedBranchId): int => (int) $mappedBranchId)
            ->filter(fn(int $mappedBranchId): bool => $mappedBranchId > 0)
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

        if ($approverType === ClaimApproval::APPROVER_TYPE_USER) {
            return User::query()->whereKey($approverId)->get();
        }

        return User::query()
            ->whereHas('roles', fn($roleQuery) => $roleQuery->where('roles.id', $approverId))
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    private function assertApproverTypeSupported(string $approverType, ApprovalFlowStep $flowStep): void
    {
        if (
            in_array(
                $approverType,
                [ClaimApproval::APPROVER_TYPE_USER, ClaimApproval::APPROVER_TYPE_ROLE],
                true,
            )
        ) {
            return;
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

    private function resolveApproverName(ApprovalFlowStep $flowStep): ?string
    {
        $approverType = strtoupper(trim((string) $flowStep->approver_type));

        if ($approverType === ClaimApproval::APPROVER_TYPE_USER) {
            return User::query()->whereKey($flowStep->approver_id)->value('name');
        }

        if ($approverType === ClaimApproval::APPROVER_TYPE_ROLE) {
            return Role::query()->whereKey($flowStep->approver_id)->value('nama');
        }

        return null;
    }

    /**
     * Pemohon adalah penekan tombol Submit; created_by dipakai sebagai cadangan.
     */
    private function resolveRequesterId(Claim $claim): int
    {
        return (int) ($claim->submitted_by ?? $claim->created_by ?? 0);
    }
}
