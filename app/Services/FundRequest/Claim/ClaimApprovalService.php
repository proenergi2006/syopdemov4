<?php

namespace App\Services\FundRequest\Claim;

use App\Models\Claim;
use App\Models\ClaimApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Mesin approval Claim
|--------------------------------------------------------------------------
| Bekerja di atas baris claim_approvals hasil generator, mengikuti
| perilaku PR: mode ANY cukup satu approver, mode ALL menunggu semua.
|--------------------------------------------------------------------------
*/
class ClaimApprovalService
{
    public function getCurrentStepOrder(Claim $claim): ?int
    {
        $stepOrder = ClaimApproval::query()
            ->where('claim_id', $claim->id)
            ->where('status', ClaimApproval::STATUS_WAITING)
            ->min('step_order');

        return $stepOrder !== null
            ? (int) $stepOrder
            : null;
    }

    public function getCurrentWaitingApprovals(
        Claim $claim,
        bool $lockForUpdate = false,
    ): Collection {
        $currentStepOrder = $this->getCurrentStepOrder($claim);

        if ($currentStepOrder === null) {
            return new Collection();
        }

        $query = ClaimApproval::query()
            ->where('claim_id', $claim->id)
            ->where('step_order', $currentStepOrder)
            ->where('status', ClaimApproval::STATUS_WAITING)
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function getUserCurrentApproval(
        Claim $claim,
        User $user,
        bool $lockForUpdate = false,
    ): ?ClaimApproval {
        return $this
            ->getCurrentWaitingApprovals($claim, $lockForUpdate)
            ->first(
                fn(ClaimApproval $approval): bool =>
                $this->userCanApprove($approval, $user),
            );
    }

    public function userCanApprove(
        ClaimApproval $approval,
        User $user,
    ): bool {
        if (
            strtoupper((string) $approval->status)
            !== ClaimApproval::STATUS_WAITING
        ) {
            return false;
        }

        $approverType = strtoupper(trim((string) $approval->approver_type));

        if ($approverType === ClaimApproval::APPROVER_TYPE_USER) {
            return (int) $approval->approver_id === (int) $user->id;
        }

        if ($approverType === ClaimApproval::APPROVER_TYPE_ROLE) {
            return $this->userHasRoleId($user, (int) $approval->approver_id);
        }

        return false;
    }

    /**
     * Menyetujui step aktif milik user.
     *
     * @return array{
     *     approval: ClaimApproval,
     *     step_completed: bool,
     *     has_pending_approval: bool,
     *     is_final_approved: bool,
     *     next_step_order: int|null
     * }
     */
    public function approveCurrentStep(
        Claim $claim,
        User $user,
        ?string $notes = null,
    ): array {
        $approval = $this->getUserCurrentApproval($claim, $user, true);

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Claim ini.',
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
                    ?: ClaimApproval::APPROVAL_MODE_ANY
                ),
            ),
        );

        $approval->update([
            'status' => ClaimApproval::STATUS_APPROVED,
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
        if ($approvalMode === ClaimApproval::APPROVAL_MODE_ANY) {
            ClaimApproval::query()
                ->where('claim_id', $claim->id)
                ->where('step_order', $approval->step_order)
                ->whereKeyNot($approval->id)
                ->where('status', ClaimApproval::STATUS_WAITING)
                ->update([
                    'status' => ClaimApproval::STATUS_SKIPPED,
                    'notes' => 'Skipped karena approval mode ANY telah dipenuhi oleh '
                        . ($user->name ?? 'approver')
                        . '.',
                    'updated_at' => now(),
                ]);
        }

        $stepStillWaiting = ClaimApproval::query()
            ->where('claim_id', $claim->id)
            ->where('step_order', $approval->step_order)
            ->where('status', ClaimApproval::STATUS_WAITING)
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

        $nextStepOrder = ClaimApproval::query()
            ->where('claim_id', $claim->id)
            ->where('status', ClaimApproval::STATUS_PENDING)
            ->where('step_order', '>', $approval->step_order)
            ->min('step_order');

        if ($nextStepOrder !== null) {
            ClaimApproval::query()
                ->where('claim_id', $claim->id)
                ->where('step_order', (int) $nextStepOrder)
                ->where('status', ClaimApproval::STATUS_PENDING)
                ->update([
                    'status' => ClaimApproval::STATUS_WAITING,
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

        $this->markClaimApproved($claim, $user);

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
        Claim $claim,
        User $user,
        ?string $notes = null,
    ): ClaimApproval {
        $approval = $this->getUserCurrentApproval($claim, $user, true);

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Anda bukan approver aktif untuk Claim ini.',
                ],
            ]);
        }

        $notes = $this->sanitizeNotes($notes);

        $approval->update([
            'status' => ClaimApproval::STATUS_REJECTED,
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => !empty($user->signature_path) ? now() : null,
            'approved_at' => null,
            'rejected_at' => now(),
            'notes' => $notes,
        ]);

        ClaimApproval::query()
            ->where('claim_id', $claim->id)
            ->whereKeyNot($approval->id)
            ->whereIn('status', [
                ClaimApproval::STATUS_WAITING,
                ClaimApproval::STATUS_PENDING,
            ])
            ->update([
                'status' => ClaimApproval::STATUS_CANCELLED,
                'notes' => 'Cancelled karena Claim direject.',
                'updated_at' => now(),
            ]);

        $this->markClaimRejected($claim, $user, $notes);

        return $approval->fresh();
    }

    public function hasPendingApproval(Claim $claim): bool
    {
        return ClaimApproval::query()
            ->where('claim_id', $claim->id)
            ->whereIn('status', [
                ClaimApproval::STATUS_WAITING,
                ClaimApproval::STATUS_PENDING,
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
    public function markClaimApproved(
        Claim $claim,
        User $user,
    ): void {
        $claim->update([
            'status' => Claim::STATUS_APPROVED,
            'final_approved_by' => $user->id,
            'final_approved_at' => now(),
        ]);
    }

    public function markClaimRejected(
        Claim $claim,
        User $user,
        ?string $notes = null,
    ): void {
        $claim->update([
            'status' => Claim::STATUS_REJECTED,
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
