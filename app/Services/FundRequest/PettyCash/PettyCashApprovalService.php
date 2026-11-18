<?php

namespace App\Services\FundRequest\PettyCash;

use App\Models\PettyCash;
use App\Models\PettyCashApproval;
use App\Models\PettyCashTopup;
use App\Models\PettyCashTopupApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Mesin approval Petty Cash dan Top Up
|--------------------------------------------------------------------------
| Bekerja di atas baris hasil generator, dengan perilaku yang sama persis
| seperti keempat modul lain: mode ANY cukup satu penyetuju, mode ALL
| menunggu semuanya, satu penolakan menghentikan seluruh rantai.
|
| Satu kelas untuk dua dokumen, sejalan dengan generatornya -- alasannya
| ditulis panjang di kepala PettyCashApprovalGeneratorService.
|
| YANG TIDAK DIKERJAKAN DI SINI: akibat dari disetujui penuh. Petty cash yang
| disetujui penuh belum memotong plafon dan belum membayar claim apa pun; itu
| akibat dari PENCAIRAN, dan ada di PettyCashService. Top up yang disetujui
| penuh memang langsung menambah saldo, tetapi pencatatannya pun lewat
| PettyCashLedgerService, bukan dari sini. Mesin approval hanya menggerakkan
| status; uang tidak pernah berpindah di dalam kelas ini.
|--------------------------------------------------------------------------
*/
class PettyCashApprovalService
{
    /**
     * @return array{approval: class-string<Model>, foreign: string, label: string, approved: string, rejected: string}
     */
    private function config(Model $document): array
    {
        if ($document instanceof PettyCash) {
            return [
                'approval' => PettyCashApproval::class,
                'foreign' => 'petty_cash_id',
                'label' => 'Petty Cash',
                'approved' => PettyCash::STATUS_APPROVED,
                'rejected' => PettyCash::STATUS_REJECTED,
            ];
        }

        if ($document instanceof PettyCashTopup) {
            return [
                'approval' => PettyCashTopupApproval::class,
                'foreign' => 'petty_cash_topup_id',
                'label' => 'Top Up Petty Cash',
                'approved' => PettyCashTopup::STATUS_APPROVED,
                'rejected' => PettyCashTopup::STATUS_REJECTED,
            ];
        }

        throw new \InvalidArgumentException(
            'Mesin approval ini hanya melayani PettyCash dan PettyCashTopup, bukan ' . $document::class
        );
    }

    public function getCurrentStepOrder(Model $document): ?int
    {
        $cfg = $this->config($document);

        /** @var class-string<Model> $kelas */
        $kelas = $cfg['approval'];

        $stepOrder = $kelas::query()
            ->where($cfg['foreign'], $document->id)
            ->where('status', PettyCashApproval::STATUS_WAITING)
            ->min('step_order');

        return $stepOrder !== null ? (int) $stepOrder : null;
    }

    public function getCurrentWaitingApprovals(
        Model $document,
        bool $lockForUpdate = false,
    ): Collection {
        $stepOrder = $this->getCurrentStepOrder($document);

        if ($stepOrder === null) {
            return new Collection();
        }

        $cfg = $this->config($document);

        /** @var class-string<Model> $kelas */
        $kelas = $cfg['approval'];

        $query = $kelas::query()
            ->where($cfg['foreign'], $document->id)
            ->where('step_order', $stepOrder)
            ->where('status', PettyCashApproval::STATUS_WAITING)
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * Baris approval aktif milik akun ini, bila ada.
     */
    public function getUserCurrentApproval(
        Model $document,
        User $user,
        bool $lockForUpdate = false,
    ): ?Model {
        foreach ($this->getCurrentWaitingApprovals($document, $lockForUpdate) as $approval) {
            if ($this->userCanApprove($approval, $user)) {
                return $approval;
            }
        }

        return null;
    }

    public function isRequester(Model $document, User $user): bool
    {
        $pemohon = (int) ($document->submitted_by ?? $document->created_by ?? 0);

        return $pemohon > 0 && $pemohon === (int) $user->id;
    }

    public function userCanApprove(Model $approval, User $user): bool
    {
        if (strtoupper((string) $approval->status) !== PettyCashApproval::STATUS_WAITING) {
            return false;
        }

        $tipe = strtoupper(trim((string) $approval->approver_type));

        if ($tipe === PettyCashApproval::APPROVER_TYPE_USER) {
            return (int) $approval->approver_id === (int) $user->id;
        }

        if ($tipe === PettyCashApproval::APPROVER_TYPE_ROLE) {
            return $this->userHasRoleId($user, (int) $approval->approver_id);
        }

        return false;
    }

    /**
     * Menyetujui step aktif milik akun ini.
     *
     * @return array{
     *     approval: Model,
     *     step_completed: bool,
     *     has_pending_approval: bool,
     *     is_final_approved: bool,
     *     next_step_order: int|null
     * }
     */
    public function approveCurrentStep(
        Model $document,
        User $user,
        ?string $notes = null,
    ): array {
        $cfg = $this->config($document);

        $approval = $this->getUserCurrentApproval($document, $user, true);

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => ['Anda bukan approver aktif untuk ' . $cfg['label'] . ' ini.'],
            ]);
        }

        if (empty($user->signature_path)) {
            throw ValidationException::withMessages([
                'signature' => ['Anda belum memiliki tanda tangan digital.'],
            ]);
        }

        $notes = $this->sanitizeNotes($notes);

        $mode = strtoupper(trim(
            (string) ($approval->approval_mode ?: PettyCashApproval::APPROVAL_MODE_ANY),
        ));

        $approval->update([
            'status' => PettyCashApproval::STATUS_APPROVED,
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => now(),
            'approved_at' => now(),
            'rejected_at' => null,
            'notes' => $notes,
        ]);

        /** @var class-string<Model> $kelas */
        $kelas = $cfg['approval'];

        /* Mode ANY: satu penyetuju cukup, sisanya pada step yang sama di-skip. */
        if ($mode === PettyCashApproval::APPROVAL_MODE_ANY) {
            $kelas::query()
                ->where($cfg['foreign'], $document->id)
                ->where('step_order', $approval->step_order)
                ->whereKeyNot($approval->id)
                ->where('status', PettyCashApproval::STATUS_WAITING)
                ->update([
                    'status' => PettyCashApproval::STATUS_SKIPPED,
                    'notes' => 'Skipped karena approval mode ANY telah dipenuhi oleh '
                        . ($user->name ?? 'approver') . '.',
                    'updated_at' => now(),
                ]);
        }

        $stepMasihMenunggu = $kelas::query()
            ->where($cfg['foreign'], $document->id)
            ->where('step_order', $approval->step_order)
            ->where('status', PettyCashApproval::STATUS_WAITING)
            ->exists();

        /* Mode ALL: masih ada penyetuju lain pada step ini. */
        if ($stepMasihMenunggu) {
            return [
                'approval' => $approval->fresh(),
                'step_completed' => false,
                'has_pending_approval' => true,
                'is_final_approved' => false,
                'next_step_order' => null,
            ];
        }

        $stepBerikut = $kelas::query()
            ->where($cfg['foreign'], $document->id)
            ->where('status', PettyCashApproval::STATUS_PENDING)
            ->where('step_order', '>', $approval->step_order)
            ->min('step_order');

        if ($stepBerikut !== null) {
            $kelas::query()
                ->where($cfg['foreign'], $document->id)
                ->where('step_order', (int) $stepBerikut)
                ->where('status', PettyCashApproval::STATUS_PENDING)
                ->update([
                    'status' => PettyCashApproval::STATUS_WAITING,
                    'updated_at' => now(),
                ]);

            return [
                'approval' => $approval->fresh(),
                'step_completed' => true,
                'has_pending_approval' => true,
                'is_final_approved' => false,
                'next_step_order' => (int) $stepBerikut,
            ];
        }

        $this->markApproved($document, $user);

        return [
            'approval' => $approval->fresh(),
            'step_completed' => true,
            'has_pending_approval' => false,
            'is_final_approved' => true,
            'next_step_order' => null,
        ];
    }

    /**
     * Menolak step aktif. Satu penolakan menghentikan seluruh rantai.
     */
    public function rejectCurrentStep(
        Model $document,
        User $user,
        ?string $notes = null,
    ): Model {
        $cfg = $this->config($document);

        $approval = $this->getUserCurrentApproval($document, $user, true);

        if (!$approval) {
            throw ValidationException::withMessages([
                'approval' => ['Anda bukan approver aktif untuk ' . $cfg['label'] . ' ini.'],
            ]);
        }

        $notes = $this->sanitizeNotes($notes);

        $approval->update([
            'status' => PettyCashApproval::STATUS_REJECTED,
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => !empty($user->signature_path) ? now() : null,
            'approved_at' => null,
            'rejected_at' => now(),
            'notes' => $notes,
        ]);

        /** @var class-string<Model> $kelas */
        $kelas = $cfg['approval'];

        $kelas::query()
            ->where($cfg['foreign'], $document->id)
            ->whereKeyNot($approval->id)
            ->whereIn('status', [
                PettyCashApproval::STATUS_WAITING,
                PettyCashApproval::STATUS_PENDING,
            ])
            ->update([
                'status' => PettyCashApproval::STATUS_CANCELLED,
                'notes' => 'Cancelled karena ' . $cfg['label'] . ' direject.',
                'updated_at' => now(),
            ]);

        $this->markRejected($document, $user, $notes);

        return $approval->fresh();
    }

    public function hasPendingApproval(Model $document): bool
    {
        $cfg = $this->config($document);

        /** @var class-string<Model> $kelas */
        $kelas = $cfg['approval'];

        return $kelas::query()
            ->where($cfg['foreign'], $document->id)
            ->whereIn('status', [
                PettyCashApproval::STATUS_WAITING,
                PettyCashApproval::STATUS_PENDING,
            ])
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Akhir rantai
    |--------------------------------------------------------------------------
    | Petty cash berhenti di APPROVED -- pencairan adalah aksi terpisah yang
    | memindahkannya ke DISBURSED dan baru di sana plafon terpotong.
    |
    | Top up juga berhenti di APPROVED, tetapi di situlah akhirnya: tidak ada
    | tahap pencairan tersendiri, karena yang menyetujui terakhir adalah
    | Finance yang juga mengirim uangnya.
    |--------------------------------------------------------------------------
    */
    public function markApproved(Model $document, User $user): void
    {
        $document->update([
            'status' => $this->config($document)['approved'],
            'final_approved_by' => $user->id,
            'final_approved_at' => now(),
        ]);
    }

    public function markRejected(Model $document, User $user, ?string $notes = null): void
    {
        $document->update([
            'status' => $this->config($document)['rejected'],
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

        return htmlspecialchars(strip_tags($notes), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Akun ini memegang role tersebut.
     *
     * Pivot user_roles DAN kolom lama users.id_role, keduanya.
     *
     * Semula saya tulis pivot saja -- lebih bersih, dan kolom lama memang
     * sedang ditinggalkan. Tetapi generatornya memakai roleApprovers(), yang
     * menghitung pemegang dari kedua sumber ketika memastikan sebuah tingkat
     * ada yang bisa menyetujuinya.
     *
     * Kalau mesin ini lebih ketat daripada generatornya, akibatnya dokumen
     * yang LOLOS dibuat tetapi TIDAK BISA disetujui siapa pun: generator
     * menghitung pemegang id_role sebagai penyetuju yang sah, lalu di sini
     * orang itu ditolak. Dokumennya menunggu selamanya tanpa pesan.
     *
     * Jadi keduanya dibaca. Membersihkan kolom lama adalah pekerjaan
     * tersendiri yang harus menyentuh kedua sisi sekaligus.
     */
    private function userHasRoleId(User $user, int $roleId): bool
    {
        if ($roleId <= 0) {
            return false;
        }

        if ((int) ($user->id_role ?? 0) === $roleId) {
            return true;
        }

        return DB::table('user_roles')
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->exists();
    }
}
