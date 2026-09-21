<?php

namespace App\Http\Controllers\Concerns;

use App\Services\FundRequest\PaymentScheduleService;
use Carbon\CarbonInterface;

/*
|--------------------------------------------------------------------------
| Membekukan tanggal pembayaran saat berkas diterima
|--------------------------------------------------------------------------
| Dipakai FPU, Realisasi, dan Claim. Ketiganya menghitung hal yang sama pada
| saat yang sama, jadi disatukan supaya tidak ada satu modul pun yang
| perlahan menyimpang dari yang lain.
|
| Tanggalnya dibekukan, bukan dihitung ulang tiap kali ditampilkan: angka ini
| sudah terlanjur dikirim ke pemohon lewat email, jadi mengubah master jadwal
| tidak boleh diam-diam menggeser janji yang sudah dibuat.
|--------------------------------------------------------------------------
*/
trait SchedulesPayment
{
    /**
     * Tanggal pembayaran untuk dokumen yang baru diterima.
     *
     * Mengembalikan null bila memang tidak ada jadwal yang cocok -- dokumennya
     * tetap bisa dibayar, hanya tanpa tanggal yang dijanjikan.
     */
    protected function freezePaymentDate(
        string $documentType,
        CarbonInterface $receivedAt,
        ?int $transactionCategoryId = null,
    ): ?string {
        return app(PaymentScheduleService::class)
            ->nextPaymentDate($receivedAt, $documentType, $transactionCategoryId)
            ?->toDateString();
    }

    /**
     * Ketepatan waktu sebuah dokumen terhadap jadwalnya.
     *
     * Dihitung, bukan disimpan -- kedua tanggalnya sudah ada di dokumen.
     */
    protected function paymentTiming(mixed $scheduledDate, mixed $paidAt = null): ?string
    {
        return app(PaymentScheduleService::class)->timing($scheduledDate, $paidAt);
    }

    /**
     * Apakah dokumen ini boleh dibayar HARI INI.
     *
     * Kasir hanya bekerja pada hari tertentu, jadi membayar lebih awal maupun
     * terlambat pun tetap harus jatuh pada hari pembayaran -- bukan pada hari
     * sembarang.
     *
     * Pembatasannya melekat pada JADWAL, bukan pada modul. Dokumen yang tidak
     * punya tanggal jadwal berarti memang tidak berada di bawah jadwal mana
     * pun: dokumen lama, atau modul yang jadwalnya belum dibuat. Dokumen
     * seperti itu tidak dibatasi -- kalau dilarang, modulnya berhenti total
     * tanpa ada aturan yang sebenarnya dilanggar.
     */
    protected function canPayToday(
        mixed $scheduledDate,
        string $documentType,
        ?int $transactionCategoryId = null,
    ): bool {
        if (!$scheduledDate) {
            return true;
        }

        return app(PaymentScheduleService::class)
            ->isPaymentDay(null, $documentType, $transactionCategoryId);
    }

    /**
     * Hari pembayarannya dalam penomoran ISO, untuk dirangkai di layar.
     *
     * Layar merangkai sendiri kalimatnya supaya namanya ikut berganti saat
     * pengguna menukar bahasa, tanpa perlu mengambil datanya ulang.
     *
     * @return int[]
     */
    protected function paymentDays(
        string $documentType,
        ?int $transactionCategoryId = null,
    ): array {
        return app(PaymentScheduleService::class)
            ->paymentDays($documentType, $transactionCategoryId);
    }

    /**
     * Nama hari pembayarannya, untuk dipakai pesan penolakan dari server.
     */
    protected function paymentDaysText(
        string $documentType,
        ?int $transactionCategoryId = null,
    ): string {
        return app(PaymentScheduleService::class)
            ->paymentDaysText($documentType, $transactionCategoryId);
    }
}
