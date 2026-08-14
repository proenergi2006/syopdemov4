<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Tipe Dokumen Khusus
|--------------------------------------------------------------------------
| Berisi contoh awal saja. Tipe berikutnya cukup ditambah lewat menu tanpa
| menyentuh kode maupun seeder ini.
|
| department_id dicari lewat KODE department, bukan ID, supaya seeder tetap
| benar walau ID department berbeda antar environment.
|--------------------------------------------------------------------------
*/
class SpecialDocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $types = [
            [
                'code' => 'KAPAL',
                'name' => 'Dokumen Kapal',
                'description' => 'Dokumen terkait biaya angkut kapal. Jalur approval-nya berbeda dari dokumen biasa.',
                'department_code' => 'PROC',
            ],
        ];

        foreach ($types as $type) {
            $departmentId = DB::table('departments')
                ->where('kode', $type['department_code'])
                ->value('id');

            if (!$departmentId) {
                $this->command?->warn(
                    'Department ' . $type['department_code']
                        . ' tidak ditemukan, tipe ' . $type['code'] . ' dilewati.',
                );

                continue;
            }

            $existing = DB::table('special_document_types')
                ->where('code', $type['code'])
                ->first();

            DB::table('special_document_types')->updateOrInsert(
                [
                    'code' => $type['code'],
                ],
                [
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'department_id' => $departmentId,
                    'is_active' => true,
                    'created_at' => $existing?->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
