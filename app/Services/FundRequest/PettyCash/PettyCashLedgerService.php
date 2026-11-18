<?php

namespace App\Services\FundRequest\PettyCash;

use App\Models\PettyCash;
use App\Models\PettyCashCeiling;
use App\Models\PettyCashLedger;
use App\Models\PettyCashTopup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Buku mutasi kas kecil -- satu-satunya penulisnya
|--------------------------------------------------------------------------
| Tidak ada tempat lain di aplikasi yang boleh menulis petty_cash_ledgers.
| Alasannya bukan kerapian, tapi kuncian: membaca saldo lalu menulis di tempat
| lain berarti dua pencairan yang berjalan bersamaan sama-sama membaca saldo
| yang masih utuh, dan keduanya merasa cukup. Kas kecil tidak punya cara untuk
| memberi tahu bahwa ia sudah kosong.
|
| Karena itu setiap pencatatan di sini:
|
|   1. mengunci baris plafonnya (SELECT ... FOR UPDATE)
|   2. baru menjumlahkan saldonya
|   3. menulis mutasinya
|
| Dalam urutan itu, dan selalu di dalam transaksi pemanggilnya. Pemanggil yang
| belum membuka transaksi akan ditolak -- lebih baik gagal keras daripada
| menulis uang tanpa kuncian yang berarti.
|
| PLAFON YANG BERUBAH DI MASTER TIDAK MENGUBAH SALDO. Menaikkan plafon tidak
| menambah uang di tangan ADH; yang membawanya adalah top up berikutnya, yang
| nominalnya memang dihitung dari plafon baru. Kalau saldo ikut naik seketika,
| sistem akan mengizinkan pencairan atas uang yang belum dikirim siapa pun.
|--------------------------------------------------------------------------
*/
class PettyCashLedgerService
{
    /**
     * Saldo berjalan sebuah plafon.
     *
     * Tanpa kuncian. Dipakai untuk MENAMPILKAN, bukan untuk memutuskan boleh
     * tidaknya uang keluar -- keputusan itu selalu lewat salah satu method
     * pencatatan di bawah, yang mengunci lebih dulu.
     */
    public function balance(int $ceilingId): float
    {
        return (float) PettyCashLedger::query()
            ->where('petty_cash_ceiling_id', $ceilingId)
            ->sum('amount');
    }

    /**
     * Saldo beberapa plafon sekaligus, untuk daftar dan dashboard.
     *
     * Satu kueri untuk semuanya. Memanggil balance() di dalam perulangan
     * adalah cara membuat halaman daftar melahirkan satu kueri per baris.
     *
     * @param  array<int>  $ceilingIds
     * @return array<int, float>  petty_cash_ceiling_id => saldo
     */
    public function balances(array $ceilingIds): array
    {
        if ($ceilingIds === []) {
            return [];
        }

        $rows = PettyCashLedger::query()
            ->selectRaw('petty_cash_ceiling_id, COALESCE(SUM(amount), 0) AS saldo')
            ->whereIn('petty_cash_ceiling_id', $ceilingIds)
            ->groupBy('petty_cash_ceiling_id')
            ->pluck('saldo', 'petty_cash_ceiling_id');

        $hasil = [];

        foreach ($ceilingIds as $id) {
            $hasil[(int) $id] = (float) ($rows[$id] ?? 0);
        }

        return $hasil;
    }

    /**
     * Saldo awal, dicatat sekali saat plafon dibuat.
     *
     * Nilai awalnya masuk lewat buku, bukan dibaca dari kolom plafon. Dengan
     * begitu plafon yang kelak diubah tidak mengubah arti riwayat yang sudah
     * lewat -- lihat keterangan di kepala kelas ini.
     */
    public function recordOpening(PettyCashCeiling $ceiling, ?User $user): PettyCashLedger
    {
        return $this->record(
            ceiling: $ceiling,
            entryType: PettyCashLedger::ENTRY_OPENING,
            amount: (float) $ceiling->ceiling_amount,
            referenceType: PettyCashLedger::REFERENCE_CEILING,
            referenceId: $ceiling->id,
            notes: 'Saldo awal saat plafon dibuat.',
            user: $user,
        );
    }

    /**
     * Pencairan petty cash: uang keluar dari kas.
     *
     * Mengembalikan null bila saldonya tidak cukup -- pemanggil yang
     * membatalkan transaksinya dan menyampaikan pesannya, karena hanya ia
     * yang tahu harus menjawab apa kepada siapa.
     */
    public function recordDisbursement(
        PettyCashCeiling $ceiling,
        PettyCash $pettyCash,
        ?User $user,
    ): ?PettyCashLedger {
        $nominal = (float) $pettyCash->total_amount;

        /*
        | Kunci lebih dulu, baru periksa kecukupannya. Dibalik urutannya,
        | pemeriksaan ini tidak ada artinya.
        */
        $this->lock($ceiling->id);

        if ($this->balance($ceiling->id) < $nominal) {
            return null;
        }

        return $this->record(
            ceiling: $ceiling,
            entryType: PettyCashLedger::ENTRY_DISBURSEMENT,
            amount: -$nominal,
            referenceType: PettyCashLedger::REFERENCE_PETTY_CASH,
            referenceId: $pettyCash->id,
            notes: 'Pencairan ' . $pettyCash->petty_cash_number,
            user: $user,
            sudahDikunci: true,
        );
    }

    /**
     * Pembalikan pencairan: uangnya kembali ke kas.
     *
     * Belum dipakai jalur mana pun hari ini -- pembatalan petty cash hanya
     * diizinkan sebelum pencairan. Disediakan karena bentuknya sudah jelas
     * sekarang, dan karena pembalikan yang dibutuhkan mendadak tanpa tempat
     * untuk mencatatnya akan dikerjakan lewat SQL langsung.
     */
    public function recordReversal(
        PettyCashCeiling $ceiling,
        PettyCash $pettyCash,
        ?User $user,
        ?string $notes = null,
    ): PettyCashLedger {
        return $this->record(
            ceiling: $ceiling,
            entryType: PettyCashLedger::ENTRY_REVERSAL,
            amount: (float) $pettyCash->total_amount,
            referenceType: PettyCashLedger::REFERENCE_PETTY_CASH,
            referenceId: $pettyCash->id,
            notes: $notes ?: 'Pembalikan pencairan ' . $pettyCash->petty_cash_number,
            user: $user,
        );
    }

    /**
     * Pengisian kembali yang sudah disetujui penuh.
     */
    public function recordTopup(
        PettyCashCeiling $ceiling,
        PettyCashTopup $topup,
        ?User $user,
    ): PettyCashLedger {
        return $this->record(
            ceiling: $ceiling,
            entryType: PettyCashLedger::ENTRY_TOPUP,
            amount: (float) $topup->amount,
            referenceType: PettyCashLedger::REFERENCE_TOPUP,
            referenceId: $topup->id,
            notes: 'Pengisian kembali ' . $topup->topup_number,
            user: $user,
        );
    }

    /**
     * Penyesuaian manual oleh Finance.
     *
     * Jalan keluar untuk keadaan yang tidak punya dokumen: selisih hasil
     * hitung fisik, atau pencairan keliru yang sudah tidak bisa dibatalkan.
     * Alasannya WAJIB, karena baris tanpa dokumen hanya bisa dipertanggung
     * jawabkan oleh keterangannya.
     */
    public function recordAdjustment(
        PettyCashCeiling $ceiling,
        float $amount,
        string $notes,
        ?User $user,
    ): PettyCashLedger {
        return $this->record(
            ceiling: $ceiling,
            entryType: PettyCashLedger::ENTRY_ADJUSTMENT,
            amount: $amount,
            referenceType: PettyCashLedger::REFERENCE_CEILING,
            referenceId: $ceiling->id,
            notes: $notes,
            user: $user,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Dalam
    |--------------------------------------------------------------------------
    */

    /**
     * Mengunci baris plafon sampai transaksi pemanggil selesai.
     */
    private function lock(int $ceilingId): void
    {
        DB::table('petty_cash_ceilings')
            ->where('id', $ceilingId)
            ->lockForUpdate()
            ->value('id');
    }

    private function record(
        PettyCashCeiling $ceiling,
        string $entryType,
        float $amount,
        string $referenceType,
        ?int $referenceId,
        ?string $notes,
        ?User $user,
        bool $sudahDikunci = false,
    ): PettyCashLedger {
        /*
        | Menulis uang di luar transaksi berarti tidak ada yang menjamin
        | mutasi dan dokumennya sama-sama tersimpan atau sama-sama tidak.
        | Dibiarkan lolos, akibatnya baru ketahuan berbulan-bulan kemudian
        | saat saldo tidak bisa dijelaskan.
        */
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'Mutasi kas kecil hanya boleh dicatat di dalam transaksi database.'
            );
        }

        if (!$sudahDikunci) {
            $this->lock($ceiling->id);
        }

        $saldoSesudah = $this->balance($ceiling->id) + $amount;

        return PettyCashLedger::create([
            'petty_cash_ceiling_id' => $ceiling->id,
            'branch_id' => $ceiling->branch_id,
            'entry_type' => $entryType,
            'amount' => $amount,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'balance_after' => $saldoSesudah,
            'created_by' => $user?->id,
        ]);
    }
}
