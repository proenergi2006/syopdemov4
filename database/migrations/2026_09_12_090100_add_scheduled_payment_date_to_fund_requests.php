<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Tanggal jadwal pembayaran pada dokumen
|--------------------------------------------------------------------------
| Dihitung sekali saat berkasnya ditandai diterima, lalu DIBEKUKAN. Sengaja
| tidak dihitung ulang: tanggal ini sudah terlanjur diberitahukan ke pemohon
| lewat email, jadi mengubah master jadwal tidak boleh diam-diam menggeser
| janji yang sudah dibuat pada ratusan dokumen berjalan.
|
| Kosong berarti dokumennya memang tidak menunggu pembayaran Finance:
|
| - Dokumen yang sudah dibayar sebelum fitur ini ada
| - Realisasi yang justru MENGEMBALIKAN sisa dana (uang masuk, bukan keluar)
|
| Ketepatan waktunya tidak disimpan. Tanggal jadwal dan tanggal bayar sudah
| ada keduanya, jadi "lebih awal" atau "terlambat" cukup dihitung saat
| ditampilkan -- nilai turunan yang disimpan cepat atau lambat menyimpang
| dari sumbernya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    private const TABEL = [
        'cash_advances',
        'cash_advance_realizations',
        'claims',
    ];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table): void {
                $table->date('scheduled_payment_date')->nullable()->after('receipt_notes');
            });

            DB::statement("COMMENT ON COLUMN {$tabel}.scheduled_payment_date IS 'Tanggal pembayaran hasil master jadwal, dibekukan saat dokumen ditandai diterima'");

            DB::statement("CREATE INDEX {$tabel}_scheduled_payment_date_index ON {$tabel} (scheduled_payment_date) WHERE scheduled_payment_date IS NOT NULL");
        }

        $this->isiMundur();
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table): void {
                $table->dropColumn('scheduled_payment_date');
            });
        }
    }

    /**
     * Mengisi jadwal dokumen yang sudah diterima tetapi belum dibayar.
     *
     * Dokumen yang SUDAH DIBAYAR sengaja dibiarkan kosong: menilai ketepatan
     * waktunya secara surut tidak adil, karena jadwalnya memang belum ada saat
     * dokumen itu diproses.
     */
    private function isiMundur(): void
    {
        $menunggu = [
            'cash_advances' => ['type' => 'FPU', 'difference' => null],
            'claims' => ['type' => 'CLAIM', 'difference' => null],

            /* Hanya yang kekurangannya dibayar perusahaan; pengembalian tidak. */
            'cash_advance_realizations' => ['type' => 'REALISASI', 'difference' => 'REIMBURSE'],
        ];

        foreach ($menunggu as $tabel => $syarat) {
            $query = DB::table($tabel)
                ->where('status', 'RECEIVED')
                ->whereNotNull('received_at');

            if ($syarat['difference'] !== null) {
                $query->where('difference_type', $syarat['difference']);
            }

            foreach ($query->get(['id', 'received_at', 'transaction_category_id']) as $baris) {
                $tanggal = $this->hitung(
                    $baris->received_at,
                    $syarat['type'],
                    $baris->transaction_category_id,
                );

                if (!$tanggal) {
                    continue;
                }

                DB::table($tabel)
                    ->where('id', $baris->id)
                    ->update(['scheduled_payment_date' => $tanggal]);
            }
        }
    }

    /**
     * Salinan ringkas dari PaymentScheduleService.
     *
     * Migrasi sengaja tidak memanggil service-nya: migrasi harus tetap
     * menghasilkan hal yang sama bertahun-tahun kemudian, sekalipun service
     * itu berubah atau hilang.
     */
    private function hitung(string $receivedAt, string $documentType, $categoryId): ?string
    {
        $jadwal = DB::table('payment_schedules')
            ->where('is_active', true)
            ->where(function ($q) use ($documentType): void {
                $q->whereNull('document_type')->orWhere('document_type', $documentType);
            })
            ->where(function ($q) use ($categoryId): void {
                $q->whereNull('transaction_category_id');

                if ($categoryId !== null) {
                    $q->orWhere('transaction_category_id', $categoryId);
                }
            })
            ->get();

        if ($jadwal->isEmpty()) {
            return null;
        }

        /* Yang paling khusus menang: keterangan transaksi 2, modul 1. */
        $terpilih = $jadwal
            ->sortByDesc(fn ($b): int => ($b->transaction_category_id !== null ? 2 : 0)
                + ($b->document_type !== null ? 1 : 0))
            ->first();

        $batasan = DB::table('payment_schedule_cutoffs')
            ->where('payment_schedule_id', $terpilih->id)
            ->get();

        if ($batasan->isEmpty()) {
            return null;
        }

        $diterima = \Carbon\Carbon::parse($receivedAt);
        $terbaik = null;

        foreach ($batasan as $baris) {
            $batas = $diterima->copy()
                ->startOfWeek(\Carbon\CarbonInterface::MONDAY)
                ->addDays($baris->cutoff_day - 1)
                ->setTimeFromTimeString($baris->cutoff_time);

            if ($batas->lt($diterima)) {
                $batas->addWeek();
            }

            $bayar = $batas->copy()
                ->startOfWeek(\Carbon\CarbonInterface::MONDAY)
                ->addWeeks($baris->payment_week_offset)
                ->addDays($baris->payment_day - 1);

            if ($terbaik === null || $batas->lt($terbaik['batas'])) {
                $terbaik = ['batas' => $batas, 'bayar' => $bayar];
            }
        }

        return $terbaik['bayar']->toDateString();
    }
};
