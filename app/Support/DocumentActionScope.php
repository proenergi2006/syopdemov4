<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Scope untuk AKSI, bukan untuk daftar
|--------------------------------------------------------------------------
| Scope permission selama ini hanya menyaring DAFTAR. Aksi yang mengubah
| dokumen -- ubah, hapus, kirim, batal -- memeriksa "punya izinnya atau
| tidak", lalu mengambil dokumennya begitu saja.
|
| Akibatnya scope pada permission aksi tidak berarti apa-apa. Seorang CFO
| dengan cash_advance.delete bercakupan OWN_DATA terbukti berhasil menghapus
| FPU draft milik orang lain, dan dengan business_trip.cancel bercakupan
| OWN_DATA berhasil membatalkan perdin orang lain.
|
| SETIAP AKSI MEMBACA SCOPE PERMISSIONNYA SENDIRI.
|
| Jadi "view: ALL, cancel: OWN_DATA" berbunyi sebagaimana mestinya -- boleh
| melihat semua, hanya boleh membatalkan miliknya sendiri. Itu yang diharapkan
| siapa pun yang mengisi formulir permission.
|
| TIDAK ADA JALUR KHUSUS PENYETUJU.
|
| Daftar dokumen memang memperlihatkan dokumen yang masuk ke alur approval
| seseorang walau di luar cakupannya -- ia memang harus bisa melihat apa yang
| harus ia putuskan. Tetapi MENYETUJUI bukan MENGUBAH. Penyetuju yang perlu
| mengubah atau membatalkan dokumen orang lain diberi scope yang lebih luas,
| bukan pengecualian diam-diam yang tidak terlihat di layar permission.
|
| URUTAN KELUASANNYA:
|
|   ALL > OWN_DEPARTMENT > OWN_CABANG > OWN_DATA
|
| Sama persis dengan yang dipakai penyaring daftar, dan memang harus sama:
| dua aturan berbeda untuk satu kata yang sama adalah sumber kebingungan yang
| tidak akan pernah habis.
|--------------------------------------------------------------------------
*/
class DocumentActionScope
{
    /**
     * Akun ini boleh melakukan aksinya terhadap dokumen ini.
     *
     * @param  object  $dokumen  Baris dokumennya (model atau stdClass)
     * @param  array{branch?: string, department?: string, owner?: string}  $kolom
     *                           Nama kolom pada dokumen itu. Berbeda antar
     *                           modul -- FPU memakai `branch`, sebagian lain
     *                           memakai nama lain -- jadi disebutkan oleh yang
     *                           memanggil alih-alih ditebak di sini.
     */
    public static function allows(
        ?User $user,
        string $permission,
        object $dokumen,
        array $kolom = [],
    ): bool {
        if (!$user) {
            return false;
        }

        $scope = strtoupper(trim((string) $user->getPermissionScope($permission)));

        if ($scope === 'ALL') {
            return true;
        }

        $kolomBranch = $kolom['branch'] ?? 'branch';
        $kolomDepartment = $kolom['department'] ?? 'department_id';
        $kolomOwner = $kolom['owner'] ?? 'created_by';

        if ($scope === 'OWN_DATA') {
            return (int) ($dokumen->{$kolomOwner} ?? 0) === (int) $user->id;
        }

        [$branchIds, $departmentIds] = self::wilayah($user);

        if ($scope === 'OWN_DEPARTMENT') {
            return self::cocokDepartment($dokumen, $kolomDepartment, $departmentIds);
        }

        if ($scope === 'OWN_CABANG') {
            return self::cocokCabang($dokumen, $kolomBranch, $branchIds)
                && self::cocokDepartment($dokumen, $kolomDepartment, $departmentIds);
        }

        if ($scope === 'ASSIGNED_DEPARTMENTS') {
            return self::cocokDepartment($dokumen, $kolomDepartment, self::departmentDitugaskan($user));
        }

        /*
        | NONE, kosong, atau nilai yang tidak dikenal.
        |
        | Ditolak, bukan diizinkan. Scope yang tidak terbaca adalah tanda ada
        | yang keliru di master permission, dan menebak "boleh" pada keadaan
        | yang tidak dipahami adalah cara membuka pintu tanpa sengaja.
        */
        return false;
    }

    /**
     * Cabang dan department yang melekat pada akun.
     *
     * Bawaan akun DAN seluruh akses tambahannya -- sama seperti yang dipakai
     * penyaring daftar. Memakai bawaannya saja akan menutup dokumen di cabang
     * tambahan yang justru sengaja diberikan kepadanya.
     *
     * @return array{0: Collection, 1: Collection}
     */
    private static function wilayah(User $user): array
    {
        $penugasan = DB::table('user_access_assignments')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get(['branch_id', 'department_id']);

        $branchIds = collect([(int) ($user->cabang_id ?? 0)])
            ->merge($penugasan->pluck('branch_id')->map(fn ($id): int => (int) $id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $departmentIds = collect([(int) ($user->departemen_id ?? 0)])
            ->merge($penugasan->pluck('department_id')->map(fn ($id): int => (int) $id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        return [$branchIds, $departmentIds];
    }

    private static function departmentDitugaskan(User $user): Collection
    {
        return DB::table('user_permission_departments')
            ->where('user_id', $user->id)
            ->pluck('department_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    /**
     * Cabang dokumen disimpan sebagai teks di sebagian modul, angka di
     * sebagian lain. Dibandingkan sebagai teks supaya "4" dan 4 tidak
     * terbaca berbeda.
     */
    private static function cocokCabang(object $dokumen, string $kolom, Collection $ids): bool
    {
        if ($ids->isEmpty()) {
            return false;
        }

        $nilai = trim((string) ($dokumen->{$kolom} ?? ''));

        return $nilai !== ''
            && $ids->map(fn ($id): string => (string) $id)->contains($nilai);
    }

    private static function cocokDepartment(object $dokumen, string $kolom, Collection $ids): bool
    {
        if ($ids->isEmpty()) {
            return false;
        }

        $nilai = (int) ($dokumen->{$kolom} ?? 0);

        return $nilai > 0 && $ids->contains($nilai);
    }
}
