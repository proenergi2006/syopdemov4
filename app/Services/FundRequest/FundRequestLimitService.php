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
     * Batas yang berlaku untuk sebuah cabang dan department.
     *
     * Cakupannya dua DAFTAR, bukan dua kolom: sebuah batas boleh menyebut
     * beberapa cabang dan beberapa department sekaligus. Daftar yang kosong
     * berarti "berlaku untuk semua" -- itu yang membuat batas umum menjadi
     * batas umum.
     *
     * Cabang atau department yang TIDAK DIKETAHUI (null) hanya cocok dengan
     * batas yang tidak membatasi sumbu itu. Mencocokkannya ke batas yang
     * menyebut cabang tertentu berarti menebak, dan tebakan di sini berujung
     * pada orang yang diblokir oleh batas yang bukan miliknya.
     *
     * Mengembalikan null bila tidak ada satu pun batas aktif yang cocok --
     * keadaan yang seharusnya tidak pernah terjadi selama batas umum masih
     * ada, dan karena itu dicatat sebagai peringatan.
     */
    public function resolve(
        int|string|null $branchId = null,
        ?int $departmentId = null,
    ): ?FundRequestLimit {
        $cabang = $branchId !== null && (string) $branchId !== '' ? (int) $branchId : null;

        $batas = FundRequestLimit::query()
            ->with(['branches:id,nama_cabang', 'departments:id,nama'])
            ->withCount(['branches', 'departments'])
            ->where('is_active', true)

            ->where(function ($q) use ($cabang): void {
                $q->whereDoesntHave('branches');

                if ($cabang !== null) {
                    $q->orWhereHas(
                        'branches',
                        fn ($b) => $b->where('fund_request_limit_branches.cabang_id', $cabang),
                    );
                }
            })

            ->where(function ($q) use ($departmentId): void {
                $q->whereDoesntHave('departments');

                if ($departmentId !== null) {
                    $q->orWhereHas(
                        'departments',
                        fn ($d) => $d->where(
                            'fund_request_limit_departments.department_id',
                            $departmentId,
                        ),
                    );
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
                'branch_id' => $cabang,
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
     * - Realisasi melepaskan FPU-nya begitu BERKASNYA DITERIMA FINANCE --
     *   bukan sejak disetujui approver terakhir. Disetujui saja belum berarti
     *   berkasnya berpindah tangan; aturan ini ada supaya uang tidak menumpuk
     *   tanpa pertanggungjawaban, dan pertanggungjawaban baru nyata ketika
     *   Finance memegang berkasnya. Syaratnya ditulis sekali saja, di
     *   CashAdvanceRealization::saringPelepasKuota() -- dibaca juga oleh kedua
     *   dashboard, supaya tidak ada tiga pendapat tentang satu angka.
     *
     * KUOTANYA PER CABANG, bukan per akun.
     *
     * Satu akun bisa memegang beberapa cabang, dan jatah tiap cabang berdiri
     * sendiri: dua FPU di cabang A menyisakan satu di cabang A, sementara
     * cabang B masih utuh. Tanpa $branchId, yang dihitung seluruh cabang
     * sekaligus -- angka yang benar hanya untuk akun bercabang tunggal, dan
     * diam-diam salah untuk yang tidak.
     *
     * @param  int|string|null  $branchId  Dikosongkan berarti seluruh cabang.
     */
    public function outstandingQuery(int $userId, int|string|null $branchId = null)
    {
        return CashAdvance::query()
            ->where('created_by', $userId)
            ->where('status', CashAdvance::STATUS_DISBURSED)

            /*
            | cash_advances.branch bertipe teks, jadi dibandingkan sebagai
            | teks. Melewatkan integer ke kolom varchar membuat PostgreSQL
            | melempar galat tipe, bukan mengembalikan nol baris.
            */
            ->when(
                $branchId !== null && (string) $branchId !== '',
                fn ($q) => $q->where('cash_advances.branch', (string) $branchId),
            )

            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('cash_advance_realizations as r')
                    ->whereColumn('r.cash_advance_id', 'cash_advances.id')
                    ->whereNull('r.deleted_at');

                CashAdvanceRealization::saringPelepasKuota($q, 'r');
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
     *   reasons: string[],
     *   branch_id: string|null
     * }
     *
     * @param  int|string|null  $branchId  Cabang yang dinilai. Menentukan DUA
     *                                     hal sekaligus: FPU mana yang ikut
     *                                     dihitung, dan batas mana yang
     *                                     berlaku. Dikosongkan berarti seluruh
     *                                     cabang sekaligus -- hanya benar untuk
     *                                     akun bercabang tunggal.
     */
    public function evaluate(
        int $userId,
        int|string|null $branchId = null,
        ?int $departmentId = null,
        ?CarbonInterface $now = null,
    ): array {
        $batas = $this->resolve($branchId, $departmentId);

        $berjalan = $this->outstandingQuery($userId, $branchId)
            ->get(['id', 'advance_number', 'disbursed_at']);

        /*
        | Batas yang memang tidak membatasi apa pun.
        |
        | Jumlah FPU berjalan tetap dihitung dan dikirim -- itu kabar yang
        | berguna, dan pemiliknya berhak tahu berapa yang masih dipegangnya.
        | Yang tidak dikirim angka batasnya: tidak ada angka untuk dibanding,
        | dan mengirim angka yang tidak berlaku membuat layar menggambar
        | 'sudah 3 dari 3' pada orang yang justru dibebaskan.
        */
        if ($batas && $batas->is_unlimited) {
            return [
                'limit' => $batas,
                'outstanding' => $berjalan->count(),
                'max_outstanding' => null,
                'max_days' => null,
                'overdue' => [],
                'blocked' => false,
                'reasons' => [],
                'unlimited' => true,
                'branch_id' => $branchId !== null ? (string) $branchId : null,
            ];
        }

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

            /* Selalu ada, supaya yang membacanya tidak perlu memeriksa keberadaannya. */
            'unlimited' => false,

            'branch_id' => $branchId !== null ? (string) $branchId : null,
        ];
    }

    /*
    | areaTypeOf() DICABUT.
    |
    | Batas tidak lagi dicocokkan ke "HO atau cabang", melainkan ke daftar
    | cabang yang disebut satu per satu. Membiarkan fungsinya berdiri akan
    | mengundang pemanggil baru yang mengira sumbu area masih berlaku.
    */
}
