<?php

namespace App\Services\Bdv;

use App\Models\BankDisbursementVoucher;
use App\Models\BdvApproval;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Lonceng aplikasi untuk BDV
|--------------------------------------------------------------------------
| LONCENG SAJA, TANPA EMAIL.
|
| FPU, Claim, dan Realisasi masing-masing punya sepasang servis -- satu
| notifikasi, satu email. BDV sengaja hanya punya yang pertama: ia dokumen
| internal Finance yang penggarapnya duduk di aplikasi ini sepanjang hari,
| dan email kedua untuk tiap langkahnya hanya menambah kotak masuk tanpa
| mempercepat satu pun keputusan. Kalau kelak dibutuhkan, yang ditambahkan
| adalah BdvMailService di sebelahnya -- bukan pengiriman email dari dalam
| servis ini.
|
| SUMBER PENERIMA ADALAH BARIS bdv_approvals, BUKAN MATRIKSNYA.
|
| Rantai approval dibekukan saat voucher diajukan. Membaca matriks berarti
| mengabari orang menurut keadaan matriks HARI INI, sedangkan yang berhak
| memutuskan adalah orang yang tercatat pada rantai saat itu. Keduanya
| berbeda setiap kali admin menyunting matriksnya, dan yang kedua yang benar.
|
| KABAR YANG GAGAL TIDAK BOLEH MENGGAGALKAN TINDAKANNYA. Seluruh pemanggil
| membungkusnya dengan try/catch dan hanya mencatat ke log: saat servis ini
| dipanggil, vouchernya sudah berpindah tangan.
|--------------------------------------------------------------------------
*/
class BdvNotificationService
{
    private const MODULE = 'bdv';

    private const URL = '/cash_bank/bdv';

    /*
    |--------------------------------------------------------------------------
    | Ke approver: ada yang menunggu di meja Anda
    |--------------------------------------------------------------------------
    */

    /**
     * Dipanggil sesudah voucher diajukan, dan sesudah satu tahap selesai
     * sehingga tahap berikutnya menjadi giliran.
     */
    public function mintaApproval(BankDisbursementVoucher $bdv): void
    {
        $baris = $this->barisTahapBerjalan($bdv);

        if ($baris->isEmpty()) {
            Log::warning('[BDV Notifikasi] Tidak ada baris approval WAITING', [
                'bdv_id' => $bdv->id,
                'bdv_number' => $bdv->bdv_number,
            ]);

            return;
        }

        /*
        | unique('id') mencegah kabar ganda bagi orang yang terpilih dua
        | kali pada tahap yang sama -- sekali sebagai dirinya, sekali lewat
        | rolenya.
        */
        $penerima = $baris
            ->flatMap(fn (BdvApproval $a): Collection => $this->resolveApproverUsers($a))
            ->filter(fn ($u): bool => $u instanceof User)
            ->unique('id')
            ->values();

        if ($penerima->isEmpty()) {
            Log::warning('[BDV Notifikasi] Approver tidak ditemukan', [
                'bdv_id' => $bdv->id,
                'bdv_number' => $bdv->bdv_number,
                'baris' => $baris
                    ->map(fn (BdvApproval $a): array => [
                        'approval_id' => $a->id,
                        'approver_type' => $a->approver_type,
                        'approver_id' => $a->approver_id,
                        'label' => $a->label,
                    ])
                    ->values()
                    ->all(),
            ]);

            return;
        }

        $tahap = (int) $baris->first()->step_order;
        $label = $baris->pluck('label')->filter()->first();

        foreach ($penerima as $orang) {
            /*
            | Satu voucher, satu kabar yang belum dibaca.
            |
            | Approver yang sudah punya pemberitahuan belum terbaca untuk
            | voucher ini tidak dikabari lagi. Tanpa penjaga ini, satu
            | voucher yang maju-mundur beberapa kali akan menumpuk di
            | loncengnya dan mendorong voucher lain keluar dari pandangan.
            */
            $sudahAda = Notification::query()
                ->where('user_id', $orang->id)
                ->where('type', 'bdv_approval')
                ->where('reference_type', BankDisbursementVoucher::class)
                ->where('reference_id', $bdv->id)
                ->whereNull('read_at')
                ->exists();

            if ($sudahAda) {
                continue;
            }

            $params = [
                'bdv_number' => $this->nomor($bdv),
                'step_order' => $tahap,
            ];

            if ($label) {
                $params['step_label'] = $label;
            }

            $kunciPesan = $label
                ? 'notification_messages.bdv.approval_request.message_with_label'
                : 'notification_messages.bdv.approval_request.message_without_label';

            $this->simpan(
                $orang->id,
                'bdv_approval',
                'notification_messages.bdv.approval_request.title',
                $kunciPesan,
                $params,
                $bdv,
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Ke pemohon: dokumen Anda bergerak
    |--------------------------------------------------------------------------
    */

    /**
     * Satu tahap disetujui.
     *
     * $masihMenunggu membedakan "maju satu langkah" dari "selesai seluruhnya"
     * -- dua kabar yang sangat berbeda artinya bagi pemohon, dan kalimat yang
     * menyamakan keduanya membuat ia mengira uangnya sudah bisa cair.
     */
    public function tahapDisetujui(
        BankDisbursementVoucher $bdv,
        User $approver,
        BdvApproval $baris,
        bool $masihMenunggu,
    ): void {
        $pemohon = $this->pemohonId($bdv);

        if (!$pemohon) {
            return;
        }

        $kelompok = $masihMenunggu ? 'approval_step_pending' : 'approval_step_final';

        $this->simpan(
            $pemohon,
            $masihMenunggu ? 'bdv_approval_step_approved' : 'bdv_approved',
            "notification_messages.bdv.{$kelompok}.title",
            "notification_messages.bdv.{$kelompok}.message",
            [
                'bdv_number' => $this->nomor($bdv),
                'approver_name' => $approver->name ?? '-',
                'step_order' => (int) $baris->step_order,
            ],
            $bdv,
        );
    }

    /** Vouchernya ditolak. */
    public function ditolak(BankDisbursementVoucher $bdv, User $penolak): void
    {
        $pemohon = $this->pemohonId($bdv);

        if (!$pemohon) {
            return;
        }

        $this->simpan(
            $pemohon,
            'bdv_rejected',
            'notification_messages.bdv.rejected.title',
            'notification_messages.bdv.rejected.message',
            [
                'bdv_number' => $this->nomor($bdv),
                'rejecter_name' => $penolak->name ?? '-',
            ],
            $bdv,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Baris approval -> daftar pengguna
    |--------------------------------------------------------------------------
    | Publik dengan sengaja: RevisionNotifier meminta pengetahuan ini sebagai
    | callable, supaya ia sendiri tidak perlu tahu sedang mengurus modul yang
    | mana. Lihat keterangan di kepala servis itu.
    |--------------------------------------------------------------------------
    */

    /** @return Collection<int, User> */
    public function resolveApproverUsers(BdvApproval $baris): Collection
    {
        if (!$baris->approver_id) {
            return collect();
        }

        $tipe = strtoupper(trim((string) $baris->approver_type));

        if ($tipe === BdvApproval::APPROVER_TYPE_USER) {
            return User::query()->whereKey($baris->approver_id)->get();
        }

        if ($tipe === BdvApproval::APPROVER_TYPE_ROLE) {
            return $this->penggunaBerrole((int) $baris->approver_id);
        }

        return collect();
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian dalam
    |--------------------------------------------------------------------------
    */

    /**
     * Seluruh baris pada tahap terendah yang masih menunggu.
     *
     * @return Collection<int, BdvApproval>
     */
    private function barisTahapBerjalan(BankDisbursementVoucher $bdv): Collection
    {
        $tahap = BdvApproval::query()
            ->where('bdv_id', $bdv->id)
            ->where('status', BdvApproval::STATUS_WAITING)
            ->min('step_order');

        if ($tahap === null) {
            return collect();
        }

        return BdvApproval::query()
            ->where('bdv_id', $bdv->id)
            ->where('step_order', (int) $tahap)
            ->where('status', BdvApproval::STATUS_WAITING)
            ->orderBy('id')
            ->get();
    }

    /**
     * Pengguna aktif yang memegang sebuah role.
     *
     * Dua tabel lama ikut dibaca (`role_user`, kolom `users.role_id`) karena
     * basis data ini pernah memakai keduanya; yang tidak ada dilewati lewat
     * Schema, bukan lewat try/catch.
     *
     * @return Collection<int, User>
     */
    private function penggunaBerrole(int $roleId): Collection
    {
        if ($roleId <= 0) {
            return collect();
        }

        $idPengguna = collect();

        if (Schema::hasTable('user_roles')) {
            $idPengguna = $idPengguna->merge(
                DB::table('user_roles')->where('role_id', $roleId)->pluck('user_id'),
            );
        }

        if (Schema::hasTable('role_user')) {
            $idPengguna = $idPengguna->merge(
                DB::table('role_user')->where('role_id', $roleId)->pluck('user_id'),
            );
        }

        if (Schema::hasColumn('users', 'role_id')) {
            $idPengguna = $idPengguna->merge(
                User::query()->where('role_id', $roleId)->pluck('id'),
            );
        }

        $idPengguna = $idPengguna
            ->filter(fn ($id): bool => $id !== null && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($idPengguna->isEmpty()) {
            return collect();
        }

        $query = User::query()->whereIn('id', $idPengguna);

        /*
        | Akun nonaktif tidak dikabari. Ia tidak bisa masuk untuk
        | menindaklanjutinya, dan kabar yang tidak akan pernah dibaca hanya
        | membuat angka lonceng tidak pernah turun.
        */
        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Pemohon: yang menekan Ajukan, dengan pembuatnya sebagai cadangan.
     */
    private function pemohonId(BankDisbursementVoucher $bdv): ?int
    {
        $id = $bdv->submitted_by ?? $bdv->created_by ?? null;

        return $id ? (int) $id : null;
    }

    private function nomor(BankDisbursementVoucher $bdv): string
    {
        return (string) ($bdv->bdv_number ?: '-');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function simpan(
        int $userId,
        string $jenis,
        string $kunciJudul,
        string $kunciPesan,
        array $params,
        BankDisbursementVoucher $bdv,
    ): void {
        Notification::create([
            'user_id' => $userId,
            'type' => $jenis,

            /*
            | Teks DAN kuncinya sama-sama disimpan.
            |
            | Teksnya supaya pemberitahuan lama tetap terbaca apa adanya;
            | kuncinya supaya layar bisa menerjemahkan ulang ke bahasa yang
            | sedang dipakai pembacanya. Menyimpan salah satu saja berarti
            | memilih antara riwayat yang beku dan riwayat yang hilang.
            */
            'title' => __($kunciJudul, $params),
            'title_key' => $kunciJudul,

            'message' => __($kunciPesan, $params),
            'message_key' => $kunciPesan,
            'message_params' => $params,

            'module' => self::MODULE,
            'reference_type' => BankDisbursementVoucher::class,
            'reference_id' => $bdv->id,
            'reference_public_id' => $bdv->encrypted_id,
            'url' => self::URL,
        ]);
    }
}
