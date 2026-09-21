<?php

namespace App\Services\FundRequest\CashAdvance;

use App\Models\CashAdvance;
use App\Support\DocumentNumberLock;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Penomoran FPU
|--------------------------------------------------------------------------
| Format : FPU/{cabang}/{department}/{tahun 2 angka}/{bulan romawi}/{urut 3 digit}
| Contoh : FPU/JKT/PROC/26/VIII/001
|
| Penanda FPU di depan supaya jenis dokumennya terbaca dari nomornya sendiri,
| tanpa perlu tahu dari mana nomor itu disalin. Sejalan dengan RLS pada
| Realisasi dan CLM pada Claim.
|
| Nomor urut berjalan PER KOMBINASI cabang + department, dan direset setiap
| pergantian tahun. Jadi JKT/PROC dan HO/GA punya deret masing-masing.
|
| DOKUMEN LAMA TIDAK DIUBAH. Nomor yang sudah tercetak dan ditandatangani
| tidak boleh berganti; yang berpenanda hanya dokumen sejak perubahan ini.
| Deretnya tetap menyambung, karena penghitungnya menghitung baris, bukan
| mencocokkan pola nomor.
|--------------------------------------------------------------------------
*/
class CashAdvanceNumberService
{
    private const PREFIX = 'FPU';

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
     * Nomor asli baru dibentuk saat submit, supaya deret nomor tidak
     * terpakai oleh draft yang batal diajukan.
     */
    public function generateDraftNumber(): string
    {
        $year = (int) now()->format('Y');

        /*
        | Deret draft dipakai bersama seluruh modul ini, jadi kuncinya cukup
        | satu per tahun.
        */
        DocumentNumberLock::acquire('fpu', 'draft', (string) $year);

        $sequence = CashAdvance::withTrashed()
            ->whereYear('created_at', $year)
            ->count() + 1;

        do {
            $number = sprintf('DRAFT/%s/%d/%04d', self::PREFIX, $year, $sequence);

            $exists = CashAdvance::withTrashed()
                ->where('advance_number', $number)
                ->exists();

            if ($exists) {
                $sequence++;
            }
        } while ($exists);

        return $number;
    }

    /**
     * Nomor final saat dokumen disubmit.
     *
     * @param  CashAdvance  $cashAdvance  Dokumen yang sedang disubmit.
     */
    public function generateFinalNumber(CashAdvance $cashAdvance): string
    {
        $branchCode = $this->resolveBranchCode($cashAdvance->branch);
        $departmentCode = $this->resolveDepartmentCode($cashAdvance->department_id);

        $now = now();
        $year = (int) $now->format('Y');

        $shortYear = $now->format('y');

        /*
        | Deretnya terpisah per cabang dan department, jadi kuncinya pun
        | terpisah -- cabang lain tidak perlu ikut menunggu.
        */
        DocumentNumberLock::acquire(
            'fpu',
            'final',
            (string) $cashAdvance->branch,
            (string) $cashAdvance->department_id,
            (string) $year,
        );
        $romanMonth = self::ROMAN_MONTHS[(int) $now->format('n')];

        /*
        | Hitung dokumen pada kombinasi cabang + department di tahun berjalan.
        |
        | Draft tidak ikut dihitung karena belum memakai nomor final. Dokumen
        | yang sudah dihapus tetap dihitung agar nomornya tidak dipakai ulang.
        */
        $used = CashAdvance::withTrashed()
            ->where('branch', $cashAdvance->branch)
            ->where('department_id', $cashAdvance->department_id)
            ->whereYear('created_at', $year)
            ->where('advance_number', 'NOT LIKE', 'DRAFT/%')
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

            $exists = CashAdvance::withTrashed()
                ->where('advance_number', $number)
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
    | Bila kode tidak ditemukan, dipakai penanda agar nomor tetap terbentuk
    | dan kejanggalannya langsung terlihat -- lebih baik daripada gagal
    | menyimpan dokumen atau menghasilkan nomor dengan segmen kosong.
    */

    private function resolveBranchCode($branch): string
    {
        $code = DB::table('cabang')
            ->where('id', (int) $branch)
            ->value('inisial_cabang');

        return $this->normalizeCode($code, 'NA');
    }

    private function resolveDepartmentCode($departmentId): string
    {
        $code = DB::table('departments')
            ->where('id', (int) $departmentId)
            ->value('kode');

        return $this->normalizeCode($code, 'NA');
    }

    private function normalizeCode($code, string $fallback): string
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return $fallback;
        }

        /*
        | Garis miring adalah pemisah segmen nomor, jadi tidak boleh muncul
        | di dalam kode.
        */
        return str_replace('/', '-', $code);
    }
}
