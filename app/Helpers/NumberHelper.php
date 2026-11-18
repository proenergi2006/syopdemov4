<?php

if (!function_exists('terbilang')) {

    function terbilang($x)
    {
        $x = abs((int)$x);

        $satuan = [
            1 => "Satu",
            "Dua",
            "Tiga",
            "Empat",
            "Lima",
            "Enam",
            "Tujuh",
            "Delapan",
            "Sembilan",
            "Sepuluh",
            "Sebelas"
        ];

        if ($x < 12) return $satuan[$x] ?? '';

        if ($x < 20) return terbilang($x - 10) . " Belas";

        if ($x < 100) return terbilang($x / 10) . " Puluh " . terbilang($x % 10);

        if ($x < 200) return "Seratus " . terbilang($x - 100);

        if ($x < 1000) return terbilang($x / 100) . " Ratus " . terbilang($x % 100);

        if ($x < 2000) return "Seribu " . terbilang($x - 1000);

        if ($x < 1000000) return terbilang($x / 1000) . " Ribu " . terbilang($x % 1000);

        if ($x < 1000000000) return terbilang($x / 1000000) . " Juta " . terbilang($x % 1000000);

        return '';
    }
}

if (!function_exists('terbilang_inggris')) {

    function terbilang_inggris($x)
    {
        $x = abs((int) $x);

        $units = [
            1 => "One",
            "Two",
            "Three",
            "Four",
            "Five",
            "Six",
            "Seven",
            "Eight",
            "Nine",
            "Ten",
            "Eleven",
            "Twelve",
        ];

        $tens = [
            2 => "Twenty",
            "Thirty",
            "Forty",
            "Fifty",
            "Sixty",
            "Seventy",
            "Eighty",
            "Ninety",
        ];

        if ($x == 0)
            return "Zero";

        if ($x < 13)
            return $units[$x];

        if ($x < 20)
            return terbilang_inggris($x - 10) . " Teen";

        if ($x < 100)
            return trim(
                $tens[(int)($x / 10)] . " " .
                    terbilang_inggris($x % 10)
            );

        if ($x < 1000)
            return trim(
                terbilang_inggris((int)($x / 100))
                    . " Hundred "
                    . terbilang_inggris($x % 100)
            );

        if ($x < 1000000)
            return trim(
                terbilang_inggris((int)($x / 1000))
                    . " Thousand "
                    . terbilang_inggris($x % 1000)
            );

        if ($x < 1000000000)
            return trim(
                terbilang_inggris((int)($x / 1000000))
                    . " Million "
                    . terbilang_inggris($x % 1000000)
            );

        if ($x < 1000000000000)
            return trim(
                terbilang_inggris((int)($x / 1000000000))
                    . " Billion "
                    . terbilang_inggris($x % 1000000000)
            );

        return '';
    }
}

/*
|--------------------------------------------------------------------------
| Format angka -- satu tempat untuk seluruh keluaran PHP
|--------------------------------------------------------------------------
| Pemisah ribuan koma, pemisah desimal titik: 1,250,000.75
|
| Pasangannya di sisi layar adalah resources/ts/utils/numberFormatter.ts.
| Keduanya harus menulis angka yang sama persis: orang yang mencetak sebuah
| dokumen sedang mencocokkannya dengan yang baru saja dilihatnya di layar,
| dan dua cara menulis angka membuat pencocokan itu gagal tanpa sebab.
|
| Bila formatnya berubah lagi, yang diubah cukup dua tetapan di bawah.
|--------------------------------------------------------------------------
*/

if (!defined('ANGKA_PEMISAH_RIBUAN')) {
    define('ANGKA_PEMISAH_RIBUAN', ',');
    define('ANGKA_PEMISAH_DESIMAL', '.');
}

if (!function_exists('angka')) {

    /**
     * Angka tanpa lambang mata uang: 1,250,000.75
     */
    function angka($nilai, int $desimal = 0): string
    {
        return number_format(
            (float) ($nilai ?? 0),
            $desimal,
            ANGKA_PEMISAH_DESIMAL,
            ANGKA_PEMISAH_RIBUAN
        );
    }
}

if (!function_exists('rupiah')) {

    /**
     * Nominal uang beserta lambangnya: Rp 1,250,000
     *
     * Tanda minus ditulis sebelum lambangnya ("-Rp 50,000"), bukan di antara
     * lambang dan angkanya, supaya terbacanya tetap satu nominal utuh.
     */
    function rupiah($nilai, int $desimal = 0): string
    {
        $angka = (float) ($nilai ?? 0);
        $tanda = $angka < 0 ? '-' : '';

        return $tanda . 'Rp ' . angka(abs($angka), $desimal);
    }
}
