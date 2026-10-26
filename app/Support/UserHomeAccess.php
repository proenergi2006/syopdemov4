<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Cabang dan department bawaan akun
|--------------------------------------------------------------------------
| Tiap akun punya cabang dan department pada master user-nya. Itulah tempat
| orangnya bernaung, dan dari sanalah dokumennya diajukan kalau tidak dipilih
| yang lain.
|
| DULU BAWAAN ITU TIDAK PERNAH JADI BARIS. Ia hanya dipakai sebagai cadangan
| ketika user belum punya satu pun baris akses:
|
|     if ($assignments->isEmpty()) { ...pakai master user... }
|
| Cadangan itu mati begitu satu baris akses tambahan dibuat. Akibatnya
| menambah akses ke cabang lain diam-diam MENGGANTI cabang asalnya, bukan
| menambah -- dan pemohon kehilangan cabangnya sendiri dari pilihan.
|
| Sekarang bawaan akun selalu berupa baris sungguhan, dan baris itu tidak
| boleh dinonaktifkan. Memindahkan orang ke cabang lain dilakukan dengan
| mengubah master akunnya, bukan dengan mencabut aksesnya.
|
| Penggabungan di sini tetap dipertahankan sebagai jaring pengaman untuk akun
| yang barisnya belum sempat dibuat -- akun baru, atau production yang belum
| dijalankan perintah backfill-nya.
|--------------------------------------------------------------------------
*/
class UserHomeAccess
{
    /**
     * Cabang dan department bawaan akun, atau null bila master user-nya
     * memang belum diisi.
     *
     * @return array{branch_id: int, department_id: int}|null
     */
    public static function kombinasi(?object $user): ?array
    {
        if (!$user) {
            return null;
        }

        $branchId = (int) ($user->cabang_id ?? 0);
        $departmentId = (int) ($user->departemen_id ?? 0);

        if ($branchId <= 0 || $departmentId <= 0) {
            return null;
        }

        return [
            'branch_id' => $branchId,
            'department_id' => $departmentId,
        ];
    }

    /**
     * Apakah sebuah kombinasi adalah bawaan akun.
     */
    public static function adalahBawaan(
        ?object $user,
        int $branchId,
        int $departmentId,
    ): bool {
        $bawaan = self::kombinasi($user);

        return $bawaan !== null
            && $bawaan['branch_id'] === $branchId
            && $bawaan['department_id'] === $departmentId;
    }

    /**
     * Memastikan baris bawaan akun ada dan aktif.
     *
     * Dipanggil dari layar master akses -- bukan dari pembacaan form, supaya
     * membaca tetap membaca.
     *
     * Baris yang BARU dibuat di sini langsung ditandai utama. Alasannya:
     * satu-satunya akun yang belum punya baris bawaan adalah akun yang
     * terkena celah lama, dan di sana tanda utama jatuh ke baris tambahan
     * bukan karena dipilih, melainkan karena ia kebetulan baris pertama.
     * Akun yang barisnya sudah ada tidak diutak-atik tanda utamanya.
     *
     * @return bool true bila ada yang berubah
     */
    public static function pastikanBaris(
        int $userId,
        ?int $authUserId = null,
    ): bool {
        $user = DB::table('users')
            ->where('id', $userId)
            ->select(['id', 'cabang_id', 'departemen_id'])
            ->first();

        $bawaan = self::kombinasi($user);

        if ($bawaan === null) {
            return false;
        }

        $baris = DB::table('user_access_assignments')
            ->where('user_id', $userId)
            ->where('branch_id', $bawaan['branch_id'])
            ->where('department_id', $bawaan['department_id'])
            ->first();

        /* Sudah ada dan aktif -- tidak ada yang perlu dikerjakan. */
        if ($baris && $baris->is_active) {
            return false;
        }

        return DB::transaction(function () use (
            $userId,
            $bawaan,
            $baris,
            $authUserId,
        ) {
            /*
            | Pernah dinonaktifkan sebelum aturan ini berlaku. Dihidupkan
            | kembali tanpa menyentuh tanda utama: tanda itu urusan admin.
            */
            if ($baris) {
                DB::table('user_access_assignments')
                    ->where('id', $baris->id)
                    ->update([
                        'is_active' => true,
                        'updated_by' => $authUserId,
                        'updated_at' => now(),
                    ]);

                return true;
            }

            DB::table('user_access_assignments')
                ->where('user_id', $userId)
                ->update([
                    'is_primary' => false,
                    'updated_by' => $authUserId,
                    'updated_at' => now(),
                ]);

            DB::table('user_access_assignments')->insert([
                'user_id' => $userId,
                'branch_id' => $bawaan['branch_id'],
                'department_id' => $bawaan['department_id'],
                'is_primary' => true,
                'is_active' => true,
                'created_by' => $authUserId,
                'updated_by' => $authUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * Menyisipkan bawaan akun ke daftar kombinasi yang boleh dipakai, bila
     * belum ada di sana.
     *
     * Dipakai oleh pembacaan form dan oleh pemeriksaan saat submit, supaya
     * keduanya sepakat tentang apa yang boleh dipilih.
     *
     * @param  iterable<array{branch_id: int, department_id: int}>  $kombinasi
     * @return array<int, array{branch_id: int, department_id: int}>
     */
    public static function gabungkan(?object $user, iterable $kombinasi): array
    {
        $hasil = [];

        foreach ($kombinasi as $item) {
            $hasil[] = [
                'branch_id' => (int) $item['branch_id'],
                'department_id' => (int) $item['department_id'],
            ];
        }

        $bawaan = self::kombinasi($user);

        if ($bawaan === null) {
            return $hasil;
        }

        foreach ($hasil as $item) {
            if (
                $item['branch_id'] === $bawaan['branch_id']
                && $item['department_id'] === $bawaan['department_id']
            ) {
                return $hasil;
            }
        }

        /*
        | Bawaan akun selalu di depan: dialah yang menjadi pilihan terbuka
        | saat form dibuka.
        */
        array_unshift($hasil, $bawaan);

        return $hasil;
    }
}
