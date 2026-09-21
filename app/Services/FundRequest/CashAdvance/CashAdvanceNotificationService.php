<?php

namespace App\Services\FundRequest\CashAdvance;

use App\Models\CashAdvance;
use App\Models\CashAdvanceApproval;
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
| Notifikasi aplikasi untuk FPU
|--------------------------------------------------------------------------
| Sumber penerima adalah baris cash_advance_approvals hasil generator, bukan
| approval_flow_steps -- sehingga penggantian approver apa pun otomatis ikut
| terbawa ke notifikasi.
|--------------------------------------------------------------------------
*/
class CashAdvanceNotificationService
{
    private const MODULE = 'cash_advance';

    private const URL = '/fund_request/cash_advance';

    private const PERMISSION_RECEIVE = 'cash_advance.receive';

    private const PERMISSION_DISBURSE = 'cash_advance.disburse';

    public function __construct(
        private readonly PermissionRecipientService $permissionRecipients,
    ) {
    }

    /**
     * Dipanggil setelah FPU disubmit atau setelah step berikutnya aktif.
     */
    public function notifyApprovalRequest(CashAdvance $cashAdvance): void
    {
        $currentStepOrder = CashAdvanceApproval::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->where('status', CashAdvanceApproval::STATUS_WAITING)
            ->min('step_order');

        if ($currentStepOrder === null) {
            Log::warning('[FPU Notification] Approval WAITING tidak ditemukan', [
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

        /*
        | unique('id') mencegah notifikasi ganda bila satu user dipilih
        | langsung sekaligus ter-resolve lewat role.
        */
        $approverUsers = $currentApprovals
            ->flatMap(
                fn(CashAdvanceApproval $approval): Collection =>
                $this->resolveApproverUsers($approval),
            )
            ->filter(fn($user) => $user instanceof User)
            ->unique('id')
            ->values();

        if ($approverUsers->isEmpty()) {
            Log::warning('[FPU Notification] Approver user tidak ditemukan', [
                'cash_advance_id' => $cashAdvance->id,
                'advance_number' => $cashAdvance->advance_number,
                'step_order' => (int) $currentStepOrder,
                'approvals' => $currentApprovals
                    ->map(fn(CashAdvanceApproval $approval) => [
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
                ->where('type', 'cash_advance_approval')
                ->where('reference_type', CashAdvance::class)
                ->where('reference_id', $cashAdvance->id)
                ->whereNull('read_at')
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            $messageParams = [
                'advance_number' => $cashAdvance->advance_number,
                'step_order' => (int) $currentStepOrder,
            ];

            if ($stepLabel) {
                $messageParams['step_label'] = $stepLabel;
            }

            $messageKey = $stepLabel
                ? 'notification_messages.cash_advance.approval_request.message_with_label'
                : 'notification_messages.cash_advance.approval_request.message_without_label';

            Notification::create([
                'user_id' => $user->id,
                'type' => 'cash_advance_approval',
                'title' => __('notification_messages.cash_advance.approval_request.title'),
                'title_key' => 'notification_messages.cash_advance.approval_request.title',
                'message' => __($messageKey, $messageParams),
                'message_key' => $messageKey,
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => CashAdvance::class,
                'reference_id' => $cashAdvance->id,
                'reference_public_id' => $cashAdvance->encrypted_id,
                'url' => self::URL,
            ]);
        }
    }

    /**
     * Dikirim ke pemohon setelah satu tahap disetujui.
     */
    public function notifyApprovalStep(
        CashAdvance $cashAdvance,
        User $approver,
        CashAdvanceApproval $approval,
        bool $hasPendingApproval,
    ): void {
        $requesterId = $this->getRequesterUserId($cashAdvance);

        if (!$requesterId) {
            return;
        }

        $translationGroup = $hasPendingApproval
            ? 'approval_step_pending'
            : 'approval_step_final';

        $messageParams = [
            'advance_number' => $cashAdvance->advance_number,
            'approver_name' => $approver->name ?? '-',
            'step_order' => $approval->step_order,
        ];

        $titleKey = "notification_messages.cash_advance.{$translationGroup}.title";
        $messageKey = "notification_messages.cash_advance.{$translationGroup}.message";

        Notification::create([
            'user_id' => $requesterId,

            'type' => $hasPendingApproval
                ? 'cash_advance_approval_step_approved'
                : 'cash_advance_approved',

            'title' => __($titleKey),
            'title_key' => $titleKey,

            'message' => __($messageKey, $messageParams),
            'message_key' => $messageKey,
            'message_params' => $messageParams,

            'module' => self::MODULE,
            'reference_type' => CashAdvance::class,
            'reference_id' => $cashAdvance->id,
            'reference_public_id' => $cashAdvance->encrypted_id,
            'url' => self::URL,
        ]);
    }

    public function notifyRejected(
        CashAdvance $cashAdvance,
        User $rejecter,
    ): void {
        $requesterId = $this->getRequesterUserId($cashAdvance);

        if (!$requesterId) {
            return;
        }

        $messageParams = [
            'advance_number' => $cashAdvance->advance_number,
            'rejecter_name' => $rejecter->name ?? '-',
        ];

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'cash_advance_rejected',
            'title' => __('notification_messages.cash_advance.rejected.title'),
            'title_key' => 'notification_messages.cash_advance.rejected.title',
            'message' => __('notification_messages.cash_advance.rejected.message', $messageParams),
            'message_key' => 'notification_messages.cash_advance.rejected.message',
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => CashAdvance::class,
            'reference_id' => $cashAdvance->id,
            'reference_public_id' => $cashAdvance->encrypted_id,
            'url' => self::URL,
        ]);
    }

    /**
     * Dikirim ke pemegang permission pencairan setelah FPU disetujui penuh.
     *
     * Berada di luar approval flow: pencairan bukan tahap approval, jadi tidak
     * ada baris cash_advance_approvals yang bisa dijadikan sumber penerima.
     * Penerimanya siapa pun yang memegang cash_advance.disburse, sama persis
     * dengan yang tombol Cairkan-nya muncul.
     */
    /*
    |--------------------------------------------------------------------------
    | Menunggu diterima PIC
    |--------------------------------------------------------------------------
    | Dikirim tepat setelah approval tuntas. Penerimanya pemegang permission
    | penerimaan -- tahap ini berada di luar approval flow, jadi tidak bisa
    | ditentukan dari baris approval mana pun.
    |--------------------------------------------------------------------------
    */
    public function notifyReceiptRequest(CashAdvance $cashAdvance): void
    {
        $recipients = $this->permissionRecipients
            ->usersWithPermission(self::PERMISSION_RECEIVE);

        if ($recipients->isEmpty()) {
            Log::warning('[FPU Notification] Pemegang permission penerimaan tidak ditemukan', [
                'cash_advance_id' => $cashAdvance->id,
                'advance_number' => $cashAdvance->advance_number,
                'permission' => self::PERMISSION_RECEIVE,
            ]);

            return;
        }

        $messageParams = [
            'advance_number' => $cashAdvance->advance_number,
            'total_amount' => number_format((float) $cashAdvance->total_amount, 0, ',', '.'),
        ];

        foreach ($recipients as $user) {
            /* Cegah tumpukan notifikasi untuk dokumen yang sama. */
            $alreadyExists = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', 'cash_advance_receipt_request')
                ->where('reference_type', CashAdvance::class)
                ->where('reference_id', $cashAdvance->id)
                ->whereNull('read_at')
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            Notification::create([
                'user_id' => $user->id,
                'type' => 'cash_advance_receipt_request',
                'title' => __('notification_messages.cash_advance.receipt_request.title'),
                'title_key' => 'notification_messages.cash_advance.receipt_request.title',
                'message' => __('notification_messages.cash_advance.receipt_request.message', $messageParams),
                'message_key' => 'notification_messages.cash_advance.receipt_request.message',
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => CashAdvance::class,
                'reference_id' => $cashAdvance->id,
                'reference_public_id' => $cashAdvance->encrypted_id,
                'url' => self::URL,
            ]);
        }
    }

    /**
     * Dikirim ke pemohon setelah PIC menandai dokumennya diterima.
     */
    public function notifyReceived(
        CashAdvance $cashAdvance,
        User $receiver,
    ): void {
        $requesterId = $this->getRequesterUserId($cashAdvance);

        if (!$requesterId) {
            return;
        }

        $messageParams = [
            'advance_number' => $cashAdvance->advance_number,
            'receiver_name' => $receiver->name ?? '-',
            'payment_date' => PaymentScheduleService::formatTanggal($cashAdvance->scheduled_payment_date),
        ];

        /*
        | Dokumen tanpa jadwal tetap memakai kalimat lama. Menyebut tanggal
        | pembayaran pada dokumen yang memang tidak dibayarkan hanya
        | membingungkan yang membacanya.
        */
        $kunci = $cashAdvance->scheduled_payment_date
            ? 'notification_messages.cash_advance.received.message_scheduled'
            : 'notification_messages.cash_advance.received.message';

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'cash_advance_received',
            'title' => __('notification_messages.cash_advance.received.title'),
            'title_key' => 'notification_messages.cash_advance.received.title',
            'message' => __($kunci, $messageParams),
            'message_key' => $kunci,
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => CashAdvance::class,
            'reference_id' => $cashAdvance->id,
            'reference_public_id' => $cashAdvance->encrypted_id,
            'url' => self::URL,
        ]);
    }

    public function notifyDisbursementRequest(CashAdvance $cashAdvance): void
    {
        $recipients = $this->permissionRecipients
            ->usersWithPermission(self::PERMISSION_DISBURSE);

        if ($recipients->isEmpty()) {
            Log::warning('[FPU Notification] Pemegang permission pencairan tidak ditemukan', [
                'cash_advance_id' => $cashAdvance->id,
                'advance_number' => $cashAdvance->advance_number,
                'permission' => self::PERMISSION_DISBURSE,
            ]);

            return;
        }

        $messageParams = [
            'advance_number' => $cashAdvance->advance_number,
            'total_amount' => number_format((float) $cashAdvance->total_amount, 0, ',', '.'),
        ];

        foreach ($recipients as $user) {
            /*
            | Cegah tumpukan notifikasi untuk dokumen yang sama bila approval
            | final sempat terpanggil lebih dari sekali.
            */
            $alreadyExists = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', 'cash_advance_disbursement_request')
                ->where('reference_type', CashAdvance::class)
                ->where('reference_id', $cashAdvance->id)
                ->whereNull('read_at')
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            Notification::create([
                'user_id' => $user->id,
                'type' => 'cash_advance_disbursement_request',
                'title' => __('notification_messages.cash_advance.disbursement_request.title'),
                'title_key' => 'notification_messages.cash_advance.disbursement_request.title',
                'message' => __('notification_messages.cash_advance.disbursement_request.message', $messageParams),
                'message_key' => 'notification_messages.cash_advance.disbursement_request.message',
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => CashAdvance::class,
                'reference_id' => $cashAdvance->id,
                'reference_public_id' => $cashAdvance->encrypted_id,
                'url' => self::URL,
            ]);
        }
    }

    /**
     * Dikirim ke pemohon setelah Finance mencairkan dana.
     */
    public function notifyDisbursed(
        CashAdvance $cashAdvance,
        User $disburser,
    ): void {
        $requesterId = $this->getRequesterUserId($cashAdvance);

        if (!$requesterId) {
            return;
        }

        $messageParams = [
            'advance_number' => $cashAdvance->advance_number,
            'disburser_name' => $disburser->name ?? '-',
        ];

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'cash_advance_disbursed',
            'title' => __('notification_messages.cash_advance.disbursed.title'),
            'title_key' => 'notification_messages.cash_advance.disbursed.title',
            'message' => __('notification_messages.cash_advance.disbursed.message', $messageParams),
            'message_key' => 'notification_messages.cash_advance.disbursed.message',
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => CashAdvance::class,
            'reference_id' => $cashAdvance->id,
            'reference_public_id' => $cashAdvance->encrypted_id,
            'url' => self::URL,
        ]);
    }

    public function resolveApproverUsers(
        CashAdvanceApproval $approval,
    ): Collection {
        $approverType = strtoupper(trim((string) $approval->approver_type));

        if (!$approval->approver_id) {
            return collect();
        }

        if ($approverType === CashAdvanceApproval::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($approval->approver_id)
                ->get();
        }

        if ($approverType === CashAdvanceApproval::APPROVER_TYPE_ROLE) {
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
    private function getRequesterUserId(CashAdvance $cashAdvance): ?int
    {
        $requesterId = $cashAdvance->submitted_by
            ?? $cashAdvance->created_by
            ?? null;

        return $requesterId ? (int) $requesterId : null;
    }
}
