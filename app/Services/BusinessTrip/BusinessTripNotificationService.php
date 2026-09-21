<?php

namespace App\Services\BusinessTrip;

use App\Models\BusinessTrip;
use App\Models\BusinessTripApproval;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Notifikasi aplikasi untuk Perdin
|--------------------------------------------------------------------------
| Sumber penerima adalah baris business_trip_approvals hasil generator, bukan
| approval_flow_steps -- sehingga penggantian approver apa pun otomatis ikut
| terbawa ke notifikasi.
|
| SENGAJA LEBIH RAMPING daripada milik Claim. Claim punya peristiwa penerimaan
| dan pembayaran; Perdin tidak punya keduanya -- yang disetujui perjalanannya,
| bukan uangnya. Menyalin peristiwa itu ke sini hanya akan menghasilkan kode
| yang tidak pernah dijalankan, dan kode semacam itu membusuk tanpa ketahuan.
|
| Tiga peristiwa yang ada:
|
|   1. diajukan        -> approver langkah yang sedang aktif
|   2. satu tahap lewat -> pemohon
|   3. ditolak          -> pemohon
|--------------------------------------------------------------------------
*/
class BusinessTripNotificationService
{
    private const MODULE = 'business_trip';

    private const URL = '/business_trip/perdin';

    /**
     * Dipanggil setelah Perdin diajukan atau setelah step berikutnya aktif.
     */
    public function notifyApprovalRequest(BusinessTrip $trip): void
    {
        $currentStepOrder = BusinessTripApproval::query()
            ->where('business_trip_id', $trip->id)
            ->where('status', BusinessTripApproval::STATUS_WAITING)
            ->min('step_order');

        if ($currentStepOrder === null) {
            Log::warning('[Perdin Notification] Approval WAITING tidak ditemukan', [
                'business_trip_id' => $trip->id,
                'trip_number' => $trip->trip_number,
            ]);

            return;
        }

        $currentApprovals = BusinessTripApproval::query()
            ->where('business_trip_id', $trip->id)
            ->where('step_order', (int) $currentStepOrder)
            ->where('status', BusinessTripApproval::STATUS_WAITING)
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
                fn (BusinessTripApproval $approval): Collection => $this->resolveApproverUsers($approval),
            )
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->values();

        if ($approverUsers->isEmpty()) {
            Log::warning('[Perdin Notification] Approver user tidak ditemukan', [
                'business_trip_id' => $trip->id,
                'trip_number' => $trip->trip_number,
                'step_order' => (int) $currentStepOrder,
            ]);

            return;
        }

        $stepLabel = $currentApprovals->pluck('label')->filter()->first();

        foreach ($approverUsers as $user) {
            /* Cegah notifikasi ganda yang masih belum dibaca untuk dokumen sama. */
            $sudahAda = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', 'business_trip_approval')
                ->where('reference_type', BusinessTrip::class)
                ->where('reference_id', $trip->id)
                ->whereNull('read_at')
                ->exists();

            if ($sudahAda) {
                continue;
            }

            $messageParams = [
                'trip_number' => $trip->trip_number,
                'step_order' => (int) $currentStepOrder,
            ];

            if ($stepLabel) {
                $messageParams['step_label'] = $stepLabel;
            }

            $messageKey = $stepLabel
                ? 'notification_messages.business_trip.approval_request.message_with_label'
                : 'notification_messages.business_trip.approval_request.message_without_label';

            Notification::create([
                'user_id' => $user->id,
                'type' => 'business_trip_approval',
                'title' => __('notification_messages.business_trip.approval_request.title'),
                'title_key' => 'notification_messages.business_trip.approval_request.title',
                'message' => __($messageKey, $messageParams),
                'message_key' => $messageKey,
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => BusinessTrip::class,
                'reference_id' => $trip->id,
                'reference_public_id' => $trip->encrypted_id,
                'url' => self::URL,
            ]);
        }
    }

    /**
     * Dikirim ke pemohon setelah satu tahap disetujui.
     */
    public function notifyApprovalStep(
        BusinessTrip $trip,
        User $approver,
        BusinessTripApproval $approval,
        bool $hasPendingApproval,
    ): void {
        $requesterId = $this->getRequesterUserId($trip);

        if (!$requesterId) {
            return;
        }

        $kelompok = $hasPendingApproval
            ? 'approval_step_pending'
            : 'approval_step_final';

        $messageParams = [
            'trip_number' => $trip->trip_number,
            'approver_name' => $approver->name ?? '-',
            'step_order' => $approval->step_order,
        ];

        $titleKey = "notification_messages.business_trip.{$kelompok}.title";
        $messageKey = "notification_messages.business_trip.{$kelompok}.message";

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'business_trip_' . $kelompok,
            'title' => __($titleKey),
            'title_key' => $titleKey,
            'message' => __($messageKey, $messageParams),
            'message_key' => $messageKey,
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => BusinessTrip::class,
            'reference_id' => $trip->id,
            'reference_public_id' => $trip->encrypted_id,
            'url' => self::URL,
        ]);
    }

    /**
     * Dikirim ke pemohon saat pengajuannya ditolak.
     */
    public function notifyRejected(
        BusinessTrip $trip,
        User $approver,
        ?string $notes = null,
    ): void {
        $requesterId = $this->getRequesterUserId($trip);

        if (!$requesterId) {
            return;
        }

        $messageParams = [
            'trip_number' => $trip->trip_number,
            'approver_name' => $approver->name ?? '-',
            'notes' => $notes ?: '-',
        ];

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'business_trip_rejected',
            'title' => __('notification_messages.business_trip.rejected.title'),
            'title_key' => 'notification_messages.business_trip.rejected.title',
            'message' => __('notification_messages.business_trip.rejected.message', $messageParams),
            'message_key' => 'notification_messages.business_trip.rejected.message',
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => BusinessTrip::class,
            'reference_id' => $trip->id,
            'reference_public_id' => $trip->encrypted_id,
            'url' => self::URL,
        ]);
    }

    /**
     * Akun-akun yang berhak menyetujui sebuah baris approval.
     *
     * Baris bertipe ROLE bisa menunjuk banyak orang sekaligus.
     */
    public function resolveApproverUsers(BusinessTripApproval $approval): Collection
    {
        $tipe = strtoupper(trim((string) $approval->approver_type));

        if (!$approval->approver_id) {
            return collect();
        }

        if ($tipe === BusinessTripApproval::APPROVER_TYPE_USER) {
            return User::query()->whereKey($approval->approver_id)->get();
        }

        if ($tipe === BusinessTripApproval::APPROVER_TYPE_ROLE) {
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
                DB::table('user_roles')->where('role_id', $roleId)->pluck('user_id'),
            );
        }

        /* Kompatibilitas struktur lama, sama seperti modul lain. */
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
            ->filter(fn ($id) => $id !== null && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
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
     * Pemohon adalah penekan tombol Ajukan; created_by dipakai sebagai cadangan.
     */
    private function getRequesterUserId(BusinessTrip $trip): ?int
    {
        $requesterId = $trip->submitted_by ?? $trip->created_by ?? null;

        return $requesterId ? (int) $requesterId : null;
    }
}
