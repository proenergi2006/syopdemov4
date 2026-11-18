<?php

namespace App\Services\FundRequest\PettyCash;

use App\Models\PettyCash;
use App\Models\PettyCashTopup;
use App\Support\DocumentNumberLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Penomoran Petty Cash dan Top Up
|--------------------------------------------------------------------------
| Format : {PTC|PTCT}/{cabang}/{department}/{tahun 2 angka}/{bulan romawi}/{urut 3 digit}
| Contoh : PTC/SLW/FIN/26/XI/004
|
| Aturannya sama persis dengan CLM dan FPU, termasuk:
|
|   - nomor sementara selama DRAFT, supaya deret nomor resmi tidak terpakai
|     oleh draft yang batal diajukan;
|   - deret berjalan per kombinasi cabang + department, direset tiap tahun;
|   - dokumen terhapus tetap dihitung, supaya nomornya tidak dipakai ulang.
|
| Satu kelas untuk dua dokumen, sejalan dengan generator approval-nya: yang
| membedakan keduanya hanya penanda di depan dan tabelnya.
|--------------------------------------------------------------------------
*/
class PettyCashNumberService
{
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
     * @return array{prefix: string, draft: string, kunci: string, kelas: class-string<Model>, kolom: string}
     */
    private function config(string $jenis): array
    {
        if ($jenis === PettyCashTopup::class) {
            return [
                'prefix' => 'PTCT',
                'draft' => 'PTCTOPUP',
                'kunci' => 'petty_cash_topup',
                'kelas' => PettyCashTopup::class,
                'kolom' => 'topup_number',
            ];
        }

        return [
            'prefix' => 'PTC',
            'draft' => 'PTCASH',
            'kunci' => 'petty_cash',
            'kelas' => PettyCash::class,
            'kolom' => 'petty_cash_number',
        ];
    }

    /**
     * Nomor sementara saat dokumen masih DRAFT.
     *
     * @param  class-string<Model>  $jenis
     */
    public function generateDraftNumber(string $jenis = PettyCash::class): string
    {
        $cfg = $this->config($jenis);

        /** @var class-string<Model> $kelas */
        $kelas = $cfg['kelas'];

        $tahun = (int) now()->format('Y');

        /*
        | Deret draft dipakai bersama seluruh dokumen jenis ini, jadi kuncinya
        | cukup satu per tahun.
        */
        DocumentNumberLock::acquire($cfg['kunci'], 'draft', (string) $tahun);

        $urut = $kelas::withTrashed()
            ->whereYear('created_at', $tahun)
            ->count() + 1;

        do {
            $nomor = sprintf('DRAFT/%s/%d/%04d', $cfg['draft'], $tahun, $urut);

            $ada = $kelas::withTrashed()
                ->where($cfg['kolom'], $nomor)
                ->exists();

            if ($ada) {
                $urut++;
            }
        } while ($ada);

        return $nomor;
    }

    /**
     * Nomor final saat dokumen disubmit.
     */
    public function generateFinalNumber(Model $document): string
    {
        $cfg = $this->config($document::class);

        /** @var class-string<Model> $kelas */
        $kelas = $cfg['kelas'];

        $kodeCabang = $this->resolveBranchCode($document->branch);
        $kodeDepartment = $this->resolveDepartmentCode($document->department_id);

        $sekarang = now();
        $tahun = (int) $sekarang->format('Y');

        /*
        | Deretnya terpisah per cabang dan department, jadi kuncinya pun
        | terpisah -- cabang lain tidak perlu ikut menunggu.
        */
        DocumentNumberLock::acquire(
            $cfg['kunci'],
            'final',
            (string) $document->branch,
            (string) $document->department_id,
            (string) $tahun,
        );

        $terpakai = $kelas::withTrashed()
            ->where('branch', $document->branch)
            ->where('department_id', $document->department_id)
            ->whereYear('created_at', $tahun)
            ->where($cfg['kolom'], 'NOT LIKE', 'DRAFT/%')
            ->count();

        $urut = $terpakai + 1;

        do {
            $nomor = sprintf(
                '%s/%s/%s/%s/%s/%03d',
                $cfg['prefix'],
                $kodeCabang,
                $kodeDepartment,
                $sekarang->format('y'),
                self::ROMAN_MONTHS[(int) $sekarang->format('n')],
                $urut,
            );

            $ada = $kelas::withTrashed()
                ->where($cfg['kolom'], $nomor)
                ->exists();

            if ($ada) {
                $urut++;
            }
        } while ($ada);

        return $nomor;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    | Bila kode tidak ditemukan, dipakai penanda agar nomor tetap terbentuk
    | dan kejanggalannya langsung terlihat -- lebih baik daripada gagal
    | menyimpan dokumen atau menghasilkan nomor dengan segmen kosong.
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

    private function normalizeCode($code, string $fallback = 'NA'): string
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
