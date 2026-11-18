<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Dashboard FPU: permission, grup, dan modulnya
|--------------------------------------------------------------------------
| Tiga baris data yang harus ada sebelum layarnya bisa dibuka siapa pun:
|
| 1. Permission dashboard.cash-advance.view, BERSCOPE -- scope itulah yang
|    menentukan seberapa luas data yang tampil dan filter mana yang boleh
|    dipilih pemiliknya.
|
| 2. Grup "Pengajuan Dana" pada launcher dashboard. Grup baru, karena FPU dan
|    saudara-saudaranya bukan Non Stock: yang satu soal barang, yang satu soal
|    uang muka.
|
| 3. Modul FPU di dalam grup itu.
|
| TIDAK MEMBERIKAN PERMISSION KEPADA SIAPA PUN.
|
| Permission-nya dibuat, pemberiannya tidak. Siapa yang boleh membuka
| dashboard FPU dan seberapa luas datanya adalah keputusan yang diambil lewat
| layar Role Permission -- bukan keputusan yang pantas diselipkan sebuah
| seeder.
|
| AMAN DIJALANKAN BERULANG. Baris yang sudah ada diperbarui, bukan
| digandakan, dan status aktifnya tidak dipaksa kembali menyala bila ada yang
| sengaja mematikannya.
|--------------------------------------------------------------------------
*/
class CashAdvanceDashboardSeeder extends Seeder
{
    private const PERMISSION = 'dashboard.cash-advance.view';

    private const GROUP_CODE = 'FUND_REQUEST';

    private const MODULE_CODE = 'CASH_ADVANCE';

    public function run(): void
    {
        $now = now();

        $this->seedPermission($now);

        $groupId = $this->seedGroup($now);

        $this->seedModule($groupId, $now);
    }

    private function seedPermission($now): void
    {
        $ada = DB::table('permissions')->where('code', self::PERMISSION)->first();

        $isi = [
            'module' => 'dashboard',
            'action' => 'view',
            'code' => self::PERMISSION,
            'name' => 'Lihat Dashboard FPU',
            'description' => 'Membuka dashboard FPU. Scope menentukan luas datanya:'
                . ' ALL seluruh perusahaan, OWN_DEPARTMENT department sendiri di semua cabang,'
                . ' OWN_CABANG cabang dan department sendiri, OWN_DATA hanya pengajuannya sendiri.',

            /*
            | Wajib. Tanpa penanda ini layar Role Permission tidak menawarkan
            | pilihan scope sama sekali, dan seluruh pemegangnya akan jatuh ke
            | cabang "default" di service -- yang menolak aksesnya.
            */
            'requires_scope' => true,
            'updated_at' => $now,
        ];

        if ($ada) {
            DB::table('permissions')->where('id', $ada->id)->update($isi);

            $this->command?->info('Permission ' . self::PERMISSION . ' diperbarui.');

            return;
        }

        DB::table('permissions')->insert($isi + [
            'is_active' => true,
            'created_at' => $now,
        ]);

        $this->command?->info('Permission ' . self::PERMISSION . ' dibuat.');
    }

    private function seedGroup($now): int
    {
        $ada = DB::table('dashboard_module_groups')
            ->where('code', self::GROUP_CODE)
            ->whereNull('deleted_at')
            ->first();

        if ($ada) {
            DB::table('dashboard_module_groups')
                ->where('id', $ada->id)
                ->update([
                    'name' => 'Pengajuan Dana',
                    'icon' => 'mdi-cash-multiple',
                    'updated_at' => $now,
                ]);

            $this->command?->info('Grup "Pengajuan Dana" diperbarui.');

            return (int) $ada->id;
        }

        $id = DB::table('dashboard_module_groups')->insertGetId([
            'code' => self::GROUP_CODE,
            'name' => 'Pengajuan Dana',
            'icon' => 'mdi-cash-multiple',

            /* Di bawah Non Stock (10), menyisakan ruang untuk grup di antaranya. */
            'sort_order' => 20,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command?->info('Grup "Pengajuan Dana" dibuat.');

        return (int) $id;
    }

    private function seedModule(int $groupId, $now): void
    {
        $isi = [
            'dashboard_module_group_id' => $groupId,
            'code' => self::MODULE_CODE,
            'title' => 'FPU (Form Pengajuan Uang)',
            'short_title' => 'FPU',
            'description' => 'Pantau pengajuan uang muka: nilai yang diajukan, letak penumpukan'
                . ' approval, dan dana yang sudah cair tetapi belum direalisasikan.',
            'icon' => 'mdi-cash-fast',
            'color' => 'success',
            'route_path' => '/dashboards/cash-advance',
            'permission_name' => self::PERMISSION,

            'features' => json_encode([
                'Nilai pengajuan',
                'Penumpukan approval',
                'Dana belum direalisasi',
            ], JSON_UNESCAPED_UNICODE),

            'sort_order' => 10,
            'updated_at' => $now,
        ];

        $ada = DB::table('dashboard_modules')->where('code', self::MODULE_CODE)->first();

        if ($ada) {
            DB::table('dashboard_modules')->where('id', $ada->id)->update($isi);

            $this->command?->info('Modul dashboard FPU diperbarui.');

            return;
        }

        DB::table('dashboard_modules')->insert($isi + [
            'is_active' => true,
            'is_available' => true,
            'created_at' => $now,
        ]);

        $this->command?->info('Modul dashboard FPU dibuat.');
    }
}
