<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Pemberian awal permission penerimaan
|--------------------------------------------------------------------------
| Permission yang baru dibuat tidak dipegang siapa pun. Untuk tahap yang
| berdiri di tengah alur, itu berarti dokumen berhenti di APPROVED dan tidak
| ada seorang pun yang bisa memajukannya -- modulnya mati sampai ada yang
| menyadari dan mengatur permission-nya lewat matriks.
|
| Karena itu permission penerimaan diberikan lebih dulu kepada role yang sudah
| memegang tahap penutup modul yang sama. Merekalah yang selama ini memang
| menangani dokumen-dokumen ini; bukan menambah wewenang baru ke orang baru,
| hanya menyambung tahap yang tadinya tidak ada.
|
| Dijalankan SEKALI: begitu permission-nya sudah punya pemegang, seeder ini
| tidak menyentuhnya lagi. Jadi kalau daftarnya dipersempit lewat matriks,
| seeder ini tidak akan mengembalikannya.
|--------------------------------------------------------------------------
*/
class ReceiveStagePermissionGrantSeeder extends Seeder
{
    /**
     * Permission penerimaan => permission tahap penutup yang jadi acuannya.
     *
     * @var array<string, string[]>
     */
    private const ACUAN = [
        'cash_advance_realization.receive' => [
            'cash_advance_realization.return',
            'cash_advance_realization.reimburse',
        ],

        'claim.receive' => [
            'claim.pay',
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::ACUAN as $kode => $acuan) {
            $permissionId = DB::table('permissions')->where('code', $kode)->value('id');

            if (!$permissionId) {
                $this->command?->warn("  {$kode}: permission belum ada, dilewati.");

                continue;
            }

            $sudahAda = DB::table('role_permissions')
                ->where('permission_id', $permissionId)
                ->exists();

            if ($sudahAda) {
                $this->command?->info("  {$kode}: sudah punya baris pemberian, tidak disentuh.");

                continue;
            }

            /*
            | Hanya pemberian yang AKTIF yang ditiru. Baris tidak aktif adalah
            | wewenang yang sengaja dicabut -- menirunya sama saja dengan
            | mengembalikan akses yang sudah ditutup.
            |
            | Scope-nya ikut disalin apa adanya, supaya batas cabangnya persis
            | sama dengan tahap penutup yang sudah berjalan.
            */
            $acuanGrants = DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->whereIn('permissions.code', $acuan)
                ->where('role_permissions.is_active', true)
                ->get(['role_permissions.role_id', 'role_permissions.scope'])
                ->unique('role_id');

            if ($acuanGrants->isEmpty()) {
                $this->command?->warn("  {$kode}: tidak ada role acuan yang aktif, tidak diberikan ke siapa pun.");

                continue;
            }

            foreach ($acuanGrants as $grant) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $grant->role_id, 'permission_id' => $permissionId],
                    [
                        'scope' => $grant->scope,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            $this->command?->info("  {$kode}: diberikan ke {$acuanGrants->count()} role.");
        }
    }
}
