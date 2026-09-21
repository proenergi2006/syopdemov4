<?php

namespace App\Services\FundRequest\Realization;

use App\Models\CashAdvanceRealization;
use App\Models\CashAdvanceRealizationApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Mesin approval Realisasi FPU
|--------------------------------------------------------------------------
| Bekerja di atas baris cash_advance_realization_approvals hasil generator, mengikuti
| perilaku PR: mode ANY cukup satu approver, mode ALL menunggu semua.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationApprovalService
{
    public function getCurrentStepOrder(CashAdvanceRealization $realization): ?int
    {
        $stepOrder = CashAdvanceRealizationApproval::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->where('status', CashAdvanceRealizationApproval::STATUS_WAITING)
            ->min('step_order');

        return $stepOrder !== null
            ? (int) $stepOrder
            : null;
    }

    public function getCurrentWaitingApprovals(
        CashAdvanceRealization $realization,
        bool $lockForUpdate = false,
    ): Collection {
        $currentStepOrder = $this->getCurrentStepOrder($realization);

        if ($currentStepOrder === null) {
            return new Collection();
        }

        $query = CashAdvanceRealizationApproval::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->where('step_order', $currentStepOrder)
            ->where('status', CashAdvanceRealizationApproval::STATUS_WAITING)
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function getUserCurrentApproval(
        CashAdvanceRealization $realization,
        User $user,
        bool $lockForUpdate = false,
    ): ?CashAdvanceRealizationApproval {
        return $this
            ->getCurrentWaitingApprovals($realization, $lockForUpdate)
            ->first(
                fn(CashAdvanceRealizationApproval $approval): bool =>
                $this->userCanApprove($approval, $user),
            );
    }

    public function userCanApprove(
        CashAdvanceRealizationApproval $approval,
        User $user,
    ): bool {
        if (
            strtoupper((string) $approval->status)
            !== CashAdvanceRealizationApproval::STATUS_WAITING
        ) {
            return false;
        }

        $approverType = strtoupper(trim((string) $approval->approver_type));

        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_USER) {
            return (int) $approval->approver_id === (int) $user->id;
        }

        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_ROLE) {
            return $this->userHasRoleId($user, (int) $approval->approver_id);
        }

        return false;
    }

    /**
     * Menyetujui step aktif milik user.
     *
     * @return array{
     *     approval: CashAdvanceRealizationApproval,
     *     step_completed: bool,
     *     has_pending_approval: bool,
     *     is_final_approved: bool,
     *     next_step_order: int|null
     * }
     */
    public function approveCurrentStep(
        CashAdvanceRealization $realization,
        User $user,
        ?string $notes = null,
    ): array {
        $approval = $this->getUserCurrentApproval($realization, $user, true);

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Realisasi FPU ini.',
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
                    ?: CashAdvanceRealizationApproval::APPROVAL_MODE_ANY
                ),
            ),
        );

        $approval->update([
            'status' => CashAdvanceRealizationApproval::STATUS_APPROVED,
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
        if ($approvalMode === CashAdvanceRealizationApproval::APPROVAL_MODE_ANY) {
            CashAdvanceRealizationApproval::query()
                ->where('cash_advance_realization_id', $realization->id)
                ->where('step_order', $approval->step_order)
                ->whereKeyNot($approval->id)
                ->where('status', CashAdvanceRealizationApproval::STATUS_WAITING)
                ->update([
                    'status' => CashAdvanceRealizationApproval::STATUS_SKIPPED,
                    'notes' => 'Skipped karena approval mode ANY telah dipenuhi oleh '
                        . ($user->name ?? 'approver')
                        . '.',
                    'updated_at' => now(),
                ]);
        }

        $stepStillWaiting = CashAdvanceRealizationApproval::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->where('step_order', $approval->step_order)
            ->where('status', CashAdvanceRealizationApproval::STATUS_WAITING)
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

        $nextStepOrder = CashAdvanceRealizationApproval::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->where('status', CashAdvanceRealizationApproval::STATUS_PENDING)
            ->where('step_order', '>', $approval->step_order)
            ->min('step_order');

        if ($nextStepOrder !== null) {
            CashAdvanceRealizationApproval::query()
                ->where('cash_advance_realization_id', $realization->id)
                ->where('step_order', (int) $nextStepOrder)
                ->where('status', CashAdvanceRealizationApproval::STATUS_PENDING)
                ->update([
                    'status' => CashAdvanceRealizationApproval::STATUS_WAITING,
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

        $this->markRealizationApproved($realization, $user);

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
        CashAdvanceRealization $realization,
        User $user,
        ?string $notes = null,
    ): CashAdvanceRealizationApproval {
        $approval = $this->getUserCurrentApproval($realization, $user, true);

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Realisasi FPU ini.',
                ],
            ]);
        }

        $notes = $this->sanitizeNotes($notes);

        $approval->update([
            'status' => CashAdvanceRealizationApproval::STATUS_REJECTED,
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => !empty($user->signature_path) ? now() : null,
            'approved_at' => null,
            'rejected_at' => now(),
            'notes' => $notes,
        ]);

        CashAdvanceRealizationApproval::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->whereKeyNot($approval->id)
            ->whereIn('status', [
                CashAdvanceRealizationApproval::STATUS_WAITING,
                CashAdvanceRealizationApproval::STATUS_PENDING,
            ])
            ->update([
                'status' => CashAdvanceRealizationApproval::STATUS_CANCELLED,
                'notes' => 'Cancelled karena Realisasi FPU direject.',
                'updated_at' => now(),
            ]);

        $this->markRealizationRejected($realization, $user, $notes);

        return $approval->fresh();
    }

    public function hasPendingApproval(CashAdvanceRealization $realization): bool
    {
        return CashAdvanceRealizationApproval::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->whereIn('status', [
                CashAdvanceRealizationApproval::STATUS_WAITING,
                CashAdvanceRealizationApproval::STATUS_PENDING,
            ])
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Final approved
    |--------------------------------------------------------------------------
    | Berhenti di APPROVED. Penyelesaian selisih oleh Finance adalah aksi
    | terpisah yang memindahkan status ke SETTLED.
    |--------------------------------------------------------------------------
    */
    public function markRealizationApproved(
        CashAdvanceRealization $realization,
        User $user,
    ): void {
        $realization->update([
            'status' => CashAdvanceRealization::STATUS_APPROVED,
            'final_approved_by' => $user->id,
            'final_approved_at' => now(),
        ]);
    }

    public function markRealizationRejected(
        CashAdvanceRealization $realization,
        User $user,
        ?string $notes = null,
    ): void {
        $realization->update([
            'status' => CashAdvanceRealization::STATUS_REJECTED,
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
