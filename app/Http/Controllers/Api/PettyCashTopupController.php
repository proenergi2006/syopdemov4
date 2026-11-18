<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PettyCashCeiling;
use App\Models\PettyCashTopup;
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
| Pengisian kembali kas kecil
|--------------------------------------------------------------------------
| Nominalnya TIDAK diisi pemohon. Ia dihitung saat pengajuan dibuat: plafon
| dikurangi saldo berjalan. Formulirnya hanya menanyakan tanggal dan
| keterangan.
|
| Tidak ada tahap pencairan tersendiri. Begitu disetujui penuh, saldo langsung
| bertambah -- karena yang menyetujui terakhir adalah Finance yang juga
| mengirim uangnya, jadi persetujuannya dan keluarnya uang satu tindakan.
|--------------------------------------------------------------------------
*/
class PettyCashTopupController extends Controller
{
    private const TABEL = 'petty_cash_topups';
    private const TABEL_APPROVAL = 'petty_cash_topup_approvals';

    public function __construct(
        private readonly PettyCashService $pettyCash,
        private readonly PettyCashEligibilityService $kelayakan,
        private readonly PettyCashLedgerService $ledger,
        private readonly PettyCashApprovalService $mesin,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash_topup.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk melihat Top Up Petty Cash.',
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

        $context = PettyCashVisibility::context($user, 'petty_cash_topup.view');

        $query = PettyCashTopup::query()->with([
            'branchData:id,nama_cabang,inisial_cabang',
            'departmentData:id,nama,kode',
            'creator:id,name,username',
            'finalApprover:id,name',
        ]);

        PettyCashVisibility::apply($query, $user, $context, self::TABEL, self::TABEL_APPROVAL);

        if (!empty($validated['search'])) {
            $cari = '%' . $validated['search'] . '%';

            $query->where(function ($q) use ($cari): void {
                $q->where('topup_number', 'ilike', $cari)->orWhere('subject', 'ilike', $cari);
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
            'data' => $halaman->through(fn (PettyCashTopup $row) => $this->baris($row, $user)),
            'meta' => [
                'scope' => $context['scope'],
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

    public function show(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash_topup.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk melihat Top Up Petty Cash.',
            ], 403);
        }

        try {
            $dokumen = $this->findVisible($publicId, $user);
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
            'canceller:id,name',
            'rejecter:id,name',
            'approvals',
            'ceiling',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->baris($dokumen, $user) + [
                'notes' => $dokumen->notes,
                'rejection_notes' => $dokumen->rejection_notes,
                'cancellation_notes' => $dokumen->cancellation_notes,

                /*
                | Saldo sekarang ikut dikirim, berdampingan dengan saldo saat
                | diajukan. Kalau keduanya berbeda, berarti ada pencairan di
                | antaranya -- dan uang yang dikirim nanti tidak akan
                | mengembalikan saldo tepat ke plafon. Itu harus terlihat
                | penyetujunya, bukan ditemukan belakangan.
                */
                'balance_now' => $dokumen->ceiling
                    ? $this->ledger->balance($dokumen->ceiling->id)
                    : null,

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
            ],
        ], 200);
    }

    /**
     * Keadaan kas kecil sebuah cabang, untuk formulir pengajuan.
     *
     * Dipakai layar untuk memperlihatkan nominal yang akan diajukan SEBELUM
     * tombolnya ditekan -- angka yang muncul tiba-tiba sesudah menyimpan
     * adalah angka yang tidak pernah disetujui siapa pun dengan sadar.
     */
    public function ceilingState(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash_topup.create')) {
            return response()->json(['success' => false, 'message' => 'Tidak memiliki akses.'], 403);
        }

        $validated = $request->validate([
            'branch' => ['required', 'integer', 'min:1'],
        ]);

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

        $saldo = $this->ledger->balance($plafon->id);

        $berjalan = PettyCashTopup::query()
            ->where('petty_cash_ceiling_id', $plafon->id)
            ->whereIn('status', [PettyCashTopup::STATUS_DRAFT, PettyCashTopup::STATUS_IN_PROGRESS])
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'ceiling_amount' => (float) $plafon->ceiling_amount,
                'balance' => $saldo,
                'amount' => $this->pettyCash->hitungNominalTopup($plafon),
                'warning_percentage' => (int) $plafon->warning_percentage,
                'warning_threshold' => $plafon->warningThreshold(),
                'below_warning' => $saldo < $plafon->warningThreshold(),

                /*
                | Satu top up berjalan per plafon. Diberitahukan di sini supaya
                | layar bisa menjelaskannya lebih dulu, alih-alih membiarkan
                | penyimpanan ditolak database dengan pesan yang tidak bisa
                | dibaca siapa pun.
                */
                'running_topup' => $berjalan ? [
                    'public_id' => $berjalan->encrypted_id,
                    'topup_number' => $berjalan->topup_number,
                    'status' => $berjalan->status,
                ] : null,
            ],
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash_topup.create')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengajukan Top Up Petty Cash.',
            ], 403);
        }

        $validated = $request->validate([
            'branch' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date'],
            'subject' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $branchId = (int) $validated['branch'];

        if (!$this->kelayakan->isHolderFor($user, $branchId)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda bukan pemegang kas kecil cabang tersebut.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $plafon = $this->kelayakan->ceilingForBranch($branchId);

            if (!$plafon) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Cabang tersebut belum punya plafon petty cash yang aktif.',
                ], 422);
            }

            /*
            | Plafon dikunci sebelum saldonya dibaca. Tanpa ini, sebuah
            | pencairan yang terjadi pada detik yang sama membuat nominal top
            | up dihitung dari saldo yang sudah kedaluwarsa.
            */
            DB::table('petty_cash_ceilings')->where('id', $plafon->id)->lockForUpdate()->value('id');

            $saldo = $this->ledger->balance($plafon->id);
            $nominal = $this->pettyCash->hitungNominalTopup($plafon);

            if ($nominal === null) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Saldo kas kecil sudah penuh. Tidak ada yang perlu diisi.',
                ], 422);
            }

            $dokumen = PettyCashTopup::create([
                'topup_number' => app(PettyCashNumberService::class)
                    ->generateDraftNumber(PettyCashTopup::class),
                'date' => $validated['date'],
                'subject' => $this->bersihkan($validated['subject'] ?? null)
                    ?: 'Pengisian kembali kas kecil',
                'branch' => (string) $branchId,
                'department_id' => $plafon->department_id,
                'petty_cash_ceiling_id' => $plafon->id,
                'ceiling_amount' => (float) $plafon->ceiling_amount,
                'balance_before' => $saldo,
                'amount' => $nominal,
                'notes' => $this->bersihkan($validated['notes'] ?? null),
                'status' => PettyCashTopup::STATUS_DRAFT,
                'created_by' => $user->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan top up berhasil dibuat.',
                'data' => [
                    'public_id' => $dokumen->encrypted_id,
                    'topup_number' => $dokumen->topup_number,
                    'amount' => (float) $dokumen->amount,
                ],
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();

            /*
            | Indeks "satu top up berjalan per plafon" yang menahan. Dijawab
            | dengan keterangan, bukan galat database -- yang menekan tombol
            | tidak perlu tahu nama indeksnya.
            */
            if (str_contains($e->getMessage(), 'petty_cash_topups_running_unique')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Masih ada pengajuan top up yang berjalan untuk cabang ini. '
                        . 'Selesaikan atau batalkan dulu yang itu.',
                ], 422);
            }

            return $this->gagal('store', $e);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal('store', $e);
        }
    }

    public function update(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash_topup.update')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah Top Up Petty Cash.',
            ], 403);
        }

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'subject' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::beginTransaction();

        try {
            $dokumen = PettyCashTopup::query()->lockForUpdate()->findOrFail(
                Crypt::decryptString($publicId),
            );

            if (!$this->bolehBeraksi($user, $dokumen, 'petty_cash_topup.update')) {
                DB::rollBack();

                return $this->tidakDitemukan();
            }

            if (strtoupper((string) $dokumen->status) !== PettyCashTopup::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Hanya pengajuan berstatus draft yang dapat diubah.',
                ], 422);
            }

            /*
            | Nominalnya ikut dihitung ulang, karena saldo bisa bergerak selama
            | dokumen masih draft. Yang tidak dihitung ulang adalah nominal
            | dokumen yang sudah DIAJUKAN -- lihat keterangan di migrasinya.
            */
            $plafon = $dokumen->ceiling;
            $saldo = $this->ledger->balance($plafon->id);

            $dokumen->update([
                'date' => $validated['date'],
                'subject' => $this->bersihkan($validated['subject'] ?? null) ?: $dokumen->subject,
                'notes' => $this->bersihkan($validated['notes'] ?? null),
                'ceiling_amount' => (float) $plafon->ceiling_amount,
                'balance_before' => $saldo,
                'amount' => $this->pettyCash->hitungNominalTopup($plafon) ?? 0,
                'updated_by' => $user->id,
            ]);

            DB::commit();

            $dokumen->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan top up berhasil diperbarui.',
                'data' => [
                    'public_id' => $dokumen->encrypted_id,
                    'amount' => (float) $dokumen->amount,
                ],
            ], 200);
        } catch (ModelNotFoundException|DecryptException) {
            DB::rollBack();

            return $this->tidakDitemukan();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal('update', $e);
        }
    }

    public function destroy(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash_topup.delete')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus Top Up Petty Cash.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $dokumen = PettyCashTopup::query()->lockForUpdate()->findOrFail(
                Crypt::decryptString($publicId),
            );

            if (!$this->bolehBeraksi($user, $dokumen, 'petty_cash_topup.delete')) {
                DB::rollBack();

                return $this->tidakDitemukan();
            }

            if (strtoupper((string) $dokumen->status) !== PettyCashTopup::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Hanya pengajuan berstatus draft yang dapat dihapus.',
                ], 422);
            }

            $dokumen->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan top up berhasil dihapus.',
            ], 200);
        } catch (ModelNotFoundException|DecryptException) {
            DB::rollBack();

            return $this->tidakDitemukan();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal('destroy', $e);
        }
    }

    public function submit(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash_topup.submit')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengajukan Top Up Petty Cash.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $dokumen = PettyCashTopup::query()->lockForUpdate()->findOrFail(
                Crypt::decryptString($publicId),
            );

            if (!$this->bolehBeraksi($user, $dokumen, 'petty_cash_topup.submit')) {
                DB::rollBack();

                return $this->tidakDitemukan();
            }

            if (strtoupper((string) $dokumen->status) !== PettyCashTopup::STATUS_DRAFT) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Hanya pengajuan berstatus draft yang dapat diajukan.',
                ], 422);
            }

            /*
            | Angkanya dibekukan DI SINI, pada keadaan saldo saat diajukan --
            | dan tidak dihitung ulang lagi sesudahnya, termasuk saat
            | disetujui. Alasannya dan akibatnya ditulis panjang di kepala
            | migrasi 2026_11_04_100200.
            */
            $plafon = $dokumen->ceiling;

            DB::table('petty_cash_ceilings')->where('id', $plafon->id)->lockForUpdate()->value('id');

            $saldo = $this->ledger->balance($plafon->id);
            $nominal = $this->pettyCash->hitungNominalTopup($plafon);

            if ($nominal === null) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Saldo kas kecil sudah penuh. Pengajuan ini tidak perlu dilanjutkan.',
                ], 422);
            }

            $dokumen->update([
                'ceiling_amount' => (float) $plafon->ceiling_amount,
                'balance_before' => $saldo,
                'amount' => $nominal,
                'status' => PettyCashTopup::STATUS_IN_PROGRESS,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'requester_signed_by' => $user->id,
                'requester_signature_path' => $user->signature_path,
                'requester_signed_at' => $user->signature_path ? now() : null,
            ]);

            if (str_starts_with((string) $dokumen->topup_number, 'DRAFT/')) {
                $dokumen->topup_number = app(PettyCashNumberService::class)
                    ->generateFinalNumber($dokumen);
                $dokumen->save();
            }

            app(PettyCashApprovalGeneratorService::class)->generate($dokumen);

            DB::commit();

            $dokumen->refresh();

            app(PettyCashNotificationService::class)->notifyApprovalRequest($dokumen);

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan top up berhasil diajukan.',
                'data' => [
                    'public_id' => $dokumen->encrypted_id,
                    'topup_number' => $dokumen->topup_number,
                    'amount' => (float) $dokumen->amount,
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
            $dokumen = PettyCashTopup::query()->lockForUpdate()->findOrFail(
                Crypt::decryptString($publicId),
            );

            if (strtoupper((string) $dokumen->status) !== PettyCashTopup::STATUS_IN_PROGRESS) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Pengajuan ini sudah tidak dalam proses approval.',
                ], 422);
            }

            if (!$setuju) {
                $this->mesin->rejectCurrentStep($dokumen, $user, $validated['notes']);

                DB::commit();

                app(PettyCashNotificationService::class)
                    ->notifyRejected($dokumen->fresh(), $user, $validated['notes']);

                return response()->json([
                    'success' => true,
                    'message' => 'Pengajuan top up ditolak.',
                    'data' => ['public_id' => $dokumen->encrypted_id, 'status' => $dokumen->fresh()->status],
                ], 200);
            }

            $hasil = $this->mesin->approveCurrentStep($dokumen, $user, $validated['notes'] ?? null);

            $saldoSesudah = null;

            /*
            | Disetujui penuh berarti uangnya dilepas. Saldo bertambah di sini,
            | dalam transaksi yang sama dengan persetujuannya -- tidak ada
            | keadaan di mana dokumen tercatat disetujui tetapi saldonya tidak
            | bertambah.
            */
            if ($hasil['is_final_approved'] ?? false) {
                $saldoSesudah = $this->pettyCash->applyApprovedTopup($dokumen->fresh(), $user);
            }

            DB::commit();

            $dokumen->refresh();

            $notif = app(PettyCashNotificationService::class);
            $masihAdaTahap = (bool) ($hasil['has_pending_approval'] ?? false);

            $notif->notifyApprovalStep($dokumen, $user, $masihAdaTahap);

            if ($masihAdaTahap) {
                $notif->notifyApprovalRequest($dokumen);
            }

            return response()->json([
                'success' => true,
                'message' => $saldoSesudah !== null
                    ? 'Top up disetujui. Saldo kas kecil sudah bertambah.'
                    : 'Pengajuan top up berhasil disetujui.',
                'data' => [
                    'public_id' => $dokumen->encrypted_id,
                    'status' => $dokumen->status,
                    'balance_after' => $saldoSesudah,
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

    public function cancel(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('petty_cash_topup.cancel')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membatalkan Top Up Petty Cash.',
            ], 403);
        }

        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        DB::beginTransaction();

        try {
            $dokumen = PettyCashTopup::query()->lockForUpdate()->findOrFail(
                Crypt::decryptString($publicId),
            );

            if (!$this->bolehBeraksi($user, $dokumen, 'petty_cash_topup.cancel')) {
                DB::rollBack();

                return $this->tidakDitemukan();
            }

            /*
            | Yang sudah disetujui penuh tidak bisa dibatalkan: saldonya sudah
            | bertambah dan uangnya sudah dikirim. Yang tersedia untuk keadaan
            | itu adalah penyesuaian saldo yang tercatat alasannya.
            */
            if (strtoupper((string) $dokumen->status) === PettyCashTopup::STATUS_APPROVED) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Top up yang sudah disetujui penuh tidak dapat dibatalkan. '
                        . 'Saldonya sudah bertambah; yang tersedia adalah penyesuaian saldo kas kecil.',
                ], 422);
            }

            if (!in_array(strtoupper((string) $dokumen->status), [
                PettyCashTopup::STATUS_DRAFT,
                PettyCashTopup::STATUS_IN_PROGRESS,
            ], true)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Pengajuan ini sudah tidak dapat dibatalkan.',
                ], 422);
            }

            $dokumen->update([
                'status' => PettyCashTopup::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_notes' => $this->bersihkan($validated['notes']),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan top up berhasil dibatalkan.',
                'data' => ['public_id' => $dokumen->encrypted_id, 'status' => $dokumen->fresh()->status],
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

    private function findVisible(string $publicId, $user): ?PettyCashTopup
    {
        $context = PettyCashVisibility::context($user, 'petty_cash_topup.view');

        $query = PettyCashTopup::query()->whereKey(Crypt::decryptString($publicId));

        PettyCashVisibility::apply($query, $user, $context, self::TABEL, self::TABEL_APPROVAL);

        return $query->first();
    }

    private function bolehBeraksi($user, PettyCashTopup $dokumen, string $permission): bool
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
    private function baris(PettyCashTopup $row, $user): array
    {
        $status = strtoupper((string) $row->status);

        return [
            'public_id' => $row->encrypted_id,
            'topup_number' => $row->topup_number,
            'date' => $row->date?->toDateString(),
            'subject' => $row->subject,
            'branch' => $row->branchData?->nama_cabang,
            'branch_id' => (int) $row->branch,
            'department' => $row->departmentData?->nama,
            'ceiling_amount' => (float) $row->ceiling_amount,
            'balance_before' => (float) $row->balance_before,
            'amount' => (float) $row->amount,
            'status' => $row->status,
            'requester' => $row->creator?->name,
            'submitted_at' => $row->submitted_at,
            'final_approved_at' => $row->final_approved_at,
            'final_approved_by' => $row->finalApprover?->name,

            'can' => [
                'update' => $status === PettyCashTopup::STATUS_DRAFT
                    && $user->hasPermission('petty_cash_topup.update')
                    && $this->bolehBeraksi($user, $row, 'petty_cash_topup.update'),

                'delete' => $status === PettyCashTopup::STATUS_DRAFT
                    && $user->hasPermission('petty_cash_topup.delete')
                    && $this->bolehBeraksi($user, $row, 'petty_cash_topup.delete'),

                'submit' => $status === PettyCashTopup::STATUS_DRAFT
                    && $user->hasPermission('petty_cash_topup.submit')
                    && $this->bolehBeraksi($user, $row, 'petty_cash_topup.submit'),

                'cancel' => in_array($status, [
                    PettyCashTopup::STATUS_DRAFT,
                    PettyCashTopup::STATUS_IN_PROGRESS,
                ], true)
                    && $user->hasPermission('petty_cash_topup.cancel')
                    && $this->bolehBeraksi($user, $row, 'petty_cash_topup.cancel'),

                'approve' => $status === PettyCashTopup::STATUS_IN_PROGRESS
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
            'message' => 'Pengajuan top up tidak ditemukan.',
        ], 404);
    }

    private function gagal(string $aksi, \Throwable $e): JsonResponse
    {
        Log::error('[Petty Cash Topup] ' . $aksi . ' error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Proses top up petty cash gagal.',
            'debug' => app()->environment('local') ? $e->getMessage() : null,
        ], 500);
    }
}
