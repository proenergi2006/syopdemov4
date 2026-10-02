<?php

namespace App\Services\FundRequest\Claim;

use App\Models\Claim;
use App\Models\ClaimApproval;
use App\Models\Notification;
use App\Models\User;
use App\Services\FundRequest\PaymentScheduleService;
use App\Services\Permission\PermissionRecipientService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Notifikasi aplikasi untuk Claim
|--------------------------------------------------------------------------
| Sumber penerima adalah baris claim_approvals hasil generator, bukan
| approval_flow_steps -- sehingga penggantian approver apa pun otomatis ikut
| terbawa ke notifikasi.
|--------------------------------------------------------------------------
*/
class ClaimNotificationService
{
    private const MODULE = 'claim';

    private const URL = '/fund_request/claim';

    private const PERMISSION_PAY = 'claim.pay';

    /*
    | Penerimaan berdiri di luar approval flow: yang diberi tahu adalah
    | pemegang permission ini, bukan approver berikutnya.
    */
    private const PERMISSION_RECEIVE = 'claim.receive';

    public function __construct(
        private readonly PermissionRecipientService $permissionRecipients,
    ) {
    }

    /**
     * Dipanggil setelah Claim disubmit atau setelah step berikutnya aktif.
     */
    public function notifyApprovalRequest(Claim $claim): void
    {
        $currentStepOrder = ClaimApproval::query()
            ->where('claim_id', $claim->id)
            ->where('status', ClaimApproval::STATUS_WAITING)
            ->min('step_order');

        if ($currentStepOrder === null) {
            Log::warning('[Claim Notification] Approval WAITING tidak ditemukan', [
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

        /*
        | unique('id') mencegah notifikasi ganda bila satu user dipilih
        | langsung sekaligus ter-resolve lewat role.
        */
        $approverUsers = $currentApprovals
            ->flatMap(
                fn(ClaimApproval $approval): Collection =>
                $this->resolveApproverUsers($approval),
            )
            ->filter(fn($user) => $user instanceof User)
            ->unique('id')
            ->values();

        if ($approverUsers->isEmpty()) {
            Log::warning('[Claim Notification] Approver user tidak ditemukan', [
                'claim_id' => $claim->id,
                'claim_number' => $claim->claim_number,
                'step_order' => (int) $currentStepOrder,
                'approvals' => $currentApprovals
                    ->map(fn(ClaimApproval $approval) => [
                        'approval_id' => $approval->id,
                        'approver_type' => $approval->approver_type,
                        'approver_id' => $approval->approver_id,
                        'label' => $approval->label,
                    ])
                    ->values()
                    ->all(),
            ]);

            return;
        }

        $stepLabel = $currentApprovals->pluck('label')->filter()->first();

        foreach ($approverUsers as $user) {
            // Cegah duplikasi notifikasi aktif untuk dokumen yang sama.
            $alreadyExists = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', 'claim_approval')
                ->where('reference_type', Claim::class)
                ->where('reference_id', $claim->id)
                ->whereNull('read_at')
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            $messageParams = [
                'claim_number' => $claim->claim_number,
                'step_order' => (int) $currentStepOrder,
            ];

            if ($stepLabel) {
                $messageParams['step_label'] = $stepLabel;
            }

            $messageKey = $stepLabel
                ? 'notification_messages.claim.approval_request.message_with_label'
                : 'notification_messages.claim.approval_request.message_without_label';

            Notification::create([
                'user_id' => $user->id,
                'type' => 'claim_approval',
                'title' => __('notification_messages.claim.approval_request.title'),
                'title_key' => 'notification_messages.claim.approval_request.title',
                'message' => __($messageKey, $messageParams),
                'message_key' => $messageKey,
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => Claim::class,
                'reference_id' => $claim->id,
                'reference_public_id' => $claim->encrypted_id,
                'url' => self::URL,
            ]);
        }
    }

    /**
     * Dikirim ke pemohon setelah satu tahap disetujui.
     */
    public function notifyApprovalStep(
        Claim $claim,
        User $approver,
        ClaimApproval $approval,
        bool $hasPendingApproval,
    ): void {
        $requesterId = $this->getRequesterUserId($claim);

        if (!$requesterId) {
            return;
        }

        $translationGroup = $hasPendingApproval
            ? 'approval_step_pending'
            : 'approval_step_final';

        $messageParams = [
            'claim_number' => $claim->claim_number,
            'approver_name' => $approver->name ?? '-',
            'step_order' => $approval->step_order,
        ];

        $titleKey = "notification_messages.claim.{$translationGroup}.title";
        $messageKey = "notification_messages.claim.{$translationGroup}.message";

        Notification::create([
            'user_id' => $requesterId,

            'type' => $hasPendingApproval
                ? 'claim_approval_step_approved'
                : 'claim_approved',

            'title' => __($titleKey),
            'title_key' => $titleKey,

            'message' => __($messageKey, $messageParams),
            'message_key' => $messageKey,
            'message_params' => $messageParams,

            'module' => self::MODULE,
            'reference_type' => Claim::class,
            'reference_id' => $claim->id,
            'reference_public_id' => $claim->encrypted_id,
            'url' => self::URL,
        ]);
    }

    public function notifyRejected(
        Claim $claim,
        User $rejecter,
    ): void {
        $requesterId = $this->getRequesterUserId($claim);

        if (!$requesterId) {
            return;
        }

        $messageParams = [
            'claim_number' => $claim->claim_number,
            'rejecter_name' => $rejecter->name ?? '-',
        ];

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'claim_rejected',
            'title' => __('notification_messages.claim.rejected.title'),
            'title_key' => 'notification_messages.claim.rejected.title',
            'message' => __('notification_messages.claim.rejected.message', $messageParams),
            'message_key' => 'notification_messages.claim.rejected.message',
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => Claim::class,
            'reference_id' => $claim->id,
            'reference_public_id' => $claim->encrypted_id,
            'url' => self::URL,
        ]);
    }

    /**
     * Dikirim ke pemegang permission pembayaran setelah Claim disetujui penuh.
     *
     * Berada di luar approval flow: pembayaran bukan tahap approval, jadi tidak
     * ada baris claim_approvals yang bisa dijadikan sumber penerima.
     * Penerimanya siapa pun yang memegang claim.pay, sama persis dengan yang
     * tombol Bayar-nya muncul.
     */
    /**
     * Dikirim ke pemegang permission penerimaan setelah approval tuntas.
     */
    public function notifyReceiptRequest(Claim $claim): void
    {
        $recipients = $this->permissionRecipients
            ->usersWithPermission(self::PERMISSION_RECEIVE);

        if ($recipients->isEmpty()) {
            Log::warning('[Claim Notification] Pemegang permission penerimaan tidak ditemukan', [
                'claim_id' => $claim->id,
                'claim_number' => $claim->claim_number,
                'permission' => self::PERMISSION_RECEIVE,
            ]);

            return;
        }

        $messageParams = [
            'claim_number' => $claim->claim_number,
            'total_amount' => number_format((float) $claim->total_amount, 0, ',', '.'),
        ];

        foreach ($recipients as $user) {
            /* Cegah tumpukan notifikasi untuk dokumen yang sama. */
            $alreadyExists = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', 'claim_receipt_request')
                ->where('reference_type', Claim::class)
                ->where('reference_id', $claim->id)
                ->whereNull('read_at')
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            Notification::create([
                'user_id' => $user->id,
                'type' => 'claim_receipt_request',
                'title' => __('notification_messages.claim.receipt_request.title'),
                'title_key' => 'notification_messages.claim.receipt_request.title',
                'message' => __('notification_messages.claim.receipt_request.message', $messageParams),
                'message_key' => 'notification_messages.claim.receipt_request.message',
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => Claim::class,
                'reference_id' => $claim->id,
                'reference_public_id' => $claim->encrypted_id,
                'url' => self::URL,
            ]);
        }
    }

    /**
     * Dikirim ke pemohon setelah PIC menandai dokumennya diterima.
     */
    /**
     * Dikirim ke pemohon ketika Finance membetulkan nominalnya.
     *
     * Kembarannya di FPU -- lihat
     * CashAdvanceNotificationService::notifyAmountRevised() untuk alasan
     * kenapa kedua angkanya disebut terang-terangan di kabarnya.
     */
    public function notifyAmountRevised(
        Claim $claim,
        User $reviser,
        ?string $totalLama,
        ?string $totalBaru,
    ): void {
        $requesterId = $this->getRequesterUserId($claim);

        if (!$requesterId) {
            return;
        }

        $rupiah = static fn ($nilai): string =>
            'Rp ' . number_format((float) $nilai, 0, ',', '.');

        $messageParams = [
            'claim_number' => $claim->claim_number,
            'reviser_name' => $reviser->name ?? '-',
            'old_total' => $rupiah($totalLama),
            'new_total' => $rupiah($totalBaru),
            'reason' => trim((string) $claim->amount_revision_notes),
        ];

        $titleKey = 'notification_messages.claim.amount_revised.title';
        $messageKey = 'notification_messages.claim.amount_revised.message';

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'claim_amount_revised',
            'title' => __($titleKey),
            'title_key' => $titleKey,
            'message' => __($messageKey, $messageParams),
            'message_key' => $messageKey,
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => Claim::class,
            'reference_id' => $claim->id,
            'reference_public_id' => $claim->encrypted_id,
            'url' => self::URL,
        ]);
    }

    /**
     * Dikirim ke pemohon ketika Finance menarik kembali penerimaan berkasnya.
     *
     * Tanggal pembayaran yang batal disebut terang-terangan: itulah yang
     * sudah ia catat dari email sebelumnya, dan tanpa menyebutnya kabar ini
     * hanya memberi tahu bahwa "ada sesuatu yang berubah".
     *
     * Dokumennya sendiri tidak hilang -- ia kembali ke status disetujui dan
     * masih bisa diterima ulang. Itu pun perlu dikatakan, kalau tidak pemohon
     * akan mengira pengajuannya batal.
     */
    public function notifyReceiptReverted(
        Claim $claim,
        User $actor,
        ?string $scheduledDate,
        string $reason,
    ): void {
        $requesterId = $this->getRequesterUserId($claim);

        if (!$requesterId) {
            return;
        }

        /*
        | Dokumen lama bisa saja tidak pernah punya tanggal pembayaran.
        | Kalimatnya dipilih menurut itu, bukan diisi tanda hubung -- "tanggal
        | pembayaran - dibatalkan" tidak berarti apa-apa bagi pembacanya.
        */
        $bagian = $scheduledDate ? 'with_date' : 'without_date';

        $messageParams = [
            'claim_number' => $claim->claim_number,
            'actor_name' => $actor->name ?? '-',
            'scheduled_date' => $scheduledDate ?? '-',
            'reason' => trim($reason),
        ];

        $titleKey = 'notification_messages.claim.receipt_reverted.title';
        $messageKey = 'notification_messages.claim.receipt_reverted.' . $bagian;

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'claim_receipt_reverted',
            'title' => __($titleKey),
            'title_key' => $titleKey,
            'message' => __($messageKey, $messageParams),
            'message_key' => $messageKey,
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => Claim::class,
            'reference_id' => $claim->id,
            'reference_public_id' => $claim->encrypted_id,
            'url' => self::URL,
        ]);
    }

    public function notifyReceived(
        Claim $claim,
        User $receiver,
    ): void {
        $requesterId = $this->getRequesterUserId($claim);

        if (!$requesterId) {
            return;
        }

        $messageParams = [
            'claim_number' => $claim->claim_number,
            'receiver_name' => $receiver->name ?? '-',
            'payment_date' => PaymentScheduleService::formatTanggal($claim->scheduled_payment_date),
        ];

        /*
        | Dokumen tanpa jadwal tetap memakai kalimat lama. Menyebut tanggal
        | pembayaran pada dokumen yang memang tidak dibayarkan hanya
        | membingungkan yang membacanya.
        */
        $kunci = $claim->scheduled_payment_date
            ? 'notification_messages.claim.received.message_scheduled'
            : 'notification_messages.claim.received.message';

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'claim_received',
            'title' => __('notification_messages.claim.received.title'),
            'title_key' => 'notification_messages.claim.received.title',
            'message' => __($kunci, $messageParams),
            'message_key' => $kunci,
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => Claim::class,
            'reference_id' => $claim->id,
            'reference_public_id' => $claim->encrypted_id,
            'url' => self::URL,
        ]);
    }

    public function notifyPaymentRequest(Claim $claim): void
    {
        $recipients = $this->permissionRecipients
            ->usersWithPermission(self::PERMISSION_PAY);

        if ($recipients->isEmpty()) {
            Log::warning('[Claim Notification] Pemegang permission pembayaran tidak ditemukan', [
                'claim_id' => $claim->id,
                'claim_number' => $claim->claim_number,
                'permission' => self::PERMISSION_PAY,
            ]);

            return;
        }

        $messageParams = [
            'claim_number' => $claim->claim_number,
            'total_amount' => number_format((float) $claim->total_amount, 0, ',', '.'),
        ];

        foreach ($recipients as $user) {
            /*
            | Cegah tumpukan notifikasi untuk dokumen yang sama bila approval
            | final sempat terpanggil lebih dari sekali.
            */
            $alreadyExists = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', 'claim_payment_request')
                ->where('reference_type', Claim::class)
                ->where('reference_id', $claim->id)
                ->whereNull('read_at')
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            Notification::create([
                'user_id' => $user->id,
                'type' => 'claim_payment_request',
                'title' => __('notification_messages.claim.payment_request.title'),
                'title_key' => 'notification_messages.claim.payment_request.title',
                'message' => __('notification_messages.claim.payment_request.message', $messageParams),
                'message_key' => 'notification_messages.claim.payment_request.message',
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => Claim::class,
                'reference_id' => $claim->id,
                'reference_public_id' => $claim->encrypted_id,
                'url' => self::URL,
            ]);
        }
    }

    /**
     * Dikirim ke pemohon setelah Finance mengganti uangnya.
     */
    public function notifyPaid(Claim $claim, User $payer): void
    {
        $requesterId = $this->getRequesterUserId($claim);

        if (!$requesterId) {
            return;
        }

        $messageParams = [
            'claim_number' => $claim->claim_number,
            'payer_name' => $payer->name ?? '-',
        ];

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'claim_paid',
            'title' => __('notification_messages.claim.paid.title'),
            'title_key' => 'notification_messages.claim.paid.title',
            'message' => __('notification_messages.claim.paid.message', $messageParams),
            'message_key' => 'notification_messages.claim.paid.message',
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => Claim::class,
            'reference_id' => $claim->id,
            'reference_public_id' => $claim->encrypted_id,
            'url' => self::URL,
        ]);
    }
    /**
     * Dokumen gugur karena perjalanan dinasnya ditolak atau dibatalkan.
     *
     * Dipisah dari notifyRejected() justru karena tidak ada yang menolaknya:
     * pesan yang menyebut penolak akan mengarang orang. Yang berhenti bukan
     * permintaannya, melainkan sebabnya.
     *
     * Dipanggil SESUDAH transaksi pembatalan perdinnya tuntas. Notifikasi
     * yang terkirim lalu transaksinya gagal akan memberitahukan sesuatu yang
     * tidak pernah terjadi.
     *
     * @param  string  $tripAction  'cancelled' atau 'rejected' -- memilih kalimatnya
     * @param  \Illuminate\Support\Collection  $cancelledApprovals  langkah yang tugasnya ikut hilang
     */
    public function notifyLapsedByBusinessTrip(
        Claim $claim,
        string $tripNumber,
        string $tripAction,
        $cancelledApprovals,
    ): void {
        $messageParams = [
            'claim_number' => $claim->claim_number,
            'trip_number' => $tripNumber,
        ];

        $dasar = "notification_messages.claim.lapsed_by_trip";

        $requesterId = $this->getRequesterUserId($claim);

        if ($requesterId) {
            Notification::create([
                'user_id' => $requesterId,
                'type' => 'claim_lapsed_by_trip',
                'title' => __("{$dasar}.requester.{$tripAction}.title"),
                'title_key' => "{$dasar}.requester.{$tripAction}.title",
                'message' => __("{$dasar}.requester.{$tripAction}.message", $messageParams),
                'message_key' => "{$dasar}.requester.{$tripAction}.message",
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => Claim::class,
                'reference_id' => $claim->id,
                'reference_public_id' => $claim->encrypted_id,
                'url' => self::URL,
            ]);
        }

        /*
        | Penyetuju yang tugasnya lenyap dari daftar.
        |
        | unique() mencegah kabar ganda bila satu orang ditunjuk langsung
        | sekaligus ter-resolve lewat peran; pemohonnya dikeluarkan karena ia
        | sudah menerima kabar versinya sendiri di atas.
        */
        $penyetuju = collect($cancelledApprovals)
            ->flatMap(fn(ClaimApproval $approval): Collection => $this->resolveApproverUsers($approval))
            ->filter(fn($user) => $user instanceof User)
            ->unique('id')
            ->reject(fn($user): bool => (int) $user->id === (int) $requesterId)
            ->values();

        foreach ($penyetuju as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'claim_lapsed_by_trip',
                'title' => __("{$dasar}.approver.{$tripAction}.title"),
                'title_key' => "{$dasar}.approver.{$tripAction}.title",
                'message' => __("{$dasar}.approver.{$tripAction}.message", $messageParams),
                'message_key' => "{$dasar}.approver.{$tripAction}.message",
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => Claim::class,
                'reference_id' => $claim->id,
                'reference_public_id' => $claim->encrypted_id,
                'url' => self::URL,
            ]);
        }
    }

    public function resolveApproverUsers(
        ClaimApproval $approval,
    ): Collection {
        $approverType = strtoupper(trim((string) $approval->approver_type));

        if (!$approval->approver_id) {
            return collect();
        }

        if ($approverType === ClaimApproval::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($approval->approver_id)
                ->get();
        }

        if ($approverType === ClaimApproval::APPROVER_TYPE_ROLE) {
            return $this->resolveUsersByRoleId((int) $approval->approver_id);
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
                DB::table('user_roles')
                    ->where('role_id', $roleId)
                    ->pluck('user_id'),
            );
        }

        // Kompatibilitas struktur lama.
        if (Schema::hasTable('role_user')) {
            $userIds = $userIds->merge(
                DB::table('role_user')
                    ->where('role_id', $roleId)
                    ->pluck('user_id'),
            );
        }

        if (Schema::hasColumn('users', 'role_id')) {
            $userIds = $userIds->merge(
                User::query()
                    ->where('role_id', $roleId)
                    ->pluck('id'),
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

        $query = User::query()->whereIn('id', $userIds);

        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Pemohon adalah penekan tombol Submit; created_by dipakai sebagai cadangan.
     */
    private function getRequesterUserId(Claim $claim): ?int
    {
        $requesterId = $claim->submitted_by
            ?? $claim->created_by
            ?? null;

        return $requesterId ? (int) $requesterId : null;
    }
}
