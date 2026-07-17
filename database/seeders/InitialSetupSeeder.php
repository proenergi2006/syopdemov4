<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class InitialSetupSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = now();

            $superAdminRole = DB::table('roles')
                ->where('kode', 'SA')
                ->where('is_active', true)
                ->first();

            if (!$superAdminRole) {
                throw new RuntimeException(
                    'Role SA / Super Administrator belum tersedia. Jalankan RoleSeeder terlebih dahulu.'
                );
            }

            $cabangId = DB::table('cabang')
                ->orderBy('id')
                ->value('id');

            if (!$cabangId) {
                throw new RuntimeException(
                    'Data cabang belum tersedia. Jalankan seeder cabang terlebih dahulu.'
                );
            }

            $departmentId = DB::table('departments')
                ->where('kode', 'IT')
                ->value('id');

            if (!$departmentId) {
                $departmentId = DB::table('departments')
                    ->orderBy('id')
                    ->value('id');
            }

            if (!$departmentId) {
                throw new RuntimeException(
                    'Data department belum tersedia. Jalankan DepartmentSeeder terlebih dahulu.'
                );
            }

            $existingUser = DB::table('users')
                ->where('username', 'admin.syop')
                ->first();

            if ($existingUser) {
                DB::table('users')
                    ->where('id', $existingUser->id)
                    ->update([
                        'name' => 'Super Admin SYOP',
                        'email' => $existingUser->email ?: 'admin.syop@syop.local',
                        'username' => 'admin.syop',
                        'cabang_id' => $cabangId,
                        'departemen_id' => $departmentId,
                        'is_active' => true,
                        'updated_at' => $now,
                    ]);

                $adminUserId = (int) $existingUser->id;
            } else {
                $adminUserId = (int) DB::table('users')->insertGetId([
                    'name' => 'Super Admin SYOP',
                    'email' => 'admin.syop@syop.local',
                    'username' => 'admin.syop',
                    'password' => Hash::make('admin123'),
                    'cabang_id' => $cabangId,
                    'departemen_id' => $departmentId,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Pastikan admin.syop hanya punya role SA
            |--------------------------------------------------------------------------
            */
            DB::table('user_roles')
                ->where('user_id', $adminUserId)
                ->delete();

            DB::table('user_roles')->insert([
                'user_id' => $adminUserId,
                'role_id' => $superAdminRole->id,
            ]);
        });
    }
}
