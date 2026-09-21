<?php

namespace App\Services\FundRequest\Claim;

use App\Mail\ClaimApprovalMail;
use App\Models\Claim;
use App\Models\ClaimApproval;
use App\Models\User;
use App\Services\Permission\PermissionRecipientService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Email Claim
|--------------------------------------------------------------------------
| Penerima diambil dari baris claim_approvals hasil generator, bukan
| dari approval_flow_steps -- sehingga penggantian approver apa pun otomatis
| ikut terbawa, sama seperti notifikasi in-app.
|
| Semua email dikirim lewat queue: kegagalan SMTP tidak boleh membuat submit
| atau approve ikut gagal.
|--------------------------------------------------------------------------
*/
class ClaimMailService
{
    private const PERMISSION_PAY = 'claim.pay';

    private const PERMISSION_RECEIVE = 'claim.receive';

    public function __construct(
        private readonly PermissionRecipientService $permissionRecipients,
    ) {
    }

    /**
     * Email ke approver pada step yang sedang aktif.
     *
     * Dipanggil setelah Claim disubmit dan setelah step berikutnya aktif.
     */
    public function sendApprovalRequest(Claim $claim): void
    {
        $currentStepOrder = ClaimApproval::query()
            ->where('claim_id', $claim->id)
            ->where('status', ClaimApproval::STATUS_WAITING)
            ->min('step_order');

        if ($currentStepOrder === null) {
            Log::warning('[Claim Mail] Approval WAITING tidak ditemukan', [
                'claim_id' => $claim->id,
                'claim_number' => $claim->claim_number,
            ]);

            return;
        }

        $currentApprovals = ClaimApproval::query()
            ->where('claim_id', $claim->id)
            ->where('step_order', (int) $currentStepOrder)
            ->where('status', ClaimApproval::STATUS_WAITING)
            ->orderBy('id')
            ->get();

        if ($currentApprovals->isEmpty()) {
            return;
        }

        $approvers = $this->resolveRecipients($currentApprovals);

        if ($approvers->isEmpty()) {
            Log::warning('[Claim Mail] Approver ber-email tidak ditemukan', [
                'claim_id' => $claim->id,
                'claim_number' => $claim->claim_number,
                'step_order' => (int) $currentStepOrder,
            ]);

            return;
        }

        $stepLabel = $currentApprovals->pluck('label')->filter()->first();

        $claim->loadMissing(['items', 'transactionCategory']);

        foreach ($approvers as $approver) {
            $this->queue(
                $approver,
                new ClaimApprovalMail(
                    claim: $claim,
                    recipient: $approver,
                    mode: 'approval_request',
                    stepOrder: (int) $currentStepOrder,
                    stepLabel: $stepLabel,
                ),
                $claim,
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
        Claim $claim,
        User $approver,
        bool $hasPendingApproval,
    ): void {
        if ($hasPendingApproval) {
            return;
        }

        $requester = $this->resolveRequester($claim);

        if (!$requester) {
            return;
        }

        $claim->loadMissing(['items', 'transactionCategory']);

        $this->queue(
            $requester,
            new ClaimApprovalMail(
                claim: $claim,
                recipient: $requester,
                mode: 'final_approved',
                actor: $approver,
            ),
            $claim,
            'final approved',
        );
    }

    public function sendRejected(
        Claim $claim,
        User $rejecter,
        ?string $notes = null,
    ): void {
        $requester = $this->resolveRequester($claim);

        if (!$requester) {
            return;
        }

        $claim->loadMissing(['items', 'transactionCategory']);

        $this->queue(
            $requester,
            new ClaimApprovalMail(
                claim: $claim,
                recipient: $requester,
                mode: 'rejected',
                actor: $rejecter,
                notes: $notes,
            ),
            $claim,
            'rejected',
        );
    }

    /**
     * Email ke pemegang permission pembayaran setelah Claim disetujui penuh.
     */
    /**
     * Email ke pemegang permission penerimaan setelah approval tuntas.
     */
    public function sendReceiptRequest(Claim $claim): void
    {
        $recipients = $this->permissionRecipients
            ->mailableUsersWithPermission(self::PERMISSION_RECEIVE);

        if ($recipients->isEmpty()) {
            Log::warning('[Claim Mail] Pemegang permission penerimaan ber-email tidak ditemukan', [
                'claim_id' => $claim->id,
                'claim_number' => $claim->claim_number,
                'permission' => self::PERMISSION_RECEIVE,
            ]);

            return;
        }

        $claim->loadMissing(['items', 'transactionCategory']);

        foreach ($recipients as $recipient) {
            $this->queue(
                $recipient,
                new ClaimApprovalMail(
                    claim: $claim,
                    recipient: $recipient,
                    mode: 'receipt_request',
                ),
                $claim,
                'receipt_request',
            );
        }
    }

    /**
     * Email ke pemohon setelah PIC menandai dokumennya diterima.
     */
    public function sendReceived(
        Claim $claim,
        User $receiver,
    ): void {
        $requester = $this->resolveRequester($claim);

        if (!$requester) {
            return;
        }

        $claim->loadMissing(['items', 'transactionCategory']);

        $this->queue(
            $requester,
            new ClaimApprovalMail(
                claim: $claim,
                recipient: $requester,
                mode: 'received',
                actor: $receiver,
                notes: $claim->receipt_notes,
            ),
            $claim,
            'received',
        );
    }

    public function sendPaymentRequest(Claim $claim): void
    {
        $recipients = $this->permissionRecipients
            ->mailableUsersWithPermission(self::PERMISSION_PAY);

        if ($recipients->isEmpty()) {
            Log::warning('[Claim Mail] Pemegang permission pembayaran ber-email tidak ditemukan', [
                'claim_id' => $claim->id,
                'claim_number' => $claim->claim_number,
                'permission' => self::PERMISSION_PAY,
            ]);

            return;
        }

        $claim->loadMissing(['items', 'transactionCategory']);

        foreach ($recipients as $recipient) {
            $this->queue(
                $recipient,
                new ClaimApprovalMail(
                    claim: $claim,
                    recipient: $recipient,
                    mode: 'payment_request',
                ),
                $claim,
                'payment request',
            );
        }
    }

    /**
     * Email ke pemohon setelah Finance mengganti uangnya.
     */
    public function sendPaid(Claim $claim, User $payer): void
    {
        $requester = $this->resolveRequester($claim);

        if (!$requester) {
            return;
        }

        $claim->loadMissing(['items', 'transactionCategory']);

        $this->queue(
            $requester,
            new ClaimApprovalMail(
                claim: $claim,
                recipient: $requester,
                mode: 'paid',
                actor: $payer,
                notes: $claim->payment_notes,
            ),
            $claim,
            'paid',
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
     * @param  Collection<int, ClaimApproval>  $approvals
     * @return Collection<int, User>
     */
    private function resolveRecipients(Collection $approvals): Collection
    {
        return $approvals
            ->flatMap(
                fn(ClaimApproval $approval): Collection =>
                $this->resolveApprovalUsers($approval),
            )
            ->filter(fn($user) => $user instanceof User && filled($user->email))
            ->unique('id')
            ->unique(fn(User $user): string => strtolower(trim((string) $user->email)))
            ->values();
    }

    private function resolveApprovalUsers(ClaimApproval $approval): Collection
    {
        $approverType = strtoupper(trim((string) $approval->approver_type));
        $approverId = (int) $approval->approver_id;

        if ($approverId <= 0) {
            return collect();
        }

        if ($approverType === ClaimApproval::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($approverId)
                ->whereNotNull('email')
                ->get();
        }

        if ($approverType === ClaimApproval::APPROVER_TYPE_ROLE) {
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
    private function resolveRequester(Claim $claim): ?User
    {
        $requesterId = $claim->submitted_by ?? $claim->created_by;

        if (!$requesterId) {
            return null;
        }

        $requester = User::query()
            ->whereKey((int) $requesterId)
            ->first();

        if (!$requester || blank($requester->email)) {
            Log::warning('[Claim Mail] Pemohon tidak memiliki email', [
                'claim_id' => $claim->id,
                'claim_number' => $claim->claim_number,
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
        ClaimApprovalMail $mail,
        Claim $claim,
        string $context,
    ): void {
        try {
            Log::info('[Claim Mail] Queue email ' . $context, [
                'claim_id' => $claim->id,
                'claim_number' => $claim->claim_number,
                'user_id' => $recipient->id,
                'to' => $recipient->email,
                'queue_connection' => config('queue.default'),
            ]);

            Mail::to($recipient->email)->queue($mail);
        } catch (\Throwable $e) {
            Log::error('[Claim Mail] Gagal queue email ' . $context, [
                'claim_id' => $claim->id,
                'claim_number' => $claim->claim_number,
                'user_id' => $recipient->id,
                'to' => $recipient->email,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
