<?php

namespace App\Services\FundRequest\Realization;

use App\Mail\CashAdvanceRealizationApprovalMail;
use App\Models\CashAdvanceRealization;
use App\Models\CashAdvanceRealizationApproval;
use App\Models\User;
use App\Services\Permission\PermissionRecipientService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Email Realisasi FPU
|--------------------------------------------------------------------------
| Penerima diambil dari baris cash_advance_realization_approvals hasil
| generator, bukan dari approval_flow_steps -- sehingga penggantian approver
| apa pun otomatis ikut terbawa, sama seperti notifikasi in-app.
|
| Semua email dikirim lewat queue: kegagalan SMTP tidak boleh membuat submit
| atau approve ikut gagal.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationMailService
{
    /*
    | Penyelesaian selisih dipecah mengikuti arah uangnya, jadi penerimanya pun
    | berbeda: yang berwenang mencatat pengembalian belum tentu yang berwenang
    | mencatat pembayaran kekurangan.
    */
    private const PERMISSION_BY_DIFFERENCE = [
        CashAdvanceRealization::DIFFERENCE_RETURN => 'cash_advance_realization.return',
        CashAdvanceRealization::DIFFERENCE_REIMBURSE => 'cash_advance_realization.reimburse',
    ];

    private const PERMISSION_RECEIVE = 'cash_advance_realization.receive';

    /*
    | Memeriksa pengembalian yang dinyatakan pemohon. Izinnya sendiri, bukan
    | .receive: menerima berkas dan menyatakan uang sudah masuk ke rekening
    | perusahaan adalah dua penilaian berbeda.
    */
    private const PERMISSION_CONFIRM_RETURN = 'cash_advance_realization.confirm_return';

    public function __construct(
        private readonly PermissionRecipientService $permissionRecipients,
    ) {
    }

    /**
     * Email ke approver pada step yang sedang aktif.
     *
     * Dipanggil setelah Realisasi FPU disubmit dan setelah step berikutnya aktif.
     */
    public function sendApprovalRequest(CashAdvanceRealization $realization): void
    {
        $currentStepOrder = CashAdvanceRealizationApproval::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->where('status', CashAdvanceRealizationApproval::STATUS_WAITING)
            ->min('step_order');

        if ($currentStepOrder === null) {
            Log::warning('[Realisasi FPU Mail] Approval WAITING tidak ditemukan', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
            ]);

            return;
        }

        $currentApprovals = CashAdvanceRealizationApproval::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->where('step_order', (int) $currentStepOrder)
            ->where('status', CashAdvanceRealizationApproval::STATUS_WAITING)
            ->orderBy('id')
            ->get();

        if ($currentApprovals->isEmpty()) {
            return;
        }

        $approvers = $this->resolveRecipients($currentApprovals);

        if ($approvers->isEmpty()) {
            Log::warning('[Realisasi FPU Mail] Approver ber-email tidak ditemukan', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
                'step_order' => (int) $currentStepOrder,
            ]);

            return;
        }

        $stepLabel = $currentApprovals->pluck('label')->filter()->first();

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        foreach ($approvers as $approver) {
            $this->queue(
                $approver,
                new CashAdvanceRealizationApprovalMail(
                    realization: $realization,
                    recipient: $approver,
                    mode: 'approval_request',
                    stepOrder: (int) $currentStepOrder,
                    stepLabel: $stepLabel,
                ),
                $realization,
                'approval request',
            );
        }
    }

    /**
     * Email ke pemohon ketika seluruh proses approval selesai.
     *
     * Tahap yang belum final tidak dikirimi email -- pemohon cukup diberi tahu
     * lewat notifikasi in-app agar kotak masuknya tidak penuh.
     */
    public function sendApprovalStep(
        CashAdvanceRealization $realization,
        User $approver,
        bool $hasPendingApproval,
    ): void {
        if ($hasPendingApproval) {
            return;
        }

        $requester = $this->resolveRequester($realization);

        if (!$requester) {
            return;
        }

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        $this->queue(
            $requester,
            new CashAdvanceRealizationApprovalMail(
                realization: $realization,
                recipient: $requester,
                mode: 'final_approved',
                actor: $approver,
            ),
            $realization,
            'final approved',
        );
    }

    public function sendRejected(
        CashAdvanceRealization $realization,
        User $rejecter,
        ?string $notes = null,
    ): void {
        $requester = $this->resolveRequester($realization);

        if (!$requester) {
            return;
        }

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        $this->queue(
            $requester,
            new CashAdvanceRealizationApprovalMail(
                realization: $realization,
                recipient: $requester,
                mode: 'rejected',
                actor: $rejecter,
                notes: $notes,
            ),
            $realization,
            'rejected',
        );
    }

    /**
     * Email ke pemegang permission penyelesaian setelah Realisasi disetujui.
     *
     * Realisasi tanpa selisih tidak mengirim apa pun -- tidak ada uang yang
     * perlu berpindah.
     */
    /**
     * Email ke pemegang permission penerimaan setelah approval tuntas.
     */
    public function sendReceiptRequest(CashAdvanceRealization $realization): void
    {
        $recipients = $this->permissionRecipients
            ->mailableUsersWithPermission(self::PERMISSION_RECEIVE);

        if ($recipients->isEmpty()) {
            Log::warning('[Realisasi FPU Mail] Pemegang permission penerimaan ber-email tidak ditemukan', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
                'permission' => self::PERMISSION_RECEIVE,
            ]);

            return;
        }

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        foreach ($recipients as $recipient) {
            $this->queue(
                $recipient,
                new CashAdvanceRealizationApprovalMail(
                    realization: $realization,
                    recipient: $recipient,
                    mode: 'receipt_request',
                ),
                $realization,
                'receipt_request',
            );
        }
    }

    /**
     * Email ke pemohon setelah PIC menandai dokumennya diterima.
     */
    public function sendReceived(
        CashAdvanceRealization $realization,
        User $receiver,
    ): void {
        $requester = $this->resolveRequester($realization);

        if (!$requester) {
            return;
        }

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        $this->queue(
            $requester,
            new CashAdvanceRealizationApprovalMail(
                realization: $realization,
                recipient: $requester,
                mode: 'received',
                actor: $receiver,
                notes: $realization->receipt_notes,
            ),
            $realization,
            'received',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Selisihnya menunggu diselesaikan
    |--------------------------------------------------------------------------
    | DUA ARAH, DUA PENERIMA.
    |
    | Kekurangan dibayarkan perusahaan: yang dipanggil pemegang izin
    | pembayarannya. Sisa dana dikembalikan PEMOHON: yang dipanggil pemohonnya
    | sendiri, karena dialah yang memegang uangnya.
    |
    | Cabang pengembalian dahulu pun dikirim ke pemegang izin. Akibatnya
    | terukur: satu dokumen GA di Kantor Pusat mengirim pemberitahuan ke 38
    | akun dari 15 role -- Marketing, Service Advisor, Admin Warehouse -- yang
    | tidak satu pun berurusan dengan uang itu, sedangkan pemohonnya hanya
    | menerima kabar "berkas Anda sudah diterima" yang tidak menyebut sepeser
    | pun tentang dana yang harus ia kembalikan.
    |--------------------------------------------------------------------------
    */
    public function sendDifferenceSettlementRequest(
        CashAdvanceRealization $realization,
    ): void {
        $differenceType = strtoupper(trim((string) $realization->difference_type));

        if ($differenceType === CashAdvanceRealization::DIFFERENCE_RETURN) {
            $this->sendReturnRequestToRequester($realization);

            return;
        }

        $permission = self::PERMISSION_BY_DIFFERENCE[$differenceType] ?? null;

        if (!$permission) {
            return;
        }

        $recipients = $this->permissionRecipients
            ->mailableUsersWithPermission($permission);

        if ($recipients->isEmpty()) {
            Log::warning('[Realisasi FPU Mail] Pemegang permission penyelesaian ber-email tidak ditemukan', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
                'difference_type' => $differenceType,
                'permission' => $permission,
            ]);

            return;
        }

        $mode = $differenceType === CashAdvanceRealization::DIFFERENCE_RETURN
            ? 'return_request'
            : 'reimburse_request';

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        foreach ($recipients as $recipient) {
            $this->queue(
                $recipient,
                new CashAdvanceRealizationApprovalMail(
                    realization: $realization,
                    recipient: $recipient,
                    mode: $mode,
                ),
                $realization,
                $mode,
            );
        }
    }

    /** Email ke pemohon: sisa dananya perlu dikembalikan ke Finance. */
    private function sendReturnRequestToRequester(
        CashAdvanceRealization $realization,
    ): void {
        $requester = $this->resolveRequester($realization);

        if (!$requester) {
            return;
        }

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        $this->queue(
            $requester,
            new CashAdvanceRealizationApprovalMail(
                realization: $realization,
                recipient: $requester,
                mode: 'return_request',
            ),
            $realization,
            'return_request',
        );
    }

    /**
     * Email ke Finance: pemohon menyatakan sudah mengembalikan dananya.
     *
     * Penerimanya pemegang izin PENERIMAAN. Izin pengembalian sekarang milik
     * pemohon, jadi memakainya di sini akan mengirim surat itu kembali ke
     * orang yang baru saja menulisnya.
     */
    public function sendReturnDeclared(
        CashAdvanceRealization $realization,
        User $declarer,
    ): void {
        $recipients = $this->permissionRecipients
            ->mailableUsersWithPermission(self::PERMISSION_CONFIRM_RETURN);

        if ($recipients->isEmpty()) {
            Log::warning('[Realisasi FPU Mail] Pemegang izin konfirmasi pengembalian ber-email tidak ditemukan', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
                'permission' => self::PERMISSION_CONFIRM_RETURN,
            ]);

            return;
        }

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        foreach ($recipients as $recipient) {
            $this->queue(
                $recipient,
                new CashAdvanceRealizationApprovalMail(
                    realization: $realization,
                    recipient: $recipient,
                    mode: 'return_declared',
                    actor: $declarer,
                    notes: $realization->return_declaration_notes,
                ),
                $realization,
                'return_declared',
            );
        }
    }

    /**
     * Email ke pemohon: realisasinya yang nilainya pas sudah disetujui.
     *
     * Terpisah dari sendSettled, dan kalimatnya pun berbeda. "Selisih
     * Realisasi telah diselesaikan" pada dokumen yang tidak punya selisih
     * menyuruh pembacanya mencari perpindahan uang yang tidak pernah ada.
     */
    public function sendFinalized(
        CashAdvanceRealization $realization,
        User $approver,
    ): void {
        $requester = $this->resolveRequester($realization);

        if (!$requester) {
            return;
        }

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        $this->queue(
            $requester,
            new CashAdvanceRealizationApprovalMail(
                realization: $realization,
                recipient: $requester,
                mode: 'finalized',
                actor: $approver,
                notes: $realization->settlement_notes,
            ),
            $realization,
            'finalized',
        );
    }

    /**
     * Email ke pemohon: Finance sudah menyetujui realisasinya.
     *
     * Mode suratnya dipilih menurut cabangnya, dengan alasan yang sama
     * seperti notifikasinya: pemohon cabang kekurangan belum menerima
     * uangnya, dan surat berbunyi "selesai" akan membuatnya berhenti
     * menunggu sesuatu yang justru belum datang.
     */
    public function sendFinanceApproved(
        CashAdvanceRealization $realization,
        User $approver,
    ): void {
        $requester = $this->resolveRequester($realization);

        if (!$requester) {
            return;
        }

        $mode = match (strtoupper(trim((string) $realization->difference_type))) {
            CashAdvanceRealization::DIFFERENCE_REIMBURSE => 'finance_approved_reimburse',
            CashAdvanceRealization::DIFFERENCE_RETURN => 'finance_approved_return',
            default => 'finalized',
        };

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        $this->queue(
            $requester,
            new CashAdvanceRealizationApprovalMail(
                realization: $realization,
                recipient: $requester,
                mode: $mode,
                actor: $approver,
                notes: $realization->settlement_notes ?? $realization->verification_notes,
            ),
            $realization,
            $mode,
        );
    }

    /**
     * Email ke pemohon: penerimaan berkasnya ditarik kembali Finance.
     *
     * DAHULU HANYA NOTIFIKASI DALAM APLIKASI, dan itu tidak cukup.
     *
     * Menarik penerimaan membatalkan tanggal pembayaran yang SUDAH pernah
     * dikirim ke pemohon lewat email. Yang ia pegang sekarang satu janji
     * tertulis di kotak masuknya dan satu pembatalan yang hanya ada di dalam
     * aplikasi -- dan yang ia percayai tentu yang tertulis. Akibatnya ia
     * menunggu uang pada hari yang tidak akan pernah datang.
     *
     * Pembatalan sebuah janji harus sampai lewat jalan yang sama dengan
     * janjinya.
     */
    public function sendReceiptReverted(
        CashAdvanceRealization $realization,
        User $actor,
        ?string $reason = null,
    ): void {
        $requester = $this->resolveRequester($realization);

        if (!$requester) {
            return;
        }

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        $this->queue(
            $requester,
            new CashAdvanceRealizationApprovalMail(
                realization: $realization,
                recipient: $requester,
                mode: 'receipt_reverted',
                actor: $actor,
                notes: $reason,
            ),
            $realization,
            'receipt_reverted',
        );
    }

    /** Email ke pemohon: pernyataan pengembaliannya ditolak Finance. */
    public function sendReturnRejected(
        CashAdvanceRealization $realization,
        User $actor,
        ?string $reason = null,
    ): void {
        $requester = $this->resolveRequester($realization);

        if (!$requester) {
            return;
        }

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        $this->queue(
            $requester,
            new CashAdvanceRealizationApprovalMail(
                realization: $realization,
                recipient: $requester,
                mode: 'return_rejected',
                actor: $actor,
                notes: $reason,
            ),
            $realization,
            'return_rejected',
        );
    }

    /**
     * Email ke pemohon setelah Finance menyelesaikan selisih.
     */
    public function sendSettled(
        CashAdvanceRealization $realization,
        User $settler,
    ): void {
        $requester = $this->resolveRequester($realization);

        if (!$requester) {
            return;
        }

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        $this->queue(
            $requester,
            new CashAdvanceRealizationApprovalMail(
                realization: $realization,
                recipient: $requester,
                mode: 'settled',
                actor: $settler,
                notes: $realization->settlement_notes,
            ),
            $realization,
            'settled',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    /**
     * Mengubah baris approval menjadi daftar penerima email yang unik.
     *
     * Dua tahap deduplikasi: pertama per user, lalu per alamat email -- dua
     * akun berbeda bisa memakai alamat yang sama, dan orang itu tidak perlu
     * menerima email yang sama dua kali.
     *
     * @param  Collection<int, CashAdvanceRealizationApproval>  $approvals
     * @return Collection<int, User>
     */
    private function resolveRecipients(Collection $approvals): Collection
    {
        return $approvals
            ->flatMap(
                fn(CashAdvanceRealizationApproval $approval): Collection =>
                $this->resolveApprovalUsers($approval),
            )
            ->filter(fn($user) => $user instanceof User && filled($user->email))
            ->unique('id')
            ->unique(fn(User $user): string => strtolower(trim((string) $user->email)))
            ->values();
    }

    private function resolveApprovalUsers(CashAdvanceRealizationApproval $approval): Collection
    {
        $approverType = strtoupper(trim((string) $approval->approver_type));
        $approverId = (int) $approval->approver_id;

        if ($approverId <= 0) {
            return collect();
        }

        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($approverId)
                ->whereNotNull('email')
                ->get();
        }

        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_ROLE) {
            return $this->resolveUsersByRoleId($approverId);
        }

        return collect();
    }

    private function resolveUsersByRoleId(int $roleId): Collection
    {
        if ($roleId <= 0) {
            return collect();
        }

        $userIds = collect();

        if (Schema::hasTable('user_roles')) {
            $userIds = $userIds->merge(
                DB::table('user_roles')->where('role_id', $roleId)->pluck('user_id'),
            );
        }

        // Kompatibilitas struktur lama.
        if (Schema::hasTable('role_user')) {
            $userIds = $userIds->merge(
                DB::table('role_user')->where('role_id', $roleId)->pluck('user_id'),
            );
        }

        if (Schema::hasColumn('users', 'role_id')) {
            $userIds = $userIds->merge(
                User::query()->where('role_id', $roleId)->pluck('id'),
            );
        }

        $userIds = $userIds
            ->filter(fn($id) => $id !== null && (int) $id > 0)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        $query = User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('email');

        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Pemohon adalah penekan tombol Submit; created_by dipakai sebagai cadangan.
     */
    /*
    |--------------------------------------------------------------------------
    | Dokumen dikembalikan ke pemohon untuk direvisi
    |--------------------------------------------------------------------------
    | Email, bukan hanya notifikasi dalam aplikasi, dan itu disengaja: begitu
    | dikembalikan, dokumennya BERHENTI sampai pemohon mengerjakannya. Pemohon
    | yang sedang tidak membuka aplikasi tidak akan tahu bahwa yang ditunggu
    | sekarang adalah dirinya, dan dokumennya terdiam berhari-hari tanpa ada
    | yang merasa sedang menahannya.
    |
    | Alasannya ikut dikirim utuh. Email yang hanya berkata "dikembalikan"
    | memaksa pemohon membuka aplikasi hanya untuk mencari tahu apa yang
    | salah -- padahal justru itu yang perlu ia bawa saat membukanya.
    |--------------------------------------------------------------------------
    */
    public function sendReturnedForRevision(
        CashAdvanceRealization $realization,
        User $actor,
        ?string $reason = null,
        ?int $revisionNumber = null,
    ): void {
        $requester = $this->resolveRequester($realization);

        if (!$requester) {
            return;
        }

        $realization->loadMissing(['items', 'transactionCategory', 'cashAdvance']);

        $this->queue(
            $requester,
            new CashAdvanceRealizationApprovalMail(
                realization: $realization,
                recipient: $requester,
                mode: 'returned_for_revision',
                actor: $actor,
                notes: $reason,
                revisionNumber: $revisionNumber,
            ),
            $realization,
            'returned_for_revision',
        );
    }
    private function resolveRequester(CashAdvanceRealization $realization): ?User
    {
        $requesterId = $realization->submitted_by ?? $realization->created_by;

        if (!$requesterId) {
            return null;
        }

        $requester = User::query()
            ->whereKey((int) $requesterId)
            ->first();

        if (!$requester || blank($requester->email)) {
            Log::warning('[Realisasi FPU Mail] Pemohon tidak memiliki email', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
                'requester_id' => $requesterId,
            ]);

            return null;
        }

        return $requester;
    }

    /**
     * Melepas email ke queue.
     *
     * Kegagalan di sini tidak boleh menggagalkan aksi yang memicunya --
     * dokumennya sudah tersimpan, jadi cukup dicatat.
     */
    private function queue(
        User $recipient,
        CashAdvanceRealizationApprovalMail $mail,
        CashAdvanceRealization $realization,
        string $context,
    ): void {
        try {
            Log::info('[Realisasi FPU Mail] Queue email ' . $context, [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
                'user_id' => $recipient->id,
                'to' => $recipient->email,
                'queue_connection' => config('queue.default'),
            ]);

            Mail::to($recipient->email)->queue($mail);
        } catch (\Throwable $e) {
            Log::error('[Realisasi FPU Mail] Gagal queue email ' . $context, [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
                'user_id' => $recipient->id,
                'to' => $recipient->email,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
