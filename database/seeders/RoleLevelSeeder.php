<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Tingkat jabatan tiap role
|--------------------------------------------------------------------------
| Angkanya data, bukan kode: sesudah ini diubah lewat master role.
|
| DICOCOKKAN LEWAT NAMA, BUKAN ID.
|
| Id role di basis data production berbeda. Nama yang tidak ditemukan
| dilaporkan dan dilewati -- tidak ada role yang diberi tingkat secara
| kebetulan.
|
| Role yang tidak disebut di sini dibiarkan NULL, artinya aturan "bawahan
| tidak menyetujui atasan" tidak berlaku untuknya. Super Administrator
| sengaja dibiarkan begitu: ia akses sistem, bukan jabatan.
|--------------------------------------------------------------------------
*/
class RoleLevelSeeder extends Seeder
{
    /**
     * @var array<int, array<int, string>>
     */
    private const TINGKAT = [
        5 => [
            'Chief Executive Officer',
            'Chief Finance Officer',
        ],

        4 => [
            'Finance Manager',
            'HRGA Manager',
            'Manager IT',
            'Manager Logistik',
            'General Manager Procurement',
            'Operation Manager',
        ],

        3 => [
            'Assistant Manager',
            'Branch Manager',
            'Branch Head',
            'IT Head',
            'Logistic Head',
        ],

        2 => [
            'IT Infrastructure SPV',
            'Supervisor General Affair',
            'Programmer SPV',
            'Supervisor Logistik',
            'Finance Spv',
            'Finance Accounting Supervisor',
            'Supervisor Procurement',
            'Admin Head',
            'Sales Supervisor',
            'HR Comben Industrial Supervisor',
            'HR Recruitment Development Supervisor',
            'Legal Supervisor',
            'Tax Supervisor',
            'Collection Supervisor',
            'Fleet SPV',
        ],

        1 => [
            'Staff IT',
            'Staff GA',
            'Staff Logistic',
            'Staff Procurement',
            'Admin Finance',
            'HRGA Admin',
            'Admin Logistic',
            'Admin Scheduller',
            'Finance',
            'IT Infrastructure',
            'IT Programmer',
            'HR Recruitment',
            'Tax Officer',
            'Service Advisor',
            'Mechanical',
            'Partsman',
            'Dispatcher',
            'Marketing',
            'Key Account',
            'Messenger',
            'Office Boy',
            'Office Girl',
        ],
    ];

    public function run(): void
    {
        $diisi = 0;
        $tidakKetemu = [];

        foreach (self::TINGKAT as $tingkat => $daftar) {
            foreach ($daftar as $nama) {
                $terpengaruh = DB::table('roles')
                    ->where('nama', $nama)
                    ->update(['level' => $tingkat]);

                if ($terpengaruh === 0) {
                    $tidakKetemu[] = $nama;

                    continue;
                }

                $diisi += $terpengaruh;
            }
        }

        $this->command?->info("[Tingkat Jabatan] {$diisi} role diisi tingkatnya.");

        if ($tidakKetemu !== []) {
            $this->command?->warn(
                '[Tingkat Jabatan] Tidak ditemukan, dilewati: ' . implode(', ', $tidakKetemu),
            );
        }

        $kosong = DB::table('roles')->whereNull('level')->pluck('nama');

        if ($kosong->isNotEmpty()) {
            $this->command?->info(
                '[Tingkat Jabatan] Dibiarkan tanpa tingkat: ' . $kosong->implode(', '),
            );
        }
    }
}
