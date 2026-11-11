<?php

namespace App\Services\FundRequest\PettyCash;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\PettyCash;
use App\Models\PettyCashApproval;
use App\Models\PettyCashTopup;
use App\Models\PettyCashTopupApproval;
use App\Models\Role;
use App\Models\User;
use App\Services\FundRequest\ApprovalStepRuleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Generator snapshot approval petty cash DAN top up
|--------------------------------------------------------------------------
| Satu kelas untuk dua dokumen, bukan dua salinan.
|
| Keputusan ini perlu dijelaskan, karena ia menyimpang dari pola yang ada:
| FPU, Realisasi, Claim, dan Perdin masing-masing punya generator sendiri,
| sekitar 900 baris, dan keempatnya sudah saling menjauh -- lebih dari seribu
| baris berbeda antara yang satu dan yang lain, padahal tugasnya sama. Artinya
| perbaikan pada aturan approval harus dikerjakan empat kali, dan yang
| terlewat tidak akan bersuara.
|
| Menambah salinan kelima dan keenam akan membuatnya enam kali. Jadi kedua
| dokumen baru ini berbagi satu generator, dibedakan oleh satu tabel config di
| bawah. Yang lama tidak disentuh: menyatukan keempatnya adalah pekerjaan
| tersendiri yang menyentuh alur yang sedang berjalan, dan bukan pekerjaan
| yang pantas diselundupkan ke dalam fitur baru.
|
| Aturan approval-nya sendiri TIDAK ditulis ulang di sini. Semuanya tetap di
| ApprovalStepRuleService -- yang dipakai bersama keenam dokumen -- sehingga
| "pemohon tidak boleh menyetujui dokumennya sendiri" tetap berarti satu hal
| yang sama di seluruh aplikasi.
|--------------------------------------------------------------------------
*/
class PettyCashApprovalGeneratorService
{
    /**
     * Yang membedakan kedua dokumen. Selebihnya sama persis.
     *
     * @return array{approval: class-string<Model>, foreign: string, document_type: string, label: string}
     */
    private function config(Model $document): array
    {
        if ($document instanceof PettyCash) {
            return [
                'approval' => PettyCashApproval::class,
                'foreign' => 'petty_cash_id',
                'document_type' => ApprovalFlow::DOCUMENT_TYPE_PETTY_CASH,
                'label' => 'Petty Cash',
            ];
        }

        if ($document instanceof PettyCashTopup) {
            return [
                'approval' => PettyCashTopupApproval::class,
                'foreign' => 'petty_cash_topup_id',
                'document_type' => ApprovalFlow::DOCUMENT_TYPE_PETTY_CASH_TOPUP,
                'label' => 'Top Up Petty Cash',
            ];
        }

        throw new \InvalidArgumentException(
            'Generator ini hanya melayani PettyCash dan PettyCashTopup, bukan '
            . $document::class
        );
    }

    public function generate(Model $document): void
    {
        $cfg = $this->config($document);

        /** @var class-string<Model> $approvalClass */
        $approvalClass = $cfg['approval'];

        $sudahAda = $approvalClass::query()
            ->where($cfg['foreign'], $document->id)
            ->exists();

        if ($sudahAda) {
            throw ValidationException::withMessages([
                'approval' => ['Approval ' . $cfg['label'] . ' sudah pernah dibuat.'],
            ]);
        }

        $requesterId = (int) ($document->submitted_by ?? $document->created_by ?? 0);

        $totalAmount = (float) ($document->total_amount ?? $document->amount ?? 0);

        if ($totalAmount <= 0) {
            throw ValidationException::withMessages([
                'total_amount' => [
                    'Total nilai ' . $cfg['label'] . ' harus lebih besar dari 0.',
                ],
            ]);
        }

        $flow = $this->findMatchingFlow($document, $cfg, $totalAmount);

        if (!$flow) {
            $document->loadMissing('departmentData');

            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approval flow %s tidak ditemukan untuk area %s, department %s,'
                        . ' dan nominal Rp %s.'
                        . ' Lengkapi matriks approval-nya untuk kombinasi tersebut.',
                        $cfg['label'],
                        $document->getApprovalAreaType() ?: '-',
                        $document->departmentData?->nama ?? '-',
                        angka($totalAmount, 0),
                    ),
                ],
            ]);
        }

        $flowSteps = ApprovalFlowStep::query()
            ->with(['branchMappings', 'specialApprovers'])
            ->where('approval_flow_id', $flow->id)
            ->orderBy('step_order')
            ->orderBy('id')
            ->get();

        if ($flowSteps->isEmpty()) {
            throw ValidationException::withMessages([
                'approval_flow' => ['Approval flow ditemukan, tetapi belum memiliki approver.'],
            ]);
        }

        $aturan = app(ApprovalStepRuleService::class);

        $requesterRoleIds = $aturan->roleIdsOf($requesterId);

        /*
        | Nasib tiap step dihitung lebih dulu, dengan aturan yang sama persis
        | seperti keempat modul lain. Step bercakupan cabang ikut dicairkan
        | supaya keputusannya diambil atas orang yang benar-benar akan menjadi
        | approver dokumen ini, bukan atas role yang mungkin kosong di cabang
        | tersebut.
        */
        $rencanaStep = $aturan->planSteps(
            $flowSteps,
            $requesterId,
            $requesterRoleIds,
            function (ApprovalFlowStep $step) use ($document) {
                $scope = strtoupper(trim(
                    (string) ($step->approver_scope ?: ApprovalFlowStep::APPROVER_SCOPE_GLOBAL),
                ));

                $tipe = strtoupper(trim((string) $step->approver_type));

                if ($scope === ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH) {
                    return $this->resolveSameBranchApprovers($step, $document, $tipe);
                }

                if ($scope === ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES) {
                    return $this->resolveSelectedBranchApprovers($step, $document, $tipe);
                }

                return null;
            },
        );

        $aturan->assertApproverAda($rencanaStep, $cfg['label']);

        $candidates = $this->buildCandidates(
            $document,
            $cfg,
            $flow,
            $flowSteps,
            $requesterId,
            $rencanaStep,
        );

        if ($candidates === []) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Tidak ada approver yang tersisa pada approval flow ' . $cfg['label']
                    . ' ini untuk Anda. Hubungi admin untuk memeriksa matriks approval-nya.',
                ],
            ]);
        }

        $this->assertEveryStepHasApprover($candidates, $flowSteps, $cfg['label']);

        $this->persist($candidates, $approvalClass);
    }

    /*
    |--------------------------------------------------------------------------
    | Pengumpulan kandidat
    |--------------------------------------------------------------------------
    | Hasilnya array baris siap simpan, dikelompokkan per step_order. Status
    | belum diisi -- baru ditentukan sesudah diketahui step mana yang tersisa.
    |
    | @return array<int, array<int, array<string, mixed>>>
    */
    private function buildCandidates(
        Model $document,
        array $cfg,
        ApprovalFlow $flow,
        Collection $flowSteps,
        int $requesterId,
        array $rencanaStep,
    ): array {
        $aturan = app(ApprovalStepRuleService::class);

        $candidates = [];

        // Mencegah baris ganda pada step yang sama.
        $seen = [];

        foreach ($flowSteps as $flowStep) {
            $stepOrder = (int) $flowStep->step_order;

            $approverType = strtoupper(trim((string) $flowStep->approver_type));

            $approverScope = strtoupper(trim(
                (string) ($flowStep->approver_scope ?: ApprovalFlowStep::APPROVER_SCOPE_GLOBAL),
            ));

            $approvalMode = strtoupper(trim(
                (string) ($flowStep->approval_mode ?: ApprovalFlowStep::APPROVAL_MODE_ANY),
            ));

            if (!in_array($approvalMode, [
                ApprovalFlowStep::APPROVAL_MODE_ANY,
                ApprovalFlowStep::APPROVAL_MODE_ALL,
            ], true)) {
                $approvalMode = ApprovalFlowStep::APPROVAL_MODE_ANY;
            }

            $this->assertApproverTypeSupported($approverType, $flowStep);

            $sebabGugur = $rencanaStep['skipped'][(int) $flowStep->id] ?? null;

            if ($sebabGugur !== null) {
                Log::info('[Petty Cash Approval Generator] Step dilewati', [
                    'document' => $cfg['label'],
                    'document_id' => $document->id,
                    'step_order' => $stepOrder,
                    'label' => $flowStep->label,
                    'sebab' => $sebabGugur,
                ]);

                continue;
            }

            $rekan = $aturan->peerApproval($flowStep);

            $dasar = [
                $cfg['foreign'] => $document->id,
                'approval_flow_id' => $flow->id,
                'approval_flow_step_id' => $flowStep->id,
                'step_order' => $stepOrder,
                'label' => $flowStep->label,
                'approval_mode' => $approvalMode,
            ];

            /*
            | Scope cabang dicairkan menjadi USER konkret. GLOBAL disimpan apa
            | adanya: ROLE tetap ROLE, sehingga pergantian pemegang role masih
            | terbawa sampai dokumen disetujui.
            */
            if ($approverScope === ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH) {
                $resolved = $this->resolveSameBranchApprovers($flowStep, $document, $approverType);
            } elseif ($approverScope === ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES) {
                $resolved = $this->resolveSelectedBranchApprovers($flowStep, $document, $approverType);
            } elseif (
                $approverType === ApprovalFlowStep::APPROVER_TYPE_DEPARTMENT
                || ($rekan && $approverType === ApprovalFlowStep::APPROVER_TYPE_ROLE)
            ) {
                /*
                | DEPARTMENT tidak punya bentuk lain -- tidak ada baris approval
                | bertipe department di hilir.
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
                    | Akun nonaktif tidak masuk ke rantai approval. Orang yang
                    | akunnya sudah dimatikan tidak akan pernah menyetujui apa
                    | pun; barisnya hanya membuat "cukup satu dari tiga"
                    | terbaca lebih longgar daripada kenyataannya.
                    */
                    if (!in_array($userId, $idAktif, true)) {
                        continue;
                    }

                    $barisStep[] = $dasar + [
                        'approver_type' => ApprovalFlowStep::APPROVER_TYPE_USER,
                        'approver_id' => $userId,
                        'approver_name_snapshot' => $user->name ?? null,
                    ];
                }

                $barisStep = $this->saringRekan(
                    $barisStep,
                    $rekan,
                    $requesterId,
                    $document,
                    $flowStep,
                    $cfg,
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
            $baris = $this->saringRekan(
                $baris,
                $rekan,
                $requesterId,
                $document,
                $flowStep,
                $cfg,
                $aturan,
            );

            if ($baris === []) {
                continue;
            }

            $seen[$key] = true;

            $candidates[$stepOrder][] = $baris[0];
        }

        ksort($candidates);

        return $candidates;
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $candidates
     * @param  class-string<Model>  $approvalClass
     */
    private function persist(array $candidates, string $approvalClass): void
    {
        $stepPertama = min(array_keys($candidates));

        foreach ($candidates as $stepOrder => $rows) {
            foreach ($rows as $row) {
                $approvalClass::create($row + [
                    'status' => $stepOrder === $stepPertama
                        ? PettyCashApproval::STATUS_WAITING
                        : PettyCashApproval::STATUS_PENDING,
                ]);
            }
        }
    }

    /**
     * Mengeluarkan pemohon dari sebuah step yang boleh disetujui rekannya.
     *
     * Step biasa ($rekan = false) dikembalikan apa adanya.
     *
     * @param  array<int, array<string, mixed>>  $baris
     * @return array<int, array<string, mixed>>
     */
    private function saringRekan(
        array $baris,
        bool $rekan,
        int $requesterId,
        Model $document,
        ApprovalFlowStep $flowStep,
        array $cfg,
        ApprovalStepRuleService $aturan,
    ): array {
        if (!$rekan) {
            return $baris;
        }

        $sesudah = $aturan->withoutRequester($baris, $requesterId);

        if (count($sesudah) !== count($baris)) {
            Log::info('[Petty Cash Approval Generator] Pemohon dikeluarkan dari step rekan setingkat', [
                'document' => $cfg['label'],
                'document_id' => $document->id,
                'step_order' => (int) $flowStep->step_order,
                'label' => $flowStep->label,
                'tersisa' => count($sesudah),
            ]);
        }

        return $sesudah;
    }

    private function assertEveryStepHasApprover(
        array $candidates,
        Collection $flowSteps,
        string $label,
    ): void {
        foreach ($candidates as $stepOrder => $rows) {
            if ($rows !== []) {
                continue;
            }

            $stepLabel = $flowSteps
                ->firstWhere('step_order', $stepOrder)
                ?->label ?? sprintf('Step %d', $stepOrder);

            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approver untuk step "%s" pada cabang %s ini belum ditemukan.',
                        $stepLabel,
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

    private function findMatchingFlow(Model $document, array $cfg, float $totalAmount): ?ApprovalFlow
    {
        $areaType = $document->getApprovalAreaType();
        $departmentId = $document->department_id;

        if (!$departmentId) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Department ' . $cfg['label'] . ' tidak tersedia untuk mencari approval flow.',
                ],
            ]);
        }

        Log::info('[Petty Cash Approval Generator] Matching flow parameters', [
            'document' => $cfg['label'],
            'document_id' => $document->id,
            'document_type' => $cfg['document_type'],
            'branch_id' => $document->branch,
            'area_type' => $areaType,
            'creator_department_id' => (int) $departmentId,
            'total_amount' => $totalAmount,
        ]);

        $flow = ApprovalFlow::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereRaw('UPPER(TRIM(document_type)) = ?', [$cfg['document_type']])

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
                        fn ($q) => $q->where('departments.id', (int) $departmentId),
                    )
                    ->orWhere(function ($legacy) use ($departmentId) {
                        $legacy
                            ->where('creator_department_id', (int) $departmentId)
                            ->whereDoesntHave('departments');
                    });
            })

            /*
            | Rentang nominal dibandingkan sejak baris pertama, seperti FPU dan
            | Claim sekarang. Nominal yang tidak tercakup rentang mana pun
            | berarti tidak ada flow -- dan dokumennya ditolak saat submit,
            | bukan diloloskan diam-diam ke flow yang kebetulan id-nya terbesar.
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
            | COALESCE dipakai karena PostgreSQL menempatkan NULL lebih dulu
            | pada ORDER BY DESC -- tanpa itu, flow "semua nilai" justru menang
            | atas flow yang rentangnya lebih sempit.
            */
            ->orderBy('all_departments')
            ->orderByRaw('COALESCE(min_amount, 0) DESC')
            ->orderByRaw('COALESCE(NULLIF(max_amount, 0), 999999999999999999) ASC')
            ->orderByDesc('id')
            ->first();

        Log::info('[Petty Cash Approval Generator] Matching flow result', [
            'document' => $cfg['label'],
            'document_id' => $document->id,
            'approval_flow_id' => $flow?->id,
            'approval_flow_name' => $flow?->name,
            'area_type' => $flow?->area_type,
            'all_departments' => $flow?->all_departments,
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
     * tiga jalur, dan dua yang pertama membawa model User utuh sementara yang
     * ketiga hanya id dan nama. Membaca $user->is_active langsung akan
     * menghasilkan null pada jalur ketiga, dan null berarti seluruh
     * kandidatnya terbuang diam-diam.
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
        Model $document,
        string $approverType,
    ): Collection {
        $branchId = (int) $document->branch;
        $approverId = (int) $flowStep->approver_id;

        $this->assertCabangDanApprover($branchId, $approverId, $flowStep);

        /*
        | USER: dikembalikan hanya bila cabangnya sama. Bila berbeda, hasilnya
        | kosong dan kandidat ini dilewati.
        */
        if ($approverType === ApprovalFlowStep::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($approverId)
                ->where('users.cabang_id', $branchId)
                ->get();
        }

        // ROLE: seluruh user dengan role tersebut pada cabang dokumen.
        return User::query()
            ->where('users.cabang_id', $branchId)
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $approverId))
            ->get();
    }

    private function resolveSelectedBranchApprovers(
        ApprovalFlowStep $flowStep,
        Model $document,
        string $approverType,
    ): Collection {
        $branchId = (int) $document->branch;
        $approverId = (int) $flowStep->approver_id;

        $this->assertCabangDanApprover($branchId, $approverId, $flowStep);

        $branchIds = $flowStep
            ->branchMappings
            ->pluck('cabang_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
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
        | Cabang dokumen di luar cakupan approver ini bukan error -- mungkin
        | ada approver lain pada step yang sama yang menanganinya.
        */
        if (!$branchIds->contains($branchId)) {
            return collect();
        }

        if ($approverType === ApprovalFlowStep::APPROVER_TYPE_USER) {
            return User::query()->whereKey($approverId)->get();
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $approverId))
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    private function assertCabangDanApprover(
        int $branchId,
        int $approverId,
        ApprovalFlowStep $flowStep,
    ): void {
        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'branch' => ['Cabang dokumen belum ditentukan.'],
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
    }

    private function assertApproverTypeSupported(
        string $approverType,
        ApprovalFlowStep $flowStep,
    ): void {
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

        if ($approverType === ApprovalFlowStep::APPROVER_TYPE_USER) {
            return User::query()->whereKey($flowStep->approver_id)->value('name');
        }

        if ($approverType === ApprovalFlowStep::APPROVER_TYPE_ROLE) {
            return Role::query()->whereKey($flowStep->approver_id)->value('nama');
        }

        return null;
    }
}
