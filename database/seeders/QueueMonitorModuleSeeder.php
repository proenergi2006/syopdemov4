<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Modul Pemantauan Antrean
|--------------------------------------------------------------------------
| Mendaftarkan permission module, permission, dan menu untuk halaman
| pemantauan antrean job pada menu Monitoring. Aman dijalankan berulang --
| seluruhnya memakai updateOrInsert.
|
| Dua permission dipisah dengan sengaja: melihat keadaan antrean berguna bagi
| siapa pun yang menunggu emailnya, sedangkan mengulang atau membuang job
| gagal berpengaruh ke sistem dan pantas dibatasi lebih ketat.
|--------------------------------------------------------------------------
*/
class QueueMonitorModuleSeeder extends Seeder
{
    private const MODULE_CODE = 'queue_monitor';

    public function run(): void
    {
        $now = now();

        $this->seedPermissionModule($now);
        $this->seedPermissions($now);
        $this->seedMenu($now);
    }

    private function seedPermissionModule($now): void
    {
        DB::table('permission_modules')->updateOrInsert(
            ['code' => self::MODULE_CODE],
            [
                'name' => 'Pemantauan Antrean',
                'description' => 'Module pemantauan antrean job dan kegagalan pengiriman email.',
                'route_prefix' => '/monitoring/queue-health',
                'sort_order' => 92,
                'is_active' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('permission_modules')
            ->where('code', self::MODULE_CODE)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);
    }

    private function seedPermissions($now): void
    {
        $permissions = [
            /*
            | Tidak memakai scope: antrean adalah milik sistem, bukan milik
            | cabang atau department mana pun.
            */
            [
                'action' => 'view',
                'name' => 'Lihat Pemantauan Antrean',
                'description' => 'Melihat kesehatan antrean job dan daftar job yang gagal.',
                'requires_scope' => false,
            ],
            [
                'action' => 'manage',
                'name' => 'Kelola Job Gagal',
                'description' => 'Mengulang atau membuang job yang gagal dari antrean.',
                'requires_scope' => false,
            ],
        ];

        foreach ($permissions as $permission) {
            $code = self::MODULE_CODE . '.' . $permission['action'];

            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => self::MODULE_CODE,
                    'action' => $permission['action'],
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                    'requires_scope' => $permission['requires_scope'],
                    'is_active' => true,
                    'updated_at' => $now,
                ],
            );

            DB::table('permissions')
                ->where('code', $code)
                ->whereNull('created_at')
                ->update(['created_at' => $now]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Menu
    |--------------------------------------------------------------------------
    | Menempel pada menu induk "Monitoring", bersama Log Viewer dan Activity
    | Log -- ketiganya sama-sama alat untuk memeriksa keadaan sistem.
    |--------------------------------------------------------------------------
    */
    private function seedMenu($now): void
    {
        $parentId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Monitoring')
            ->value('id');

        if (!$parentId) {
            return;
        }

        DB::table('menus')->updateOrInsert(
            [
                'name' => 'Antrean Email',
                'parent_id' => $parentId,
            ],
            [
                'path' => '/monitoring/queue-health',
                'route_name' => 'queue-health',
                'icon' => 'tabler-mail-cog',
                'order_no' => 3,
                'permission_key' => self::MODULE_CODE . '.view',
                'is_active' => true,
                'show_in_sidebar' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('menus')
            ->where('parent_id', $parentId)
            ->where('name', 'Antrean Email')
            ->whereNull('created_at')
            ->update(['created_at' => $now]);
    }
}
