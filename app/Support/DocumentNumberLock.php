<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| Kunci deret penomoran dokumen
|--------------------------------------------------------------------------
| Seluruh penomoran di aplikasi ini memakai pola yang sama: lihat nomor yang
| sudah terpakai, lalu ambil berikutnya. Pola itu aman selama hanya satu
| permintaan yang berjalan.
|
| Begitu dua orang submit bersamaan, keduanya membaca keadaan yang sama lalu
| sama-sama menyimpulkan nomor berikutnya yang sama. Yang kedua akan ditolak
| indeks unik -- muncul sebagai error 500 yang tidak dimengerti user, tepat
| saat sistem sedang ramai. Pada tabel tanpa indeks unik, nomornya justru
| benar-benar kembar.
|
| Advisory lock PostgreSQL menyerialkan keduanya: yang kedua menunggu sampai
| yang pertama selesai, lalu membaca keadaan yang sudah diperbarui. Kuncinya
| terikat pada transaksi -- terlepas sendiri saat commit maupun rollback,
| jadi tidak ada kunci yang tertinggal bila terjadi kegagalan di tengah.
|
| Kuncinya per DERET nomor, bukan per tabel: FPU cabang Jakarta tidak
| menghalangi FPU cabang Palembang, karena deret nomornya memang terpisah.
|--------------------------------------------------------------------------
*/
final class DocumentNumberLock
{
    /**
     * Mengunci satu deret nomor sampai transaksi yang berjalan selesai.
     *
     * @param  string  ...$parts  Penyusun identitas deret -- misalnya modul,
     *                            cabang, department, dan tahun.
     */
    public static function acquire(string ...$parts): void
    {
        /*
        | Di luar transaksi, kunci ini dilepas begitu pernyataannya selesai
        | sehingga tidak melindungi apa pun. Lebih baik gagal terang-terangan
        | saat pengembangan daripada memberi rasa aman yang palsu.
        */
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'Penomoran dokumen harus berjalan di dalam transaksi. '
                . 'Di luar transaksi, kunci deret nomor terlepas seketika '
                . 'dan tidak mencegah nomor kembar.',
            );
        }

        /*
        | hashtext memampatkan teks menjadi angka yang dibutuhkan fungsi
        | kuncinya. Dua deret berbeda yang kebetulan berhash sama hanya akan
        | ikut mengantre bergantian -- sedikit lebih lambat, tidak pernah
        | salah.
        */
        DB::statement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            [implode('|', $parts)],
        );
    }
}
