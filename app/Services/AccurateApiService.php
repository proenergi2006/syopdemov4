<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AccurateApiService
{
    /*
    |--------------------------------------------------------------------------
    | Pembatas laju permintaan ke Accurate
    |--------------------------------------------------------------------------
    | Accurate Online membatasi sekitar 8 permintaan per detik, dan melewatinya
    | berisiko membuat akun kita diblokir -- bukan sekadar permintaan itu yang
    | ditolak, melainkan seluruh sambungan aplikasi ini ke Accurate.
    |
    | Pembatasnya diletakkan di sini, bukan di layar, karena di sinilah semua
    | permintaan bertemu. Jeda di sisi layar hanya menahan SATU pengguna; yang
    | membuat batas itu terlewati adalah banyak pengguna sekaligus, ditambah
    | proses latar seperti Goods Receipt dan Gain Loss yang juga memanggil
    | Accurate tanpa ada orang yang menunggui.
    |
    | Hitungannya memakai cache dengan kunci per detik. Driver cache di sini
    | adalah file, yang penambahannya tidak dijamin atomik antar proses, jadi
    | angkanya bisa meleset satu-dua pada saat ramai. Karena itu batasnya
    | dipasang di bawah 8 -- selisihnya sengaja, sebagai ruang aman.
    |--------------------------------------------------------------------------
    */

    /** Paling lama menunggu giliran, dalam detik. */
    private const TUNGGU_MAKS = 2.0;

    /** Jeda tiap kali memeriksa ulang, dalam mikrodetik. */
    private const JEDA_PERIKSA = 120000;

    private function getSignature($datenow)
    {
        $secret = config('services.accurate.secret');

        $hmac = hash_hmac('sha256', $datenow, $secret, true);

        return base64_encode($hmac);
    }

    private function headers()
    {
        $datenow = now()->format('d/m/Y H:i:s');

        return [
            'Content-Type' => 'application/json',
            'X-Api-Timestamp' => $datenow,
            'X-Api-Signature' => $this->getSignature($datenow),
            'Authorization' => 'Bearer ' . config('services.accurate.token'),
        ];
    }

    /**
     * Menunggu sampai ada jatah permintaan pada detik berjalan.
     *
     * Mengembalikan false bila sampai batas waktu tidak kebagian. Pemanggil
     * tidak boleh melanjutkan permintaannya -- menunggu lebih lama hanya
     * menumpuk antrean, dan menerobos justru hal yang ingin dihindari.
     */
    private function ambilGiliran(): bool
    {
        $batas = (int) config('services.accurate.max_per_second', 6);

        if ($batas <= 0) {
            return true;
        }

        $tenggat = microtime(true) + self::TUNGGU_MAKS;

        do {
            $detik = (int) floor(microtime(true));

            /*
            | DUA EMBER, BUKAN SATU.
            |
            | Menghitung per detik kalender saja tidak cukup, dan ini terbukti
            | saat diukur: enam permintaan di ujung detik pertama lalu enam
            | lagi di awal detik berikutnya menghasilkan dua belas permintaan
            | dalam rentang satu detik yang sebenarnya -- 8,26 per detik pada
            | pengukuran, padahal batasnya enam.
            |
            | Yang dijumlahkan karena itu detik berjalan DAN detik sebelumnya.
            | Dengan begitu tidak ada satu detik berjalan mana pun yang bisa
            | memuat lebih dari batasnya. Hitungan ini memang lebih ketat dari
            | perlunya -- laju mantapnya jadi sekitar setengah batas -- dan itu
            | pilihan yang disengaja: diblokir Accurate jauh lebih mahal
            | daripada dropdown yang terisi sepersekian detik lebih lambat.
            */
            $kunci = 'accurate:laju:' . $detik;
            $kunciSebelumnya = 'accurate:laju:' . ($detik - 1);

            $sebelumnya = (int) (Cache::get($kunciSebelumnya) ?? 0);

            /*
            | add() dulu supaya kuncinya pasti ada sebelum increment, dan
            | umurnya 3 detik supaya kunci detik yang sudah lewat masih sempat
            | terbaca sebagai "detik sebelumnya", lalu hilang sendiri.
            */
            Cache::add($kunci, 0, 3);

            if ($sebelumnya + (int) Cache::increment($kunci) <= $batas) {
                return true;
            }

            /*
            | Sudah penuh pada detik ini. Ditunggu sebentar -- detiknya akan
            | berganti, dan kuncinya ikut berganti.
            */
            usleep(self::JEDA_PERIKSA);
        } while (microtime(true) < $tenggat);

        Log::warning('[Accurate] Permintaan ditahan: jatah per detik habis', [
            'batas_per_detik' => $batas,
            'menunggu_detik' => self::TUNGGU_MAKS,
        ]);

        return false;
    }

    /**
     * Jawaban "gagal" yang bentuknya sama dengan jawaban gagal dari Accurate.
     *
     * Disamakan dengan sengaja: seluruh pemanggil sudah memeriksa `s`, jadi
     * tidak ada satu pun yang perlu diubah untuk menangani keadaan ini.
     *
     * @return array<string, mixed>
     */
    private function jawabanDitahan(): array
    {
        return [
            's' => false,
            'd' => ['Permintaan ke Accurate sedang padat. Coba lagi sebentar.'],
        ];
    }

    //GET API
    public function get($url)
    {
        if (!$this->ambilGiliran()) {
            return $this->jawabanDitahan();
        }

        $response = Http::withHeaders($this->headers())
            ->get($url);

        return $response->json();
    }

    // POST API
    public function post($url, $data)
    {
        if (!$this->ambilGiliran()) {
            return $this->jawabanDitahan();
        }

        $response = Http::withHeaders($this->headers())
            ->post($url, $data);

        return $response->json();
    }

    // DELETE
    public function delete($url, $data = [])
    {
        if (!$this->ambilGiliran()) {
            return $this->jawabanDitahan();
        }

        $response = Http::withHeaders($this->headers())
            ->delete($url, $data);

        return $response->json();
    }

    // =========================
    // HTTP STATUS (replace get_http_status)
    // =========================
    public function status($url)
    {
        $response = Http::head($url);

        return $response->status();
    }
}
