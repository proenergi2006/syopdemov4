<?php

namespace App\Services\FundRequest\CashAdvance;

use App\Mail\CashAdvanceApprovalMail;
use App\Models\CashAdvance;
use App\Models\CashAdvanceApproval;
use App\Models\User;
use App\Services\Permission\PermissionRecipientService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Email FPU
|--------------------------------------------------------------------------
| Penerima diambil dari baris cash_advance_approvals hasil generator, bukan
| dari approval_flow_steps -- sehingga penggantian approver apa pun otomatis
| ikut terbawa, sama seperti notifikasi in-app.
|
| Semua email dikirim lewat queue: kegagalan SMTP tidak boleh membuat submit
| atau approve ikut gagal.
|--------------------------------------------------------------------------
*/
class CashAdvanceMailService
{
    private const PERMISSION_RECEIVE = 'cash_advance.receive';

    private const PERMISSION_DISBURSE = 'cash_advance.disburse';

    public function __construct(
        private readonly PermissionRecipientService $permissionRecipients,
    ) {
    }

    /**
     * Email ke approver pada step yang sedang aktif.
     *
     * Dipanggil setelah FPU disubmit dan setelah step berikutnya aktif.
     */
    public function sendApprovalRequest(CashAdvance $cashAdvance): void
    {
        $currentStepOrder = CashAdvanceApproval::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->where('status', CashAdvanceApproval::STATUS_WAITING)
            ->min('step_order');

        if ($currentStepOrder === null) {
            Log::warning('[FPU Mail] Approval WAITING tidak ditemukan', [
                'cash_advance_id' => $cashAdvance->id,
                'advance_number' => $cashAdvance->advance_number,
            ]);

            return;
        }

        $currentApprovals = CashAdvanceApproval::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->where('step_order', (int) $currentStepOrder)
            ->where('status', CashAdvanceApproval::STATUS_WAITING)
            ->orderBy('id')
            ->get();

        if ($currentApprovals->isEmpty()) {
            return;
        }

        $approvers = $this->resolveRecipients($currentApprovals);

        if ($approvers->isEmpty()) {
            Log::warning('[FPU Mail] Approver ber-email tidak ditemukan', [
                'cash_advance_id' => $cashAdvance->id,
                'advance_number' => $cashAdvance->advance_number,
                'step_order' => (int) $currentStepOrder,
            ]);

            return;
        }

        $stepLabel = $currentApprovals->pluck('label')->filter()->first();

        $cashAdvance->loadMissing(['items', 'transactionCategory']);

        foreach ($approvers as $approver) {
            $this->queue(
                $approver,
                new CashAdvanceApprovalMail(
                    fpu: $cashAdvance,
                    recipient: $approver,
                    mode: 'approval_request',
                    stepOrder: (int) $currentStepOrder,
                    stepLabel: $stepLabel,
                ),
                $cashAdvance,
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
        CashAdvance $cashAdvance,
        User $approver,
        bool $hasPendingApproval,
    ): void {
        if ($hasPendingApproval) {
            return;
        }

        $requester = $this->resolveRequester($cashAdvance);

        if (!$requester) {
            return;
        }

        $cashAdvance->loadMissing(['items', 'transactionCategory']);

        $this->queue(
            $requester,
            new CashAdvanceApprovalMail(
                fpu: $cashAdvance,
                recipient: $requester,
                mode: 'final_approved',
                actor: $approver,
            ),
            $cashAdvance,
            'final approved',
        );
    }

    public function sendRejected(
        CashAdvance $cashAdvance,
        User $rejecter,
        ?string $notes = null,
    ): void {
        $requester = $this->resolveRequester($cashAdvance);

        if (!$requester) {
            return;
        }

        $cashAdvance->loadMissing(['items', 'transactionCategory']);

        $this->queue(
            $requester,
            new CashAdvanceApprovalMail(
                fpu: $cashAdvance,
                recipient: $requester,
                mode: 'rejected',
                actor: $rejecter,
                notes: $notes,
            ),
            $cashAdvance,
            'rejected',
        );
    }

    /**
     * Email ke pemegang permission pencairan setelah FPU disetujui penuh.
     *
     * Penerimanya bukan dari baris approval -- pencairan berada di luar
     * approval flow -- melainkan siapa pun yang memegang cash_advance.disburse.
     */
    /*
    |--------------------------------------------------------------------------
    | Menunggu diterima PIC
    |--------------------------------------------------------------------------
    */
    public function sendReceiptRequest(CashAdvance $cashAdvance): void
    {
        $recipients = $this->permissionRecipients
            ->mailableUsersWithPermission(self::PERMISSION_RECEIVE);

        if ($recipients->isEmpty()) {
            Log::warning('[FPU Mail] Pemegang permission penerimaan ber-email tidak ditemukan', [
                'cash_advance_id' => $cashAdvance->id,
                'permission' => self::PERMISSION_RECEIVE,
            ]);

            return;
        }

        $cashAdvance->loadMissing(['items', 'transactionCategory']);

        foreach ($recipients as $recipient) {
            $this->queue(
                $recipient,
                new CashAdvanceApprovalMail(
                    fpu: $cashAdvance,
                    recipient: $recipient,
                    mode: 'receipt_request',
                ),
                $cashAdvance,
                'receipt_request',
            );
        }
    }

    /**
     * Dikirim ke pemohon setelah PIC menandai dokumennya diterima.
     */
    public function sendReceived(
        CashAdvance $cashAdvance,
        User $receiver,
    ): void {
        $requester = $this->resolveRequester($cashAdvance);

        if (!$requester) {
            return;
        }

        $cashAdvance->loadMissing(['items', 'transactionCategory']);

        $this->queue(
            $requester,
            new CashAdvanceApprovalMail(
                fpu: $cashAdvance,
                recipient: $requester,
                mode: 'received',
                actor: $receiver,
                notes: $cashAdvance->receipt_notes,
            ),
            $cashAdvance,
            'received',
        );
    }

    public function sendDisbursementRequest(CashAdvance $cashAdvance): void
    {
        $recipients = $this->permissionRecipients
            ->mailableUsersWithPermission(self::PERMISSION_DISBURSE);

        if ($recipients->isEmpty()) {
            Log::warning('[FPU Mail] Pemegang permission pencairan ber-email tidak ditemukan', [
                'cash_advance_id' => $cashAdvance->id,
                'advance_number' => $cashAdvance->advance_number,
                'permission' => self::PERMISSION_DISBURSE,
            ]);

            return;
        }

        $cashAdvance->loadMissing(['items', 'transactionCategory']);

        foreach ($recipients as $recipient) {
            $this->queue(
                $recipient,
                new CashAdvanceApprovalMail(
                    fpu: $cashAdvance,
                    recipient: $recipient,
                    mode: 'disbursement_request',
                ),
                $cashAdvance,
                'disbursement request',
            );
        }
    }

    /**
     * Email ke pemohon setelah Finance mencairkan dana.
     */
    public function sendDisbursed(
        CashAdvance $cashAdvance,
        User $disburser,
    ): void {
        $requester = $this->resolveRequester($cashAdvance);

        if (!$requester) {
            return;
        }

        $cashAdvance->loadMissing(['items', 'transactionCategory']);

        $this->queue(
            $requester,
            new CashAdvanceApprovalMail(
                fpu: $cashAdvance,
                recipient: $requester,
                mode: 'disbursed',
                actor: $disburser,
                notes: $cashAdvance->disbursement_notes,
            ),
            $cashAdvance,
            'disbursed',
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
     * @param  Collection<int, CashAdvanceApproval>  $approvals
     * @return Collection<int, User>
     */
    private function resolveRecipients(Collection $approvals): Collection
    {
        return $approvals
            ->flatMap(
                fn(CashAdvanceApproval $approval): Collection =>
                $this->resolveApprovalUsers($approval),
            )
            ->filter(fn($user) => $user instanceof User && filled($user->email))
            ->unique('id')
            ->unique(fn(User $user): string => strtolower(trim((string) $user->email)))
            ->values();
    }

    private function resolveApprovalUsers(CashAdvanceApproval $approval): Collection
    {
        $approverType = strtoupper(trim((string) $approval->approver_type));
        $approverId = (int) $approval->approver_id;

        if ($approverId <= 0) {
            return collect();
        }

        if ($approverType === CashAdvanceApproval::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($approverId)
                ->whereNotNull('email')
                ->get();
        }

        if ($approverType === CashAdvanceApproval::APPROVER_TYPE_ROLE) {
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
    private function resolveRequester(CashAdvance $cashAdvance): ?User
    {
        $requesterId = $cashAdvance->submitted_by ?? $cashAdvance->created_by;

        if (!$requesterId) {
            return null;
        }

        $requester = User::query()
            ->whereKey((int) $requesterId)
            ->first();

        if (!$requester || blank($requester->email)) {
            Log::warning('[FPU Mail] Pemohon tidak memiliki email', [
                'cash_advance_id' => $cashAdvance->id,
                'advance_number' => $cashAdvance->advance_number,
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
        CashAdvanceApprovalMail $mail,
        CashAdvance $cashAdvance,
        string $context,
    ): void {
        try {
            Log::info('[FPU Mail] Queue email ' . $context, [
                'cash_advance_id' => $cashAdvance->id,
                'advance_number' => $cashAdvance->advance_number,
                'user_id' => $recipient->id,
                'to' => $recipient->email,
                'queue_connection' => config('queue.default'),
            ]);

            Mail::to($recipient->email)->queue($mail);
        } catch (\Throwable $e) {
            Log::error('[FPU Mail] Gagal queue email ' . $context, [
                'cash_advance_id' => $cashAdvance->id,
                'advance_number' => $cashAdvance->advance_number,
                'user_id' => $recipient->id,
                'to' => $recipient->email,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
