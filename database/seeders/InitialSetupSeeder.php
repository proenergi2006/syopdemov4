<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InitialSetupSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {

            // 1) Wilayah
            $wilayahId = DB::table('wilayah')->insertGetId([
                'kode' => 'WIL-01',
                'nama' => 'JABODETABEK',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2) Cabang
            $cabangId = DB::table('cabang')->insertGetId([
                'wilayah_id' => $wilayahId,
                'kode' => 'CBG-01',
                'nama' => 'JAKARTA',
                'alamat' => 'Head Office',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3) Departemen
            $deptIT = DB::table('departemen')->insertGetId([
                'kode' => 'DEP-IT',
                'nama' => 'IT',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4) Roles
            $roles = [
                ['kode' => 'ADMIN', 'nama' => 'Administrator'],
                ['kode' => 'BM', 'nama' => 'Branch Manager'],
                ['kode' => 'OM', 'nama' => 'Operation Manager'],
                ['kode' => 'PROC', 'nama' => 'Procurement'],
            ];

            $roleIds = [];
            foreach ($roles as $r) {
                $roleIds[$r['kode']] = DB::table('roles')->insertGetId([
                    'kode' => $r['kode'],
                    'nama' => $r['nama'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 5) Menus (tree)
            $dashboardMenuId = DB::table('menus')->insertGetId([
                'parent_id' => null,
                'name' => 'Dashboard',
                'path' => '/dashboard',
                'route_name' => 'dashboard',
                'icon' => 'tabler-smart-home',
                'order_no' => 1,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $masterMenuId = DB::table('menus')->insertGetId([
                'parent_id' => null,
                'name' => 'Master',
                'path' => null,
                'route_name' => null,
                'icon' => 'tabler-settings',
                'order_no' => 2,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $regionalMenuId = DB::table('menus')->insertGetId([
                'parent_id' => $masterMenuId,
                'name' => 'Regional',
                'path' => null,
                'route_name' => null,
                'icon' => 'tabler-map-2',
                'order_no' => 1,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $authMenuId = DB::table('menus')->insertGetId([
                'parent_id' => null,
                'name' => 'Auth',
                'path' => null,
                'route_name' => null,
                'icon' => 'tabler-lock',
                'order_no' => 3,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $userMenuId = DB::table('menus')->insertGetId([
                'parent_id' => $authMenuId,
                'name' => 'Users',
                'path' => '/master/users',
                'route_name' => 'master-users',
                'icon' => 'tabler-user',
                'order_no' => 1,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // --- Regional children (urut rapi)
            $provinsiMenuId = DB::table('menus')->insertGetId([
                'parent_id' => $regionalMenuId,
                'name' => 'Provinsi',
                'path' => '/master/provinsi',
                'route_name' => 'master-provinsi',
                'icon' => 'tabler-map-pin',
                'order_no' => 2,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $kabMenuId = DB::table('menus')->insertGetId([
                'parent_id' => $regionalMenuId,
                'name' => 'Kabupaten',
                'path' => '/master/kabupaten',
                'route_name' => 'master-kabupaten',
                'icon' => 'tabler-map-pin',
                'order_no' => 3,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $wilayahMenuId = DB::table('menus')->insertGetId([
                'parent_id' => $regionalMenuId,
                'name' => 'Wilayah',
                'path' => '/master/wilayah',
                'route_name' => 'master-wilayah',
                'icon' => 'tabler-map',
                'order_no' => 4,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $areaMenuId = DB::table('menus')->insertGetId([
                'parent_id' => $regionalMenuId,
                'name' => 'Area',
                'path' => '/master/area',
                'route_name' => 'master-area',
                'icon' => 'tabler-map-pin',
                'order_no' => 5,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // --- Master children
            $cabangMenuId = DB::table('menus')->insertGetId([
                'parent_id' => $masterMenuId,
                'name' => 'Cabang',
                'path' => '/master/cabang',
                'route_name' => 'master-cabang',
                'icon' => 'tabler-building',
                'order_no' => 8,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $deptMenuId = DB::table('menus')->insertGetId([
                'parent_id' => $masterMenuId,
                'name' => 'Departemen',
                'path' => '/master/departemen',
                'route_name' => 'master-departemen',
                'icon' => 'tabler-users',
                'order_no' => 9,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $vendorMenuId = DB::table('menus')->insertGetId([
                'parent_id' => $masterMenuId,
                'name' => 'Vendor',
                'path' => '/master/vendor',
                'route_name' => 'master-vendor',
                'icon' => 'tabler-building-store',
                'order_no' => 10,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $terminalMenuId = DB::table('menus')->insertGetId([
                'parent_id' => $masterMenuId,
                'name' => 'Terminal',
                'path' => '/master/terminal',
                'route_name' => 'master-terminal',
                'icon' => 'tabler-gas-station',
                'order_no' => 11,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // --- Auth children
            $roleMenuId = DB::table('menus')->insertGetId([
                'parent_id' => $authMenuId,
                'name' => 'Roles',
                'path' => '/master/roles',
                'route_name' => 'master-roles',
                'icon' => 'tabler-shield',
                'order_no' => 2,
                'permission_key' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $roleMenuSettingId = DB::table('menus')->insertGetId([
                'parent_id' => $authMenuId,
                'name' => 'Role Menu',
                'path' => '/master/role-menus',
                'route_name' => 'master-role-menus',
                'icon' => 'tabler-lock',
                'order_no' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $produkId = DB::table('menus')->insertGetId([
                'parent_id' => $authMenuId,
                'name' => 'Produk',
                'path' => '/master/produk',
                'route_name' => 'master-produk',
                'icon' => 'tabler-archive',
                'order_no' => 4,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $pbbkbId = DB::table('menus')->insertGetId([
                'parent_id' => $authMenuId,
                'name' => 'PBBKB',
                'path' => '/master/pbbkb',
                'route_name' => 'master-pbbkb',
                'icon' => 'tabler-article',
                'order_no' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 6) Admin User
            $adminUserId = DB::table('users')->insertGetId([
                'name' => 'Admin SYOP',
                'email' => 'admin@syop.local',
                'password' => Hash::make('admin123'),
                'cabang_id' => $cabangId,
                'departemen_id' => $deptIT,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 7) Attach admin role
            DB::table('user_roles')->insert([
                'user_id' => $adminUserId,
                'role_id' => $roleIds['ADMIN'],
            ]);

            // 8) Attach menus to ADMIN role
            $menuIds = [
                $dashboardMenuId,
                $masterMenuId,
                $regionalMenuId,
                $provinsiMenuId,
                $kabMenuId,
                $wilayahMenuId,
                $areaMenuId,
                $cabangMenuId,
                $deptMenuId,
                $vendorMenuId,
                $terminalMenuId,

                $authMenuId,
                $userMenuId,
                $roleMenuId,
                $roleMenuSettingId,
                $produkId,
                $pbbkbId,
            ];

            foreach ($menuIds as $mid) {
                DB::table('role_menus')->insert([
                    'role_id' => $roleIds['ADMIN'],
                    'menu_id' => $mid,
                ]);
            }

            // 9) Multi-cabang user (opsional)
            DB::table('user_cabang')->insert([
                'user_id' => $adminUserId,
                'cabang_id' => $cabangId,
            ]);
        });
    }
}
