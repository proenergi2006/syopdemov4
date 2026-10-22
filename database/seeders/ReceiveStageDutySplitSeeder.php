<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Pemisahan tugas penerimaan berkas antar meja Finance
|--------------------------------------------------------------------------
| Penerimaan berkas FPU dan Claim adalah pekerjaan meja Finance, sedangkan
| penerimaan berkas Realisasi pekerjaan meja Admin Finance. Dua meja, dua
| tugas, dan sejak awal memang begitu pembagiannya di kantor.
|
| KENAPA SEMULA TIDAK BEGITU.
|
| ReceiveStagePermissionGrantSeeder memberikan permission penerimaan kepada
| pemegang permission tahap PENUTUP modul yang sama -- claim.receive kepada
| pemegang claim.pay, misalnya. Pada saat itu itulah tebakan terbaik yang
| tersedia: tahapnya baru dibuat dan belum dipegang siapa pun, dan modul yang
| tidak ada penerimanya berarti dokumen berhenti di Approved tanpa ada yang
| bisa memajukannya.
|
| Tebakan itu menyamakan "yang membayar" dengan "yang menerima". Admin
| Finance memang membayar FPU dan Claim, jadi ia ikut menerima permission
| penerimaannya -- lalu ikut menerima email "menunggu diterima" untuk dokumen
| yang bukan tugasnya.
|
| KENAPA DUA HAL INI SATU SAKLAR.
|
| Penerima email ditentukan dari kepemilikan permission aksinya, bukan dari
| daftar penerima tersendiri. Itu disengaja: kabar mengikuti wewenang,
| sehingga tidak pernah ada orang yang dikabari tentang pekerjaan yang tidak
| bisa ia kerjakan, maupun orang yang bisa mengerjakan tetapi tidak dikabari.
| Konsekuensinya, "tidak perlu dikabari" berarti "tidak mengurus" -- dan
| itulah yang dinyatakan di sini.
|
| YANG TIDAK DISENTUH.
|
| Permission pencairan dan pembayaran (cash_advance.disburse, claim.pay)
| tetap pada Admin Finance: ia memang yang membayarnya, dan kabar "menunggu
| pencairan" serta "menunggu pembayaran" memang perlu sampai kepadanya.
| Yang dicabut hanya tahap penerimaan berkasnya.
|
| Barisnya dipertahankan, hanya is_active-nya dimatikan -- sama dengan yang
| dilakukan matriks permission di layar, sehingga riwayat pemberiannya tidak
| hilang dan tanggal pemberian pertamanya tetap terbaca.
|--------------------------------------------------------------------------
*/
class ReceiveStageDutySplitSeeder extends Seeder
{
    /**
     * Role => permission penerimaan yang BUKAN tugasnya.
     *
     * Role disebut dengan namanya, bukan id-nya: id role berbeda antara
     * mesin pengembangan dan production, dan seeder yang menyebut angka
     * akan mencabut wewenang role yang salah di sana.
     *
     * @var array<string, string[]>
     */
    private const BUKAN_TUGASNYA = [
        'Admin Finance' => [
            'cash_advance.receive',
            'claim.receive',
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::BUKAN_TUGASNYA as $namaRole => $daftarKode) {
            $roleId = DB::table('roles')->where('nama', $namaRole)->value('id');

            if (!$roleId) {
                $this->command?->warn("  Role '{$namaRole}' tidak ada, dilewati.");

                continue;
            }

            foreach ($daftarKode as $kode) {
                $permissionId = DB::table('permissions')->where('code', $kode)->value('id');

                if (!$permissionId) {
                    $this->command?->warn("  {$kode}: permission tidak ada, dilewati.");

                    continue;
                }

                $baris = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->first();

                if (!$baris || !$baris->is_active) {
                    $this->command?->info("  {$namaRole} / {$kode}: sudah tidak aktif.");

                    continue;
                }

                /*
                | Penjaga: tahapnya tidak boleh kehilangan SELURUH pemegangnya.
                |
                | Dokumen yang tidak punya penerima akan berhenti di Approved,
                | dan tidak ada pesan kesalahan yang muncul di mana pun --
                | hanya dokumen yang diam. Lebih baik pencabutan ini gagal
                | dengan berisik daripada mematikan satu tahap.
                */
                $pemegangLain = $this->pemegangAktifLain($permissionId, (int) $roleId);

                if ($pemegangLain === 0) {
                    $this->command?->warn(
                        "  {$namaRole} / {$kode}: TIDAK dicabut -- tidak ada role lain"
                        . ' yang memegangnya, dan tahap penerimaannya akan mati.',
                    );

                    continue;
                }

                DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->update([
                        'is_active' => false,
                        'updated_at' => $now,
                    ]);

                $this->command?->info(
                    "  {$namaRole} / {$kode}: dicabut"
                    . " ({$pemegangLain} role lain masih memegangnya).",
                );
            }
        }
    }

    /** Berapa role LAIN yang masih memegang permission ini secara aktif. */
    private function pemegangAktifLain(int $permissionId, int $kecualiRoleId): int
    {
        return DB::table('role_permissions')
            ->where('permission_id', $permissionId)
            ->where('role_id', '!=', $kecualiRoleId)
            ->where('is_active', true)
            ->count();
    }
}
