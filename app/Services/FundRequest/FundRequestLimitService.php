<?php

namespace App\Services\FundRequest;

use App\Models\CashAdvance;
use App\Models\CashAdvanceRealization;
use App\Models\FundRequestLimit;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Memilih batas pengajuan yang berlaku
|--------------------------------------------------------------------------
| Sebuah FPU dinilai berdasarkan area dan department-nya. Dua sumbu itu bisa
| membuat lebih dari satu batas cocok sekaligus; yang paling khusus menang.
|
| Penjagaannya sendiri -- menghitung FPU berjalan dan umurnya -- belum ada di
| sini. Yang ini baru masternya: menjawab "berapa batasnya", bukan "apakah
| sudah lewat".
|--------------------------------------------------------------------------
*/
class FundRequestLimitService
{
    /**
     * Batas yang berlaku untuk sebuah area dan department.
     *
     * Mengembalikan null bila tidak ada satu pun batas aktif yang cocok --
     * keadaan yang seharusnya tidak pernah terjadi selama batas umum masih
     * ada, dan karena itu dicatat sebagai peringatan.
     */
    public function resolve(
        ?string $areaType = null,
        ?int $departmentId = null,
    ): ?FundRequestLimit {
        $batas = FundRequestLimit::query()
            ->with('department:id,nama')
            ->where('is_active', true)
            ->where(function ($q) use ($areaType): void {
                $q->whereNull('area_type');

                if ($areaType !== null) {
                    $q->orWhere('area_type', $areaType);
                }
            })
            ->where(function ($q) use ($departmentId): void {
                $q->whereNull('department_id');

                if ($departmentId !== null) {
                    $q->orWhere('department_id', $departmentId);
                }
            })
            ->get()

            /*
            | Kalau bobotnya seri -- dua batas sama-sama menyebut department
            | yang sama -- yang dipakai adalah yang paling dulu dibuat, supaya
            | hasilnya tetap sama dari waktu ke waktu dan bukan bergantung
            | urutan baris yang kebetulan.
            */
            ->sort(fn (FundRequestLimit $a, FundRequestLimit $b): int =>
                [$b->scope_weight, $a->id] <=> [$a->scope_weight, $b->id])
            ->first();

        if (!$batas) {
            Log::warning('[Batas FPU] Tidak ada batas yang cocok', [
                'area_type' => $areaType,
                'department_id' => $departmentId,
            ]);
        }

        return $batas;
    }

    /**
     * FPU milik seseorang yang masih berjalan.
     *
     * "Berjalan" berarti DANANYA SUDAH KELUAR dan belum dipertanggungjawabkan.
     * Dua batasan itu disengaja:
     *
     * - Yang belum cair tidak dihitung. Tujuan aturannya mencegah uang
     *   menumpuk tanpa pertanggungjawaban, dan dokumen yang belum cair belum
     *   memegang uang siapa pun. Menghitungnya juga akan menghukum pemohon
     *   atas dokumen yang macet di meja approver, bukan di mejanya sendiri.
     *
     * - Realisasi baru melepaskan FPU-nya setelah APPROVED. Realisasi yang
     *   masih berjalan berarti pertanggungjawabannya belum diterima.
     */
    public function outstandingQuery(int $userId)
    {
        return CashAdvance::query()
            ->where('created_by', $userId)
            ->where('status', CashAdvance::STATUS_DISBURSED)
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('cash_advance_realizations as r')
                    ->whereColumn('r.cash_advance_id', 'cash_advances.id')
                    ->whereNull('r.deleted_at')
                    ->whereIn('r.status', [
                        CashAdvanceRealization::STATUS_APPROVED,
                        CashAdvanceRealization::STATUS_SETTLED,
                    ]);
            });
    }

    /**
     * Menilai apakah seseorang masih boleh mengajukan FPU baru.
     *
     * Mengembalikan gambaran utuh -- bukan sekadar boleh atau tidak -- supaya
     * layar bisa memberi tahu pemohon SEBELUM ia mengisi formulir, memakai
     * angka yang sama persis dengan yang dipakai penolakannya.
     *
     * @return array{
     *   limit: FundRequestLimit|null,
     *   outstanding: int,
     *   max_outstanding: int|null,
     *   max_days: int|null,
     *   overdue: array<int, array{number: string, days: int}>,
     *   blocked: bool,
     *   reasons: string[]
     * }
     */
    public function evaluate(
        int $userId,
        ?string $areaType = null,
        ?int $departmentId = null,
        ?CarbonInterface $now = null,
    ): array {
        $batas = $this->resolve($areaType, $departmentId);

        $berjalan = $this->outstandingQuery($userId)
            ->get(['id', 'advance_number', 'disbursed_at']);

        $hariIni = ($now ? Carbon::parse($now) : Carbon::now())->startOfDay();

        $telat = [];

        if ($batas) {
            foreach ($berjalan as $fpu) {
                if (!$fpu->disbursed_at) {
                    continue;
                }

                /* Hari kalender: Sabtu dan Minggu ikut dihitung. */
                $umur = Carbon::parse($fpu->disbursed_at)->startOfDay()->diffInDays($hariIni, false);

                if ($umur > $batas->max_realization_days) {
                    $telat[] = [
                        'number' => (string) $fpu->advance_number,
                        'days' => (int) $umur,
                    ];
                }
            }
        }

        $alasan = [];

        if ($batas && $berjalan->count() >= $batas->max_outstanding) {
            $alasan[] = 'TOO_MANY';
        }

        if ($telat !== []) {
            $alasan[] = 'OVERDUE';
        }

        return [
            'limit' => $batas,
            'outstanding' => $berjalan->count(),
            'max_outstanding' => $batas?->max_outstanding,
            'max_days' => $batas?->max_realization_days,
            'overdue' => $telat,

            /*
            | Tanpa batas yang cocok, tidak ada dasar untuk melarang apa pun.
            | Seharusnya tidak pernah terjadi selama batas umum masih ada --
            | dan resolve() sudah mencatatnya sebagai peringatan bila terjadi.
            */
            'blocked' => $batas !== null && $alasan !== [],
            'reasons' => $alasan,
        ];
    }

    /**
     * Area sebuah FPU, diturunkan dari cabangnya.
     *
     * Memakai aturan yang sama dengan mesin approval supaya tidak ada dokumen
     * yang dianggap HO oleh satu bagian dan cabang oleh bagian lain.
     */
    public function areaTypeOf(mixed $branch): string
    {
        return (string) $branch === (string) \App\Models\CashAdvance::HO_BRANCH_ID
            ? 'HO'
            : 'CABANG';
    }
}
