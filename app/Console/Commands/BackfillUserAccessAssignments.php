<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\UserHomeAccess;
use Illuminate\Console\Command;

/*
|--------------------------------------------------------------------------
| Melengkapi baris akses bawaan akun
|--------------------------------------------------------------------------
| Tiap akun harus punya satu baris akses untuk cabang dan department yang
| melekat pada master akunnya. Tanpa baris itu, menambah akses ke cabang lain
| akan MENGGANTI cabang asalnya pada form pengajuan, bukan menambahnya.
|
| Aturannya tidak ditulis ulang di sini. Seluruhnya dikerjakan oleh
| App\Support\UserHomeAccess, yang juga dipakai layar master akses -- supaya
| perintah ini dan layar itu tidak punya dua versi aturan yang berbeda.
|
| Dulu di sini ada versi tersendiri: baris bawaan hanya ditandai utama kalau
| belum ada baris lain yang memegang tanda itu. Akibatnya akun yang terkena
| celah lama -- tanda utamanya jatuh ke akses tambahan karena ia kebetulan
| baris pertama -- tidak ikut diperbaiki perintah ini.
|
| Aman dijalankan berulang.
|--------------------------------------------------------------------------
*/
class BackfillUserAccessAssignments extends Command
{
    protected $signature = 'user-access:backfill';

    protected $description = 'Melengkapi baris akses bawaan akun dari users.cabang_id dan users.departemen_id';

    public function handle(): int
    {
        $this->info('Melengkapi baris akses bawaan akun...');

        $diperbaiki = 0;
        $sudahBenar = 0;
        $tanpaMaster = 0;

        User::query()
            ->select([
                'id',
                'name',
                'cabang_id',
                'departemen_id',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($users) use (
                &$diperbaiki,
                &$sudahBenar,
                &$tanpaMaster,
            ): void {
                foreach ($users as $user) {
                    if (UserHomeAccess::kombinasi($user) === null) {
                        $tanpaMaster++;

                        continue;
                    }

                    if (UserHomeAccess::pastikanBaris($user->id)) {
                        $diperbaiki++;
                        $this->line("  diperbaiki: #{$user->id} {$user->name}");
                    } else {
                        $sudahBenar++;
                    }
                }
            });

        $this->newLine();
        $this->info("Selesai. Diperbaiki: {$diperbaiki}, sudah benar: {$sudahBenar}.");

        if ($tanpaMaster > 0) {
            $this->warn(
                "{$tanpaMaster} akun dilewati karena cabang atau department pada master akunnya masih kosong."
            );
        }

        return self::SUCCESS;
    }
}
