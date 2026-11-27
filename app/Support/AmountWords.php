<?php

namespace App\Support;

use App\Services\FundRequest\RupiahWords;

/*
|--------------------------------------------------------------------------
| Terbilang dua bahasa
|--------------------------------------------------------------------------
| Lahir bersama cetakan BDV, yang berbahasa Indonesia atau Inggris menurut
| pilihan pencetaknya. Cetakan lain hanya berbahasa Indonesia dan tetap
| memanggil RupiahWords langsung -- tidak ada yang perlu diubah di sana.
|
| SISI INDONESIANYA TIDAK DITULIS ULANG. Ia meneruskan ke RupiahWords, yang
| sudah dipakai cetakan FPU, Realisasi, dan Claim sejak lama. Menyalinnya ke
| sini berarti dua terbilang yang berawal sama lalu berselisih pada angka
| yang jarang muncul -- dan selisih semacam itu baru ketahuan di atas
| dokumen keuangan yang sudah ditandatangani.
|
| Keduanya berakhir dengan satuan mata uang yang sama, "Rupiah": yang
| diterjemahkan bahasanya, bukan uangnya.
|--------------------------------------------------------------------------
*/
final class AmountWords
{
    private const SATUAN_EN = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight',
        'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen',
        'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const PULUHAN_EN = [
        2 => 'Twenty',
        3 => 'Thirty',
        4 => 'Forty',
        5 => 'Fifty',
        6 => 'Sixty',
        7 => 'Seventy',
        8 => 'Eighty',
        9 => 'Ninety',
    ];

    /**
     * Skala pendek, sesuai yang dipakai laporan keuangan berbahasa Inggris
     * di Indonesia: 1.000.000.000 = Billion, bukan Milliard.
     */
    private const SKALA_EN = [
        1_000_000_000_000 => 'Trillion',
        1_000_000_000 => 'Billion',
        1_000_000 => 'Million',
        1_000 => 'Thousand',
    ];

    /**
     * @param  string  $locale  'en' untuk Inggris; selain itu Indonesia.
     */
    public static function of(float $amount, string $locale = 'id'): string
    {
        return strtolower(trim($locale)) === 'en'
            ? self::inggris($amount)
            : RupiahWords::of($amount);
    }

    private static function inggris(float $amount): string
    {
        $kata = trim(preg_replace('/\s+/', ' ', self::kataInggris($amount)) ?? '');

        if ($kata === '') {
            $kata = 'Zero';
        }

        return $kata . ' Rupiah';
    }

    private static function kataInggris(float|int $amount): string
    {
        $amount = abs((int) $amount);

        if ($amount < 20) {
            return self::SATUAN_EN[$amount];
        }

        if ($amount < 100) {
            $sisa = $amount % 10;

            return self::PULUHAN_EN[intdiv($amount, 10)]
                . ($sisa > 0 ? '-' . self::SATUAN_EN[$sisa] : '');
        }

        if ($amount < 1000) {
            return self::SATUAN_EN[intdiv($amount, 100)] . ' Hundred '
                . self::kataInggris($amount % 100);
        }

        foreach (self::SKALA_EN as $nilai => $nama) {
            if ($amount >= $nilai) {
                return self::kataInggris(intdiv($amount, $nilai)) . ' ' . $nama . ' '
                    . self::kataInggris($amount % $nilai);
            }
        }

        /*
        | Tidak terjangkau: seluruh angka di bawah seribu sudah ditangani di
        | atas, dan yang di atasnya pasti kena salah satu skala. Ditulis
        | supaya fungsinya tetap mengembalikan string pada jalur mana pun.
        */
        return '';
    }
}
