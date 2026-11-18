<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Dashboard Claim: permission dan modulnya
|--------------------------------------------------------------------------
| Kartu ketiga di grup "Pengajuan Dana". Grupnya dicari lewat kode, bukan id,
| dan dibuat sendiri bila belum ada -- jadi seeder ini bisa berdiri sendiri,
| dijalankan sebelum maupun sesudah seeder dashboard FPU dan Realisasi.
|
| TIDAK MEMBERIKAN PERMISSION KEPADA SIAPA PUN. Siapa yang boleh membuka dan
| seberapa luas datanya adalah keputusan yang diambil lewat layar Role
| Permission.
|
| AMAN DIJALANKAN BERULANG.
|--------------------------------------------------------------------------
*/
class ClaimDashboardSeeder extends Seeder
{
    private const PERMISSION = 'dashboard.claim.view';

    private const GROUP_CODE = 'FUND_REQUEST';

    private const MODULE_CODE = 'CLAIM';

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
            'name' => 'Lihat Dashboard Claim',
            'description' => 'Membuka dashboard Claim. Scope menentukan luas datanya:'
                . ' ALL seluruh perusahaan, OWN_DEPARTMENT department sendiri di semua cabang,'
                . ' OWN_CABANG cabang dan department sendiri, OWN_DATA hanya klaimnya sendiri.',

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
            'title' => 'Claim',
            'short_title' => 'Claim',
            'description' => 'Pantau penggantian uang karyawan: berapa yang belum diganti,'
                . ' mana yang lewat jadwal bayar, dan mana yang belum dijadwalkan sama sekali.',
            'icon' => 'mdi-cash-refund',
            'color' => 'warning',
            'route_path' => '/dashboards/claim',
            'permission_name' => self::PERMISSION,

            'features' => json_encode([
                'Belum diganti',
                'Jadwal pembayaran',
                'Revisi nominal',
            ], JSON_UNESCAPED_UNICODE),

            /* Di bawah FPU (10) dan Realisasi (20). */
            'sort_order' => 30,
            'updated_at' => $now,
        ];

        $ada = DB::table('dashboard_modules')->where('code', self::MODULE_CODE)->first();

        if ($ada) {
            DB::table('dashboard_modules')->where('id', $ada->id)->update($isi);

            $this->command?->info('Modul dashboard Claim diperbarui.');

            return;
        }

        DB::table('dashboard_modules')->insert($isi + [
            'is_active' => true,
            'is_available' => true,
            'created_at' => $now,
        ]);

        $this->command?->info('Modul dashboard Claim dibuat.');
    }
}
