<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Master role beserta tingkat jabatannya
|--------------------------------------------------------------------------
| DIHASILKAN OTOMATIS oleh "php artisan role:export-seeder" pada
| 23 October 2026 14:31. Jangan disunting tangan -- ekspor ulang saja.
|
| 51 role.
|
| AMAN DIJALANKAN DI BASIS DATA YANG SUDAH HIDUP.
|
| Kuncinya KODE, bukan nama. Karena itu:
|
|   - kode yang belum ada  -> role baru dibuat
|   - kode yang sudah ada  -> namanya dan tingkatnya diperbarui
|
| Yang kedua itu yang membuat penggantian nama bekerja. Seeder berkunci nama
| akan membuat role kedua dan meninggalkan yang lama beserta seluruh izin,
| penugasan akun, dan baris approval flow yang menunjuknya.
|
| is_active TIDAK disentuh pada role yang sudah ada: role yang sengaja
| dimatikan di production tidak boleh hidup kembali karena berkas ini.
| Penugasan role ke akun, izin, dan menu juga bukan urusan berkas ini.
|
| Tidak ada yang dihapus. Role yang hanya ada di production dibiarkan.
|--------------------------------------------------------------------------
*/
class RoleMasterSeeder extends Seeder
{
    /**
     * kode => nama + tingkat jabatan.
     *
     * @var array<int, array{kode: string, nama: string, level: int|null}>
     */
    private const ROLES = [
        ['kode' => 'ADH', 'nama' => 'Admin Head', 'level' => 2],
        ['kode' => 'AF', 'nama' => 'Admin Finance', 'level' => 1],
        ['kode' => 'AL', 'nama' => 'Admin Logistic', 'level' => 1],
        ['kode' => 'AS', 'nama' => 'Admin Scheduller', 'level' => 1],
        ['kode' => 'ASMGR', 'nama' => 'Assistant Manager', 'level' => 3],
        ['kode' => 'BH', 'nama' => 'Branch Head', 'level' => 3],
        ['kode' => 'BM', 'nama' => 'Branch Manager', 'level' => 3],
        ['kode' => 'CEO', 'nama' => 'Chief Executive Officer', 'level' => 5],
        ['kode' => 'CFO', 'nama' => 'Chief Finance Officer', 'level' => 5],
        ['kode' => 'CSPV', 'nama' => 'Collection Supervisor', 'level' => 2],
        ['kode' => 'D', 'nama' => 'Dispatcher', 'level' => 1],
        ['kode' => 'F Spv', 'nama' => 'Finance Spv', 'level' => 2],
        ['kode' => 'FA Spv', 'nama' => 'Finance Accounting Supervisor', 'level' => 2],
        ['kode' => 'FIN', 'nama' => 'Finance', 'level' => 1],
        ['kode' => 'FSPV', 'nama' => 'Fleet SPV', 'level' => 2],
        ['kode' => 'GMP', 'nama' => 'General Manager Procurement', 'level' => 4],
        ['kode' => 'HRCI Spv', 'nama' => 'HR Comben Industrial Supervisor', 'level' => 2],
        ['kode' => 'HRCIS', 'nama' => 'HR Recruitment Development Supervisor', 'level' => 2],
        ['kode' => 'HRGA Mgr', 'nama' => 'HRGA Manager', 'level' => 4],
        ['kode' => 'HRGADM', 'nama' => 'HRGA Admin', 'level' => 1],
        ['kode' => 'HRR', 'nama' => 'HR Recruitment', 'level' => 1],
        ['kode' => 'ITH', 'nama' => 'IT Head', 'level' => 3],
        ['kode' => 'ITINFRA', 'nama' => 'IT Infrastructure', 'level' => 1],
        ['kode' => 'ITPROG', 'nama' => 'IT Programmer', 'level' => 1],
        ['kode' => 'KAE', 'nama' => 'Key Account', 'level' => 1],
        ['kode' => 'LH', 'nama' => 'Logistic Head', 'level' => 3],
        ['kode' => 'LS', 'nama' => 'Legal Supervisor', 'level' => 2],
        ['kode' => 'M', 'nama' => 'Mechanical', 'level' => 1],
        ['kode' => 'MGF', 'nama' => 'Finance Manager', 'level' => 4],
        ['kode' => 'Mgr IT', 'nama' => 'Manager IT', 'level' => 4],
        ['kode' => 'MKT', 'nama' => 'Marketing', 'level' => 1],
        ['kode' => 'ML', 'nama' => 'Manager Logistik', 'level' => 4],
        ['kode' => 'MSG', 'nama' => 'Messenger', 'level' => 1],
        ['kode' => 'OB', 'nama' => 'Office Boy', 'level' => 1],
        ['kode' => 'OG', 'nama' => 'Office Girl', 'level' => 1],
        ['kode' => 'OM', 'nama' => 'Operation Manager', 'level' => 4],
        ['kode' => 'P', 'nama' => 'Partsman', 'level' => 1],
        ['kode' => 'PS', 'nama' => 'Programmer SPV', 'level' => 2],
        ['kode' => 'S Spv', 'nama' => 'Sales Supervisor', 'level' => 2],
        ['kode' => 'SA', 'nama' => 'Super Administrator', 'level' => null],
        ['kode' => 'SAL', 'nama' => 'Service Advisor', 'level' => 1],
        ['kode' => 'SG', 'nama' => 'Staff GA', 'level' => 1],
        ['kode' => 'SIT', 'nama' => 'Staff IT', 'level' => 1],
        ['kode' => 'SL', 'nama' => 'Staff Logistic', 'level' => 1],
        ['kode' => 'SP', 'nama' => 'Staff Procurement', 'level' => 1],
        ['kode' => 'Spv GA', 'nama' => 'Supervisor General Affair', 'level' => 2],
        ['kode' => 'Spv IT', 'nama' => 'IT Infrastructure SPV', 'level' => 2],
        ['kode' => 'Spv LOG', 'nama' => 'Supervisor Logistik', 'level' => 2],
        ['kode' => 'Spv Proc', 'nama' => 'Supervisor Procurement', 'level' => 2],
        ['kode' => 'TO', 'nama' => 'Tax Officer', 'level' => 1],
        ['kode' => 'TS', 'nama' => 'Tax Supervisor', 'level' => 2],
    ];

    public function run(): void
    {
        $now = now();

        $dibuat = 0;
        $gantiNama = 0;
        $gantiLevel = 0;
        $tetap = 0;

        foreach (self::ROLES as $role) {
            $ada = DB::table('roles')->where('kode', $role['kode'])->first();

            if (!$ada) {
                DB::table('roles')->insert([
                    'kode' => $role['kode'],
                    'nama' => $role['nama'],
                    'level' => $role['level'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->command?->info("  + {$role['kode']} = {$role['nama']} (tingkat " . ($role['level'] ?? '-') . ')');
                $dibuat++;

                continue;
            }

            $ubah = [];

            if ((string) $ada->nama !== $role['nama']) {
                $ubah['nama'] = $role['nama'];

                $this->command?->info("  ~ {$role['kode']}: \"{$ada->nama}\" -> \"{$role['nama']}\"");
                $gantiNama++;
            }

            /*
            | Tingkat yang kosong di sini TIDAK menghapus tingkat yang sudah
            | ada di sana: yang kosong berarti belum diputuskan, bukan
            | diputuskan kosong.
            */
            if ($role['level'] !== null && (int) ($ada->level ?? 0) !== (int) $role['level']) {
                $ubah['level'] = $role['level'];

                $this->command?->info(
                    "  # {$role['kode']} tingkat: " . ($ada->level ?? '-') . " -> {$role['level']}"
                );
                $gantiLevel++;
            }

            if ($ubah === []) {
                $tetap++;

                continue;
            }

            $ubah['updated_at'] = $now;

            DB::table('roles')->where('kode', $role['kode'])->update($ubah);
        }

        $this->command?->info(
            "[Role] {$dibuat} dibuat, {$gantiNama} ganti nama, {$gantiLevel} ganti tingkat, {$tetap} tidak berubah."
        );
    }
}
