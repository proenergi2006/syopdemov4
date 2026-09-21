<?php

namespace App\Services\BusinessTrip;

use App\Models\BusinessTrip;
use App\Support\DocumentNumberLock;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Penomoran Perdin
|--------------------------------------------------------------------------
| Format : PRDN/{cabang}/{tahun 2 angka}/{bulan romawi}/{urut 3 digit}
| Contoh : PRDN/HO/26/IX/001
|
| Penandanya di DEPAN, sejajar dengan FPU, RLS, dan CLM -- supaya jenis
| dokumen terbaca dari huruf pertama nomornya, di modul mana pun.
|
| Deretnya berjalan per cabang dan direset tiap pergantian tahun. Department
| tidak ikut jadi pembeda deret -- berbeda dari FPU, yang deretnya per cabang
| DAN department -- karena perdin dihitung sebagai keberangkatan dari sebuah
| cabang, bukan sebagai pengeluaran sebuah department.
|
| Perdin punya tahap draft seperti FPU: nomor sementara saat disimpan, nomor
| resmi saat diajukan -- supaya deret nomor resmi tidak terpakai oleh draft
| yang batal diajukan.
|--------------------------------------------------------------------------
*/
class BusinessTripNumberService
{
    private const PREFIX = 'PRDN';

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
     * Nomor resmi baru dibentuk saat diajukan, supaya deret nomor resmi tidak
     * terpakai oleh draft yang batal diajukan.
     */
    public function generateDraftNumber(): string
    {
        $year = (int) now()->format('Y');

        /*
        | Deret draft dipakai bersama seluruh modul ini, jadi kuncinya cukup
        | satu per tahun.
        */
        DocumentNumberLock::acquire('perdin', 'draft', (string) $year);

        $sequence = BusinessTrip::withTrashed()
            ->whereYear('created_at', $year)
            ->count() + 1;

        do {
            $number = sprintf('DRAFT/%s/%d/%04d', self::PREFIX, $year, $sequence);

            $exists = BusinessTrip::withTrashed()
                ->where('trip_number', $number)
                ->exists();

            if ($exists) {
                $sequence++;
            }
        } while ($exists);

        return $number;
    }

    /**
     * Nomor resmi saat dokumen diajukan.
     *
     * @param  string|int|null  $branch  id cabang, sebagaimana disimpan dokumen.
     */
    public function generateFinalNumber($branch): string
    {
        $branchCode = $this->resolveBranchCode($branch);

        $now = now();
        $year = (int) $now->format('Y');
        $shortYear = $now->format('y');
        $romanMonth = self::ROMAN_MONTHS[(int) $now->format('n')];

        /*
        | Dua permintaan yang bersamaan bisa sama-sama menghitung urutan yang
        | sama. Kuncinya per cabang per tahun, jadi cabang lain tidak ikut
        | menunggu.
        */
        DocumentNumberLock::acquire(
            'perdin',
            (string) $branch,
            (string) $year,
        );

        /*
        | Dokumen yang sudah dihapus tetap dihitung: nomor yang pernah dipakai
        | tidak boleh muncul kembali pada dokumen lain.
        */
        $used = BusinessTrip::withTrashed()
            ->where('branch', (string) $branch)
            ->whereYear('created_at', $year)
            ->where('trip_number', 'NOT LIKE', 'DRAFT/%')
            ->count();

        $sequence = $used + 1;

        do {
            $number = sprintf(
                '%s/%s/%s/%s/%03d',
                self::PREFIX,
                $branchCode,
                $shortYear,
                $romanMonth,
                $sequence,
            );

            $exists = BusinessTrip::withTrashed()
                ->where('trip_number', $number)
                ->exists();

            if ($exists) {
                $sequence++;
            }
        } while ($exists);

        return $number;
    }

    /*
    | Bila kode cabang tidak ditemukan, dipakai penanda supaya nomornya tetap
    | terbentuk dan kejanggalannya langsung terlihat -- lebih baik daripada
    | gagal menyimpan, atau menghasilkan nomor dengan segmen kosong.
    */
    private function resolveBranchCode($branch): string
    {
        $code = strtoupper(trim((string) DB::table('cabang')
            ->where('id', (int) $branch)
            ->value('inisial_cabang')));

        if ($code === '') {
            return 'NA';
        }

        /* Garis miring adalah pemisah segmen, tidak boleh ada di dalam kode. */
        return str_replace('/', '-', $code);
    }
}
