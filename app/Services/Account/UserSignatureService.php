<?php

namespace App\Services\Account;

use App\Models\User;
use App\Models\UserSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mengelola slot tanda tangan milik seorang user.
 *
 * Dua aturan yang dijaga di sini, dan hanya di sini:
 *
 *  1. File tanda tangan tidak pernah ditimpa dan tidak pernah dihapus.
 *     Dokumen yang sudah diajukan atau disetujui hanya menyimpan JALUR
 *     filenya. Menimpa atau membuang file berarti mengubah -- atau
 *     mengosongkan -- tanda tangan pada dokumen yang sudah lama selesai.
 *     Menghapus slot hanya membebaskan slotnya; filenya tetap di tempat.
 *
 *  2. users.signature_path selalu mencerminkan slot yang aktif. Seluruh modul
 *     (FPU, Realisasi, Klaim, Perdin, PR, PO, Petty Cash) membaca kolom itu
 *     saat mengecap tanda tangan ke dokumen, dan tidak perlu tahu bahwa
 *     sekarang ada beberapa pilihan.
 */
class UserSignatureService
{
    public function __construct(
        private readonly SignatureImageService $gambar,
    ) {
    }

    /* ================================================================== */
    /* Pembacaan                                                          */
    /* ================================================================== */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function daftar(User $user): array
    {
        return UserSignature::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn(UserSignature $ttd) => [
                'id' => $ttd->id,
                'label' => $ttd->label,
                'source' => $ttd->source,
                'is_active' => (bool) $ttd->is_active,
                'url' => $ttd->url,
                'created_at' => $ttd->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function sisaSlot(User $user): int
    {
        return max(0, UserSignature::MAKS_PER_USER - $this->jumlahSlot($user));
    }

    /* ================================================================== */
    /* Penambahan                                                         */
    /* ================================================================== */

    /**
     * Tanda tangan yang ditulis langsung di layar.
     */
    public function tambahDariDataUrl(
        User $user,
        string $dataUrl,
        ?string $label = null,
    ): UserSignature {
        return $this->tambah(
            $user,
            $this->gambar->dariDataUrl($dataUrl),
            $label,
            UserSignature::SOURCE_DRAW,
        );
    }

    /**
     * Tanda tangan hasil foto atau pindaian kertas.
     */
    public function tambahDariPng(
        User $user,
        string $png,
        ?string $label = null,
    ): UserSignature {
        return $this->tambah(
            $user,
            $png,
            $label,
            UserSignature::SOURCE_UPLOAD,
        );
    }

    private function tambah(
        User $user,
        string $png,
        ?string $label,
        string $sumber,
    ): UserSignature {
        $terpakai = $this->jumlahSlot($user);

        if ($terpakai >= UserSignature::MAKS_PER_USER) {
            throw ValidationException::withMessages([
                'label' => [
                    __('signature_messages.slot_full', [
                        'max' => UserSignature::MAKS_PER_USER,
                    ]),
                ],
            ]);
        }

        $jalur = $this->tulisFile($user, $png);

        /*
        | Slot pertama langsung dipakai: user yang baru membuat tanda tangan
        | tidak perlu mengklik sekali lagi untuk mengaktifkannya.
        */
        $jadikanAktif = $terpakai === 0;

        return DB::transaction(function () use (
            $user,
            $jalur,
            $label,
            $sumber,
            $jadikanAktif,
            $terpakai,
        ) {
            if ($jadikanAktif) {
                $this->matikanSemua($user);
            }

            $ttd = UserSignature::create([
                'user_id' => $user->id,
                'label' => $this->rapikanLabel($label, $terpakai + 1),
                'file_path' => $jalur,
                'source' => $sumber,
                'is_active' => $jadikanAktif,
            ]);

            if ($jadikanAktif) {
                $this->cerminkan($user);
            }

            return $ttd;
        });
    }

    /* ================================================================== */
    /* Pemilihan dan penghapusan                                          */
    /* ================================================================== */

    public function aktifkan(User $user, int $id): UserSignature
    {
        $ttd = $this->milikUser($user, $id);

        return DB::transaction(function () use ($user, $ttd) {
            $this->matikanSemua($user);

            $ttd->is_active = true;
            $ttd->save();

            $this->cerminkan($user);

            return $ttd->refresh();
        });
    }

    public function ubahLabel(User $user, int $id, string $label): UserSignature
    {
        $ttd = $this->milikUser($user, $id);

        $bersih = $this->bersihkanLabel($label);

        if ($bersih === '') {
            throw ValidationException::withMessages([
                'label' => [__('signature_messages.label_required')],
            ]);
        }

        $ttd->label = $bersih;
        $ttd->save();

        return $ttd;
    }

    /**
     * Membebaskan slot. Filenya sengaja ditinggalkan di disk: dokumen lama
     * masih menunjuk ke sana, dan tanda tangan yang sudah tercap pada dokumen
     * selesai tidak boleh ikut hilang hanya karena pemiliknya merapikan
     * daftarnya.
     */
    public function hapus(User $user, int $id): void
    {
        $ttd = $this->milikUser($user, $id);

        DB::transaction(function () use ($user, $ttd) {
            $tadinyaAktif = (bool) $ttd->is_active;

            $ttd->is_active = false;
            $ttd->save();
            $ttd->delete();

            if (!$tadinyaAktif) {
                return;
            }

            /*
            | Yang aktif dihapus: slot tersisa yang terbaru menggantikannya,
            | supaya user tidak mendadak kehilangan kemampuan mengajukan
            | dokumen tanpa ia sadari.
            */
            $pengganti = UserSignature::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->first();

            if ($pengganti) {
                $pengganti->is_active = true;
                $pengganti->save();
            }

            $this->cerminkan($user);
        });
    }

    /* ================================================================== */
    /* Bagian dalam                                                       */
    /* ================================================================== */

    private function jumlahSlot(User $user): int
    {
        return UserSignature::query()
            ->where('user_id', $user->id)
            ->count();
    }

    private function milikUser(User $user, int $id): UserSignature
    {
        $ttd = UserSignature::query()
            ->where('user_id', $user->id)
            ->find($id);

        if (!$ttd) {
            throw ValidationException::withMessages([
                'id' => [__('signature_messages.not_found')],
            ]);
        }

        return $ttd;
    }

    private function matikanSemua(User $user): void
    {
        UserSignature::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * Menyalin slot aktif ke users.signature_path.
     */
    private function cerminkan(User $user): void
    {
        $aktif = UserSignature::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        $user->signature_path = $aktif?->file_path;
        $user->signature_uploaded_at = $aktif ? ($aktif->created_at ?? now()) : null;
        $user->save();
    }

    /**
     * Nama file selalu baru. Inilah yang membuat tanda tangan di dokumen lama
     * tidak pernah berubah: tidak ada file yang ditimpa.
     */
    private function tulisFile(User $user, string $png): string
    {
        $folder = 'syopv4/uploads/users/signature/'
            . $user->id . '_' . Str::slug((string) $user->name, '_');

        Storage::disk('public')->makeDirectory($folder);

        $jalur = $folder . '/signature_' . now()->format('Ymd_His') . '_'
            . Str::lower(Str::random(6)) . '.png';

        Storage::disk('public')->put($jalur, $png);

        return $jalur;
    }

    private function rapikanLabel(?string $label, int $nomor): string
    {
        $bersih = $this->bersihkanLabel((string) $label);

        return $bersih !== ''
            ? $bersih
            : 'Tanda tangan ' . $nomor;
    }

    private function bersihkanLabel(string $label): string
    {
        $bersih = trim(preg_replace('/\s+/', ' ', $label) ?? '');

        return Str::limit($bersih, 60, '');
    }
}
