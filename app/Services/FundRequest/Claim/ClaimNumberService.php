<?php

namespace App\Services\FundRequest\Claim;

use App\Models\Claim;
use App\Support\DocumentNumberLock;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Penomoran Claim
|--------------------------------------------------------------------------
| Format : CLM/{cabang}/{department}/{tahun 2 angka}/{bulan romawi}/{urut 3 digit}
| Contoh : CLM/JKT/POOL/26/III/019
|
| Penanda CLM di depan supaya jenis dokumennya terbaca dari nomornya sendiri.
| Dipendekkan, bukan 'CLAIM', supaya sebanding dengan FPU dan RLS -- nomornya
| sudah panjang, dan penanda yang lebih panjang dari yang lain membuat kolom
| nomor di daftar ikut melebar.
|
| DOKUMEN LAMA TIDAK DIUBAH. Nomor yang sudah tercetak dan ditandatangani
| tidak boleh berganti; yang berpenanda hanya dokumen sejak perubahan ini.
| Deretnya tetap menyambung, karena penghitungnya menghitung baris, bukan
| mencocokkan pola nomor.
|
| Nomor urut berjalan PER KOMBINASI cabang + department, dan direset setiap
| pergantian tahun. Jadi JKT/POOL dan HO/GA punya deret masing-masing.
|
| Nomor final baru dibentuk saat submit. Selama masih draft dokumen memakai
| nomor sementara, supaya deret nomor tidak terpakai oleh draft yang batal
| diajukan. Aturannya sama persis dengan FPU.
|--------------------------------------------------------------------------
*/
class ClaimNumberService
{
    private const PREFIX = 'CLM';

    /*
    | Nomor draft tetap memakai kata penuh, tidak ikut dipendekkan.
    |
    | Yang diminta diberi identitas adalah nomor resmi. Draft sudah punya
    | identitasnya sendiri sejak awal, dan menggantinya hanya akan membuat draft
    | lama dan baru berbeda bentuk tanpa ada yang diperoleh.
    */
    private const DRAFT_PREFIX = 'CLAIM';

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
     */
    public function generateDraftNumber(): string
    {
        $year = (int) now()->format('Y');

        /*
        | Deret draft dipakai bersama seluruh modul ini, jadi kuncinya cukup
        | satu per tahun.
        */
        DocumentNumberLock::acquire('claim', 'draft', (string) $year);

        $sequence = Claim::withTrashed()
            ->whereYear('created_at', $year)
            ->count() + 1;

        do {
            $number = sprintf('DRAFT/%s/%d/%04d', self::DRAFT_PREFIX, $year, $sequence);

            $exists = Claim::withTrashed()
                ->where('claim_number', $number)
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
    public function generateFinalNumber(Claim $claim): string
    {
        $branchCode = $this->resolveBranchCode($claim->branch);
        $departmentCode = $this->resolveDepartmentCode($claim->department_id);

        $now = now();
        $year = (int) $now->format('Y');

        $shortYear = $now->format('y');

        /*
        | Deretnya terpisah per cabang dan department, jadi kuncinya pun
        | terpisah -- cabang lain tidak perlu ikut menunggu.
        */
        DocumentNumberLock::acquire(
            'claim',
            'final',
            (string) $claim->branch,
            (string) $claim->department_id,
            (string) $year,
        );
        $romanMonth = self::ROMAN_MONTHS[(int) $now->format('n')];

        /*
        | Hitung dokumen pada kombinasi cabang + department di tahun berjalan.
        |
        | Draft tidak ikut dihitung karena belum memakai nomor final. Dokumen
        | yang sudah dihapus tetap dihitung agar nomornya tidak dipakai ulang.
        */
        $used = Claim::withTrashed()
            ->where('branch', $claim->branch)
            ->where('department_id', $claim->department_id)
            ->whereYear('created_at', $year)
            ->where('claim_number', 'NOT LIKE', 'DRAFT/%')
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

            $exists = Claim::withTrashed()
                ->where('claim_number', $number)
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
