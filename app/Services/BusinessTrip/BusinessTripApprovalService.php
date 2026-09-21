<?php

namespace App\Services\BusinessTrip;

use App\Models\BusinessTrip;
use App\Models\BusinessTripApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Mesin approval Perdin
|--------------------------------------------------------------------------
| Bekerja di atas baris business_trip_approvals hasil generator, mengikuti
| perilaku PR: mode ANY cukup satu approver, mode ALL menunggu semua.
|--------------------------------------------------------------------------
*/
class BusinessTripApprovalService
{
    public function getCurrentStepOrder(BusinessTrip $trip): ?int
    {
        $stepOrder = BusinessTripApproval::query()
            ->where('business_trip_id', $trip->id)
            ->where('status', BusinessTripApproval::STATUS_WAITING)
            ->min('step_order');

        return $stepOrder !== null
            ? (int) $stepOrder
            : null;
    }

    public function getCurrentWaitingApprovals(
        BusinessTrip $trip,
        bool $lockForUpdate = false,
    ): Collection {
        $currentStepOrder = $this->getCurrentStepOrder($trip);

        if ($currentStepOrder === null) {
            return new Collection();
        }

        $query = BusinessTripApproval::query()
            ->where('business_trip_id', $trip->id)
            ->where('step_order', $currentStepOrder)
            ->where('status', BusinessTripApproval::STATUS_WAITING)
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function getUserCurrentApproval(
        BusinessTrip $trip,
        User $user,
        bool $lockForUpdate = false,
    ): ?BusinessTripApproval {
        return $this
            ->getCurrentWaitingApprovals($trip, $lockForUpdate)
            ->first(
                fn(BusinessTripApproval $approval): bool =>
                $this->userCanApprove($approval, $user),
            );
    }

    public function userCanApprove(
        BusinessTripApproval $approval,
        User $user,
    ): bool {
        if (
            strtoupper((string) $approval->status)
            !== BusinessTripApproval::STATUS_WAITING
        ) {
            return false;
        }

        $approverType = strtoupper(trim((string) $approval->approver_type));

        if ($approverType === BusinessTripApproval::APPROVER_TYPE_USER) {
            return (int) $approval->approver_id === (int) $user->id;
        }

        if ($approverType === BusinessTripApproval::APPROVER_TYPE_ROLE) {
            return $this->userHasRoleId($user, (int) $approval->approver_id);
        }

        return false;
    }

    /**
     * Menyetujui step aktif milik user.
     *
     * @return array{
     *     approval: BusinessTripApproval,
     *     step_completed: bool,
     *     has_pending_approval: bool,
     *     is_final_approved: bool,
     *     next_step_order: int|null
     * }
     */
    public function approveCurrentStep(
        BusinessTrip $trip,
        User $user,
        ?string $notes = null,
    ): array {
        $approval = $this->getUserCurrentApproval($trip, $user, true);

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Perdin ini.',
                ],
            ]);
        }

        if (empty($user->signature_path)) {
            throw ValidationException::withMessages([
                'signature' => [
                    'Anda belum memiliki tanda tangan digital.',
                ],
            ]);
        }

        $notes = $this->sanitizeNotes($notes);

        $approvalMode = strtoupper(
            trim(
                (string) (
                    $approval->approval_mode
                    ?: BusinessTripApproval::APPROVAL_MODE_ANY
                ),
            ),
        );

        $approval->update([
            'status' => BusinessTripApproval::STATUS_APPROVED,
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => now(),
            'approved_at' => now(),
            'rejected_at' => null,
            'notes' => $notes,
        ]);

        /*
        | Mode ANY: satu approver cukup, sisanya pada step yang sama di-skip.
        */
        if ($approvalMode === BusinessTripApproval::APPROVAL_MODE_ANY) {
            BusinessTripApproval::query()
                ->where('business_trip_id', $trip->id)
                ->where('step_order', $approval->step_order)
                ->whereKeyNot($approval->id)
                ->where('status', BusinessTripApproval::STATUS_WAITING)
                ->update([
                    'status' => BusinessTripApproval::STATUS_SKIPPED,
                    'notes' => 'Skipped karena approval mode ANY telah dipenuhi oleh '
                        . ($user->name ?? 'approver')
                        . '.',
                    'updated_at' => now(),
                ]);
        }

        $stepStillWaiting = BusinessTripApproval::query()
            ->where('business_trip_id', $trip->id)
            ->where('step_order', $approval->step_order)
            ->where('status', BusinessTripApproval::STATUS_WAITING)
            ->exists();

        // Mode ALL: masih ada approver lain pada step ini.
        if ($stepStillWaiting) {
            return [
                'approval' => $approval->fresh(),
                'step_completed' => false,
                'has_pending_approval' => true,
                'is_final_approved' => false,
                'next_step_order' => null,
            ];
        }

        $nextStepOrder = BusinessTripApproval::query()
            ->where('business_trip_id', $trip->id)
            ->where('status', BusinessTripApproval::STATUS_PENDING)
            ->where('step_order', '>', $approval->step_order)
            ->min('step_order');

        if ($nextStepOrder !== null) {
            BusinessTripApproval::query()
                ->where('business_trip_id', $trip->id)
                ->where('step_order', (int) $nextStepOrder)
                ->where('status', BusinessTripApproval::STATUS_PENDING)
                ->update([
                    'status' => BusinessTripApproval::STATUS_WAITING,
                    'updated_at' => now(),
                ]);

            return [
                'approval' => $approval->fresh(),
                'step_completed' => true,
                'has_pending_approval' => true,
                'is_final_approved' => false,
                'next_step_order' => (int) $nextStepOrder,
            ];
        }

        $this->markTripApproved($trip, $user);

        return [
            'approval' => $approval->fresh(),
            'step_completed' => true,
            'has_pending_approval' => false,
            'is_final_approved' => true,
            'next_step_order' => null,
        ];
    }

    /**
     * Menolak step aktif. Satu penolakan menghentikan seluruh flow.
     */
    public function rejectCurrentStep(
        BusinessTrip $trip,
        User $user,
        ?string $notes = null,
    ): BusinessTripApproval {
        $approval = $this->getUserCurrentApproval($trip, $user, true);

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Perdin ini.',
                ],
            ]);
        }

        $notes = $this->sanitizeNotes($notes);

        $approval->update([
            'status' => BusinessTripApproval::STATUS_REJECTED,
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => !empty($user->signature_path) ? now() : null,
            'approved_at' => null,
            'rejected_at' => now(),
            'notes' => $notes,
        ]);

        BusinessTripApproval::query()
            ->where('business_trip_id', $trip->id)
            ->whereKeyNot($approval->id)
            ->whereIn('status', [
                BusinessTripApproval::STATUS_WAITING,
                BusinessTripApproval::STATUS_PENDING,
            ])
            ->update([
                'status' => BusinessTripApproval::STATUS_CANCELLED,
                'notes' => 'Cancelled karena Perdin direject.',
                'updated_at' => now(),
            ]);

        $this->markTripRejected($trip, $user, $notes);

        return $approval->fresh();
    }

    public function hasPendingApproval(BusinessTrip $trip): bool
    {
        return BusinessTripApproval::query()
            ->where('business_trip_id', $trip->id)
            ->whereIn('status', [
                BusinessTripApproval::STATUS_WAITING,
                BusinessTripApproval::STATUS_PENDING,
            ])
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Final approved
    |--------------------------------------------------------------------------
    | Berhenti di APPROVED, dan memang berhenti di situ. Perdin tidak punya
    | tahap pembayaran -- yang disetujui perjalanannya, bukan uangnya. Dananya
    | diurus FPU yang menautkan diri pada perdin ini, dengan alur dan
    | persetujuannya sendiri.
    |--------------------------------------------------------------------------
    */
    public function markTripApproved(
        BusinessTrip $trip,
        User $user,
    ): void {
        $trip->update([
            'status' => BusinessTrip::STATUS_APPROVED,
            'final_approved_by' => $user->id,
            'final_approved_at' => now(),
        ]);
    }

    public function markTripRejected(
        BusinessTrip $trip,
        User $user,
        ?string $notes = null,
    ): void {
        $trip->update([
            'status' => BusinessTrip::STATUS_REJECTED,
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_notes' => $notes,
        ]);
    }

    public function sanitizeNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        if ($notes === '') {
            return null;
        }

        return htmlspecialchars(
            strip_tags($notes),
            ENT_QUOTES,
            'UTF-8',
        );
    }

    private function userHasRoleId(User $user, int $roleId): bool
    {
        if ($roleId <= 0) {
            return false;
        }

        // Struktur utama project.
        if (
            Schema::hasTable('user_roles')
            && DB::table('user_roles')
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->exists()
        ) {
            return true;
        }

        // Kompatibilitas struktur lama.
        if (
            Schema::hasTable('role_user')
            && DB::table('role_user')
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->exists()
        ) {
            return true;
        }

        if (
            Schema::hasColumn('users', 'role_id')
            && $user->getAttribute('role_id') !== null
            && (int) $user->getAttribute('role_id') === $roleId
        ) {
            return true;
        }

        return false;
    }
}
