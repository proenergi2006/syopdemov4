<?php

namespace App\Services\FundRequest\PettyCash;

use App\Models\Claim;
use App\Models\PettyCash;
use App\Models\PettyCashCeiling;
use App\Models\PettyCashClaim;
use App\Models\PettyCashTopup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Perpindahan uang kas kecil
|--------------------------------------------------------------------------
| Yang dikerjakan di sini hanya hal-hal yang menyentuh uang atau status
| dokumen lain: menautkan claim, mencairkan, membatalkan, dan menerapkan top
| up yang sudah disetujui. CRUD biasa tetap di controller, seperti modul lain.
|
| Pemisahan ini bukan selera. Tiga di antara empat tindakan di bawah mengubah
| DUA tabel sekaligus -- kas kecil dan claim -- dan keduanya harus berubah
| bersama atau tidak berubah sama sekali. Menyebarnya ke dalam controller
| berarti aturan itu dijaga oleh ingatan orang yang menyuntingnya nanti.
|
| SELURUH METHOD DI SINI MENUNTUT TRANSAKSI SUDAH TERBUKA. Yang memanggil
| yang membukanya, karena ia pula yang tahu apa lagi yang harus ikut gagal
| bila salah satu langkah gagal.
|--------------------------------------------------------------------------
*/
class PettyCashService
{
    public function __construct(
        private readonly PettyCashLedgerService $ledger,
        private readonly PettyCashEligibilityService $kelayakan,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Penautan claim
    |--------------------------------------------------------------------------
    */

    /**
     * Menyetel claim yang dibayar formulir ini, menggantikan isi sebelumnya.
     *
     * Nominal tiap claim DIBEKUKAN di baris pasangannya, dan total dokumen
     * dihitung dari angka beku itu -- bukan dari claims.total_amount saat
     * dibaca nanti. Dengan begitu formulir yang sudah dicairkan tidak pernah
     * berubah arti karena dokumen lain disentuh.
     *
     * @param  array<int>  $claimIds
     */
    public function syncClaims(PettyCash $pettyCash, array $claimIds, ?User $user = null): void
    {
        $this->assertDalamTransaksi();

        $claimIds = collect($claimIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($claimIds->isEmpty()) {
            throw ValidationException::withMessages([
                'claims' => ['Pilih minimal satu claim yang akan dibayar.'],
            ]);
        }

        $plafon = $this->kelayakan->ceilingForBranch((int) $pettyCash->branch);

        if (!$plafon) {
            throw ValidationException::withMessages([
                'branch' => ['Cabang ini belum punya plafon petty cash yang aktif.'],
            ]);
        }

        /*
        | Claim diambil terkunci. Dua ADH yang memilih claim yang sama pada
        | detik yang sama tidak akan saling terlihat tanpa ini -- indeks unik
        | akan menahannya, tetapi pesannya akan berupa galat database alih-alih
        | keterangan yang bisa dibaca.
        */
        $claims = Claim::query()
            ->whereIn('id', $claimIds->all())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $barisLama = PettyCashClaim::query()
            ->where('petty_cash_id', $pettyCash->id)
            ->whereNull('released_at')
            ->get()
            ->keyBy('claim_id');

        foreach ($claimIds as $claimId) {
            $claim = $claims->get($claimId);

            if (!$claim) {
                throw ValidationException::withMessages([
                    'claims' => ['Salah satu claim yang dipilih tidak ditemukan.'],
                ]);
            }

            $this->assertClaimLayak($claim, $pettyCash, $plafon, $barisLama->has($claimId));
        }

        /* Pasangan yang tidak lagi dipilih dilepas, bukan dihapus. */
        $barisLama
            ->reject(fn (PettyCashClaim $baris): bool => $claimIds->contains((int) $baris->claim_id))
            ->each(fn (PettyCashClaim $baris) => $baris->update(['released_at' => now()]));

        $total = 0.0;

        foreach ($claimIds as $claimId) {
            $nominal = (float) $claims->get($claimId)->total_amount;
            $total += $nominal;

            if ($barisLama->has($claimId)) {
                $barisLama->get($claimId)->update(['amount' => $nominal]);

                continue;
            }

            PettyCashClaim::create([
                'petty_cash_id' => $pettyCash->id,
                'claim_id' => $claimId,
                'amount' => $nominal,
            ]);
        }

        $pettyCash->update([
            'total_amount' => $total,
            'petty_cash_ceiling_id' => $plafon->id,
            'updated_by' => $user?->id ?? $pettyCash->updated_by,
        ]);
    }

    /**
     * Melepas seluruh pasangan claim formulir ini.
     *
     * Dipakai saat formulirnya batal, ditolak, atau dihapus. Claim-claimnya
     * bebas dipakai formulir berikutnya, dan jejak bahwa mereka pernah ada di
     * formulir yang gagal tetap tersimpan.
     */
    public function releaseClaims(PettyCash $pettyCash): int
    {
        $this->assertDalamTransaksi();

        return PettyCashClaim::query()
            ->where('petty_cash_id', $pettyCash->id)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pencairan
    |--------------------------------------------------------------------------
    */

    /**
     * Mencairkan formulir: uang keluar dari kas kecil.
     *
     * Satu kejadian, tiga akibat yang harus terjadi bersama:
     *
     *   1. plafon terpotong, tercatat di buku mutasi;
     *   2. claim-claim di dalamnya menjadi PAID;
     *   3. dokumennya menjadi DISBURSED.
     *
     * Tidak ada keadaan di mana sebagian saja terjadi. Kalau saldonya tidak
     * cukup, tidak ada satu pun yang terjadi.
     *
     * @return array{ok: bool, message: string|null, balance_after: float|null}
     */
    public function disburse(PettyCash $pettyCash, User $user, ?string $notes = null): array
    {
        $this->assertDalamTransaksi();

        if (strtoupper((string) $pettyCash->status) !== PettyCash::STATUS_APPROVED) {
            return [
                'ok' => false,
                'message' => 'Hanya Petty Cash yang sudah disetujui penuh yang dapat dicairkan.',
                'balance_after' => null,
            ];
        }

        $plafon = PettyCashCeiling::query()
            ->whereKey($pettyCash->petty_cash_ceiling_id)
            ->first();

        if (!$plafon) {
            return [
                'ok' => false,
                'message' => 'Plafon petty cash cabang ini sudah tidak ada. Hubungi admin.',
                'balance_after' => null,
            ];
        }

        $baris = PettyCashClaim::query()
            ->where('petty_cash_id', $pettyCash->id)
            ->whereNull('released_at')
            ->get();

        if ($baris->isEmpty()) {
            return [
                'ok' => false,
                'message' => 'Formulir ini tidak lagi memuat claim mana pun.',
                'balance_after' => null,
            ];
        }

        /*
        | Mutasi dicatat LEBIH DULU, karena di situlah kecukupan saldo diuji
        | di bawah kuncian. Kalau tidak cukup, tidak ada claim yang tersentuh.
        */
        $mutasi = $this->ledger->recordDisbursement($plafon, $pettyCash, $user);

        if (!$mutasi) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Saldo kas kecil tidak cukup. Dibutuhkan Rp %s, tersedia Rp %s. Ajukan top up lebih dulu.',
                    angka((float) $pettyCash->total_amount, 0),
                    angka($this->ledger->balance($plafon->id), 0),
                ),
                'balance_after' => null,
            ];
        }

        $dicairkanPada = now();

        /*
        | Claim ditandai dibayar di sini, bukan lewat ClaimController::pay().
        |
        | pay() menuntut status RECEIVED dan hari pembayaran kasir, dan
        | keduanya tidak berlaku bagi uang tunai yang diserahkan langsung di
        | cabang. Yang dicatat tetap sama: siapa membayar, kapan, dan atas
        | dasar apa -- nomor formulirnya ikut masuk ke catatan pembayaran
        | supaya claim dan kas kecil bisa saling ditemukan dari dua arah.
        */
        foreach ($baris as $pasangan) {
            Claim::query()
                ->whereKey($pasangan->claim_id)
                ->where('status', Claim::STATUS_RECEIVED)
                ->update([
                    'status' => Claim::STATUS_PAID,
                    'paid_by' => $user->id,
                    'paid_at' => $dicairkanPada,
                    'payment_notes' => 'Dibayar tunai dari kas kecil lewat ' . $pettyCash->petty_cash_number,
                    'updated_at' => $dicairkanPada,
                ]);
        }

        $pettyCash->update([
            'status' => PettyCash::STATUS_DISBURSED,
            'disbursed_by' => $user->id,
            'disbursed_at' => $dicairkanPada,
            'disbursement_notes' => $notes,
        ]);

        return [
            'ok' => true,
            'message' => null,
            'balance_after' => (float) $mutasi->balance_after,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Pembatalan
    |--------------------------------------------------------------------------
    */

    /**
     * Membatalkan formulir yang sudah disetujui penuh tetapi BELUM dicairkan.
     *
     * Sesudah dicairkan, pembatalan tidak disediakan: uangnya sudah berpindah
     * tangan di cabang, dan membalikkan catatan tidak membalikkan uangnya.
     * Untuk keadaan itu yang tersedia adalah penyesuaian saldo yang tercatat
     * alasannya -- lihat PettyCashLedgerService::recordAdjustment().
     *
     * @return array{ok: bool, message: string|null}
     */
    public function cancel(PettyCash $pettyCash, User $user, string $notes): array
    {
        $this->assertDalamTransaksi();

        $status = strtoupper((string) $pettyCash->status);

        if ($status === PettyCash::STATUS_DISBURSED) {
            return [
                'ok' => false,
                'message' => 'Petty Cash yang sudah dicairkan tidak dapat dibatalkan. '
                    . 'Uangnya sudah diserahkan; yang tersedia adalah penyesuaian saldo kas kecil.',
            ];
        }

        if ($status !== PettyCash::STATUS_APPROVED) {
            return [
                'ok' => false,
                'message' => 'Hanya Petty Cash yang sudah disetujui penuh dan belum dicairkan yang dapat dibatalkan.',
            ];
        }

        $pettyCash->update([
            'status' => PettyCash::STATUS_CANCELLED,
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
            'cancellation_notes' => $notes,
        ]);

        /*
        | Claim-claimnya dilepas supaya bisa masuk formulir berikutnya.
        | Statusnya TIDAK disentuh: mereka masih RECEIVED, karena belum pernah
        | dibayar -- plafon pun belum terpotong, jadi tidak ada yang perlu
        | dibalikkan di buku mutasi.
        */
        $this->releaseClaims($pettyCash);

        return ['ok' => true, 'message' => null];
    }

    /*
    |--------------------------------------------------------------------------
    | Top up
    |--------------------------------------------------------------------------
    */

    /**
     * Nominal top up untuk sebuah plafon: plafon dikurangi saldo berjalan.
     *
     * Mengembalikan null bila saldonya sudah penuh -- tidak ada yang perlu
     * diisi, dan dokumen bernominal nol tidak punya arti.
     */
    public function hitungNominalTopup(PettyCashCeiling $plafon): ?float
    {
        $kekurangan = (float) $plafon->ceiling_amount - $this->ledger->balance($plafon->id);

        return $kekurangan > 0 ? $kekurangan : null;
    }

    /**
     * Menerapkan top up yang sudah disetujui penuh.
     *
     * Dipanggil dari aksi approve, tepat setelah mesin approval menyatakan
     * rantainya tuntas.
     */
    public function applyApprovedTopup(PettyCashTopup $topup, User $user): float
    {
        $this->assertDalamTransaksi();

        $plafon = PettyCashCeiling::query()
            ->whereKey($topup->petty_cash_ceiling_id)
            ->firstOrFail();

        $mutasi = $this->ledger->recordTopup($plafon, $topup, $user);

        return (float) $mutasi->balance_after;
    }

    /*
    |--------------------------------------------------------------------------
    | Dalam
    |--------------------------------------------------------------------------
    */

    /**
     * Claim ini sah dibayar formulir ini.
     *
     * Empat syarat, masing-masing dengan pesannya sendiri. Satu pesan umum
     * "claim tidak valid" akan membuat ADH menebak -- padahal keempat sebabnya
     * berbeda tindakan lanjutannya.
     */
    private function assertClaimLayak(
        Claim $claim,
        PettyCash $pettyCash,
        PettyCashCeiling $plafon,
        bool $sudahDiFormulirIni,
    ): void {
        if ((string) $claim->branch !== (string) $pettyCash->branch) {
            throw ValidationException::withMessages([
                'claims' => [sprintf(
                    'Claim %s bukan milik cabang formulir ini.',
                    $claim->claim_number,
                )],
            ]);
        }

        if (strtoupper((string) $claim->status) !== Claim::STATUS_RECEIVED) {
            throw ValidationException::withMessages([
                'claims' => [sprintf(
                    'Claim %s berstatus %s. Hanya claim yang sudah diterima yang bisa dibayar dari kas kecil.',
                    $claim->claim_number,
                    $claim->status,
                )],
            ]);
        }

        if ((float) $claim->total_amount > (float) $plafon->claim_threshold) {
            throw ValidationException::withMessages([
                'claims' => [sprintf(
                    'Claim %s bernominal Rp %s, melebihi ambang petty cash cabang ini (Rp %s).',
                    $claim->claim_number,
                    angka((float) $claim->total_amount, 0),
                    angka((float) $plafon->claim_threshold, 0),
                )],
            ]);
        }

        if ($sudahDiFormulirIni) {
            return;
        }

        $nomorLain = $this->kelayakan->livePettyCashNumberFor((int) $claim->id);

        if ($nomorLain) {
            throw ValidationException::withMessages([
                'claims' => [sprintf(
                    'Claim %s sudah dipakai formulir %s.',
                    $claim->claim_number,
                    $nomorLain,
                )],
            ]);
        }
    }

    private function assertDalamTransaksi(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'Perpindahan uang kas kecil hanya boleh dikerjakan di dalam transaksi database.'
            );
        }
    }
}
