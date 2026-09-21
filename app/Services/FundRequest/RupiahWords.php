<?php

namespace App\Services\FundRequest;

/*
|--------------------------------------------------------------------------
| Terbilang Rupiah
|--------------------------------------------------------------------------
| Dipakai cetakan FPU dan Realisasi FPU. Dibuat sebagai kelas tersendiri agar
| kedua controller tidak menyalin logika yang sama -- berbeda dari PR dan PO
| yang masing-masing punya salinan private-nya sendiri.
|
| Helper global terbilang() pada app/Helpers/NumberHelper.php sengaja tidak
| dipakai: helper itu berhenti di bawah satu miliar dan mengembalikan string
| kosong di atasnya, yang akan membuat cetakan tampak kosong tanpa penjelasan.
|--------------------------------------------------------------------------
*/
final class RupiahWords
{
    private const SATUAN = [
        '',
        'Satu',
        'Dua',
        'Tiga',
        'Empat',
        'Lima',
        'Enam',
        'Tujuh',
        'Delapan',
        'Sembilan',
        'Sepuluh',
        'Sebelas',
    ];

    /**
     * Contoh: 8950000 -> "Delapan Juta Sembilan Ratus Lima Puluh Ribu Rupiah"
     */
    public static function of(float $amount): string
    {
        $words = trim(
            preg_replace('/\s+/', ' ', self::words($amount)) ?? '',
        );

        if ($words === '') {
            $words = 'Nol';
        }

        return $words . ' Rupiah';
    }

    private static function words(float|int $amount): string
    {
        $amount = abs((int) $amount);

        if ($amount < 12) {
            return self::SATUAN[$amount];
        }

        if ($amount < 20) {
            return self::words($amount - 10) . ' Belas';
        }

        if ($amount < 100) {
            return self::words(intdiv($amount, 10)) . ' Puluh ' . self::words($amount % 10);
        }

        if ($amount < 200) {
            return 'Seratus ' . self::words($amount - 100);
        }

        if ($amount < 1000) {
            return self::words(intdiv($amount, 100)) . ' Ratus ' . self::words($amount % 100);
        }

        if ($amount < 2000) {
            return 'Seribu ' . self::words($amount - 1000);
        }

        if ($amount < 1_000_000) {
            return self::words(intdiv($amount, 1000)) . ' Ribu ' . self::words($amount % 1000);
        }

        if ($amount < 1_000_000_000) {
            return self::words(intdiv($amount, 1_000_000)) . ' Juta '
                . self::words($amount % 1_000_000);
        }

        if ($amount < 1_000_000_000_000) {
            return self::words(intdiv($amount, 1_000_000_000)) . ' Miliar '
                . self::words($amount % 1_000_000_000);
        }

        return self::words(intdiv($amount, 1_000_000_000_000)) . ' Triliun '
            . self::words($amount % 1_000_000_000_000);
    }
}
