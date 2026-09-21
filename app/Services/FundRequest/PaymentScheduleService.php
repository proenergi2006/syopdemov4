<?php

namespace App\Services\FundRequest;

use App\Models\PaymentSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Menghitung tanggal pembayaran dari master jadwal
|--------------------------------------------------------------------------
| Aturannya bukan "jeda sekian hari", melainkan batas setor: berkas yang
| diterima sebelum suatu batas ikut rombongan pembayaran batas itu.
|
| Contoh dengan jadwal umum yang berlaku sekarang:
|
|   Diterima Senin 07 Sep       -> batas Rabu 09 Sep 14:00 -> bayar Senin 14 Sep
|   Diterima Rabu  09 Sep 13:00 -> batas Rabu 09 Sep 14:00 -> bayar Senin 14 Sep
|   Diterima Rabu  09 Sep 15:00 -> batas Minggu 13 Sep     -> bayar Rabu  16 Sep
|   Diterima Sabtu 12 Sep       -> batas Minggu 13 Sep     -> bayar Rabu  16 Sep
|
| BELUM memperhitungkan hari libur nasional. Kalau tanggal hasilnya jatuh pada
| tanggal merah, Finance menanganinya di luar sistem. Master tanggal libur
| sengaja ditunda -- lihat catatan perubahan 12 September 2026.
|--------------------------------------------------------------------------
*/
class PaymentScheduleService
{
    /**
     * Tanggal pembayaran untuk berkas yang diterima pada waktu tertentu.
     *
     * Mengembalikan null bila tidak ada jadwal yang cocok -- dokumennya tetap
     * bisa dibayar, hanya saja tanpa tanggal yang dijanjikan.
     */
    public function nextPaymentDate(
        CarbonInterface $receivedAt,
        ?string $documentType = null,
        ?int $transactionCategoryId = null,
    ): ?Carbon {
        $jadwal = $this->resolve($documentType, $transactionCategoryId);

        if (!$jadwal) {
            Log::warning('[Jadwal Bayar] Tidak ada jadwal yang cocok; dokumen diterima tanpa tanggal pembayaran', [
                'received_at' => $receivedAt->toDateTimeString(),
                'document_type' => $documentType,
                'transaction_category_id' => $transactionCategoryId,
            ]);

            return null;
        }

        $terbaik = null;

        foreach ($jadwal->cutoffs as $baris) {
            $batas = $this->batasTerdekat($receivedAt, $baris->cutoff_day, (string) $baris->cutoff_time);

            $bayar = $batas->copy()
                ->startOfWeek(CarbonInterface::MONDAY)
                ->addWeeks(max(0, (int) $baris->payment_week_offset))
                ->addDays($this->hariSah($baris->payment_day) - 1)
                ->startOfDay();

            /*
            | Yang dipakai adalah batas yang paling dekat, bukan tanggal bayar
            | yang paling awal. Keduanya biasanya sama, tetapi kalau jadwalnya
            | nanti disusun tidak lazim, batas terdekat yang mencerminkan
            | rombongan sebenarnya.
            */
            if ($terbaik === null || $batas->lt($terbaik['batas'])) {
                $terbaik = ['batas' => $batas, 'bayar' => $bayar];
            }
        }

        if ($terbaik === null) {
            Log::warning('[Jadwal Bayar] Jadwal ditemukan tetapi tidak punya satu pun batas setor', [
                'payment_schedule_id' => $jadwal->id,
                'name' => $jadwal->name,
            ]);

            return null;
        }

        return $terbaik['bayar'];
    }

    /**
     * Jadwal yang berlaku untuk sebuah dokumen.
     *
     * Yang paling khusus menang. Kalau bobotnya seri -- misalnya dua jadwal
     * sama-sama menyebut keterangan transaksi yang sama -- yang dipakai adalah
     * yang paling dulu dibuat, supaya hasilnya tetap sama dari waktu ke waktu
     * dan bukan bergantung urutan baris yang kebetulan.
     */
    public function resolve(
        ?string $documentType = null,
        ?int $transactionCategoryId = null,
    ): ?PaymentSchedule {
        return PaymentSchedule::query()
            ->with('cutoffs')
            ->where('is_active', true)
            ->where(function ($q) use ($documentType): void {
                $q->whereNull('document_type');

                if ($documentType !== null) {
                    $q->orWhere('document_type', $documentType);
                }
            })
            ->where(function ($q) use ($transactionCategoryId): void {
                $q->whereNull('transaction_category_id');

                if ($transactionCategoryId !== null) {
                    $q->orWhere('transaction_category_id', $transactionCategoryId);
                }
            })
            ->get()
            ->sort(function (PaymentSchedule $a, PaymentSchedule $b): int {
                return [$b->scope_weight, $a->id] <=> [$a->scope_weight, $b->id];
            })
            ->first();
    }

    /**
     * Hari-hari pembayaran yang berlaku untuk sebuah dokumen, penomoran ISO.
     *
     * Diambil dari batas setor jadwalnya: tiap batas menyebut hari bayarnya
     * sendiri, dan gabungan yang berbeda-beda itulah hari kerja kasir.
     *
     * @return int[] terurut, tanpa kembar
     */
    public function paymentDays(
        ?string $documentType = null,
        ?int $transactionCategoryId = null,
    ): array {
        $jadwal = $this->resolve($documentType, $transactionCategoryId);

        if (!$jadwal) {
            return [];
        }

        return $jadwal->cutoffs
            ->map(fn ($baris): int => $this->hariSah($baris->payment_day))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Apakah sebuah tanggal jatuh pada hari pembayaran.
     *
     * Jadwal yang tidak punya hari bayar sama sekali mengembalikan true --
     * tanpa jadwal, tidak ada dasar untuk melarang apa pun, dan menolak
     * semuanya akan menghentikan pembayaran sepenuhnya.
     */
    public function isPaymentDay(
        ?CarbonInterface $tanggal = null,
        ?string $documentType = null,
        ?int $transactionCategoryId = null,
    ): bool {
        $hari = $this->paymentDays($documentType, $transactionCategoryId);

        if ($hari === []) {
            return true;
        }

        return in_array(
            ($tanggal ? Carbon::parse($tanggal) : Carbon::now())->dayOfWeekIso,
            $hari,
            true,
        );
    }

    /**
     * Nama hari pembayarannya, dirangkai jadi kalimat: "Senin dan Rabu".
     *
     * Dirangkai di sini, bukan di layar, supaya pesan penolakan dari server
     * dan keterangan di layar menyebut hal yang sama dengan kata yang sama.
     */
    public function paymentDaysText(
        ?string $documentType = null,
        ?int $transactionCategoryId = null,
    ): string {
        $nama = collect($this->paymentDays($documentType, $transactionCategoryId))
            ->map(fn (int $iso): string => PaymentSchedule::namaHari($iso))
            ->all();

        if ($nama === []) {
            return '-';
        }

        if (count($nama) === 1) {
            return $nama[0];
        }

        $terakhir = array_pop($nama);

        return implode(', ', $nama) . ' ' . __('payment_schedule_messages.and') . ' ' . $terakhir;
    }

    /**
     * Ketepatan waktu sebuah dokumen terhadap jadwalnya.
     *
     * Sengaja dihitung, bukan disimpan: kedua tanggalnya sudah ada, dan nilai
     * turunan yang disimpan cepat atau lambat menyimpang dari sumbernya.
     *
     * Nilai yang mungkin:
     *
     *   null        tidak terjadwal -- dokumen lama, atau memang tidak dibayar
     *   ON_TRACK    belum dibayar, jadwalnya belum lewat
     *   OVERDUE     belum dibayar, jadwalnya sudah lewat
     *   EARLY       dibayar sebelum tanggal jadwalnya
     *   ON_TIME     dibayar tepat pada tanggal jadwalnya
     *   LATE        dibayar setelah tanggal jadwalnya
     */
    public function timing(
        mixed $scheduledDate,
        mixed $paidAt = null,
        ?CarbonInterface $today = null,
    ): ?string {
        if (!$scheduledDate) {
            return null;
        }

        $jadwal = Carbon::parse($scheduledDate)->startOfDay();

        if (!$paidAt) {
            $hariIni = ($today ? Carbon::parse($today) : Carbon::now())->startOfDay();

            return $hariIni->lte($jadwal) ? 'ON_TRACK' : 'OVERDUE';
        }

        $dibayar = Carbon::parse($paidAt)->startOfDay();

        if ($dibayar->lt($jadwal)) {
            return 'EARLY';
        }

        return $dibayar->eq($jadwal) ? 'ON_TIME' : 'LATE';
    }

    /**
     * Tanggal yang bisa dibaca orang, lengkap dengan nama harinya.
     *
     * Nama harinya penting di sini: yang dijanjikan ke pemohon bukan sekadar
     * "21 September", melainkan "Senin, 21 September" -- harinya bagian dari
     * aturan, jadi harinya juga yang paling dicari saat membaca.
     */
    public static function formatTanggal(mixed $date, ?string $locale = null): string
    {
        if (!$date) {
            return '-';
        }

        $tanggal = Carbon::parse($date);
        $locale ??= app()->getLocale();

        if ($locale !== 'id') {
            return $tanggal->format('l, j F Y');
        }

        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return PaymentSchedule::namaHari($tanggal->dayOfWeekIso, $locale)
            . ', ' . $tanggal->day
            . ' ' . $bulan[$tanggal->month]
            . ' ' . $tanggal->year;
    }

    /**
     * Kemunculan terdekat sebuah batas yang belum terlewat.
     *
     * Batasnya inklusif: berkas yang masuk tepat pukul 14:00 masih ikut
     * rombongan batas pukul 14:00, sesuai aturan "maksimal Rabu jam 2".
     */
    private function batasTerdekat(CarbonInterface $receivedAt, mixed $cutoffDay, string $cutoffTime): Carbon
    {
        $batas = Carbon::parse($receivedAt)
            ->startOfWeek(CarbonInterface::MONDAY)
            ->addDays($this->hariSah($cutoffDay) - 1)
            ->setTimeFromTimeString($cutoffTime);

        if ($batas->lt($receivedAt)) {
            $batas->addWeek();
        }

        return $batas;
    }

    /**
     * Menjaga nomor hari tetap di dalam 1..7.
     *
     * Datanya berasal dari master yang bisa diubah orang, jadi nilai di luar
     * jangkauan harus menghasilkan tanggal yang masuk akal, bukan tanggal
     * yang meleset berminggu-minggu tanpa ada yang menyadari.
     */
    private function hariSah(mixed $iso): int
    {
        return max(1, min(7, (int) $iso));
    }
}
