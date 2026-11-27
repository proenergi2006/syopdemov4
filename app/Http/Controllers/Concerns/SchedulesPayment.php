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
}
