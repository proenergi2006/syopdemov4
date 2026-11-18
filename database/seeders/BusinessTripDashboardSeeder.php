<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Dashboard Perjalanan Dinas: permission dan modulnya
|--------------------------------------------------------------------------
| Kartu keempat dan terakhir di grup "Pengajuan Dana". Grupnya dicari lewat
| kode, bukan id, dan dibuat sendiri bila belum ada -- jadi seeder ini bisa
| berdiri sendiri, dijalankan dalam urutan mana pun.
|
| TIDAK MEMBERIKAN PERMISSION KEPADA SIAPA PUN.
|
| AMAN DIJALANKAN BERULANG.
|--------------------------------------------------------------------------
*/
class BusinessTripDashboardSeeder extends Seeder
{
    private const PERMISSION = 'dashboard.business-trip.view';

    private const GROUP_CODE = 'FUND_REQUEST';

    private const MODULE_CODE = 'BUSINESS_TRIP';

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
            'name' => 'Lihat Dashboard Perjalanan Dinas',
            'description' => 'Membuka dashboard Perjalanan Dinas. Scope menentukan luas datanya:'
                . ' ALL seluruh perusahaan, OWN_DEPARTMENT department sendiri di semua cabang,'
                . ' OWN_CABANG cabang dan department sendiri, OWN_DATA hanya perdin yang ia buat.',

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
            'title' => 'Perjalanan Dinas',
            'short_title' => 'Perdin',
            'description' => 'Pantau kesiapan berangkat: approval yang balapan dengan tanggal'
                . ' keberangkatan, pemesanan yang belum diurus, dan bekal yang belum diajukan.',
            'icon' => 'mdi-airplane-takeoff',
            'color' => 'primary',
            'route_path' => '/dashboards/business-trip',
            'permission_name' => self::PERMISSION,

            'features' => json_encode([
                'Kesiapan berangkat',
                'Pemesanan belum diurus',
                'Bekal belum diajukan',
            ], JSON_UNESCAPED_UNICODE),

            /* Di bawah FPU (10), Realisasi (20), dan Claim (30). */
            'sort_order' => 40,
            'updated_at' => $now,
        ];

        $ada = DB::table('dashboard_modules')->where('code', self::MODULE_CODE)->first();

        if ($ada) {
            DB::table('dashboard_modules')->where('id', $ada->id)->update($isi);

            $this->command?->info('Modul dashboard Perjalanan Dinas diperbarui.');

            return;
        }

        DB::table('dashboard_modules')->insert($isi + [
            'is_active' => true,
            'is_available' => true,
            'created_at' => $now,
        ]);

        $this->command?->info('Modul dashboard Perjalanan Dinas dibuat.');
    }
}
