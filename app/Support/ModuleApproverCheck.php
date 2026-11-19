<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Apakah seseorang berdiri di rantai approval sebuah modul
|--------------------------------------------------------------------------
| Dipakai satu hal saja: memutuskan perlu tidaknya saringan "hanya yang
| menunggu approval saya" ditampilkan.
|
| Saringan itu dahulu muncul untuk semua orang. Bagi yang tidak pernah
| menyetujui apa pun di modul tersebut -- pemohon, Finance, GA -- ia hanya
| tombol yang selalu mengosongkan daftar. Yang menyalakannya akan mengira
| datanya hilang, bukan mengira dirinya memang bukan penyetuju.
|
| DIJAWAB DARI BARIS APPROVAL, BUKAN DARI MATRIKS FLOW.
|
| Matriks flow menyebut peran, cabang, rentang nominal, dan penyetuju khusus
| -- menirukan seluruh aturannya di sini berarti menyalin mesin pemilih flow
| ke tempat yang hanya perlu menjawab ya atau tidak, dan salinan itu akan
| berselisih dengan aslinya pada hari pertama salah satunya berubah.
|
| Baris approval adalah hasil JADI dari matriks itu: kalau namanya pernah
| muncul di sana, ia memang penyetuju di modul ini. Akibatnya penyetuju baru
| yang belum pernah kebagian satu dokumen pun belum melihat saringannya --
| dan itu tidak merugikan, sebab saringan itu memang belum punya isi
| untuknya.
|--------------------------------------------------------------------------
*/
class ModuleApproverCheck
{
    /**
     * Jawaban per (user, tabel) selama satu request.
     *
     * Dipanggil sekali tiap kali daftar dimuat, dan daftar dimuat di hampir
     * setiap halaman. Kueri yang jawabannya tidak mungkin berubah di tengah
     * satu permintaan tidak perlu diulang.
     *
     * @var array<string, bool>
     */
    private static array $ingatan = [];

    /**
     * Akun ini pernah ditunjuk sebagai penyetuju pada modul tersebut.
     *
     * @param  string  $approvalTable  Nama tabel approval modulnya
     */
    public static function isApprover(?User $user, string $approvalTable): bool
    {
        if (!$user || !Schema::hasTable($approvalTable)) {
            return false;
        }

        $kunci = $user->id . '|' . $approvalTable;

        if (isset(self::$ingatan[$kunci])) {
            return self::$ingatan[$kunci];
        }

        $roleIds = self::roleIds($user);

        $ada = DB::table($approvalTable)
            ->where(function ($query) use ($user, $roleIds, $approvalTable) {
                $query->where(function ($userQuery) use ($user, $approvalTable) {
                    $userQuery
                        ->whereRaw("UPPER(TRIM({$approvalTable}.approver_type)) = ?", ['USER'])
                        ->where("{$approvalTable}.approver_id", $user->id);
                });

                if ($roleIds !== []) {
                    $query->orWhere(function ($roleQuery) use ($roleIds, $approvalTable) {
                        $roleQuery
                            ->whereRaw("UPPER(TRIM({$approvalTable}.approver_type)) = ?", ['ROLE'])
                            ->whereIn("{$approvalTable}.approver_id", $roleIds);
                    });
                }
            })
            ->exists();

        return self::$ingatan[$kunci] = $ada;
    }

    /**
     * Seluruh peran akun ini.
     *
     * Dibaca dari user_roles, bukan dari peran aktifnya saja: baris approval
     * menunjuk peran mana pun yang pernah dipakai saat dokumennya dibuat,
     * dan membatasinya pada peran aktif akan menyembunyikan saringan dari
     * orang yang memang masih menyetujui dokumen lama.
     *
     * @return array<int, int>
     */
    private static function roleIds(User $user): array
    {
        if (!Schema::hasTable('user_roles')) {
            return [];
        }

        return DB::table('user_roles')
            ->where('user_id', $user->id)
            ->pluck('role_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
