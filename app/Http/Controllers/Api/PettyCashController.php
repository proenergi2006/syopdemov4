<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\PettyCash;
use App\Models\PettyCashCeiling;
use App\Services\FundRequest\PettyCash\PettyCashApprovalGeneratorService;
use App\Services\FundRequest\PettyCash\PettyCashApprovalService;
use App\Services\FundRequest\PettyCash\PettyCashEligibilityService;
use App\Services\FundRequest\PettyCash\PettyCashLedgerService;
use App\Services\FundRequest\PettyCash\PettyCashNotificationService;
use App\Services\FundRequest\PettyCash\PettyCashNumberService;
use App\Services\FundRequest\PettyCash\PettyCashService;
use App\Support\DocumentActionScope;
use App\Support\PettyCashVisibility;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Petty Cash
|--------------------------------------------------------------------------
| Formulir ADH untuk membayar beberapa claim kecil sekaligus dari kas kecil
| cabangnya.
|
| Yang menyentuh uang TIDAK ada di sini -- pencairan, pembatalan, dan
| penautan claim dikerjakan PettyCashService, karena ketiganya mengubah dua
| tabel sekaligus dan harus berhasil atau gagal bersama. Controller ini
| mengurus validasi, cakupan akses, dan jawaban HTTP-nya.
|--------------------------------------------------------------------------
*/
class PettyCashController extends Controller
{
    private const TABEL = 'petty_cashes';
    private const TABEL_APPROVAL = 'petty_cash_approvals';

    public function __construct(
        private readonly PettyCashService $pettyCash,
        private readonly PettyCashEligibilityService $kelayakan,
        private readonly PettyCashLedgerService $ledger,
        private readonly PettyCashApprovalService $mesin,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Daftar
    |--------------------------------------------------------------------------
    */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk melihat Petty Cash.',
            ], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
            'branch' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $context = PettyCashVisibility::context($user, 'petty_cash.view');

        $query = PettyCash::query()
            ->with([
                'branchData:id,nama_cabang,inisial_cabang',
                'departmentData:id,nama,kode',
                'creator:id,name,username',
                'disburser:id,name',
            ])
            ->withCount(['activeClaimLinks as claim_count']);

        PettyCashVisibility::apply(
            $query,
            $user,
            $context,
            self::TABEL,
            self::TABEL_APPROVAL,
        );

        if (!empty($validated['search'])) {
            $cari = '%' . $validated['search'] . '%';

            $query->where(function ($q) use ($cari): void {
                $q->where('petty_cash_number', 'ilike', $cari)
                    ->orWhere('subject', 'ilike', $cari);
            });
        }

        if (!empty($validated['status'])) {
            $query->where('status', strtoupper($validated['status']));
        }

        if (!empty($validated['branch'])) {
            $query->where('branch', (string) $validated['branch']);
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('date', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('date', '<=', $validated['date_to']);
        }

        $halaman = $query
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $halaman->through(fn (PettyCash $row) => $this->baris($row, $user)),
            'meta' => [
                'scope' => $context['scope'],

                /*
                | Cabang yang kas kecilnya dipegang akun ini. Dipakai frontend
                | untuk memutuskan tombol "Buat" perlu ditampilkan atau tidak
                | -- memegang permission saja tidak cukup, cabangnya harus
                | punya plafon hidup.
                */
                'holder_branch_ids' => $this->kelayakan->holderBranchIds($user)->all(),

                /*
                | Cabang yang kas kecilnya sudah hidup. Dikirim supaya layar
                | bisa menjelaskan sendiri kenapa tombol "Buat" tidak ada:
                | memegang permission saja tidak cukup, dan pengguna tidak
                | punya cara lain mengetahui cabang mana yang sudah bisa.
                */
                'ceiling_branches' => PettyCashCeiling::query()
                    ->where('is_active', true)
                    ->with('branchData:id,nama_cabang')
                    ->get()
                    ->map(fn ($plafon) => $plafon->branchData?->nama_cabang)
                    ->filter()
                    ->values()
                    ->all(),
            ],
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Detail
    |--------------------------------------------------------------------------
    */
    public function show(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk melihat Petty Cash.',
            ], 403);
        }

        try {
            $dokumen = $this->findVisible($publicId, $request);
        } catch (DecryptException) {
            return $this->tidakDitemukan();
        }

        if (!$dokumen) {
            return $this->tidakDitemukan();
        }

        $dokumen->load([
            'branchData:id,nama_cabang,inisial_cabang',
            'departmentData:id,nama,kode',
            'creator:id,name,username',
            'submitter:id,name',
            'finalApprover:id,name',
            'disburser:id,name',
            'canceller:id,name',
            'rejecter:id,name',
            'approvals',
            'attachments',
            'activeClaimLinks.claim.creator:id,name,username',
            'activeClaimLinks.claim.departmentData:id,nama',
        ]);

        $plafon = $this->kelayakan->ceilingForBranch((int) $dokumen->branch);

        return response()->json([
            'success' => true,
            'data' => $this->baris($dokumen, $user) + [
                'notes' => $dokumen->notes,
                'disbursement_notes' => $dokumen->disbursement_notes,
                'cancellation_notes' => $dokumen->cancellation_notes,
                'rejection_notes' => $dokumen->rejection_notes,

                'claims' => $dokumen->activeClaimLinks->map(fn ($link) => [
                    'claim_id' => $link->claim_id,
                    'public_id' => $link->claim?->encrypted_id,
                    'claim_number' => $link->claim?->claim_number,
                    'date' => $link->claim?->date?->toDateString(),
                    'subject' => $link->claim?->subject,
                    'department' => $link->claim?->departmentData?->nama,
                    'requester' => $link->claim?->creator?->name,

                    /*
                    | Dua angka, sengaja ditampilkan berdampingan: nominal yang
                    | dibekukan formulir ini, dan nominal claim-nya sekarang.
                    | Keduanya harus sama -- kalau berbeda, itu tanda ada yang
                    | perlu diperiksa, dan menyembunyikannya tidak membantu.
                    */
                    'amount' => (float) $link->amount,
                    'claim_amount_now' => (float) ($link->claim?->total_amount ?? 0),

                    'status' => $link->claim?->status,
                ])->values(),

                'approvals' => $dokumen->approvals
                    ->sortBy([['step_order', 'asc'], ['id', 'asc']])
                    ->map(fn ($a) => [
                        'step_order' => (int) $a->step_order,
                        'label' => $a->label,
                        'approver_type' => $a->approver_type,
                        'approver_name' => $a->approver_name_snapshot,
                        'approval_mode' => $a->approval_mode,
                        'status' => $a->status,
                        'approved_at' => $a->approved_at,
                        'rejected_at' => $a->rejected_at,
                        'notes' => $a->notes,
                    ])->values(),

                'ceiling' => $plafon ? [
                    'ceiling_amount' => (float) $plafon->ceiling_amount,
                    'claim_threshold' => (float) $plafon->claim_threshold,
                    'balance' => $this->ledger->balance($plafon->id),
                ] : null,
            ],
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Kas kecil yang saya pegang
    |--------------------------------------------------------------------------
    | Dipakai panel di dashboard, dan inilah yang memunculkan peringatan saldo
    | menipis di hadapan ADH.
    |
    | Peringatannya juga dikirim sebagai notifikasi harian, tetapi notifikasi
    | bisa terlewat begitu ada yang lain menumpuk di atasnya. Panel ini tidak
    | bisa terlewat: ia ada di halaman yang dibuka ADH setiap pagi, dan ia
    | hilang sendiri begitu saldonya sudah di atas ambang.
    |--------------------------------------------------------------------------
    */
    public function myCeilings(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk melihat Petty Cash.',
            ], 403);
        }

        $branchIds = $this->kelayakan->holderBranchIds($user);

        if ($branchIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => []], 200);
        }

        $plafon = PettyCashCeiling::query()
            ->with('branchData:id,nama_cabang,inisial_cabang')
            ->whereIn('branch_id', $branchIds->all())
            ->where('is_active', true)
            ->orderBy('branch_id')
            ->get();

        $saldo = $this->ledger->balances(
            $plafon->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );

        /*
        | Top up yang sedang berjalan ikut dikirim. Tanpa itu, ADH yang sudah
        | mengajukan top up kemarin akan melihat peringatan yang sama hari ini
        | dan mengira pengajuannya tidak tercatat.
        */
        $topupBerjalan = \App\Models\PettyCashTopup::query()
            ->whereIn('petty_cash_ceiling_id', $plafon->pluck('id')->all())
            ->whereIn('status', ['DRAFT', 'IN PROGRESS'])
            ->get()
            ->keyBy('petty_cash_ceiling_id');

        return response()->json([
            'success' => true,
            'data' => $plafon->map(function (PettyCashCeiling $row) use ($saldo, $topupBerjalan) {
                $s = $saldo[$row->id] ?? 0.0;
                $berjalan = $topupBerjalan->get($row->id);

                return [
                    'public_id' => $row->encrypted_id,
                    'branch_id' => (int) $row->branch_id,
                    'branch' => $row->branchData?->nama_cabang,
                    'ceiling_amount' => (float) $row->ceiling_amount,
                    'balance' => $s,
                    'claim_threshold' => (float) $row->claim_threshold,
                    'warning_percentage' => (int) $row->warning_percentage,
                    'warning_threshold' => $row->warningThreshold(),
                    'below_warning' => $s < $row->warningThreshold(),
                    'topup_amount' => max(0.0, (float) $row->ceiling_amount - $s),

                    'running_topup' => $berjalan ? [
                        'topup_number' => $berjalan->topup_number,
                        'status' => $berjalan->status,
                        'amount' => (float) $berjalan->amount,
                    ] : null,

                    /* Berapa claim kecil yang sudah menunggu dibayar di cabang itu. */
                    'pending_claims' => $this->kelayakan
                        ->eligibleClaims((int) $row->branch_id)
                        ->count(),
                ];
            })->values(),
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Claim yang siap dibayar
    |--------------------------------------------------------------------------
    | Dipakai formulir saat memilih claim. Terpisah dari index claim biasa
    | karena syaratnya berbeda -- bukan sekadar "claim yang terlihat".
    |--------------------------------------------------------------------------
    */
    public function eligibleClaims(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash.create')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda bukan pemegang kas kecil.',
            ], 403);
        }

        $validated = $request->validate([
            'branch' => ['required', 'integer'],
            'petty_cash_id' => ['nullable', 'string'],
        ]);

        $branchId = (int) $validated['branch'];

        if (!$this->kelayakan->isHolderFor($user, $branchId)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda bukan pemegang kas kecil cabang tersebut.',
            ], 403);
        }

        /*
        | Claim yang sudah ada di formulir yang sedang disunting tetap
        | ditawarkan -- kalau tidak, membuka formulir untuk diubah akan
        | memperlihatkan isinya sendiri sebagai tidak tersedia.
        */
        $kecuali = [];

        if (!empty($validated['petty_cash_id'])) {
            try {
                $id = (int) Crypt::decryptString($validated['petty_cash_id']);

                $kecuali = DB::table('petty_cash_claims')
                    ->where('petty_cash_id', $id)
                    ->whereNull('released_at')
                    ->pluck('claim_id')
                    ->map(fn ($x): int => (int) $x)
                    ->all();
            } catch (DecryptException) {
                $kecuali = [];
            }
        }

        $plafon = $this->kelayakan->ceilingForBranch($branchId);

        return response()->json([
            'success' => true,
            'data' => $this->kelayakan->eligibleClaims($branchId, $kecuali)->map(fn (Claim $c) => [
                'id' => $c->id,
                'public_id' => $c->encrypted_id,
                'claim_number' => $c->claim_number,
                'date' => $c->date?->toDateString(),
                'subject' => $c->subject,
                'department' => $c->departmentData?->nama,
                'requester' => $c->creator?->name,
                'amount' => (float) $c->total_amount,
            ])->values(),
            'meta' => [
                'claim_threshold' => $plafon ? (float) $plafon->claim_threshold : null,
                'balance' => $plafon ? $this->ledger->balance($plafon->id) : null,
                'ceiling_amount' => $plafon ? (float) $plafon->ceiling_amount : null,
            ],
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Simpan
    |--------------------------------------------------------------------------
    */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash.create')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat Petty Cash.',
            ], 403);
        }

        $validated = $request->validate($this->aturan());

        $branchId = (int) $validated['branch'];

        if (!$this->kelayakan->isHolderFor($user, $branchId)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda bukan pemegang kas kecil cabang tersebut.',
            ], 403);
        }

        $plafon = $this->kelayakan->ceilingForBranch($branchId);

        if (!$plafon) {
            return response()->json([
                'success' => false,
                'message' => 'Cabang tersebut belum punya plafon petty cash yang aktif.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $dokumen = PettyCash::create([
                'petty_cash_number' => app(PettyCashNumberService::class)
                    ->generateDraftNumber(PettyCash::class),
                'date' => $validated['date'],
                'subject' => $this->bersihkan($validated['subject']),
                'branch' => (string) $branchId,

                /*
                | Department diambil dari plafonnya, bukan dari isian pemohon:
                | kas kecil itu milik satu department, dan formulirnya harus
                | berdiri di department yang sama supaya alur approval-nya
                | terbaca sebagaimana dirancang.
                */
                'department_id' => $plafon->department_id,

                'petty_cash_ceiling_id' => $plafon->id,
                'total_amount' => 0,
                'notes' => $this->bersihkan($validated['notes'] ?? null),
                'status' => PettyCash::STATUS_DRAFT,
                'created_by' => $user->id,
            ]);

            $this->pettyCash->syncClaims($dokumen, $validated['claim_ids'], $user);

            DB::commit();

            $dokumen->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Petty Cash berhasil dibuat.',
                'data' => [
                    'public_id' => $dokumen->encrypted_id,
                    'petty_cash_number' => $dokumen->petty_cash_number,
                    'total_amount' => (float) $dokumen->total_amount,
                ],
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal('store', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Ubah
    |--------------------------------------------------------------------------
    */
    public function update(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash.update')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah Petty Cash.',
            ], 403);
        }

        $validated = $request->validate($this->aturan(ubah: true));

        DB::beginTransaction();

        try {
            $dokumen = PettyCash::query()->lockForUpdate()->findOrFail(
                Crypt::decryptString($publicId),
            );

            if (!$this->bolehBeraksi($user, $dokumen, 'petty_cash.update')) {
                DB::rollBack();

                return $this->tidakDitemukan();
            }

            if (strtoupper((string) $dokumen->status) !== PettyCash::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Hanya Petty Cash berstatus draft yang dapat diubah.',
                ], 422);
            }

            $dokumen->update([
                'date' => $validated['date'],
                'subject' => $this->bersihkan($validated['subject']),
                'notes' => $this->bersihkan($validated['notes'] ?? null),
                'updated_by' => $user->id,
            ]);

            $this->pettyCash->syncClaims($dokumen, $validated['claim_ids'], $user);

            DB::commit();

            $dokumen->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Petty Cash berhasil diperbarui.',
                'data' => [
                    'public_id' => $dokumen->encrypted_id,
                    'total_amount' => (float) $dokumen->total_amount,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (ModelNotFoundException|DecryptException) {
            DB::rollBack();

            return $this->tidakDitemukan();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal('update', $e);
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

        if (!$user || !$user->hasPermission('petty_cash.delete')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus Petty Cash.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $dokumen = PettyCash::query()->lockForUpdate()->findOrFail(
                Crypt::decryptString($publicId),
            );

            if (!$this->bolehBeraksi($user, $dokumen, 'petty_cash.delete')) {
                DB::rollBack();

                return $this->tidakDitemukan();
            }

            if (strtoupper((string) $dokumen->status) !== PettyCash::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Hanya Petty Cash berstatus draft yang dapat dihapus.',
                ], 422);
            }

            /*
            | Pasangan claimnya dilepas lebih dulu. Tanpa ini, claim-claimnya
            | akan tetap terikat formulir yang sudah tidak ada dan tidak bisa
            | dipakai formulir mana pun -- terkunci tanpa sebab yang terlihat.
            */
            $this->pettyCash->releaseClaims($dokumen);

            $dokumen->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Petty Cash berhasil dihapus.',
            ], 200);
        } catch (ModelNotFoundException|DecryptException) {
            DB::rollBack();

            return $this->tidakDitemukan();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal('destroy', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Ajukan
    |--------------------------------------------------------------------------
    */
    public function submit(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash.submit')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengajukan Petty Cash.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $dokumen = PettyCash::query()->lockForUpdate()->findOrFail(
                Crypt::decryptString($publicId),
            );

            if (!$this->bolehBeraksi($user, $dokumen, 'petty_cash.submit')) {
                DB::rollBack();

                return $this->tidakDitemukan();
            }

            if (strtoupper((string) $dokumen->status) !== PettyCash::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Hanya Petty Cash berstatus draft yang dapat diajukan.',
                ], 422);
            }

            /*
            | Isinya diperiksa ulang saat diajukan, bukan hanya saat disimpan.
            |
            | Di antara disimpan dan diajukan, claim di dalamnya bisa berubah
            | -- dibatalkan, ditarik penerimaannya, atau nominalnya dibetulkan.
            | Memeriksa ulang di sini memastikan yang diajukan adalah keadaan
            | sekarang, bukan keadaan saat draft dibuat.
            */
            $this->pettyCash->syncClaims(
                $dokumen,
                $dokumen->activeClaimLinks()->pluck('claim_id')->all(),
                $user,
            );

            $dokumen->refresh();

            $dokumen->update([
                'status' => PettyCash::STATUS_IN_PROGRESS,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'requester_signed_by' => $user->id,
                'requester_signature_path' => $user->signature_path,
                'requester_signed_at' => $user->signature_path ? now() : null,
            ]);

            if (str_starts_with((string) $dokumen->petty_cash_number, 'DRAFT/')) {
                $dokumen->petty_cash_number = app(PettyCashNumberService::class)
                    ->generateFinalNumber($dokumen);
                $dokumen->save();
            }

            app(PettyCashApprovalGeneratorService::class)->generate($dokumen);

            DB::commit();

            $dokumen->refresh();

            /*
            | Notifikasi dikirim SESUDAH commit, bukan di dalamnya. Kabar
            | tentang dokumen yang ternyata gagal tersimpan lebih buruk
            | daripada kabar yang terlambat.
            */
            app(PettyCashNotificationService::class)->notifyApprovalRequest($dokumen);

            return response()->json([
                'success' => true,
                'message' => 'Petty Cash berhasil diajukan.',
                'data' => [
                    'public_id' => $dokumen->encrypted_id,
                    'petty_cash_number' => $dokumen->petty_cash_number,
                    'status' => $dokumen->status,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (ModelNotFoundException|DecryptException) {
            DB::rollBack();

            return $this->tidakDitemukan();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal('submit', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Setujui / tolak
    |--------------------------------------------------------------------------
    | Tidak memakai permission tersendiri, mengikuti keempat modul lain: yang
    | boleh menyetujui adalah orang yang namanya tercatat pada baris approval
    | dokumen ini.
    |--------------------------------------------------------------------------
    */
    public function approve(string $publicId, Request $request): JsonResponse
    {
        return $this->putuskan($publicId, $request, setuju: true);
    }

    public function reject(string $publicId, Request $request): JsonResponse
    {
        return $this->putuskan($publicId, $request, setuju: false);
    }

    private function putuskan(string $publicId, Request $request, bool $setuju): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Tidak terautentikasi.'], 401);
        }

        $validated = $request->validate([
            'notes' => $setuju
                ? ['nullable', 'string', 'max:2000']
                : ['required', 'string', 'min:3', 'max:2000'],
        ]);

        DB::beginTransaction();

        try {
            $dokumen = PettyCash::query()->lockForUpdate()->findOrFail(
                Crypt::decryptString($publicId),
            );

            if (strtoupper((string) $dokumen->status) !== PettyCash::STATUS_IN_PROGRESS) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Petty Cash ini sudah tidak dalam proses approval.',
                ], 422);
            }

            $hasil = $setuju
                ? $this->mesin->approveCurrentStep($dokumen, $user, $validated['notes'] ?? null)
                : $this->mesin->rejectCurrentStep($dokumen, $user, $validated['notes']);

            /*
            | Formulir yang DITOLAK melepas claim-claimnya, supaya tidak
            | terkunci di dokumen yang tidak akan pernah dicairkan.
            */
            if (!$setuju) {
                $this->pettyCash->releaseClaims($dokumen);
            }

            DB::commit();

            $dokumen->refresh();

            $notif = app(PettyCashNotificationService::class);

            if ($setuju) {
                $masihAdaTahap = (bool) ($hasil['has_pending_approval'] ?? false);

                $notif->notifyApprovalStep($dokumen, $user, $masihAdaTahap);

                /* Tingkat berikutnya baru aktif -- penyetujunya perlu dikabari. */
                if ($masihAdaTahap) {
                    $notif->notifyApprovalRequest($dokumen);
                }
            } else {
                $notif->notifyRejected($dokumen, $user, $validated['notes']);
            }

            return response()->json([
                'success' => true,
                'message' => $setuju
                    ? (($hasil['is_final_approved'] ?? false)
                        ? 'Petty Cash disetujui penuh dan siap dicairkan.'
                        : 'Petty Cash berhasil disetujui.')
                    : 'Petty Cash berhasil ditolak.',
                'data' => [
                    'public_id' => $dokumen->encrypted_id,
                    'status' => $dokumen->status,
                ],
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (ModelNotFoundException|DecryptException) {
            DB::rollBack();

            return $this->tidakDitemukan();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal($setuju ? 'approve' : 'reject', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cairkan
    |--------------------------------------------------------------------------
    */
    public function disburse(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash.disburse')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mencairkan Petty Cash.',
            ], 403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::beginTransaction();

        try {
            $dokumen = PettyCash::query()->lockForUpdate()->findOrFail(
                Crypt::decryptString($publicId),
            );

            /*
            | Yang mencairkan harus pemegang kas kecil cabang itu.
            |
            | Diperiksa terpisah dari permission, karena petty_cash.disburse
            | tidak memakai scope: ia menjawab "boleh mencairkan", bukan "kas
            | kecil yang mana".
            */
            if (!$this->kelayakan->isHolderFor($user, (int) $dokumen->branch)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Anda bukan pemegang kas kecil cabang ini.',
                ], 403);
            }

            $hasil = $this->pettyCash->disburse($dokumen, $user, $this->bersihkan($validated['notes'] ?? null));

            if (!$hasil['ok']) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => $hasil['message'],
                ], 422);
            }

            DB::commit();

            $dokumen->refresh();

            /*
            | Dua arah: pemohon formulirnya, dan PARA PEMOHON CLAIM di
            | dalamnya. Arah kedua yang paling berguna -- uang mereka baru
            | saja dibayarkan, dan tanpa kabar ini mereka tidak punya cara
            | mengetahuinya selain membuka daftar claim sendiri.
            */
            app(PettyCashNotificationService::class)->notifyDisbursed($dokumen, $user);

            return response()->json([
                'success' => true,
                'message' => 'Petty Cash berhasil dicairkan. Claim di dalamnya ditandai sudah dibayar.',
                'data' => [
                    'public_id' => $dokumen->encrypted_id,
                    'status' => $dokumen->status,
                    'balance_after' => $hasil['balance_after'],
                ],
            ], 200);
        } catch (ModelNotFoundException|DecryptException) {
            DB::rollBack();

            return $this->tidakDitemukan();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal('disburse', $e);
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

        if (!$user || !$user->hasPermission('petty_cash.cancel')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membatalkan Petty Cash.',
            ], 403);
        }

        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        DB::beginTransaction();

        try {
            $dokumen = PettyCash::query()->lockForUpdate()->findOrFail(
                Crypt::decryptString($publicId),
            );

            if (!$this->bolehBeraksi($user, $dokumen, 'petty_cash.cancel')) {
                DB::rollBack();

                return $this->tidakDitemukan();
            }

            $hasil = $this->pettyCash->cancel($dokumen, $user, $this->bersihkan($validated['notes']));

            if (!$hasil['ok']) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => $hasil['message'],
                ], 422);
            }

            DB::commit();

            $dokumen->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Petty Cash berhasil dibatalkan. Claim di dalamnya bisa dipakai formulir lain.',
                'data' => [
                    'public_id' => $dokumen->encrypted_id,
                    'status' => $dokumen->status,
                ],
            ], 200);
        } catch (ModelNotFoundException|DecryptException) {
            DB::rollBack();

            return $this->tidakDitemukan();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal('cancel', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Dalam
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function aturan(bool $ubah = false): array
    {
        $aturan = [
            'date' => ['required', 'date'],
            'subject' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'claim_ids' => ['required', 'array', 'min:1'],
            'claim_ids.*' => ['required', 'integer', 'min:1'],
        ];

        if (!$ubah) {
            $aturan['branch'] = ['required', 'integer', 'min:1'];
        }

        return $aturan;
    }

    private function findVisible(string $publicId, Request $request): ?PettyCash
    {
        $user = $request->user();

        $context = PettyCashVisibility::context($user, 'petty_cash.view');

        $query = PettyCash::query()->whereKey(Crypt::decryptString($publicId));

        PettyCashVisibility::apply(
            $query,
            $user,
            $context,
            self::TABEL,
            self::TABEL_APPROVAL,
        );

        return $query->first();
    }

    /**
     * Scope permission AKSI, bukan scope view.
     *
     * Dipakai di setiap aksi yang mengubah dokumen, memakai penjaga yang sama
     * dengan keempat modul lain -- supaya "view: ALL, cancel: OWN_DATA"
     * berbunyi sebagaimana mestinya di sini juga.
     */
    private function bolehBeraksi($user, PettyCash $dokumen, string $permission): bool
    {
        return DocumentActionScope::allows($user, $permission, $dokumen, [
            'branch' => 'branch',
            'department' => 'department_id',
            'owner' => 'created_by',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(PettyCash $row, $user): array
    {
        return [
            'public_id' => $row->encrypted_id,
            'petty_cash_number' => $row->petty_cash_number,
            'date' => $row->date?->toDateString(),
            'subject' => $row->subject,
            'branch' => $row->branchData?->nama_cabang,
            'branch_id' => (int) $row->branch,
            'department' => $row->departmentData?->nama,
            'total_amount' => (float) $row->total_amount,
            'claim_count' => (int) ($row->claim_count ?? $row->activeClaimLinks()->count()),
            'status' => $row->status,
            'requester' => $row->creator?->name,
            'submitted_at' => $row->submitted_at,
            'final_approved_at' => $row->final_approved_at,
            'disbursed_at' => $row->disbursed_at,
            'disbursed_by' => $row->disburser?->name,
            'cancelled_at' => $row->cancelled_at,

            /*
            | Keputusan tombol diambil di server, bukan di layar.
            |
            | Frontend yang memutuskan sendiri akan selalu menjawab berbeda
            | dari server pada keadaan pinggir -- dan yang terlihat pengguna
            | adalah tombol yang ada tetapi menolak saat ditekan.
            */
            'can' => [
                'update' => strtoupper((string) $row->status) === PettyCash::STATUS_DRAFT
                    && $user->hasPermission('petty_cash.update')
                    && $this->bolehBeraksi($user, $row, 'petty_cash.update'),

                'delete' => strtoupper((string) $row->status) === PettyCash::STATUS_DRAFT
                    && $user->hasPermission('petty_cash.delete')
                    && $this->bolehBeraksi($user, $row, 'petty_cash.delete'),

                'submit' => strtoupper((string) $row->status) === PettyCash::STATUS_DRAFT
                    && $user->hasPermission('petty_cash.submit')
                    && $this->bolehBeraksi($user, $row, 'petty_cash.submit'),

                'disburse' => strtoupper((string) $row->status) === PettyCash::STATUS_APPROVED
                    && $user->hasPermission('petty_cash.disburse')
                    && $this->kelayakan->isHolderFor($user, (int) $row->branch),

                'cancel' => strtoupper((string) $row->status) === PettyCash::STATUS_APPROVED
                    && $user->hasPermission('petty_cash.cancel')
                    && $this->bolehBeraksi($user, $row, 'petty_cash.cancel'),

                'approve' => strtoupper((string) $row->status) === PettyCash::STATUS_IN_PROGRESS
                    && $this->mesin->getUserCurrentApproval($row, $user) !== null,
            ],
        ];
    }

    private function bersihkan(?string $teks): ?string
    {
        $teks = trim((string) $teks);

        if ($teks === '') {
            return null;
        }

        return htmlspecialchars(strip_tags($teks), ENT_QUOTES, 'UTF-8');
    }

    private function tidakDitemukan(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Petty Cash tidak ditemukan.',
        ], 404);
    }

    private function gagal(string $aksi, \Throwable $e): JsonResponse
    {
        Log::error('[Petty Cash] ' . $aksi . ' error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Proses Petty Cash gagal.',
            'debug' => app()->environment('local') ? $e->getMessage() : null,
        ], 500);
    }
}
