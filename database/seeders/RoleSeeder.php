<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        /*
        |--------------------------------------------------------------------------
        | Roles
        |--------------------------------------------------------------------------
        | Jangan hardcode ID.
        | Pakai kode sebagai unique key supaya sequence tetap aman.
        |--------------------------------------------------------------------------
        */
        $roles = [
            ['kode' => 'SA', 'nama' => 'Super Administrator'],
            ['kode' => 'BM', 'nama' => 'Branch Manager'],
            ['kode' => 'OM', 'nama' => 'Operation Manager'],
            ['kode' => 'SP', 'nama' => 'Staff Procurement'],
            ['kode' => 'CEO', 'nama' => 'Chief Executive Officer'],
            ['kode' => 'FIN', 'nama' => 'Finance'],
            ['kode' => 'CFO', 'nama' => 'Chief Finance Officer'],
            ['kode' => 'GMP', 'nama' => 'General Manager Procurement'],
            ['kode' => 'Spv GA', 'nama' => 'Supervisor General Affair'],
            ['kode' => 'Spv LOG', 'nama' => 'Supervisor Logistik'],
            ['kode' => 'Spv IT', 'nama' => 'Supervisor IT'],
            ['kode' => 'HRGA Mgr', 'nama' => 'HRGA Manager'],
            ['kode' => 'ML', 'nama' => 'Manager Logistik'],
            ['kode' => 'Mgr IT', 'nama' => 'Manager IT'],
            ['kode' => 'SIT', 'nama' => 'Staff IT'],
            ['kode' => 'BH', 'nama' => 'Branch Head'],
            ['kode' => 'MGF', 'nama' => 'Finance Manager'],
            ['kode' => 'LH', 'nama' => 'Logistic Head'],
            ['kode' => 'ITH', 'nama' => 'IT Head'],
            ['kode' => 'AF', 'nama' => 'Admin Finance'],
            ['kode' => 'ADH', 'nama' => 'Admin Head'],
            ['kode' => 'SG', 'nama' => 'Staff GA'],
            ['kode' => 'SL', 'nama' => 'Staff Logistic'],
            ['kode' => 'ASMGR', 'nama' => 'Assistant Manager'],
            ['kode' => 'HRCI Spv', 'nama' => 'HR Comben Industrial Supervisor'],
            ['kode' => 'SAL', 'nama' => 'Service Advisor'],
            ['kode' => 'FA Spv', 'nama' => 'Finance Accounting Supervisor'],
            ['kode' => 'S Spv', 'nama' => 'Sales Supervisor'],
            ['kode' => 'M', 'nama' => 'Mechanical'],
            ['kode' => 'P', 'nama' => 'Partsman'],
            ['kode' => 'AS', 'nama' => 'Admin Scheduller'],
            ['kode' => 'AL', 'nama' => 'Admin Logistic'],
            ['kode' => 'D', 'nama' => 'Dispatcher'],
            ['kode' => 'F Spv', 'nama' => 'Finance Spv'],
        ];

        foreach ($roles as $role) {
            $existingRole = DB::table('roles')
                ->where('kode', $role['kode'])
                ->first();

            DB::table('roles')->updateOrInsert(
                [
                    'kode' => $role['kode'],
                ],
                [
                    'nama' => $role['nama'],
                    'is_active' => true,
                    'created_at' => $existingRole?->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
