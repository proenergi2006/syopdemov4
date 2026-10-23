<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/*
|--------------------------------------------------------------------------
| Satu perintah untuk naik ke production: matriks approval 4 modul
|--------------------------------------------------------------------------
| FPU, Realisasi FPU, Claim, dan Perjalanan Dinas.
|
| PR dan PO TIDAK disentuh sama sekali -- tidak ada satu pun flow kedua modul
| itu di dalam berkas yang dipanggil di sini.
|
| URUTANNYA BUKAN SELERA.
|
| Seeder approval flow mencari approvernya lewat NAMA role dan USERNAME akun.
| Role yang belum ada membuat flow yang memakainya DILEWATI -- dengan
| peringatan, tetapi tetap dilewati. Karena itu master role harus lebih dulu,
| dan tingkat jabatannya ikut di sana: aturan "bawahan tidak menyetujui
| atasan" membaca roles.level, dan bila kosong aturannya gugur tanpa pesan
| apa pun.
|
| AKUN TIDAK DIBUAT DI SINI.
|
| Seluruh akun yang dipakai matriks ini sudah ada di production. Yang
| dibutuhkan hanya USERNAME-nya sama -- id-nya boleh berbeda, dan memang
| berbeda. Tidak ada satu pun id di dalam seeder-seeder ini.
|
| SEBELUM MENJALANKAN, PERIKSA DULU:
|
|   php artisan approval-flow:preflight
|
| Ia tidak menulis apa pun, dan menyebut acuan yang belum ada beserta
| lingkup yang bertabrakan -- dua hal yang tidak akan menggagalkan seeder,
| hanya membuat hasilnya bolong atau kembar.
|--------------------------------------------------------------------------
*/
class FundRequestApprovalDeploySeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            /*
            | Role lebih dulu: nama role adalah kunci yang dipakai matriks
            | approval untuk menemukan approvernya, dan tingkat jabatannya
            | ikut diisi di sini.
            */
            RoleMasterSeeder::class,

            /*
            | Matriks approval FPU, Realisasi, Claim, dan Perdin -- 143 flow.
            | Hanya menambah: flow yang sudah ada dengan nama dan lingkup
            | yang sama dilewati.
            */
            FundRequestApprovalFlowSeeder::class,
        ]);
    }
}
