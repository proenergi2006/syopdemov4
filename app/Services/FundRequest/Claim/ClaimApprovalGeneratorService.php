<?php

namespace App\Services\FundRequest\Claim;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Claim;
use App\Models\ClaimApproval;
use App\Models\Role;
use App\Models\User;
use App\Services\FundRequest\ApprovalStepRuleService;
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
|    STEP-nya dilewati -- bukan rantainya dipotong. Step lain tetap
|    berjalan, termasuk yang nomornya lebih kecil. Aturannya ditulis
|    sekali di ApprovalStepRuleService dan dipakai keempat modul.
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

        $totalAmount = $this->calculateTotalAmount($claim);

        if ($totalAmount <= 0) {
            throw ValidationException::withMessages([
                'total_amount' => [
                    'Total nilai Claim harus lebih besar dari 0.',
                ],
            ]);
        }

        $approvalFlow = $this->findMatchingFlow($claim, $totalAmount);

        if (!$approvalFlow) {
            $claim->loadMissing('departmentData');

            /*
            | Nominalnya ikut disebut sejak rentang nominal benar-benar
            | dipakai. Tanpa itu, admin membaca pesan ini lalu memeriksa
            | department-nya -- padahal yang belum tercakup justru rentang
            | nominalnya.
            */
            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approval flow Claim tidak ditemukan untuk area %s, department %s,'
                        . ' dan nominal Rp %s.'
                        . ' Lengkapi matriks approval Claim untuk kombinasi tersebut.',
                        $claim->getApprovalAreaType() ?: '-',
                        $claim->departmentData?->nama ?? '-',
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
        | Kandidat dikumpulkan lebih dulu di memori, baru disimpan.
        |
        | Aturan "pemohon tidak boleh approve sendiri" perlu melihat seluruh
        | rantai sebelum memutuskan step mana yang gugur, jadi tidak bisa
        | ditulis sambil jalan seperti pada FPU.
        */
        $aturan = app(ApprovalStepRuleService::class);

        $requesterRoleIds = $aturan->roleIdsOf($requesterId);

        /*
        | Nasib tiap step dihitung lebih dulu, memakai aturan yang sama
        | dengan FPU dan Realisasi:
        |
        | - step yang memuat pemohon DILEWATI seluruhnya,
        | - step lain tetap berjalan, termasuk yang nomornya lebih kecil,
        | - step yang boleh disetujui rekan setingkat hanya mengeluarkan
        |   pemohonnya.
        |
        | Step bercakupan cabang ikut dicairkan supaya keputusannya diambil
        | atas orang yang benar-benar akan menjadi approver dokumen ini.
        */
        $rencanaStep = $aturan->planSteps(
            $flowSteps,
            $requesterId,
            $requesterRoleIds,
            function (ApprovalFlowStep $step) use ($claim) {
                $scope = strtoupper(trim(
                    (string) ($step->approver_scope ?: ApprovalFlowStep::APPROVER_SCOPE_GLOBAL),
                ));

                $tipe = strtoupper(trim((string) $step->approver_type));

                if ($scope === ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH) {
                    return $this->resolveSameBranchApprovers($step, $claim, $tipe);
                }

                if ($scope === ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES) {
                    return $this->resolveSelectedBranchApprovers($step, $claim, $tipe);
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
        $aturan->assertApproverAda($rencanaStep, 'Claim');

        $candidates = $this->buildCandidates(
            $claim,
            $approvalFlow,
            $flowSteps,
            $requesterId,
            $requesterRoleIds,
            $rencanaStep,
        );

        /*
        | Seluruh step terlewati berarti tidak ada yang menyetujui.
        |
        | Dokumen tanpa satu pun approver akan berdiri selamanya tanpa ada
        | yang bisa menggerakkannya -- ditahan di sini, bukan dibiarkan
        | tersimpan diam-diam.
        */
        if ($candidates === []) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Tidak ada approver yang tersisa pada approval flow Claim ini untuk Anda.'
                    . ' Hubungi admin untuk memeriksa matriks approval-nya.',
                ],
            ]);
        }

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
        int $requesterId = 0,
        array $requesterRoleIds = [],
        array $rencanaStep = ['skipped' => [], 'users' => []],
    ): array {
        $aturan = app(ApprovalStepRuleService::class);

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

            /*
            | Step yang gugur menurut rencana di atas: karena pemohon ada di
            | dalamnya, karena rekan sejawatnya ada di tingkat yang sama,
            | karena rekan setingkatnya habis, atau karena role pemohon
            | memang dikecualikan pada step itu.
            */
            $sebabGugur = $rencanaStep['skipped'][(int) $flowStep->id] ?? null;

            if ($sebabGugur !== null) {
                Log::info('[Claim Approval Generator] Step dilewati', [
                    'claim_id' => $claim->id,
                    'step_order' => $stepOrder,
                    'label' => $flowStep->label,
                    'sebab' => $sebabGugur,
                ]);

                continue;
            }

            $rekan = $aturan->peerApproval($flowStep);

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
            } elseif (
                $approverType === ApprovalFlowStep::APPROVER_TYPE_DEPARTMENT
                || ($rekan && $approverType === ApprovalFlowStep::APPROVER_TYPE_ROLE)
            ) {
                /*
                | Dicairkan menjadi USER konkret.
                |
                | DEPARTMENT memang tidak punya bentuk lain -- tidak ada baris
                | approval bertipe department di hilir.
                |
                | ROLE pada step rekan setingkat juga harus dicairkan: baris
                | ROLE tidak bisa mengeluarkan satu orang dari dalamnya, dan
                | membuang barisnya berarti membuang seluruh pemegang role itu.
                */
                $resolved = $aturan->resolveConcreteApprovers($flowStep);
            } else {
                $resolved = null;
            }

            if ($resolved !== null) {
                $barisStep = [];
                $idAktif = $this->saringAkunAktif($resolved);

                foreach ($resolved as $user) {
                    $userId = (int) $user->id;

                    if ($userId <= 0) {
                        continue;
                    }

                    /*
                    | Akun nonaktif tidak masuk ke rantai approval.
                    |
                    | Orang yang akunnya sudah dimatikan tidak akan pernah
                    | menyetujui apa pun; barisnya hanya menambah nama di
                    | riwayat approval dan membuat "cukup satu dari 3
                    | approver" terbaca lebih longgar daripada kenyataannya.
                    */
                    if (!in_array($userId, $idAktif, true)) {
                        continue;
                    }

                    $barisStep[] = $dasar + [
                        'approver_type' => ClaimApproval::APPROVER_TYPE_USER,
                        'approver_id' => $userId,
                        'approver_name_snapshot' => $user->name ?? null,
                    ];
                }

                $barisStep = $this->saringRekan(
                    $barisStep,
                    $rekan,
                    $requesterId,
                    $claim,
                    $flowStep,
                    $aturan,
                );

                foreach ($barisStep as $baris) {
                    $key = sprintf('%d-USER-%d', $stepOrder, (int) $baris['approver_id']);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;

                    $candidates[$stepOrder][] = $baris;
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

            /*
            | Step yang menunjuk satu akun tertentu, dan akun itu sudah
            | nonaktif. Dilewati seperti kandidat nonaktif lainnya; bila
            | tingkatnya jadi tanpa penyetuju sama sekali, assertApproverAda
            | yang akan melaporkannya dengan jelas.
            */
            if (
                $approverType === ApprovalFlowStep::APPROVER_TYPE_USER
                && !$this->akunAktif($approverId)
            ) {
                continue;
            }

            $key = sprintf('%d-%s-%d', $stepOrder, $approverType, $approverId);

            if (isset($seen[$key])) {
                continue;
            }

            $baris = [$dasar + [
                'approver_type' => $approverType,
                'approver_id' => $approverId,
                'approver_name_snapshot' => $this->resolveApproverName($flowStep),
            ]];

            /* Step rekan setingkat yang approvernya satu orang: ia pemohonnya. */
            $baris = $this->saringRekan($baris, $rekan, $requesterId, $claim, $flowStep, $aturan);

            if ($baris === []) {
                continue;
            }

            $seen[$key] = true;

            $candidates[$stepOrder][] = $baris[0];
        }

        ksort($candidates);

        return $candidates;
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
    /**
     * Mengeluarkan pemohon dari sebuah step yang boleh disetujui rekannya.
     *
     * Step biasa ($rekan = false) dikembalikan apa adanya -- inilah yang
     * membuat seluruh alur yang berjalan sekarang tidak berubah sama sekali.
     *
     * @param  array<int, array<string, mixed>>  $baris
     * @return array<int, array<string, mixed>>
     */
    private function saringRekan(
        array $baris,
        bool $rekan,
        int $requesterId,
        Claim $claim,
        ApprovalFlowStep $flowStep,
        ApprovalStepRuleService $aturan,
    ): array {
        if (!$rekan) {
            return $baris;
        }

        $sesudah = $aturan->withoutRequester($baris, $requesterId);

        if (count($sesudah) !== count($baris)) {
            Log::info('[Claim Approval Generator] Pemohon dikeluarkan dari step rekan setingkat', [
                'claim_id' => $claim->id,
                'step_order' => (int) $flowStep->step_order,
                'label' => $flowStep->label,
                'tersisa' => count($sesudah),
            ]);
        }

        return $sesudah;
    }

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

    /**
     * Total nilai Claim.
     *
     * Header dipakai lebih dulu karena ia yang sudah dibekukan saat submit;
     * penjumlahan baris hanya cadangan untuk dokumen yang headernya belum
     * terisi.
     */
    private function calculateTotalAmount(Claim $claim): float
    {
        $claim->loadMissing('items');

        $headerTotal = (float) ($claim->total_amount ?? 0);

        if ($headerTotal > 0) {
            return $headerTotal;
        }

        return (float) $claim
            ->items
            ->sum(fn($item) => (float) ($item->amount ?? 0));
    }

    private function findMatchingFlow(Claim $claim, float $totalAmount): ?ApprovalFlow
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
            'total_amount' => $totalAmount,
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
            |------------------------------------------------------------------
            | Rentang nominal
            |------------------------------------------------------------------
            | DULU TIDAK DIBANDINGKAN SAMA SEKALI.
            |
            | Admin tetap mengisi rentangnya di layar matriks, dan rentang itu
            | tersimpan -- tetapi tidak pernah dibaca saat memilih flow. Yang
            | menang hanyalah urutan terakhir, yaitu id terbesar. Akibatnya
            | SELURUH Claim pada satu area+department jatuh ke satu flow yang
            | sama, berapa pun nominalnya:
            |
            |   - Claim di bawah rentang terendah tetap lolos, padahal tidak
            |     ada flow yang mencakupnya;
            |   - Claim di atas rentang tertinggi juga lolos;
            |   - dan dua rentang lainnya tidak pernah terpakai sama sekali.
            |
            | Sekarang dibandingkan, persis seperti FPU. Nominal yang tidak
            | tercakup rentang mana pun berarti tidak ada flow -- dan dokumennya
            | ditolak saat submit, bukan diloloskan diam-diam.
            |------------------------------------------------------------------
            */
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
            | Bila beberapa flow cocok, yang paling spesifik menang: department
            | tertentu mengalahkan "Semua Divisi", lalu rentang nominal yang
            | paling sempit.
            |
            | COALESCE dipakai karena PostgreSQL menempatkan NULL di urutan
            | pertama pada ORDER BY DESC -- tanpa itu, flow "semua nilai"
            | justru menang atas flow yang rentangnya lebih sempit.
            |
            | Keterangan transaksi sengaja tidak ikut: alur Claim memang tidak
            | bergantung padanya (permission_modules.
            | approval_uses_transaction_category bernilai false untuk claim),
            | karena keterangan transaksi Claim berbentuk himpunan per baris,
            | bukan satu penentu per dokumen.
            */
            ->orderBy('all_departments')
            ->orderByRaw('COALESCE(min_amount, 0) DESC')
            ->orderByRaw('COALESCE(NULLIF(max_amount, 0), 999999999999999999) ASC')
            ->orderByDesc('id')
            ->first();

        Log::info('[Claim Approval Generator] Matching flow result', [
            'claim_id' => $claim->id,
            'approval_flow_id' => $flow?->id,
            'approval_flow_name' => $flow?->name,
            'area_type' => $flow?->area_type,
            'all_departments' => $flow?->all_departments,
            'department_ids' => $flow?->resolvedDepartmentIds(),
            'min_amount' => $flow?->min_amount,
            'max_amount' => $flow?->max_amount,
        ]);

        return $flow;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolver approver
    |--------------------------------------------------------------------------
    */

    /**
     * Id akun yang masih aktif di antara kandidat yang diberikan.
     *
     * Diperiksa sekali di muara, bukan di tiap resolver: kandidat datang dari
     * tiga jalur -- SAME_BRANCH, SELECTED_BRANCHES, dan resolveConcreteApprovers
     * -- dan dua yang pertama membawa model User utuh sementara yang ketiga
     * hanya id dan nama. Membaca $user->is_active langsung akan menghasilkan
     * null pada jalur ketiga, dan null berarti seluruh kandidatnya terbuang
     * diam-diam.
     *
     * @param  iterable<int, object>  $kandidat
     * @return array<int, int>
     */
    private function saringAkunAktif(iterable $kandidat): array
    {
        $ids = collect($kandidat)
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
        /*
        | DEPARTMENT sah di step, walau tidak pernah sampai ke baris approval:
        | ia selalu dicairkan menjadi USER lebih dulu.
        */
        if (in_array($approverType, ApprovalFlowStep::APPROVER_TYPES, true)) {
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
