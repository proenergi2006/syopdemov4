<?php

namespace App\Services\FundRequest\CashAdvance;

use App\Models\CashAdvance;
use App\Models\CashAdvanceApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Mesin approval FPU
|--------------------------------------------------------------------------
| Bekerja di atas baris cash_advance_approvals hasil generator, mengikuti
| perilaku PR: mode ANY cukup satu approver, mode ALL menunggu semua.
|--------------------------------------------------------------------------
*/
class CashAdvanceApprovalService
{
    public function getCurrentStepOrder(CashAdvance $cashAdvance): ?int
    {
        $stepOrder = CashAdvanceApproval::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->where('status', CashAdvanceApproval::STATUS_WAITING)
            ->min('step_order');

        return $stepOrder !== null
            ? (int) $stepOrder
            : null;
    }

    public function getCurrentWaitingApprovals(
        CashAdvance $cashAdvance,
        bool $lockForUpdate = false,
    ): Collection {
        $currentStepOrder = $this->getCurrentStepOrder($cashAdvance);

        if ($currentStepOrder === null) {
            return new Collection();
        }

        $query = CashAdvanceApproval::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->where('step_order', $currentStepOrder)
            ->where('status', CashAdvanceApproval::STATUS_WAITING)
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function getUserCurrentApproval(
        CashAdvance $cashAdvance,
        User $user,
        bool $lockForUpdate = false,
    ): ?CashAdvanceApproval {
        return $this
            ->getCurrentWaitingApprovals($cashAdvance, $lockForUpdate)
            ->first(
                fn(CashAdvanceApproval $approval): bool =>
                $this->userCanApprove($approval, $user),
            );
    }

    public function userCanApprove(
        CashAdvanceApproval $approval,
        User $user,
    ): bool {
        if (
            strtoupper((string) $approval->status)
            !== CashAdvanceApproval::STATUS_WAITING
        ) {
            return false;
        }

        $approverType = strtoupper(trim((string) $approval->approver_type));

        if ($approverType === CashAdvanceApproval::APPROVER_TYPE_USER) {
            return (int) $approval->approver_id === (int) $user->id;
        }

        if ($approverType === CashAdvanceApproval::APPROVER_TYPE_ROLE) {
            return $this->userHasRoleId($user, (int) $approval->approver_id);
        }

        return false;
    }

    /**
     * Menyetujui step aktif milik user.
     *
     * @return array{
     *     approval: CashAdvanceApproval,
     *     step_completed: bool,
     *     has_pending_approval: bool,
     *     is_final_approved: bool,
     *     next_step_order: int|null
     * }
     */
    public function approveCurrentStep(
        CashAdvance $cashAdvance,
        User $user,
        ?string $notes = null,
    ): array {
        $approval = $this->getUserCurrentApproval($cashAdvance, $user, true);

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk FPU ini.',
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
                    ?: CashAdvanceApproval::APPROVAL_MODE_ANY
                ),
            ),
        );

        $approval->update([
            'status' => CashAdvanceApproval::STATUS_APPROVED,
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
        if ($approvalMode === CashAdvanceApproval::APPROVAL_MODE_ANY) {
            CashAdvanceApproval::query()
                ->where('cash_advance_id', $cashAdvance->id)
                ->where('step_order', $approval->step_order)
                ->whereKeyNot($approval->id)
                ->where('status', CashAdvanceApproval::STATUS_WAITING)
                ->update([
                    'status' => CashAdvanceApproval::STATUS_SKIPPED,
                    'notes' => 'Skipped karena approval mode ANY telah dipenuhi oleh '
                        . ($user->name ?? 'approver')
                        . '.',
                    'updated_at' => now(),
                ]);
        }

        $stepStillWaiting = CashAdvanceApproval::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->where('step_order', $approval->step_order)
            ->where('status', CashAdvanceApproval::STATUS_WAITING)
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

        $nextStepOrder = CashAdvanceApproval::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->where('status', CashAdvanceApproval::STATUS_PENDING)
            ->where('step_order', '>', $approval->step_order)
            ->min('step_order');

        if ($nextStepOrder !== null) {
            CashAdvanceApproval::query()
                ->where('cash_advance_id', $cashAdvance->id)
                ->where('step_order', (int) $nextStepOrder)
                ->where('status', CashAdvanceApproval::STATUS_PENDING)
                ->update([
                    'status' => CashAdvanceApproval::STATUS_WAITING,
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

        $this->markCashAdvanceApproved($cashAdvance, $user);

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
        CashAdvance $cashAdvance,
        User $user,
        ?string $notes = null,
    ): CashAdvanceApproval {
        $approval = $this->getUserCurrentApproval($cashAdvance, $user, true);

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk FPU ini.',
                ],
            ]);
        }

        $notes = $this->sanitizeNotes($notes);

        $approval->update([
            'status' => CashAdvanceApproval::STATUS_REJECTED,
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => !empty($user->signature_path) ? now() : null,
            'approved_at' => null,
            'rejected_at' => now(),
            'notes' => $notes,
        ]);

        CashAdvanceApproval::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->whereKeyNot($approval->id)
            ->whereIn('status', [
                CashAdvanceApproval::STATUS_WAITING,
                CashAdvanceApproval::STATUS_PENDING,
            ])
            ->update([
                'status' => CashAdvanceApproval::STATUS_CANCELLED,
                'notes' => 'Cancelled karena FPU direject.',
                'updated_at' => now(),
            ]);

        $this->markCashAdvanceRejected($cashAdvance, $user, $notes);

        return $approval->fresh();
    }

    public function hasPendingApproval(CashAdvance $cashAdvance): bool
    {
        return CashAdvanceApproval::query()
            ->where('cash_advance_id', $cashAdvance->id)
            ->whereIn('status', [
                CashAdvanceApproval::STATUS_WAITING,
                CashAdvanceApproval::STATUS_PENDING,
            ])
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Final approved
    |--------------------------------------------------------------------------
    | Berhenti di APPROVED. Pencairan oleh Finance adalah aksi terpisah yang
    | memindahkan status ke DISBURSED.
    |--------------------------------------------------------------------------
    */
    public function markCashAdvanceApproved(
        CashAdvance $cashAdvance,
        User $user,
    ): void {
        $cashAdvance->update([
            'status' => CashAdvance::STATUS_APPROVED,
            'final_approved_by' => $user->id,
            'final_approved_at' => now(),
        ]);
    }

    public function markCashAdvanceRejected(
        CashAdvance $cashAdvance,
        User $user,
        ?string $notes = null,
    ): void {
        $cashAdvance->update([
            'status' => CashAdvance::STATUS_REJECTED,
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
