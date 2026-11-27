<?php

namespace App\Services\Bdv;

use App\Models\BankDisbursementVoucher;
use App\Models\BdvApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Mesin approval BDV
|--------------------------------------------------------------------------
| Bekerja di atas baris bdv_approvals hasil generator, mengikuti perilaku
| yang sama dengan FPU, Claim, Realisasi, dan Petty Cash:
|
|   mode ANY   cukup satu approver pada satu tingkat
|   mode ALL   menunggu seluruh approver pada tingkat itu
|
| Satu penolakan menghentikan seluruh rantai. Tidak ada "ditolak sebagian".
|--------------------------------------------------------------------------
*/
class BdvApprovalService
{
    /**
     * Nomor tingkat yang sedang ditunggu, atau null bila tidak ada lagi.
     */
    public function stepBerjalan(BankDisbursementVoucher $bdv): ?int
    {
        $stepOrder = BdvApproval::query()
            ->where('bdv_id', $bdv->id)
            ->where('status', BdvApproval::STATUS_WAITING)
            ->min('step_order');

        return $stepOrder !== null ? (int) $stepOrder : null;
    }

    /**
     * Seluruh baris yang sedang ditunggu pada tingkat berjalan.
     *
     * $kunci mengunci barisnya sampai transaksi selesai. Dipakai saat
     * menyetujui: dua approver pada tingkat ANY yang menekan tombol pada
     * saat yang hampir bersamaan akan sama-sama lolos pemeriksaan kalau
     * barisnya tidak dikunci, dan dokumennya maju dua tingkat sekaligus.
     */
    public function barisMenunggu(
        BankDisbursementVoucher $bdv,
        bool $kunci = false,
    ): Collection {
        $step = $this->stepBerjalan($bdv);

        if ($step === null) {
            return new Collection();
        }

        $query = BdvApproval::query()
            ->where('bdv_id', $bdv->id)
            ->where('step_order', $step)
            ->where('status', BdvApproval::STATUS_WAITING)
            ->orderBy('id');

        if ($kunci) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * Baris yang boleh ditindak orang ini sekarang, bila ada.
     */
    public function barisMilikUser(
        BankDisbursementVoucher $bdv,
        User $user,
        bool $kunci = false,
    ): ?BdvApproval {
        /*
        | Pemohon tidak pernah menjadi approver dokumennya sendiri.
        |
        | Generator sudah membuang step-nya saat dokumen diajukan; penjaga ini
        | untuk yang tidak terlihat saat itu -- baris bertipe ROLE disimpan
        | sebagai role, dan seseorang bisa DIBERI role itu sesudah dokumennya
        | diajukan. Tanpa penjaga ini, ia lalu bisa menyetujui vouchernya
        | sendiri tanpa seorang pun menyadarinya.
        */
        if ($this->pemohon($bdv, $user)) {
            return null;
        }

        return $this
            ->barisMenunggu($bdv, $kunci)
            ->first(fn (BdvApproval $baris): bool => $this->bolehMenindak($baris, $user));
    }

    /**
     * Orang ini yang mengajukan vouchernya.
     *
     * submitted_by lebih dipercaya daripada created_by: yang menekan tombol
     * Ajukan-lah yang mengajukan, sekalipun vouchernya diketik orang lain.
     */
    public function pemohon(BankDisbursementVoucher $bdv, User $user): bool
    {
        $pemohon = (int) ($bdv->submitted_by ?? $bdv->created_by ?? 0);

        return $pemohon > 0 && $pemohon === (int) $user->id;
    }

    public function bolehMenindak(BdvApproval $baris, User $user): bool
    {
        if (strtoupper((string) $baris->status) !== BdvApproval::STATUS_WAITING) {
            return false;
        }

        $tipe = strtoupper(trim((string) $baris->approver_type));

        if ($tipe === BdvApproval::APPROVER_TYPE_USER) {
            return (int) $baris->approver_id === (int) $user->id;
        }

        if ($tipe === BdvApproval::APPROVER_TYPE_ROLE) {
            return $this->punyaRole($user, (int) $baris->approver_id);
        }

        return false;
    }

    /**
     * Menyetujui tingkat yang sedang ditunggu.
     *
     * @return array{
     *     approval: BdvApproval,
     *     step_completed: bool,
     *     has_pending_approval: bool,
     *     is_final_approved: bool,
     *     next_step_order: int|null
     * }
     *
     * @throws ValidationException
     */
    public function setujui(
        BankDisbursementVoucher $bdv,
        User $user,
        ?string $catatan = null,
    ): array {
        $baris = $this->barisMilikUser($bdv, $user, true);

        if (!$baris) {
            throw ValidationException::withMessages([
                'approval' => [__('bdv_messages.approval_not_yours')],
            ]);
        }

        /*
        | Tanda tangan digital wajib ada SEBELUM menyetujui, bukan sesudah.
        |
        | Cetakan voucher memuat tanda tangan tiap penyetuju. Approval tanpa
        | tanda tangan menghasilkan cetakan dengan kolom kosong yang tidak
        | bisa diisi lagi -- dan dokumen itu sudah telanjur berjalan.
        */
        if (empty($user->signature_path)) {
            throw ValidationException::withMessages([
                'signature' => [__('bdv_messages.approval_no_signature')],
            ]);
        }

        $catatan = $this->bersihkanCatatan($catatan);

        $mode = strtoupper(trim(
            (string) ($baris->approval_mode ?: BdvApproval::APPROVAL_MODE_ANY),
        ));

        $baris->update([
            'status' => BdvApproval::STATUS_APPROVED,
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => now(),
            'approved_at' => now(),
            'rejected_at' => null,
            'notes' => $catatan,
        ]);

        /*
        | Mode ANY: satu approver cukup. Sisanya pada tingkat yang sama
        | ditandai SKIPPED -- bukan dihapus, supaya riwayatnya tetap memuat
        | siapa saja yang sebenarnya berhak dan tidak jadi menindak.
        */
        if ($mode === BdvApproval::APPROVAL_MODE_ANY) {
            BdvApproval::query()
                ->where('bdv_id', $bdv->id)
                ->where('step_order', $baris->step_order)
                ->whereKeyNot($baris->id)
                ->where('status', BdvApproval::STATUS_WAITING)
                ->update([
                    'status' => BdvApproval::STATUS_SKIPPED,
                    'notes' => __('bdv_messages.approval_skipped_any', [
                        'name' => $user->name ?? 'approver',
                    ]),
                    'updated_at' => now(),
                ]);
        }

        $masihMenunggu = BdvApproval::query()
            ->where('bdv_id', $bdv->id)
            ->where('step_order', $baris->step_order)
            ->where('status', BdvApproval::STATUS_WAITING)
            ->exists();

        /* Mode ALL: masih ada approver lain pada tingkat ini. */
        if ($masihMenunggu) {
            return [
                'approval' => $baris->fresh(),
                'step_completed' => false,
                'has_pending_approval' => true,
                'is_final_approved' => false,
                'next_step_order' => null,
            ];
        }

        $berikutnya = BdvApproval::query()
            ->where('bdv_id', $bdv->id)
            ->where('status', BdvApproval::STATUS_PENDING)
            ->where('step_order', '>', $baris->step_order)
            ->min('step_order');

        if ($berikutnya !== null) {
            BdvApproval::query()
                ->where('bdv_id', $bdv->id)
                ->where('step_order', (int) $berikutnya)
                ->where('status', BdvApproval::STATUS_PENDING)
                ->update([
                    'status' => BdvApproval::STATUS_WAITING,
                    'updated_at' => now(),
                ]);

            return [
                'approval' => $baris->fresh(),
                'step_completed' => true,
                'has_pending_approval' => true,
                'is_final_approved' => false,
                'next_step_order' => (int) $berikutnya,
            ];
        }

        $this->tandaiDisetujui($bdv, $user);

        return [
            'approval' => $baris->fresh(),
            'step_completed' => true,
            'has_pending_approval' => false,
            'is_final_approved' => true,
            'next_step_order' => null,
        ];
    }

    /**
     * Menolak. Satu penolakan menghentikan seluruh rantai.
     *
     * @throws ValidationException
     */
    public function tolak(
        BankDisbursementVoucher $bdv,
        User $user,
        ?string $catatan = null,
    ): BdvApproval {
        $baris = $this->barisMilikUser($bdv, $user, true);

        if (!$baris) {
            throw ValidationException::withMessages([
                'approval' => [__('bdv_messages.approval_not_yours')],
            ]);
        }

        $catatan = $this->bersihkanCatatan($catatan);

        /*
        | Alasan WAJIB saat menolak, tidak saat menyetujui.
        |
        | Dokumen yang ditolak kembali ke pembuatnya, dan ia harus tahu apa
        | yang perlu diperbaiki. Penolakan tanpa alasan hanya memutar dokumen
        | itu berkeliling sampai seseorang menyerah dan bertanya langsung.
        */
        if ($catatan === null) {
            throw ValidationException::withMessages([
                'notes' => [__('bdv_messages.approval_reject_needs_notes')],
            ]);
        }

        $baris->update([
            'status' => BdvApproval::STATUS_REJECTED,
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => !empty($user->signature_path) ? now() : null,
            'approved_at' => null,
            'rejected_at' => now(),
            'notes' => $catatan,
        ]);

        BdvApproval::query()
            ->where('bdv_id', $bdv->id)
            ->whereKeyNot($baris->id)
            ->whereIn('status', [BdvApproval::STATUS_WAITING, BdvApproval::STATUS_PENDING])
            ->update([
                'status' => BdvApproval::STATUS_CANCELLED,
                'notes' => __('bdv_messages.approval_cancelled_by_reject'),
                'updated_at' => now(),
            ]);

        $this->tandaiDitolak($bdv, $user, $catatan);

        return $baris->fresh();
    }

    public function masihBerjalan(BankDisbursementVoucher $bdv): bool
    {
        return BdvApproval::query()
            ->where('bdv_id', $bdv->id)
            ->whereIn('status', [BdvApproval::STATUS_WAITING, BdvApproval::STATUS_PENDING])
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Disetujui penuh
    |--------------------------------------------------------------------------
    | Berhenti di APPROVED. TIDAK ada pengiriman ke Accurate di sini --
    | vouchernya sudah ditulis ke sana sejak draft pertama disimpan, dan
    | approval di aplikasi ini memang tidak mengirim apa pun lagi.
    |
    | Menambahkan pengiriman di titik ini akan menerbitkan dokumen KEDUA di
    | pembukuan untuk voucher yang sama. Lihat BdvAccuratePostingService.
    |--------------------------------------------------------------------------
    */
    public function tandaiDisetujui(BankDisbursementVoucher $bdv, User $user): void
    {
        $bdv->update([
            'status' => BankDisbursementVoucher::STATUS_APPROVED,
            'final_approved_by' => $user->id,
            'final_approved_at' => now(),
        ]);
    }

    public function tandaiDitolak(
        BankDisbursementVoucher $bdv,
        User $user,
        ?string $catatan = null,
    ): void {
        $bdv->update([
            'status' => BankDisbursementVoucher::STATUS_REJECTED,
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_notes' => $catatan,
        ]);
    }

    /**
     * Catatan dibersihkan dari tag HTML sebelum disimpan.
     *
     * Isinya ditampilkan kembali di layar dan di cetakan PDF. Tanpa
     * pembersihan ini, satu catatan berisi tag bisa mengubah tampilan
     * dokumen orang lain -- atau lebih buruk, ikut tercetak sebagai markup.
     */
    public function bersihkanCatatan(?string $catatan): ?string
    {
        $catatan = trim((string) $catatan);

        if ($catatan === '') {
            return null;
        }

        return htmlspecialchars(strip_tags($catatan), ENT_QUOTES, 'UTF-8');
    }

    /*
    |--------------------------------------------------------------------------
    | Apakah user memegang sebuah role
    |--------------------------------------------------------------------------
    | Tiga struktur diperiksa berurutan, sama seperti modul lain: user_roles
    | (yang dipakai sekarang), role_user (struktur lama), dan kolom role_id
    | pada users (lebih lama lagi). Dipertahankan apa adanya supaya mesin
    | approval BDV tidak berperilaku berbeda dari FPU pada basis data yang
    | sama.
    |--------------------------------------------------------------------------
    */
    private function punyaRole(User $user, int $roleId): bool
    {
        if ($roleId <= 0) {
            return false;
        }

        if (
            Schema::hasTable('user_roles')
            && DB::table('user_roles')
                ->where('user_id', $user->id)
                ->where('role_id', $roleId)
                ->exists()
        ) {
            return true;
        }

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
