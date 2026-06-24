
<?php

if (!function_exists('terbilang')) {

    function terbilang($x)
    {
        $x = abs((int)$x);

        $satuan = [
            1 => "Satu", "Dua", "Tiga", "Empat", "Lima",
            "Enam", "Tujuh", "Delapan", "Sembilan", "Sepuluh", "Sebelas"
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