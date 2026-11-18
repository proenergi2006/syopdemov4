<?php

namespace App\Console\Commands;

use App\Models\PettyCashCeiling;
use App\Models\PettyCashTopup;
use App\Services\FundRequest\PettyCash\PettyCashLedgerService;
use App\Services\FundRequest\PettyCash\PettyCashNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Peringatan saldo kas kecil menipis
|--------------------------------------------------------------------------
| Memeriksa seluruh plafon aktif dan memperingatkan pemegangnya bila saldonya
| sudah di bawah ambang -- supaya top up diajukan SEBELUM ada claim kecil yang
| tidak bisa dibayar, bukan sesudahnya.
|
| Harian, bukan tiap jam. Peringatan yang muncul berulang sepanjang hari akan
| berhenti dibaca dalam dua hari, dan sesudah itu ia tidak lagi memperingatkan
| apa pun. Layanannya pun menolak mengirim dua kali sehari untuk plafon yang
| sama, jadi menjalankan perintah ini berulang kali tidak membanjiri siapa pun.
|
| CABANG YANG BELUM PUNYA PEMEGANG DILEWATI DAN DICATAT. Diam-diam melewatinya
| berarti HO -- yang pemegangnya belum ditentukan -- tidak akan pernah
| diperingatkan dan tidak ada yang tahu sebabnya.
|
| Plafon yang top up-nya sedang berjalan juga dilewati: ADH sudah mengerjakan
| apa yang diminta peringatan itu, dan mengulanginya hanya akan terbaca
| seolah pengajuannya tidak tercatat.
|--------------------------------------------------------------------------
*/
class WarnLowPettyCashBalance extends Command
{
    protected $signature = 'petty-cash:warn-low-balance';

    protected $description = 'Memperingatkan pemegang kas kecil yang saldonya sudah di bawah ambang.';

    public function handle(
        PettyCashLedgerService $ledger,
        PettyCashNotificationService $notifikasi,
    ): int {
        $plafon = PettyCashCeiling::query()
            ->with('branchData:id,nama_cabang')
            ->where('is_active', true)
            ->get();

        if ($plafon->isEmpty()) {
            $this->info('Tidak ada plafon petty cash yang aktif.');

            return self::SUCCESS;
        }

        $saldo = $ledger->balances($plafon->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $sedangTopup = PettyCashTopup::query()
            ->whereIn('petty_cash_ceiling_id', $plafon->pluck('id')->all())
            ->whereIn('status', [PettyCashTopup::STATUS_DRAFT, PettyCashTopup::STATUS_IN_PROGRESS])
            ->pluck('petty_cash_ceiling_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $diperingatkan = 0;
        $tanpaPemegang = [];

        foreach ($plafon as $baris) {
            $s = $saldo[$baris->id] ?? 0.0;
            $ambang = $baris->warningThreshold();
            $cabang = $baris->branchData?->nama_cabang ?? ('cabang #' . $baris->branch_id);

            if ($s >= $ambang) {
                continue;
            }

            if (in_array((int) $baris->id, $sedangTopup, true)) {
                $this->line(sprintf('  %-16s saldo menipis, tetapi top up-nya sedang berjalan.', $cabang));

                continue;
            }

            $pemegang = $notifikasi->pemegangKasKecil((int) $baris->branch_id);

            if ($pemegang->isEmpty()) {
                $tanpaPemegang[] = $cabang;

                $this->warn(sprintf(
                    '  %-16s saldo Rp %s di bawah ambang, TETAPI BELUM ADA PEMEGANGNYA -- tidak ada yang bisa diperingatkan.',
                    $cabang,
                    angka($s, 0),
                ));

                continue;
            }

            $dibuat = $notifikasi->notifyLowBalance($baris);
            $diperingatkan += $dibuat;

            $this->line(sprintf(
                '  %-16s saldo Rp %-14s ambang Rp %-14s -> %d pemegang diperingatkan',
                $cabang,
                angka($s, 0),
                angka($ambang, 0),
                $dibuat,
            ));
        }

        if ($tanpaPemegang !== []) {
            /*
            | Masuk log, bukan hanya ke layar: perintah ini berjalan dari
            | penjadwal, dan tidak ada yang membaca layarnya.
            */
            Log::warning('[Petty Cash] Cabang bersaldo menipis tanpa pemegang kas kecil', [
                'cabang' => $tanpaPemegang,
                'keterangan' => 'Beri permission petty_cash.create kepada seseorang di cabang tersebut.',
            ]);
        }

        $this->info(sprintf('Selesai. %d notifikasi peringatan dibuat.', $diperingatkan));

        return self::SUCCESS;
    }
}
