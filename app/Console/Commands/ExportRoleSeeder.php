<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Master role yang sudah diisi -> seeder siap jalan di production
|--------------------------------------------------------------------------
| Dipakai di komputer pengembang. Ia membaca tabel roles, lalu menuliskannya
| kembali sebagai seeder yang AMAN dijalankan di basis data yang sudah hidup.
|
| KENAPA ROLE PERLU SEEDER TERSENDIRI.
|
| Seeder approval flow mencari approvernya lewat NAMA role. Role yang belum
| ada di production membuat flow yang memakainya dilewati -- dengan
| peringatan, tetapi tetap dilewati. Jadi role harus lebih dulu, dan namanya
| harus sama persis.
|
| KODE SEBAGAI KUNCI, BUKAN NAMA.
|
| Inilah yang membuat PENGGANTIAN NAMA bekerja. Role yang namanya diubah di
| master -- "Supervisor IT" menjadi "IT Infrastructure SPV", misalnya --
| kodenya tetap. Seeder yang berkunci kode akan MENGGANTI NAMA role yang sama,
| sedangkan yang berkunci nama akan membuat role kedua dan meninggalkan yang
| lama beserta seluruh izin dan penugasannya.
|
| TINGKAT JABATAN IKUT.
|
| Aturan "bawahan tidak menyetujui atasan" membaca roles.level. Bila levelnya
| kosong, aturannya GUGUR DENGAN TENANG: tidak ada galat, tidak ada
| peringatan, dan bawahan kembali bisa menyetujui atasannya. Kolom itu baru
| ada sejak 19 Oktober dan belum pernah sampai ke production, jadi di sana
| hampir pasti masih kosong.
|
| YANG TIDAK DISENTUH.
|
| is_active pada role yang sudah ada. Role yang sengaja dimatikan di
| production tidak boleh hidup kembali hanya karena di sini ia aktif.
| Penugasan role ke akun, izin, dan menu juga tidak disentuh -- itu bukan
| urusan berkas ini.
|--------------------------------------------------------------------------
*/
class ExportRoleSeeder extends Command
{
    protected $signature = 'role:export-seeder
        {--class=RoleMasterSeeder : Nama kelas seeder yang dihasilkan}';

    protected $description = 'Menulis master role (beserta tingkat jabatannya) menjadi seeder';

    public function handle(): int
    {
        $kelas = (string) $this->option('class');

        $roles = DB::table('roles')
            ->orderBy('kode')
            ->get(['kode', 'nama', 'level', 'is_active']);

        $tanpaKode = $roles->filter(fn ($r): bool => trim((string) $r->kode) === '');

        if ($tanpaKode->isNotEmpty()) {
            $this->error('Ada role tanpa kode: ' . $tanpaKode->pluck('nama')->implode(', '));
            $this->line('Kode adalah kuncinya. Lengkapi dulu di master role, lalu ulangi.');

            return self::FAILURE;
        }

        $baris = [];

        foreach ($roles as $r) {
            $baris[] = sprintf(
                "        ['kode' => %s, 'nama' => %s, 'level' => %s],",
                var_export((string) $r->kode, true),
                var_export((string) $r->nama, true),
                $r->level === null ? 'null' : (int) $r->level,
            );
        }

        $isi = $this->berkas($kelas, $baris, $roles->count());

        $path = database_path('seeders/' . $kelas . '.php');

        file_put_contents($path, $isi);

        $this->info("Seeder ditulis: {$path}");
        $this->info("{$roles->count()} role diekspor.");

        $tanpaLevel = $roles->whereNull('level');

        if ($tanpaLevel->isNotEmpty()) {
            $this->warn(
                '  ' . $tanpaLevel->count() . ' role belum punya tingkat: '
                . $tanpaLevel->pluck('nama')->implode(', ')
            );
            $this->line('  Yang kosong dibiarkan kosong di production juga, bukan ditebak.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $baris
     */
    private function berkas(string $kelas, array $baris, int $jumlah): string
    {
        $daftar = implode("\n", $baris);
        $tanggal = now()->format('d F Y H:i');

        return <<<PHP
<?php

namespace Database\\Seeders;

use Illuminate\\Database\\Seeder;
use Illuminate\\Support\\Facades\\DB;

/*
|--------------------------------------------------------------------------
| Master role beserta tingkat jabatannya
|--------------------------------------------------------------------------
| DIHASILKAN OTOMATIS oleh "php artisan role:export-seeder" pada
| {$tanggal}. Jangan disunting tangan -- ekspor ulang saja.
|
| {$jumlah} role.
|
| AMAN DIJALANKAN DI BASIS DATA YANG SUDAH HIDUP.
|
| Kuncinya KODE, bukan nama. Karena itu:
|
|   - kode yang belum ada  -> role baru dibuat
|   - kode yang sudah ada  -> namanya dan tingkatnya diperbarui
|
| Yang kedua itu yang membuat penggantian nama bekerja. Seeder berkunci nama
| akan membuat role kedua dan meninggalkan yang lama beserta seluruh izin,
| penugasan akun, dan baris approval flow yang menunjuknya.
|
| is_active TIDAK disentuh pada role yang sudah ada: role yang sengaja
| dimatikan di production tidak boleh hidup kembali karena berkas ini.
| Penugasan role ke akun, izin, dan menu juga bukan urusan berkas ini.
|
| Tidak ada yang dihapus. Role yang hanya ada di production dibiarkan.
|--------------------------------------------------------------------------
*/
class {$kelas} extends Seeder
{
    /**
     * kode => nama + tingkat jabatan.
     *
     * @var array<int, array{kode: string, nama: string, level: int|null}>
     */
    private const ROLES = [
{$daftar}
    ];

    public function run(): void
    {
        \$now = now();

        \$dibuat = 0;
        \$gantiNama = 0;
        \$gantiLevel = 0;
        \$tetap = 0;

        foreach (self::ROLES as \$role) {
            \$ada = DB::table('roles')->where('kode', \$role['kode'])->first();

            if (!\$ada) {
                DB::table('roles')->insert([
                    'kode' => \$role['kode'],
                    'nama' => \$role['nama'],
                    'level' => \$role['level'],
                    'is_active' => true,
                    'created_at' => \$now,
                    'updated_at' => \$now,
                ]);

                \$this->command?->info("  + {\$role['kode']} = {\$role['nama']} (tingkat " . (\$role['level'] ?? '-') . ')');
                \$dibuat++;

                continue;
            }

            \$ubah = [];

            if ((string) \$ada->nama !== \$role['nama']) {
                \$ubah['nama'] = \$role['nama'];

                \$this->command?->info("  ~ {\$role['kode']}: \\"{\$ada->nama}\\" -> \\"{\$role['nama']}\\"");
                \$gantiNama++;
            }

            /*
            | Tingkat yang kosong di sini TIDAK menghapus tingkat yang sudah
            | ada di sana: yang kosong berarti belum diputuskan, bukan
            | diputuskan kosong.
            */
            if (\$role['level'] !== null && (int) (\$ada->level ?? 0) !== (int) \$role['level']) {
                \$ubah['level'] = \$role['level'];

                \$this->command?->info(
                    "  # {\$role['kode']} tingkat: " . (\$ada->level ?? '-') . " -> {\$role['level']}"
                );
                \$gantiLevel++;
            }

            if (\$ubah === []) {
                \$tetap++;

                continue;
            }

            \$ubah['updated_at'] = \$now;

            DB::table('roles')->where('kode', \$role['kode'])->update(\$ubah);
        }

        \$this->command?->info(
            "[Role] {\$dibuat} dibuat, {\$gantiNama} ganti nama, {\$gantiLevel} ganti tingkat, {\$tetap} tidak berubah."
        );
    }
}

PHP;
    }
}
