<?php

namespace App\Services\FundRequest\Realization;

use App\Models\CashAdvanceRealization;
use App\Support\DocumentNumberLock;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Penomoran Realisasi FPU
|--------------------------------------------------------------------------
| Format : RLS/{cabang}/{department}/{tahun 2 angka}/{bulan romawi}/{urut 3 digit}
| Contoh : RLS/HO/GA/26/VIII/001
|
| Penanda RLS di depan supaya tidak tertukar dengan nomor FPU yang formatnya
| sama. Nomor urut berjalan per kombinasi cabang + department dan direset tiap
| pergantian tahun, persis seperti FPU.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationNumberService
{
    private const PREFIX = 'RLS';

    private const ROMAN_MONTHS = [
        1 => 'I',
        2 => 'II',
        3 => 'III',
        4 => 'IV',
        5 => 'V',
        6 => 'VI',
        7 => 'VII',
        8 => 'VIII',
        9 => 'IX',
        10 => 'X',
        11 => 'XI',
        12 => 'XII',
    ];

    /**
     * Nomor sementara saat dokumen masih DRAFT.
     *
     * Nomor asli baru dibentuk saat submit, supaya deret nomor tidak terpakai
     * oleh draft yang batal diajukan.
     */
    public function generateDraftNumber(): string
    {
        $year = (int) now()->format('Y');

        /*
        | Deret draft dipakai bersama seluruh modul ini, jadi kuncinya cukup
        | satu per tahun.
        */
        DocumentNumberLock::acquire('realisasi', 'draft', (string) $year);

        $sequence = CashAdvanceRealization::withTrashed()
            ->whereYear('created_at', $year)
            ->count() + 1;

        do {
            $number = sprintf('DRAFT/%s/%d/%04d', self::PREFIX, $year, $sequence);

            $exists = CashAdvanceRealization::withTrashed()
                ->where('realization_number', $number)
                ->exists();

            if ($exists) {
                $sequence++;
            }
        } while ($exists);

        return $number;
    }

    /**
     * Nomor final saat dokumen disubmit.
     */
    public function generateFinalNumber(CashAdvanceRealization $realization): string
    {
        $branchCode = $this->resolveBranchCode($realization->branch);
        $departmentCode = $this->resolveDepartmentCode($realization->department_id);

        $now = now();
        $year = (int) $now->format('Y');

        $shortYear = $now->format('y');

        /*
        | Deretnya terpisah per cabang dan department, jadi kuncinya pun
        | terpisah -- cabang lain tidak perlu ikut menunggu.
        */
        DocumentNumberLock::acquire(
            'realisasi',
            'final',
            (string) $realization->branch,
            (string) $realization->department_id,
            (string) $year,
        );
        $romanMonth = self::ROMAN_MONTHS[(int) $now->format('n')];

        /*
        | Hitung dokumen pada kombinasi cabang + department di tahun berjalan.
        |
        | Draft tidak dihitung karena belum memakai nomor final. Dokumen yang
        | sudah dihapus tetap dihitung agar nomornya tidak dipakai ulang.
        */
        $used = CashAdvanceRealization::withTrashed()
            ->where('branch', $realization->branch)
            ->where('department_id', $realization->department_id)
            ->whereYear('created_at', $year)
            ->where('realization_number', 'NOT LIKE', 'DRAFT/%')
            ->count();

        $sequence = $used + 1;

        do {
            $number = sprintf(
                '%s/%s/%s/%s/%s/%03d',
                self::PREFIX,
                $branchCode,
                $departmentCode,
                $shortYear,
                $romanMonth,
                $sequence,
            );

            $exists = CashAdvanceRealization::withTrashed()
                ->where('realization_number', $number)
                ->exists();

            if ($exists) {
                $sequence++;
            }
        } while ($exists);

        return $number;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    | Bila kode tidak ditemukan, dipakai penanda agar nomor tetap terbentuk dan
    | kejanggalannya langsung terlihat -- lebih baik daripada gagal menyimpan
    | dokumen atau menghasilkan nomor dengan segmen kosong.
    |--------------------------------------------------------------------------
    */

    private function resolveBranchCode($branch): string
    {
        return $this->normalizeCode(
            DB::table('cabang')->where('id', (int) $branch)->value('inisial_cabang'),
        );
    }

    private function resolveDepartmentCode($departmentId): string
    {
        return $this->normalizeCode(
            DB::table('departments')->where('id', (int) $departmentId)->value('kode'),
        );
    }

    private function normalizeCode($code): string
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return 'NA';
        }

        // Garis miring adalah pemisah segmen, tidak boleh muncul di dalam kode.
        return str_replace('/', '-', $code);
    }
}
