<?php

namespace App\Http\Middleware;

use App\Support\LineAttachment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Menerjemahkan kegagalan unggah di tingkat PHP
|--------------------------------------------------------------------------
| Ketika PHP gagal menerima sebuah file, Laravel menyerah lebih dulu sebelum
| satu pun aturan validasi dijalankan -- lihat Validator::validateAttribute():
|
|     if ($value instanceof UploadedFile && ! $value->isValid() && ...) {
|         return $this->addFailure($attribute, 'uploaded', []);
|     }
|
| Yang sampai ke layar adalah "attachments.1 gagal diunggah." Kalimat itu
| tidak memberi tahu siapa pun apa-apa: tidak menyebut file yang mana, tidak
| menyebut sebabnya, dan tidak memberi petunjuk apa yang harus dilakukan.
| Orang yang membacanya harus bertanya ke IT hanya untuk tahu artinya.
|
| PADAHAL PHP SUDAH MENYIMPAN SEBABNYA. Setiap file yang gagal membawa kode
| galat -- melewati upload_max_filesize, terputus di tengah jalan, direktori
| temp hilang, disk penuh. Middleware ini membaca kode itu dan menggantinya
| dengan kalimat yang menyebut nama filenya dan langkah berikutnya.
|
| DIPASANG SEKALI UNTUK SELURUH APLIKASI.
|
| Lampiran divalidasi di lebih dari dua puluh tempat -- FPU, Realisasi, Claim,
| Perdin, PR, PO, Goods Receipt, Goods Return, dan beberapa master. Menambal
| satu per satu berarti dua puluh kesempatan untuk terlewat, dan tempat yang
| ke-21 akan lahir tanpa penjelasan lagi. Diperiksa di sini, sebelum request
| menyentuh controller mana pun.
|
| TIDAK MENGUBAH ATURAN APA PUN.
|
| File yang berhasil diunggah lewat tanpa disentuh. Batas ukuran aplikasi
| (max:3072 dan sejenisnya) tetap dijalankan oleh validator seperti biasa --
| yang dijelaskan di sini hanya file yang bahkan belum sampai ke sana.
|--------------------------------------------------------------------------
*/
class ExplainUploadFailure
{
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $gagal = $this->kumpulkan($request->allFiles());

        if ($gagal !== []) {
            throw ValidationException::withMessages($gagal);
        }

        $this->pastikanTidakTerpotong($request);

        return $next($request);
    }

    /**
     * Memastikan tidak ada berkas yang hilang tanpa jejak di perjalanan.
     *
     * KENAPA INI PERLU PEMERIKSAAN TERSENDIRI.
     *
     * Semua kegagalan di atas punya kode galat yang bisa dibaca. Yang satu
     * ini tidak. PHP membatasi jumlah file per pengiriman lewat
     * max_file_uploads -- bawaannya 20 -- dan file selebihnya tidak ditolak,
     * melainkan TIDAK ADA. $_FILES berhenti di file ke-20, tanpa entri,
     * tanpa kode galat, tanpa apa pun yang tersisa untuk diperiksa.
     *
     * Akibatnya terukur: dua puluh lima lampiran dikirim, dua puluh
     * tersimpan, jawabannya "201 berhasil disimpan", dan lima bukti
     * pengeluaran hilang tanpa seorang pun diberi tahu.
     *
     * Satu-satunya pembandingnya adalah angka yang DINYATAKAN pengirim.
     * Layar menyertakannya; yang tidak menyertakannya lewat seperti biasa,
     * jadi pemanggil lama tidak terpengaruh.
     *
     * @throws ValidationException
     */
    private function pastikanTidakTerpotong(Request $request): void
    {
        $dinyatakan = $request->input(LineAttachment::DECLARED_COUNT_FIELD);

        if ($dinyatakan === null || !is_numeric($dinyatakan)) {
            return;
        }

        $dinyatakan = (int) $dinyatakan;
        $diterima = LineAttachment::count($request->allFiles());

        if ($diterima >= $dinyatakan) {
            return;
        }

        $batas = (int) ini_get('max_file_uploads');

        /*
        | Dibedakan dengan sengaja. Bila yang sampai tepat sebanyak batas
        | server, sebabnya pasti batas itu, dan pengaju bisa mengatasinya
        | sendiri -- kurangi lampirannya, atau kirim sisanya lewat Ubah.
        |
        | Bila tidak, sebabnya ada di perjalanan, dan menyebut batas server
        | justru menyesatkan: ia akan sibuk mengurangi lampiran padahal yang
        | bermasalah koneksinya.
        */
        $pesan = ($batas > 0 && $diterima === $batas)
            ? __('upload.truncated.server_limit', [
                'limit' => $batas,
                'sent' => $dinyatakan,
                'lost' => $dinyatakan - $diterima,
            ])
            : __('upload.truncated.unknown', [
                'sent' => $dinyatakan,
                'received' => $diterima,
            ]);

        throw ValidationException::withMessages([
            'line_attachments' => [$pesan],
        ]);
    }

    /**
     * Berkas gagal beserta kalimatnya, berkunci nama atribut.
     *
     * Kuncinya ditulis bertitik -- "attachments.1" -- supaya sama persis
     * dengan yang dipakai validator, sehingga layar yang sudah menyorot field
     * tertentu tetap menyorot field yang sama.
     *
     * @param  array<string, mixed>  $berkas
     * @return array<string, array<int, string>>
     */
    private function kumpulkan(array $berkas, string $awalan = ''): array
    {
        $gagal = [];

        foreach ($berkas as $kunci => $isi) {
            $nama = $awalan === '' ? (string) $kunci : $awalan . '.' . $kunci;

            if (is_array($isi)) {
                foreach ($this->kumpulkan($isi, $nama) as $anak => $pesan) {
                    $gagal[$anak] = $pesan;
                }

                continue;
            }

            if (!$isi instanceof UploadedFile) {
                continue;
            }

            $kode = $isi->getError();

            /*
            | UPLOAD_ERR_NO_FILE bukan kegagalan: field filenya memang
            | dibiarkan kosong, dan aturan "nullable" yang berhak memutuskan
            | apakah itu masalah.
            */
            if ($kode === UPLOAD_ERR_OK || $kode === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $gagal[$nama] = [$this->pesan($isi, $kode)];
        }

        return $gagal;
    }

    private function pesan(UploadedFile $berkas, int $kode): string
    {
        $nama = $berkas->getClientOriginalName() ?: $berkas->getFilename();

        $kunci = match ($kode) {
            UPLOAD_ERR_INI_SIZE => 'ini_size',
            UPLOAD_ERR_FORM_SIZE => 'form_size',
            UPLOAD_ERR_PARTIAL => 'partial',
            UPLOAD_ERR_NO_TMP_DIR => 'no_tmp_dir',
            UPLOAD_ERR_CANT_WRITE => 'cant_write',
            UPLOAD_ERR_EXTENSION => 'extension',
            default => 'unknown',
        };

        return __('upload.failed.' . $kunci, [
            'file' => $nama,
            'limit' => self::batasPerBerkas(),
            'code' => $kode,
        ]);
    }

    /**
     * Batas unggah per file yang berlaku di server ini, sudah terbaca orang.
     */
    public static function batasPerBerkas(): string
    {
        return self::ukuranTerbaca(UploadedFile::getMaxFilesize());
    }

    /**
     * Batas satu kali pengiriman yang berlaku di server ini.
     *
     * Berbeda dari batas per file: yang ini menghitung SELURUH isi formulir
     * sekaligus. Tiga lampiran 2 MB bisa lolos satu per satu tetapi tetap
     * tertolak bersama-sama.
     */
    public static function batasSatuPengiriman(): string
    {
        $mentah = ini_get('post_max_size');

        /* 0 atau kosong berarti tidak dibatasi. */
        if ($mentah === false || trim((string) $mentah) === '' || (int) $mentah === 0) {
            return self::ukuranTerbaca(0);
        }

        return self::ukuranTerbaca(self::keBita((string) $mentah));
    }

    /**
     * Angka php.ini bergaya "2M" / "512K" / "1G" menjadi bita.
     */
    private static function keBita(string $nilai): int
    {
        $nilai = trim($nilai);
        $angka = (int) $nilai;

        return match (strtolower(substr($nilai, -1))) {
            'g' => $angka * 1024 * 1024 * 1024,
            'm' => $angka * 1024 * 1024,
            'k' => $angka * 1024,
            default => $angka,
        };
    }

    private static function ukuranTerbaca(int|float $bita): string
    {
        if ($bita <= 0) {
            return __('upload.unlimited');
        }

        foreach (['B', 'KB', 'MB', 'GB'] as $satuan) {
            if ($bita < 1024 || $satuan === 'GB') {
                return rtrim(rtrim(number_format((float) $bita, 2, '.', ''), '0'), '.')
                    . ' ' . $satuan;
            }

            $bita /= 1024;
        }

        return $bita . ' GB';
    }
}
