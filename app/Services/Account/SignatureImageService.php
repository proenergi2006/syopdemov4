<?php

namespace App\Services\Account;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Mengubah foto tanda tangan di atas kertas menjadi PNG berlatar tembus
 * pandang, siap ditempel di cetakan dokumen.
 *
 * Yang dikerjakan, berurutan:
 *
 *  1. Orientasi foto diluruskan dari data EXIF -- foto dari ponsel sering
 *     tersimpan miring dan hanya terlihat benar di galeri.
 *  2. Kecerahan kertas diukur per petak, bukan satu angka untuk seluruh
 *     gambar. Foto kertas hampir selalu terkena bayangan di satu sisi; ambang
 *     tunggal akan menelan tinta di sisi gelap atau meninggalkan kotak abu-abu
 *     di sisi terang.
 *  3. Setiap piksel dibandingkan dengan kecerahan kertas DI SEKITARNYA, lalu
 *     diberi alpha bertingkat. Bertingkat, bukan hitam-putih, supaya tepi
 *     goresan tidak bergerigi saat dicetak.
 *  4. Gambar dipangkas ke kotak tinta, jadi user tidak perlu memotong
 *     fotonya sendiri.
 *
 * Kalau tidak ada tinta yang terbaca, prosesnya berhenti dan mengembalikan
 * kalimat yang menyebut apa yang perlu diperbaiki -- bukan PNG kosong yang
 * baru ketahuan salah setelah muncul di dokumen resmi.
 */
class SignatureImageService
{
    /** Lebar gambar kerja saat tinta dianalisis. */
    private const LEBAR_KERJA = 900;

    /** Lebar file yang akhirnya disimpan. */
    private const LEBAR_SIMPAN = 600;

    /** Nilai alpha GD: 0 pekat, 127 tembus pandang. */
    private const ALPHA_TEMBUS = 127;

    /** Jumlah petak pengukur kecerahan kertas pada tiap sisi. */
    private const PETAK = 16;

    /** Di atas ambang ini, relatif terhadap kertas sekitarnya, dianggap kertas. */
    private const AMBANG_KERTAS = 0.80;

    /** Di bawah ambang ini dianggap tinta pekat. */
    private const AMBANG_TINTA = 0.55;

    /*
    | Batas kepadatan tinta di dalam kotak tinta, SETELAH dipangkas.
    |
    | Diukur setelah dipangkas, bukan pada seluruh bidang foto: sebelum
    | dipangkas, gumpalan gelap seluas apa pun masih terlihat kecil kalau
    | fotonya besar, sehingga pemeriksaan di sana selalu lolos.
    |
    | Tanda tangan adalah goresan tipis: di dalam kotaknya sendiri ia hanya
    | mengisi beberapa persen. Lebih dari batas atas berarti yang tertangkap
    | bukan goresan melainkan bidang gelap; kurang dari batas bawah berarti
    | yang tertangkap hanya bintik derau.
    */
    private const BATAS_PADAT_MAKS = 0.40;
    private const BATAS_PADAT_MIN = 0.01;

    /** Alpha di bawah angka ini dianggap terlihat. */
    private const ALPHA_TERLIHAT = 110;

    /** Sisa ruang di sekeliling tinta setelah dipangkas, dalam piksel. */
    private const MARGIN_PANGKAS = 10;

    /**
     * Foto atau pindaian tanda tangan di atas kertas -> PNG tembus pandang.
     */
    public function dariUnggahan(UploadedFile $file): string
    {
        $gambar = $this->baca(
            (string) file_get_contents($file->getRealPath()),
        );

        $gambar = $this->luruskanOrientasi($gambar, $file->getRealPath());
        $gambar = $this->kecilkan($gambar, self::LEBAR_KERJA);

        /*
        | File yang sudah berlatar tembus pandang -- misalnya hasil aplikasi
        | tanda tangan lain -- tidak perlu diangkat tintanya. Mengangkatnya
        | justru merusak: latar tembus pandang terbaca gelap oleh GD.
        */
        $bersih = $this->sudahTembusPandang($gambar)
            ? $this->samakanKeAlpha($gambar)
            : $this->angkatTinta($gambar);

        return $this->rapikan($bersih);
    }

    /**
     * Tanda tangan yang ditulis langsung di layar. Latarnya sudah tembus
     * pandang sejak dari kanvas, jadi tinggal dipangkas dan dikecilkan.
     */
    public function dariDataUrl(string $dataUrl): string
    {
        if (!preg_match('#^data:image/(png|jpe?g);base64,#i', $dataUrl)) {
            $this->tolak('unreadable_format');
        }

        $base64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
        $biner = base64_decode(strtr($base64, ' ', '+'), true);

        if ($biner === false || $biner === '') {
            $this->tolak('unreadable_format');
        }

        $gambar = $this->baca($biner);
        $gambar = $this->kecilkan($gambar, self::LEBAR_KERJA);

        $bersih = $this->sudahTembusPandang($gambar)
            ? $this->samakanKeAlpha($gambar)
            : $this->angkatTinta($gambar);

        return $this->rapikan($bersih);
    }

    /* ================================================================== */
    /* Pembacaan                                                          */
    /* ================================================================== */

    private function baca(string $biner): GdImage
    {
        if ($biner === '') {
            $this->tolak('unreadable_format');
        }

        $gambar = @imagecreatefromstring($biner);

        if ($gambar === false) {
            $this->tolak('unreadable_format');
        }

        /* Gambar berpalet diubah ke truecolor supaya alpha per piksel mungkin. */
        if (!imageistruecolor($gambar)) {
            imagepalettetotruecolor($gambar);
        }

        return $gambar;
    }

    private function luruskanOrientasi(GdImage $gambar, string $jalur): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $gambar;
        }

        $exif = @exif_read_data($jalur);
        $orientasi = (int) ($exif['Orientation'] ?? 0);

        $sudut = match ($orientasi) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($sudut === 0) {
            return $gambar;
        }

        $diputar = imagerotate($gambar, $sudut, 0);

        if ($diputar === false) {
            return $gambar;
        }

        return $diputar;
    }

    /* ================================================================== */
    /* Pengangkatan tinta                                                 */
    /* ================================================================== */

    /**
     * Mengukur kecerahan kertas per petak, lalu memberi alpha pada tiap
     * piksel berdasarkan seberapa gelap ia dibanding kertas di sekitarnya.
     */
    private function angkatTinta(GdImage $sumber): GdImage
    {
        $lebar = imagesx($sumber);
        $tinggi = imagesy($sumber);

        $kertas = $this->petaKertas($sumber, $lebar, $tinggi);

        $tujuan = $this->kanvasKosong($lebar, $tinggi);

        /* Kurva penggelap tinta, dihitung sekali supaya pow() tidak per piksel. */
        $kurva = [];

        for ($i = 0; $i < 256; $i++) {
            $kurva[$i] = (int) round(255 * ($i / 255) ** 2.2);
        }

        $bobotX = $this->bobotInterpolasi($lebar, self::PETAK);
        $bobotY = $this->bobotInterpolasi($tinggi, self::PETAK);

        $jumlahTinta = 0;

        for ($y = 0; $y < $tinggi; $y++) {
            [$j0, $j1, $ty] = $bobotY[$y];

            $barisAtas = $kertas[$j0];
            $barisBawah = $kertas[$j1];

            for ($x = 0; $x < $lebar; $x++) {
                [$i0, $i1, $tx] = $bobotX[$x];

                /* Kecerahan kertas di titik ini, dari empat petak terdekat. */
                $atas = $barisAtas[$i0] + ($barisAtas[$i1] - $barisAtas[$i0]) * $tx;
                $bawah = $barisBawah[$i0] + ($barisBawah[$i1] - $barisBawah[$i0]) * $tx;
                $acuan = max(1.0, $atas + ($bawah - $atas) * $ty);

                $rgb = imagecolorat($sumber, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $terang = (77 * $r + 150 * $g + 29 * $b) >> 8;
                $nisbah = $terang / $acuan;

                if ($nisbah >= self::AMBANG_KERTAS) {
                    continue;
                }

                /* Alpha bertingkat di antara dua ambang, bukan hitam-putih. */
                $pekat = $nisbah <= self::AMBANG_TINTA
                    ? 1.0
                    : (self::AMBANG_KERTAS - $nisbah)
                        / (self::AMBANG_KERTAS - self::AMBANG_TINTA);

                /*
                | Warna pena dipertahankan, hanya dipertegas: pulpen biru tetap
                | biru. Tiap kanal dibagi kecerahan kertas setempat lebih dulu,
                | supaya bayangan tidak ikut membuat tinta terlihat kusam.
                */
                $skala = 255 / $acuan;

                $warna = ($this->alphaGd($pekat) << 24)
                    | ($kurva[min(255, (int) ($r * $skala))] << 16)
                    | ($kurva[min(255, (int) ($g * $skala))] << 8)
                    | $kurva[min(255, (int) ($b * $skala))];

                imagesetpixel($tujuan, $x, $y, $warna);

                if ($pekat > 0.5) {
                    $jumlahTinta++;
                }
            }
        }

        if ($jumlahTinta === 0) {
            $this->tolak('ink_not_found');
        }

        return $tujuan;
    }

    /**
     * Kecerahan kertas per petak.
     *
     * Dipakai persentil 90, bukan nilai tertinggi: satu piksel kilau cahaya
     * tidak boleh menentukan acuan seluruh petak. Petak yang seluruhnya
     * tertutup tinta mewarisi acuan dari tetangganya lewat interpolasi.
     */
    private function petaKertas(GdImage $gambar, int $lebar, int $tinggi): array
    {
        $lebarPetak = max(1, (int) ceil($lebar / self::PETAK));
        $tinggiPetak = max(1, (int) ceil($tinggi / self::PETAK));

        $peta = [];

        for ($by = 0; $by < self::PETAK; $by++) {
            for ($bx = 0; $bx < self::PETAK; $bx++) {
                $x0 = $bx * $lebarPetak;
                $y0 = $by * $tinggiPetak;
                $x1 = min($lebar, $x0 + $lebarPetak);
                $y1 = min($tinggi, $y0 + $tinggiPetak);

                if ($x0 >= $x1 || $y0 >= $y1) {
                    $peta[$by][$bx] = 255.0;

                    continue;
                }

                $histogram = array_fill(0, 256, 0);
                $cuplikan = 0;

                /* Dicuplik tiap 2 piksel: acuannya kasar, tidak perlu teliti. */
                for ($y = $y0; $y < $y1; $y += 2) {
                    for ($x = $x0; $x < $x1; $x += 2) {
                        $rgb = imagecolorat($gambar, $x, $y);

                        $terang = (
                            77 * (($rgb >> 16) & 0xFF)
                            + 150 * (($rgb >> 8) & 0xFF)
                            + 29 * ($rgb & 0xFF)
                        ) >> 8;

                        $histogram[$terang]++;
                        $cuplikan++;
                    }
                }

                $peta[$by][$bx] = $this->persentil($histogram, $cuplikan, 0.90);
            }
        }

        return $peta;
    }

    private function persentil(array $histogram, int $jumlah, float $bagian): float
    {
        $batas = (int) ceil($jumlah * $bagian);
        $berjalan = 0;

        for ($i = 0; $i < 256; $i++) {
            $berjalan += $histogram[$i];

            if ($berjalan >= $batas) {
                return max(1.0, (float) $i);
            }
        }

        return 255.0;
    }

    /**
     * Untuk setiap baris atau kolom piksel: dua petak terdekat dan bobotnya.
     * Dihitung sekali per sumbu, bukan sekali per piksel.
     */
    private function bobotInterpolasi(int $panjang, int $petak): array
    {
        $ukuran = max(1, (int) ceil($panjang / $petak));
        $bobot = [];

        for ($p = 0; $p < $panjang; $p++) {
            $titik = $p / $ukuran - 0.5;
            $titik = max(0.0, min($petak - 1, $titik));

            $i0 = (int) floor($titik);
            $i1 = min($petak - 1, $i0 + 1);

            $bobot[$p] = [$i0, $i1, $titik - $i0];
        }

        return $bobot;
    }

    /**
     * Memastikan yang tertangkap memang goresan, bukan bidang gelap atau
     * bintik derau. Dijalankan pada gambar yang sudah dipangkas ke kotak
     * tintanya, karena di situlah kepadatan punya arti.
     */
    private function periksaKepadatan(GdImage $gambar): void
    {
        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $jumlahPiksel = $lebar * $tinggi;

        if ($jumlahPiksel === 0) {
            $this->tolak('ink_not_found');
        }

        $terlihat = 0;

        for ($y = 0; $y < $tinggi; $y++) {
            for ($x = 0; $x < $lebar; $x++) {
                if ((imagecolorat($gambar, $x, $y) >> 24) < self::ALPHA_TERLIHAT) {
                    $terlihat++;
                }
            }
        }

        $padat = $terlihat / $jumlahPiksel;

        if ($padat > self::BATAS_PADAT_MAKS) {
            $this->tolak('background_too_dark');
        }

        if ($padat < self::BATAS_PADAT_MIN) {
            $this->tolak('ink_not_found');
        }
    }

    /* ================================================================== */
    /* Gambar yang sudah tembus pandang                                   */
    /* ================================================================== */

    /**
     * Mencuplik 400 titik. Kalau seperempatnya tembus pandang, gambar ini
     * sudah bersih sejak awal dan tidak perlu diangkat tintanya.
     */
    private function sudahTembusPandang(GdImage $gambar): bool
    {
        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);

        $langkahX = max(1, (int) ($lebar / 20));
        $langkahY = max(1, (int) ($tinggi / 20));

        $cuplikan = 0;
        $tembus = 0;

        for ($y = 0; $y < $tinggi; $y += $langkahY) {
            for ($x = 0; $x < $lebar; $x += $langkahX) {
                $cuplikan++;

                if ((imagecolorat($gambar, $x, $y) >> 24) > 100) {
                    $tembus++;
                }
            }
        }

        return $cuplikan > 0 && ($tembus / $cuplikan) > 0.25;
    }

    /**
     * Menyalin apa adanya ke kanvas beralpha, sambil memastikan goresan yang
     * nyaris tak terlihat tidak lolos sebagai tinta.
     */
    private function samakanKeAlpha(GdImage $sumber): GdImage
    {
        $lebar = imagesx($sumber);
        $tinggi = imagesy($sumber);

        $tujuan = $this->kanvasKosong($lebar, $tinggi);

        $jumlahTinta = 0;

        for ($y = 0; $y < $tinggi; $y++) {
            for ($x = 0; $x < $lebar; $x++) {
                $rgb = imagecolorat($sumber, $x, $y);
                $alpha = ($rgb >> 24) & 0x7F;

                if ($alpha >= self::ALPHA_TEMBUS) {
                    continue;
                }

                imagesetpixel($tujuan, $x, $y, $rgb);

                if ($alpha < 64) {
                    $jumlahTinta++;
                }
            }
        }

        if ($jumlahTinta === 0) {
            $this->tolak('ink_not_found');
        }

        return $tujuan;
    }

    /* ================================================================== */
    /* Pemangkasan, pengecilan, penyandian                                */
    /* ================================================================== */

    private function rapikan(GdImage $gambar): string
    {
        $dipangkas = $this->potongKeTinta($gambar);

        $this->periksaKepadatan($dipangkas);

        return $this->kePng(
            $this->kecilkan($dipangkas, self::LEBAR_SIMPAN),
        );
    }

    private function potongKeTinta(GdImage $gambar): GdImage
    {
        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);

        $kiri = $lebar;
        $atas = $tinggi;
        $kanan = -1;
        $bawah = -1;

        for ($y = 0; $y < $tinggi; $y++) {
            for ($x = 0; $x < $lebar; $x++) {
                if ((imagecolorat($gambar, $x, $y) >> 24) >= self::ALPHA_TERLIHAT) {
                    continue;
                }

                if ($x < $kiri) {
                    $kiri = $x;
                }

                if ($x > $kanan) {
                    $kanan = $x;
                }

                if ($y < $atas) {
                    $atas = $y;
                }

                if ($y > $bawah) {
                    $bawah = $y;
                }
            }
        }

        if ($kanan < 0 || $bawah < 0) {
            $this->tolak('ink_not_found');
        }

        $kiri = max(0, $kiri - self::MARGIN_PANGKAS);
        $atas = max(0, $atas - self::MARGIN_PANGKAS);
        $kanan = min($lebar - 1, $kanan + self::MARGIN_PANGKAS);
        $bawah = min($tinggi - 1, $bawah + self::MARGIN_PANGKAS);

        $lebarBaru = $kanan - $kiri + 1;
        $tinggiBaru = $bawah - $atas + 1;

        $tujuan = $this->kanvasKosong($lebarBaru, $tinggiBaru);

        imagecopy($tujuan, $gambar, 0, 0, $kiri, $atas, $lebarBaru, $tinggiBaru);

        return $tujuan;
    }

    private function kecilkan(GdImage $gambar, int $lebarMaks): GdImage
    {
        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);

        if ($lebar <= $lebarMaks) {
            return $gambar;
        }

        $lebarBaru = $lebarMaks;
        $tinggiBaru = max(1, (int) round($tinggi * $lebarMaks / $lebar));

        $tujuan = $this->kanvasKosong($lebarBaru, $tinggiBaru);

        imagecopyresampled(
            $tujuan,
            $gambar,
            0,
            0,
            0,
            0,
            $lebarBaru,
            $tinggiBaru,
            $lebar,
            $tinggi,
        );

        return $tujuan;
    }

    private function kanvasKosong(int $lebar, int $tinggi): GdImage
    {
        $kanvas = imagecreatetruecolor(max(1, $lebar), max(1, $tinggi));

        imagealphablending($kanvas, false);
        imagesavealpha($kanvas, true);

        $kosong = imagecolorallocatealpha($kanvas, 255, 255, 255, self::ALPHA_TEMBUS);

        imagefilledrectangle($kanvas, 0, 0, max(1, $lebar) - 1, max(1, $tinggi) - 1, $kosong);

        return $kanvas;
    }

    private function kePng(GdImage $gambar): string
    {
        imagesavealpha($gambar, true);

        ob_start();
        imagepng($gambar, null, 9);

        return (string) ob_get_clean();
    }

    private function alphaGd(float $pekat): int
    {
        $alpha = (int) round(self::ALPHA_TEMBUS * (1 - $pekat));

        return max(0, min(self::ALPHA_TEMBUS - 1, $alpha));
    }

    /**
     * @return never
     */
    private function tolak(string $kunci): void
    {
        throw ValidationException::withMessages([
            'file' => [__('signature_messages.image.' . $kunci)],
        ]);
    }
}
