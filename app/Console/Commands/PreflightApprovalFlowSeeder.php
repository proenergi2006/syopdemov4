<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

/*
|--------------------------------------------------------------------------
| Pra-periksa sebelum seeder approval flow dijalankan
|--------------------------------------------------------------------------
| Dijalankan DI PRODUCTION, sebelum seedernya. Ia tidak menulis apa pun.
|
| KENAPA PERLU.
|
| Seeder approval flow mencari seluruh acuannya lewat nama dan kode: role
| lewat nama, akun lewat username, cabang lewat nama cabang, keterangan
| transaksi lewat kode. Acuan yang tidak ketemu membuat flow itu DILEWATI --
| dengan peringatan, tetapi tetap dilewati, dan peringatan di tengah ratusan
| baris keluaran mudah terlewat. Yang tersisa adalah matriks yang bolong, dan
| bolongnya baru terasa pada hari dokumen seseorang berhenti tanpa approver.
|
| Perintah ini menyebut kekurangannya SEBELUM apa pun ditulis, dalam daftar
| yang bisa langsung dikerjakan.
|
| SATU LAGI YANG DIPERIKSA: LINGKUP YANG BERTABRAKAN.
|
| Seeder mengenali "flow ini sudah ada" dari nama BESERTA lingkupnya. Flow
| production yang lingkupnya sama tetapi namanya berbeda tidak akan
| dikenali, dan flow baru akan tetap dibuat -- dua flow bersaing untuk
| kombinasi yang sama, dan yang terpilih bergantung urutan id. Itu bukan
| kesalahan yang dilaporkan seeder, jadi dilaporkan di sini.
|--------------------------------------------------------------------------
*/
class PreflightApprovalFlowSeeder extends Command
{
    protected $signature = 'approval-flow:preflight
        {--class=FundRequestApprovalFlowSeeder : Kelas seeder yang akan diperiksa}';

    protected $description = 'Memeriksa kesiapan basis data sebelum seeder approval flow dijalankan';

    public function handle(): int
    {
        $kelas = 'Database\\Seeders\\' . (string) $this->option('class');

        if (!class_exists($kelas)) {
            $this->error("Kelas seeder tidak ditemukan: {$kelas}");

            return self::FAILURE;
        }

        $matriks = (new ReflectionClass($kelas))->getConstant('MATRIKS');

        if (!is_array($matriks) || $matriks === []) {
            $this->error('Seeder itu tidak memuat MATRIKS.');

            return self::FAILURE;
        }

        $this->info('Memeriksa ' . count($matriks) . ' flow dari ' . $this->option('class') . '.');
        $this->newLine();

        /* ------------------------------------------------ 1. acuan ---- */
        $butuh = [
            'role' => ['tabel' => 'roles', 'kolom' => 'nama', 'nilai' => []],
            'akun' => ['tabel' => 'users', 'kolom' => 'username', 'nilai' => []],
            'department' => ['tabel' => 'departments', 'kolom' => 'nama', 'nilai' => []],
            'cabang' => ['tabel' => 'cabang', 'kolom' => 'nama_cabang', 'nilai' => []],
            'keterangan transaksi' => [
                'tabel' => 'fund_request_transaction_categories',
                'kolom' => 'code',
                'nilai' => [],
            ],
            'jenis dokumen khusus' => [
                'tabel' => 'special_document_types',
                'kolom' => 'code',
                'nilai' => [],
            ],
            'modul permission' => ['tabel' => 'permission_modules', 'kolom' => 'code', 'nilai' => []],
        ];

        foreach ($matriks as $flow) {
            foreach ((array) ($flow['departments'] ?? []) as $x) {
                $butuh['department']['nilai'][] = $x;
            }

            foreach ((array) ($flow['transaction_categories'] ?? []) as $x) {
                $butuh['keterangan transaksi']['nilai'][] = $x;
            }

            if (!empty($flow['cabang'])) {
                $butuh['cabang']['nilai'][] = $flow['cabang'];
            }

            if (!empty($flow['permission_module'])) {
                $butuh['modul permission']['nilai'][] = $flow['permission_module'];
            }

            foreach ((array) ($flow['steps'] ?? []) as $step) {
                $this->kumpulkanApprover($butuh, $step['approver'] ?? []);

                foreach ((array) ($step['branches'] ?? []) as $x) {
                    $butuh['cabang']['nilai'][] = $x;
                }

                foreach ((array) ($step['skip_for_roles'] ?? []) as $x) {
                    $butuh['role']['nilai'][] = $x;
                }

                foreach ((array) ($step['special_approvers'] ?? []) as $khusus) {
                    if (!empty($khusus['document_type_code'])) {
                        $butuh['jenis dokumen khusus']['nilai'][] = $khusus['document_type_code'];
                    }

                    $this->kumpulkanApprover($butuh, $khusus);
                }
            }
        }

        $adaYangKurang = false;

        foreach ($butuh as $nama => $data) {
            $nilai = collect($data['nilai'])->filter()->unique()->sort()->values();

            if ($nilai->isEmpty()) {
                continue;
            }

            $ada = DB::table($data['tabel'])->pluck($data['kolom'])->all();
            $kurang = $nilai->reject(fn ($v): bool => in_array($v, $ada, true))->values();

            $this->line(sprintf(
                '  %-22s %3d dibutuhkan, %3d ada, %s',
                $nama,
                $nilai->count(),
                $nilai->count() - $kurang->count(),
                $kurang->isEmpty() ? 'lengkap' : $kurang->count() . ' KURANG',
            ));

            foreach ($kurang as $k) {
                $this->warn("      tidak ada: {$k}");
                $adaYangKurang = true;
            }
        }

        /* ---------------------------------- 2. lingkup bertabrakan ---- */
        $this->newLine();

        $tabrakan = 0;

        foreach ($matriks as $flow) {
            $dept = (array) ($flow['departments'] ?? []);
            $kat = (array) ($flow['transaction_categories'] ?? []);

            sort($dept);
            sort($kat);

            $kandidat = DB::table('approval_flows')
                ->whereNull('deleted_at')
                ->whereRaw('UPPER(TRIM(document_type)) = ?', [strtoupper((string) $flow['document_type'])])
                ->whereRaw('UPPER(TRIM(COALESCE(area_type, \'\'))) = ?', [strtoupper((string) ($flow['area_type'] ?? ''))])
                ->where('min_amount', $flow['min_amount'])
                ->where('max_amount', $flow['max_amount'])
                ->where('name', '!=', $flow['name'])
                ->get(['id', 'name']);

            foreach ($kandidat as $ada) {
                $adaDept = DB::table('approval_flow_departments as p')
                    ->join('departments as d', 'd.id', '=', 'p.department_id')
                    ->where('p.approval_flow_id', $ada->id)
                    ->pluck('d.nama')
                    ->all();

                $adaKat = DB::table('approval_flow_transaction_categories as p')
                    ->join('fund_request_transaction_categories as c', 'c.id', '=', 'p.transaction_category_id')
                    ->where('p.approval_flow_id', $ada->id)
                    ->pluck('c.code')
                    ->all();

                sort($adaDept);
                sort($adaKat);

                if ($adaDept === $dept && $adaKat === $kat) {
                    $this->warn(sprintf(
                        '  LINGKUP BERTABRAKAN  %s %s %s: sudah ada flow #%d "%s"',
                        $flow['document_type'],
                        $flow['area_type'] ?? '-',
                        number_format((float) $flow['min_amount'], 0, ',', '.')
                            . '-' . number_format((float) $flow['max_amount'], 0, ',', '.'),
                        $ada->id,
                        $ada->name,
                    ));

                    $tabrakan++;
                }
            }
        }

        if ($tabrakan === 0) {
            $this->line('  lingkup bertabrakan     tidak ada');
        }

        /* --------------------------------------------- kesimpulan ----- */
        $this->newLine();

        if ($adaYangKurang) {
            $this->error('BELUM SIAP. Lengkapi acuan yang disebut di atas lebih dulu.');
            $this->line('Flow yang memakai acuan yang tidak ada akan DILEWATI, bukan gagal.');

            return self::FAILURE;
        }

        if ($tabrakan > 0) {
            $this->warn(
                'Acuannya lengkap, tetapi ada ' . $tabrakan . ' lingkup yang bertabrakan.'
            );
            $this->line(
                'Seeder tidak akan mengenalinya sebagai flow yang sama -- hasilnya dua flow'
            );
            $this->line(
                'bersaing untuk kombinasi yang sama. Putuskan dulu mana yang dipakai.'
            );

            return self::FAILURE;
        }

        $this->info('SIAP. Seluruh acuan ada, dan tidak ada lingkup yang bertabrakan.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $butuh
     * @param  array<string, mixed>  $approver
     */
    private function kumpulkanApprover(array &$butuh, array $approver): void
    {
        $tipe = strtoupper((string) ($approver['type'] ?? $approver['approver_type'] ?? ''));
        $ref = $approver['ref'] ?? null;

        if (!$ref) {
            return;
        }

        match ($tipe) {
            'ROLE' => $butuh['role']['nilai'][] = $ref,
            'USER' => $butuh['akun']['nilai'][] = $ref,
            'DEPARTMENT' => $butuh['department']['nilai'][] = $ref,
            default => null,
        };
    }
}
