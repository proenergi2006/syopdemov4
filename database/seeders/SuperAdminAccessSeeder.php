<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SuperAdminAccessSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $superAdminRole = DB::table('roles')
            ->where('kode', 'SA')
            ->first();

        if (!$superAdminRole) {
            throw new RuntimeException(
                'Role SA / Super Administrator belum tersedia. Jalankan RoleSeeder terlebih dahulu.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Assign all permissions to SA
        |--------------------------------------------------------------------------
        */
        $permissionIds = DB::table('permissions')
            ->where('is_active', true)
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            $existing = DB::table('role_permissions')
                ->where('role_id', $superAdminRole->id)
                ->where('permission_id', $permissionId)
                ->first();

            DB::table('role_permissions')->updateOrInsert(
                [
                    'role_id' => $superAdminRole->id,
                    'permission_id' => $permissionId,
                ],
                [
                    'scope' => 'ALL',
                    'is_active' => true,
                    'created_at' => $existing?->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Assign all active menus to SA
        |--------------------------------------------------------------------------
        | role_menus tidak pakai timestamps sesuai model RoleMenu.
        |--------------------------------------------------------------------------
        */
        if (Schema::hasTable('menus') && Schema::hasTable('role_menus')) {
            $menuIds = DB::table('menus')
                ->where('is_active', true)
                ->pluck('id');

            foreach ($menuIds as $menuId) {
                DB::table('role_menus')->updateOrInsert(
                    [
                        'role_id' => $superAdminRole->id,
                        'menu_id' => $menuId,
                    ],
                    []
                );
            }
        }
    }
}
