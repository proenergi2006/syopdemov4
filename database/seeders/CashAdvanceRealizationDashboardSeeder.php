<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Dashboard Realisasi FPU: permission dan modulnya
|--------------------------------------------------------------------------
| Grupnya ("Pengajuan Dana") sudah dibuat CashAdvanceDashboardSeeder. Seeder
| ini hanya menambahkan permission dan kartu modulnya ke grup yang sama --
| dicari lewat kodenya, bukan lewat id, supaya urutan jalannya seeder tidak
| menentukan hasilnya.
|
| TIDAK MEMBERIKAN PERMISSION KEPADA SIAPA PUN. Siapa yang boleh membuka dan
| seberapa luas datanya adalah keputusan yang diambil lewat layar Role
| Permission.
|
| AMAN DIJALANKAN BERULANG.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationDashboardSeeder extends Seeder
{
    private const PERMISSION = 'dashboard.cash-advance-realization.view';

    private const GROUP_CODE = 'FUND_REQUEST';

    private const MODULE_CODE = 'CASH_ADVANCE_REALIZATION';

    public function run(): void
    {
        $now = now();

        $this->seedPermission($now);

        $groupId = $this->resolveGroupId($now);

        $this->seedModule($groupId, $now);
    }

    private function seedPermission($now): void
    {
        $ada = DB::table('permissions')->where('code', self::PERMISSION)->first();

        $isi = [
            'module' => 'dashboard',
            'action' => 'view',
            'code' => self::PERMISSION,
            'name' => 'Lihat Dashboard Realisasi FPU',
            'description' => 'Membuka dashboard Realisasi FPU. Scope menentukan luas datanya:'
                . ' ALL seluruh perusahaan, OWN_DEPARTMENT department sendiri di semua cabang,'
                . ' OWN_CABANG cabang dan department sendiri, OWN_DATA hanya realisasinya sendiri.',

            /*
            | Wajib. Tanpa penanda ini layar Role Permission tidak menawarkan
            | pilihan scope, dan seluruh pemegangnya jatuh ke cabang "default"
            | di service -- yang menolak aksesnya.
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

    /**
     * Grup "Pengajuan Dana", dibuat bila belum ada.
     *
     * Biasanya sudah dibuat CashAdvanceDashboardSeeder, tetapi seeder ini
     * harus bisa berdiri sendiri -- dijalankan lebih dulu pun hasilnya sama.
     */
    private function resolveGroupId($now): int
    {
        $ada = DB::table('dashboard_module_groups')
            ->where('code', self::GROUP_CODE)
            ->whereNull('deleted_at')
            ->first();

        if ($ada) {
            return (int) $ada->id;
        }

        $this->command?->info('Grup "Pengajuan Dana" belum ada, dibuat lebih dulu.');

        return (int) DB::table('dashboard_module_groups')->insertGetId([
            'code' => self::GROUP_CODE,
            'name' => 'Pengajuan Dana',
            'icon' => 'mdi-cash-multiple',
            'sort_order' => 20,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedModule(int $groupId, $now): void
    {
        $isi = [
            'dashboard_module_group_id' => $groupId,
            'code' => self::MODULE_CODE,
            'title' => 'Realisasi FPU',
            'short_title' => 'Realisasi',
            'description' => 'Pantau pertanggungjawaban uang muka: selisih yang harus dikembalikan'
                . ' atau dibayar, dan FPU cair yang realisasinya belum masuk.',
            'icon' => 'mdi-receipt-text-check-outline',
            'color' => 'info',
            'route_path' => '/dashboards/cash-advance-realization',
            'permission_name' => self::PERMISSION,

            'features' => json_encode([
                'Selisih realisasi',
                'Belum direalisasikan',
                'Penumpukan approval',
            ], JSON_UNESCAPED_UNICODE),

            /* Di bawah kartu FPU (10), mengikuti urutan alur dokumennya. */
            'sort_order' => 20,
            'updated_at' => $now,
        ];

        $ada = DB::table('dashboard_modules')->where('code', self::MODULE_CODE)->first();

        if ($ada) {
            DB::table('dashboard_modules')->where('id', $ada->id)->update($isi);

            $this->command?->info('Modul dashboard Realisasi FPU diperbarui.');

            return;
        }

        DB::table('dashboard_modules')->insert($isi + [
            'is_active' => true,
            'is_available' => true,
            'created_at' => $now,
        ]);

        $this->command?->info('Modul dashboard Realisasi FPU dibuat.');
    }
}
