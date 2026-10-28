<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Membuang baris approval milik akun nonaktif
|--------------------------------------------------------------------------
| Generator approval dulu mencairkan step ROLE menjadi seluruh pemegang role
| itu tanpa memeriksa apakah akunnya masih aktif. Akibatnya riwayat approval
| memuat nama-nama yang tidak akan pernah menyetujui apa pun, dan keterangan
| "cukup satu dari 3 approver" terbaca jauh lebih longgar daripada
| kenyataannya -- yang benar-benar bisa bertindak mungkin hanya satu orang.
|
| Generatornya sudah diperbaiki, jadi dokumen baru tidak akan membawa baris
| seperti ini lagi. Seeder ini membereskan yang terlanjur.
|
| DUA PAGAR, DAN KEDUANYA PENTING:
|
| 1. Hanya baris yang BELUM ditindak (PENDING/WAITING) yang dibuang. Baris
|    APPROVED atau REJECTED adalah sejarah -- persetujuannya benar-benar
|    terjadi, lengkap dengan tanda tangannya, dan akun yang dimatikan
|    belakangan tidak membatalkan hal itu.
|
| 2. Tingkat yang akan menjadi kosong tidak disentuh. Lebih baik satu nama
|    nonaktif tersisa di layar daripada sebuah dokumen kehilangan seluruh
|    penyetuju pada satu tingkat dan berhenti tanpa bisa dilanjutkan.
|
| AMAN DIJALANKAN BERULANG.
|--------------------------------------------------------------------------
*/
class ApprovalRowsOfInactiveUsersCleanupSeeder extends Seeder
{
    /** @var array<string, array{0: string, 1: string}> */
    private const TABEL = [
        'FPU' => ['cash_advance_approvals', 'cash_advance_id'],
        'Realisasi' => ['cash_advance_realization_approvals', 'cash_advance_realization_id'],
        'Claim' => ['claim_approvals', 'claim_id'],
        'Perdin' => ['business_trip_approvals', 'business_trip_id'],
    ];

    private const BELUM_DITINDAK = ['PENDING', 'WAITING'];

    public function run(): void
    {
        $totalDibuang = 0;
        $totalDipertahankan = 0;

        foreach (self::TABEL as $nama => [$tabel, $kunciDokumen]) {
            $calon = DB::table($tabel . ' as a')
                ->join('users as u', 'u.id', '=', 'a.approver_id')
                ->where('a.approver_type', 'USER')
                ->where('u.is_active', false)
                ->whereIn(DB::raw('upper(trim(a.status))'), self::BELUM_DITINDAK)
                ->orderBy('a.id')
                ->get([
                    'a.id',
                    'a.step_order',
                    "a.{$kunciDokumen} as dokumen_id",
                    'u.username',
                ]);

            if ($calon->isEmpty()) {
                $this->command?->info("{$nama}: tidak ada baris approval milik akun nonaktif.");

                continue;
            }

            $dibuang = [];
            $dipertahankan = [];

            foreach ($calon as $baris) {
                /*
                | Sisa penyetuju pada tingkat yang sama, di luar baris ini dan
                | di luar baris calon buangan lainnya pada tingkat itu.
                */
                $sisa = DB::table($tabel)
                    ->where($kunciDokumen, $baris->dokumen_id)
                    ->where('step_order', $baris->step_order)
                    ->where('id', '!=', $baris->id)
                    ->whereNotIn('id', array_column($dibuang, 'id'))
                    ->count();

                if ($sisa < 1) {
                    $dipertahankan[] = $baris->username . ' (tingkat ' . $baris->step_order . ')';

                    continue;
                }

                $dibuang[] = ['id' => $baris->id, 'username' => $baris->username];
            }

            if ($dibuang !== []) {
                DB::table($tabel)->whereIn('id', array_column($dibuang, 'id'))->delete();
            }

            $totalDibuang += count($dibuang);
            $totalDipertahankan += count($dipertahankan);

            $this->command?->info(sprintf(
                '%s: %d baris dibuang (%s)%s',
                $nama,
                count($dibuang),
                implode(', ', array_unique(array_column($dibuang, 'username'))) ?: '-',
                $dipertahankan !== []
                    ? ', ' . count($dipertahankan) . ' dipertahankan karena tingkatnya akan kosong: '
                        . implode(', ', $dipertahankan)
                    : '',
            ));
        }

        $this->command?->info(sprintf(
            'Selesai. %d baris dibuang, %d dipertahankan.',
            $totalDibuang,
            $totalDipertahankan,
        ));
    }
}
