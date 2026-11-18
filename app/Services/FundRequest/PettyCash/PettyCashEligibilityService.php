<?php

namespace App\Services\FundRequest\PettyCash;

use App\Models\Claim;
use App\Models\PettyCashCeiling;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Penghubung antara Claim dan kas kecil
|--------------------------------------------------------------------------
| Satu tempat untuk menjawab tiga pertanyaan yang muncul di banyak layar:
|
|   1. Claim ini wajib lewat petty cash?
|   2. Siapa yang boleh memegang kas kecil sebuah cabang?
|   3. Claim mana yang siap dimasukkan ke formulir petty cash?
|
| Jawabannya DITURUNKAN, bukan disetel: tidak ada tabel pemetaan "dokumen
| sekian ditangani orang sekian". Penentunya ada dua, dan keduanya sudah ada:
| plafon kas kecil cabang itu (ada atau tidak, dan ambangnya berapa), dan
| permission petty_cash.create (siapa pemegangnya).
|
| Akibat yang dikehendaki: cabang tanpa plafon hidup -- atau yang plafonnya
| dinonaktifkan -- tidak punya claim yang wajib lewat petty cash sama sekali.
| Claim kecilnya kembali ke jalur Finance seperti sebelum fitur ini ada. Jadi
| mematikan fitur ini untuk satu cabang cukup dengan menonaktifkan plafonnya,
| tanpa menyentuh permission siapa pun.
|--------------------------------------------------------------------------
*/
class PettyCashEligibilityService
{
    public const PERMISSION_PEMEGANG = 'petty_cash.create';

    /**
     * Plafon hidup sebuah cabang, bila ada.
     */
    public function ceilingForBranch(?int $branchId): ?PettyCashCeiling
    {
        if (!$branchId || $branchId <= 0) {
            return null;
        }

        return PettyCashCeiling::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Claim ini wajib dibayar lewat petty cash.
     *
     * Dibaca dari plafon cabang claim itu, bukan dari pengaturan global:
     * ambangnya menempel pada kas kecilnya. Lihat keterangan di kepala kelas.
     */
    public function requiresPettyCash(Claim $claim): bool
    {
        $plafon = $this->ceilingForBranch((int) $claim->branch);

        if (!$plafon) {
            return false;
        }

        return (float) $claim->total_amount <= (float) $plafon->claim_threshold;
    }

    /**
     * Ambang claim yang berlaku di sebuah cabang, bila kas kecilnya ada.
     */
    public function thresholdForBranch(?int $branchId): ?float
    {
        $plafon = $this->ceilingForBranch($branchId);

        return $plafon ? (float) $plafon->claim_threshold : null;
    }

    /**
     * Akun ini pemegang kas kecil cabang tersebut.
     *
     * Dua syarat, dan keduanya harus terpenuhi:
     *
     *   1. memegang permission petty_cash.create;
     *   2. cabangnya termasuk cabang yang bisa ia akses.
     *
     * Syarat kedua perlu disebut terpisah karena permission itu tidak memakai
     * scope -- ia menjawab "boleh memegang kas kecil", bukan "kas kecil yang
     * mana". Tanpa pemeriksaan cabang, ADH Sulawesi akan bisa menerima dan
     * membayar claim kecil Palembang dari kas kecil yang bukan pegangannya.
     */
    public function isHolderFor(?User $user, ?int $branchId): bool
    {
        if (!$user || !$branchId || $branchId <= 0) {
            return false;
        }

        if (!$user->hasPermission(self::PERMISSION_PEMEGANG)) {
            return false;
        }

        return $user->accessibleBranchIds()->contains((int) $branchId);
    }

    /**
     * Cabang yang kas kecilnya dipegang akun ini.
     *
     * Hanya cabang yang benar-benar punya plafon hidup. Dipakai menyaring
     * daftar dan mengisi pilihan cabang pada formulir.
     *
     * @return Collection<int, int>
     */
    public function holderBranchIds(?User $user): Collection
    {
        if (!$user || !$user->hasPermission(self::PERMISSION_PEMEGANG)) {
            return collect();
        }

        $punyaPlafon = PettyCashCeiling::query()
            ->where('is_active', true)
            ->pluck('branch_id')
            ->map(fn ($id): int => (int) $id);

        return $user->accessibleBranchIds()
            ->filter(fn (int $id): bool => $punyaPlafon->contains($id))
            ->values();
    }

    /**
     * Claim yang siap dimasukkan ke formulir petty cash sebuah cabang.
     *
     * Empat syarat:
     *
     *   - sudah DITERIMA (RECEIVED) -- bukan sekadar disetujui, karena di
     *     tahap penerimaanlah nominalnya terakhir bisa berubah;
     *   - nominalnya tidak melebihi ambang cabang itu;
     *   - cabangnya sama;
     *   - belum terikat formulir petty cash yang masih hidup.
     *
     * Syarat terakhir dibaca dari petty_cash_claims.released_at, bukan dari
     * status formulirnya: itulah kolom yang dijaga indeks unik, jadi membaca
     * dari sumber yang sama membuat daftar ini dan penolakan database tidak
     * pernah berbeda pendapat.
     *
     * @return Collection<int, Claim>
     */
    public function eligibleClaims(int $branchId, array $kecuali = []): Collection
    {
        $plafon = $this->ceilingForBranch($branchId);

        if (!$plafon) {
            return collect();
        }

        return Claim::query()
            ->whereNull('deleted_at')
            ->where('branch', (string) $branchId)
            ->where('status', Claim::STATUS_RECEIVED)
            ->where('total_amount', '<=', (float) $plafon->claim_threshold)
            ->where('total_amount', '>', 0)
            ->where(function ($query) use ($kecuali) {
                $query->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('petty_cash_claims')
                        ->whereColumn('petty_cash_claims.claim_id', 'claims.id')
                        ->whereNull('petty_cash_claims.released_at');
                });

                /*
                | Claim yang sudah ada di formulir yang sedang DIUBAH tetap
                | ditawarkan -- kalau tidak, membuka formulir untuk disunting
                | akan memperlihatkan isinya sendiri sebagai tidak tersedia.
                */
                if ($kecuali !== []) {
                    $query->orWhereIn('claims.id', $kecuali);
                }
            })
            ->with(['creator:id,name,username', 'departmentData:id,nama'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Formulir petty cash hidup yang sudah memakai claim ini, bila ada.
     *
     * Dipakai menyusun pesan penolakan: "sudah dipakai PTC/SLW/FIN/26/XI/003"
     * jauh lebih berguna daripada "claim tidak tersedia".
     */
    public function livePettyCashNumberFor(int $claimId): ?string
    {
        return DB::table('petty_cash_claims as pcc')
            ->join('petty_cashes as pc', 'pc.id', '=', 'pcc.petty_cash_id')
            ->where('pcc.claim_id', $claimId)
            ->whereNull('pcc.released_at')
            ->value('pc.petty_cash_number');
    }
}
