<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Matriks approval flow
|--------------------------------------------------------------------------
| DIHASILKAN OLEH `php artisan approval-flow:export-seeder` pada 2026-10-23 14:18:09.
| Jangan disunting dengan tangan -- isi matriksnya lewat layar master, lalu
| jalankan perintah itu lagi.
|
| 170 flow.
|
| SELURUH ACUAN BERUPA NAMA, BUKAN ID.
|
| Id di basis data tujuan hampir pasti berbeda. Seeder yang menyalin id akan
| tersimpan tanpa satu pun galat, lalu mengirim dokumen ke approver yang
| salah -- kesalahan yang baru ketahuan saat dokumen pertama berjalan.
|
| Yang acuannya tidak ditemukan membuat flow itu DILEWATI dengan peringatan,
| bukan tersimpan setengah jadi. Karena itu master role, department, cabang,
| keterangan transaksi, dan akun penggunanya harus sudah ada lebih dulu.
|
| Flow yang namanya sudah ada untuk jenis dokumen yang sama dilewati -- seeder
| ini menambah, tidak menimpa.
|--------------------------------------------------------------------------
*/
class ApprovalFlowMatrixSeeder extends Seeder
{
    public function run(): void
    {
        $dilewati = 0;
        $dibuat = 0;

        /*
        | Dikelompokkan menurut CAKUPANNYA, lalu dihitung berapa yang sudah
        | ada.
        |
        | Matriksnya memang bisa memuat beberapa flow dengan cakupan yang
        | sama persis -- stepnya yang berbeda. Memeriksa "sudah ada atau
        | belum" satu per satu akan membuat yang kedua dan seterusnya dianggap
        | sudah ada, lalu tidak pernah dibuat.
        */
        $kelompok = [];

        foreach (self::MATRIKS as $flow) {
            $kelompok[$this->tandaTanganData($flow)][] = $flow;
        }

        foreach ($kelompok as $tanda => $daftar) {
            $sudahAda = $this->jumlahYangAda($daftar[0], $tanda);

            $dilewati += min($sudahAda, count($daftar));

            for ($i = $sudahAda; $i < count($daftar); $i++) {
                if ($this->buat($daftar[$i])) {
                    $dibuat++;
                }
                else {
                    $dilewati++;
                }
            }
        }

        $this->command?->info("[Approval Flow] {$dibuat} dibuat, {$dilewati} dilewati.");
    }

    /**
     * Flow dengan CAKUPAN yang sama persis sudah ada.
     *
     * Namanya saja tidak cukup untuk mengenali: beberapa flow memang dibuat
     * dengan nama yang sama untuk department yang berbeda. Memakai nama
     * sebagai kunci membuat seeder ini menganggap semuanya satu, lalu diam-
     * diam hanya membuat yang pertama -- dan department lain berjalan tanpa
     * matriks sama sekali.
     *
     * @param  array<string, mixed>  $flow
     */
    private function jumlahYangAda(array $contoh, string $tanda): int
    {
        return ApprovalFlow::query()
            ->whereNull('deleted_at')
            ->whereRaw('UPPER(TRIM(document_type)) = ?', [strtoupper($contoh['document_type'])])
            ->where('name', $contoh['name'])
            ->get()
            ->filter(fn (ApprovalFlow $ada): bool => $this->tandaTanganDb($ada) === $tanda)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $flow
     */
    private function tandaTanganData(array $flow): string
    {
        $dept = $flow['departments'];
        $kat = $flow['transaction_categories'];

        sort($dept);
        sort($kat);

        return $this->tandaTangan(
            $flow['document_type'],
            $flow['name'],
            $flow['area_type'],
            $flow['min_amount'],
            $flow['max_amount'],
            (bool) $flow['all_departments'],
            $dept,
            (bool) $flow['all_transaction_categories'],
            $kat,
        );
    }

    private function tandaTanganDb(ApprovalFlow $flow): string
    {
        $dept = DB::table('approval_flow_departments as p')
            ->join('departments as d', 'd.id', '=', 'p.department_id')
            ->where('p.approval_flow_id', $flow->id)
            ->pluck('d.nama')
            ->all();

        $kat = DB::table('approval_flow_transaction_categories as p')
            ->join('fund_request_transaction_categories as c', 'c.id', '=', 'p.transaction_category_id')
            ->where('p.approval_flow_id', $flow->id)
            ->pluck('c.code')
            ->all();

        sort($dept);
        sort($kat);

        return $this->tandaTangan(
            (string) $flow->document_type,
            (string) $flow->name,
            $flow->area_type,
            $flow->min_amount !== null ? (float) $flow->min_amount : null,
            $flow->max_amount !== null ? (float) $flow->max_amount : null,
            (bool) $flow->all_departments,
            $dept,
            (bool) $flow->all_transaction_categories,
            $kat,
        );
    }

    /**
     * @param  array<int, string>  $departments
     * @param  array<int, string>  $kategori
     */
    private function tandaTangan(
        string $documentType,
        string $name,
        ?string $areaType,
        ?float $min,
        ?float $max,
        bool $semuaDepartment,
        array $departments,
        bool $semuaKategori,
        array $kategori,
    ): string {
        $angka = fn (?float $nilai): string => $nilai === null ? '-' : number_format($nilai, 2, '.', '');

        return implode('|', [
            strtoupper(trim($documentType)),
            trim($name),
            strtoupper((string) $areaType),
            $angka($min),
            $angka($max),
            $semuaDepartment ? 'SEMUA-DEPT' : implode(',', $departments),
            $semuaKategori ? 'SEMUA-KAT' : implode(',', $kategori),
        ]);
    }

    /**
     * @param  array<string, mixed>  $flow
     */
    private function buat(array $flow): bool
    {
        $departmentIds = [];

        foreach ($flow['departments'] as $nama) {
            $id = DB::table('departments')->where('nama', $nama)->value('id');

            if (!$id) {
                return $this->gagal($flow, "department \"{$nama}\" tidak ada");
            }

            $departmentIds[] = (int) $id;
        }

        $kategoriIds = [];

        foreach ($flow['transaction_categories'] as $kode) {
            $id = DB::table('fund_request_transaction_categories')
                ->where('code', $kode)
                ->whereNull('deleted_at')
                ->value('id');

            if (!$id) {
                return $this->gagal($flow, "keterangan transaksi \"{$kode}\" tidak ada");
            }

            $kategoriIds[] = (int) $id;
        }

        $permissionModuleId = null;

        if ($flow['permission_module']) {
            $permissionModuleId = DB::table('permission_modules')
                ->where('code', $flow['permission_module'])
                ->value('id');

            if (!$permissionModuleId) {
                return $this->gagal($flow, "modul permission \"{$flow['permission_module']}\" tidak ada");
            }
        }

        /*
        | Seluruh acuan step diperiksa SEBELUM satu baris pun ditulis.
        | Flow yang separuh jadi lebih sulit diperbaiki daripada flow yang
        | tidak pernah dibuat.
        */
        $stepSiap = [];

        foreach ($flow['steps'] as $step) {
            $approverId = $this->cariApprover($step['approver']);

            if (!$approverId) {
                return $this->gagal(
                    $flow,
                    "approver {$step['approver']['type']} \"{$step['approver']['ref']}\" tidak ada",
                );
            }

            $skipRoleIds = [];

            foreach ($step['skip_for_roles'] as $namaRole) {
                $id = DB::table('roles')->where('nama', $namaRole)->value('id');

                if (!$id) {
                    return $this->gagal($flow, "role \"{$namaRole}\" (pengecualian step) tidak ada");
                }

                $skipRoleIds[] = (int) $id;
            }

            $cabangIds = [];

            foreach ($step['branches'] as $namaCabang) {
                $id = DB::table('cabang')->where('nama_cabang', $namaCabang)->value('id');

                if (!$id) {
                    return $this->gagal($flow, "cabang \"{$namaCabang}\" tidak ada");
                }

                $cabangIds[] = (int) $id;
            }

            $khusus = [];

            foreach ($step['special_approvers'] as $special) {
                $tipeId = DB::table('special_document_types')
                    ->where('code', $special['document_type_code'])
                    ->value('id');

                if (!$tipeId) {
                    return $this->gagal(
                        $flow,
                        "tipe dokumen khusus \"{$special['document_type_code']}\" tidak ada",
                    );
                }

                $penggantiId = $this->cariApprover($special['approver']);

                if (!$penggantiId) {
                    return $this->gagal(
                        $flow,
                        "approver pengganti \"{$special['approver']['ref']}\" tidak ada",
                    );
                }

                $khusus[] = [
                    'special_document_type_id' => (int) $tipeId,
                    'approver_type' => $special['approver']['type'],
                    'approver_id' => $penggantiId,
                ];
            }

            $stepSiap[] = [
                'step' => $step,
                'approver_id' => $approverId,
                'skip_role_ids' => $skipRoleIds,
                'cabang_ids' => $cabangIds,
                'special' => $khusus,
            ];
        }

        DB::transaction(function () use ($flow, $departmentIds, $kategoriIds, $permissionModuleId, $stepSiap): void {
            $baru = ApprovalFlow::create([
                'module_name' => $flow['module_name'],
                'name' => $flow['name'],
                'description' => $flow['description'],
                'document_type' => $flow['document_type'],
                'area_type' => $flow['area_type'],
                'cabang' => $flow['cabang'],
                'min_amount' => $flow['min_amount'],
                'max_amount' => $flow['max_amount'],
                'is_active' => $flow['is_active'],
                'all_departments' => $flow['all_departments'],
                'all_transaction_categories' => $flow['all_transaction_categories'],
                'permission_module_id' => $permissionModuleId,
                'creator_department_id' => $departmentIds[0] ?? null,
            ]);

            foreach ($departmentIds as $id) {
                DB::table('approval_flow_departments')->insert([
                    'approval_flow_id' => $baru->id,
                    'department_id' => $id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($kategoriIds as $id) {
                DB::table('approval_flow_transaction_categories')->insert([
                    'approval_flow_id' => $baru->id,
                    'transaction_category_id' => $id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($stepSiap as $siap) {
                $step = $siap['step'];

                $flowStep = ApprovalFlowStep::create([
                    'approval_flow_id' => $baru->id,
                    'step_order' => $step['step_order'],
                    'label' => $step['label'],
                    'approver_type' => $step['approver']['type'],
                    'approver_id' => $siap['approver_id'],
                    'approval_mode' => $step['approval_mode'],
                    'approver_scope' => $step['approver_scope'],
                    'allow_peer_approval' => $step['allow_peer_approval'],
                    'skip_for_role_ids' => $siap['skip_role_ids'],
                    'is_required' => true,
                ]);

                foreach ($siap['cabang_ids'] as $cabangId) {
                    DB::table('approval_flow_step_branches')->insert([
                        'approval_flow_step_id' => $flowStep->id,
                        'cabang_id' => $cabangId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach ($siap['special'] as $special) {
                    DB::table('approval_flow_step_special_approvers')->insert([
                        'approval_flow_step_id' => $flowStep->id,
                        'special_document_type_id' => $special['special_document_type_id'],
                        'approver_type' => $special['approver_type'],
                        'approver_id' => $special['approver_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        return true;
    }

    /**
     * @param  array<string, string>  $acuan
     */
    private function cariApprover(array $acuan): ?int
    {
        $id = match (strtoupper($acuan['type'])) {
            'ROLE' => DB::table('roles')->where('nama', $acuan['ref'])->value('id'),
            'DEPARTMENT' => DB::table('departments')->where('nama', $acuan['ref'])->value('id'),
            'USER' => DB::table('users')->where('username', $acuan['ref'])->value('id'),
            default => null,
        };

        return $id ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $flow
     */
    private function gagal(array $flow, string $sebab): bool
    {
        $this->command?->warn(
            "[Approval Flow] \"{$flow['name']}\" ({$flow['document_type']}) dilewati: {$sebab}.",
        );

        return false;
    }

    /**
     * @var array<int, array<string, mixed>>
     */
    private const MATRIKS = [
      0 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim Cabang',
        'module_name' => 'Claim',
        'description' => 'Approval Claim Cabang
    Nominal 500.001 s/d 2.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 500001.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Commercial',
          1 => 'Finance',
          2 => 'General Affair',
          3 => 'Information Technology',
          4 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      1 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim Cabang',
        'module_name' => 'Claim',
        'description' => 'Approval Claim Cabang
    Nominal 2.000.001 s/d 3.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 3000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Commercial',
          1 => 'Finance',
          2 => 'General Affair',
          3 => 'Information Technology',
          4 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'BM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Branch Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'BM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 3,
            'label' => 'BM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 3,
            'label' => 'BM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Samarinda',
              2 => 'Pontianak',
              3 => 'Banjarmasin',
              4 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          10 => [
            'step_order' => 3,
            'label' => 'BM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'ezar.mafazi',
              'name' => 'Muhammad Ezar Mafazi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      2 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim Cabang',
        'module_name' => 'Claim',
        'description' => 'Approval Claim Cabang
    Nominal 3.000.001 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 3000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Commercial',
          1 => 'Finance',
          2 => 'General Affair',
          3 => 'Information Technology',
          4 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'BM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Branch Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'BM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 3,
            'label' => 'BM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 3,
            'label' => 'BM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'ezar.mafazi',
              'name' => 'Muhammad Ezar Mafazi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          10 => [
            'step_order' => 3,
            'label' => 'BM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Samarinda',
              2 => 'Pontianak',
              3 => 'Banjarmasin',
              4 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          11 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          12 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          13 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      3 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval CLAIM – Board of Director',
        'module_name' => 'Claim',
        'description' => 'Pengajuan anggota BOD di-counter-sign anggota BOD lain, lalu disetujui Finance Manager. CEO melewati counter-sign karena tidak ada anggota BOD yang berada di atasnya.',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => NULL,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Board of Director',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Counter-sign BOD',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'DEPARTMENT',
              'ref' => 'Board of Director',
            ],
            'allow_peer_approval' => true,
            'skip_for_roles' => [
              0 => 'Chief Executive Officer',
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      4 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - FIN',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - FIN
    Nominal 500.001 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 500001.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      5 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - FIN',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - FIN
    Nominal 2.000.001 s/d 3.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 3000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      6 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - FIN',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - FIN
    Nominal 3.000.001 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 3000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 3,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      7 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - GA',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - GA
    Nominal 500.001 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 500001.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      8 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - GA',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - GA
    Nominal 2.000.001 s/d 3.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 3000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      9 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - GA',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - GA
    Nominal 3.000.001 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 3000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      10 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim Ho - HR',
        'module_name' => 'Claim',
        'description' => 'Approval Claim Ho - HR
    Nominal 3.000.001 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 3000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 3,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      11 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - HR',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - HR
    Nominal 500.001 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 500001.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      12 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - HR',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - HR
    Nominal 2.000.001 s/d 3.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 3000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      13 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - IT',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - IT
    Nominal 500.001 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 500001.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      14 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - IT',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - IT
    Nominal 2.000.001 s/d 3.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 3000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      15 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - IT',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - IT
    Nominal 3.000.001 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 3000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 3,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      16 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - LOG',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - LOG
    Nominal 500.001 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 500001.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Logistic Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'ridho',
              'name' => 'Muhammad Ridho Husaini',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      17 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - LOG',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - LOG
    Nominal 2.000.001 s/d 3.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 3000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Logistic Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'ridho',
              'name' => 'Muhammad Ridho Husaini',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      18 => [
        'document_type' => 'CLAIM',
        'name' => 'Approval Claim HO - LOG',
        'module_name' => 'Claim',
        'description' => 'Approval Claim HO - LOG
    Nominal 3.000.001 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 3000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'claim',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Logistic Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'ridho',
              'name' => 'Muhammad Ridho Husaini',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 3,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 3,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      19 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU ALL - CABANG Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow ALL-CABANG (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Commercial',
          1 => 'General Affair',
          2 => 'Logistic',
          3 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      20 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU ALL - CABANG Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow ALL-CABANG (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Commercial',
          1 => 'Finance',
          2 => 'General Affair',
          3 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      21 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU ALL - CABANG Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow ALL-CABANG (Perjalanan Dinas) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Commercial',
          1 => 'Finance',
          2 => 'General Affair',
          3 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          10 => [
            'step_order' => 6,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          11 => [
            'step_order' => 6,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      22 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU COM - CABANG NON RUTIN (Entertain, Sumbangan, Parcel)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow COM-CABANG(Entertain, Sumbangan, Parcel) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Commercial',
        ],
        'transaction_categories' => [
          0 => 'ENTERTAIN',
          1 => 'SUMBANGAN',
          2 => 'PARCEL',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      23 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU COM - CABANG NON RUTIN (Entertain, Sumbangan, Parcel)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow COM-CABANG(Entertain, Sumbangan, Parcel) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Commercial',
        ],
        'transaction_categories' => [
          0 => 'ENTERTAIN',
          1 => 'SUMBANGAN',
          2 => 'PARCEL',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'OM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Operation Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'OM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      24 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU COM - CABANG NON RUTIN (Entertain, Sumbangan, Parcel)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow COM-CABANG(Entertain, Sumbangan, Parcel) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Commercial',
        ],
        'transaction_categories' => [
          0 => 'ENTERTAIN',
          1 => 'SUMBANGAN',
          2 => 'PARCEL',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'OM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Operation Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'OM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      25 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      26 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG (Operasional Kantor) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      27 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      28 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          10 => [
            'step_order' => 6,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          11 => [
            'step_order' => 6,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      29 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'LEGALITAS_KENDARAAN',
          3 => 'PANTRY',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      30 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      31 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Banjarmasin',
              2 => 'Pontianak',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          10 => [
            'step_order' => 6,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      32 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal diatas 50.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          10 => [
            'step_order' => 6,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          11 => [
            'step_order' => 7,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      33 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU LOG - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-CABANG (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      34 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU LOG - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-CABANG (Operasional Kantor) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'LOGM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'LOGM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'ridho',
              'name' => 'Muhammad Ridho Husaini',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      35 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU LOG - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-CABANG (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'LOGM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      36 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU LOG - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-CABANG (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'LOGM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      37 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU LOG - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      38 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU LOG - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'LISTRIK',
          1 => 'TELPON',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'SERVICE_KENDARAAN',
          6 => 'STATIONARY',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Banjarmasin',
              2 => 'Pontianak',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      39 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU LOG - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Jakarta',
              1 => 'Palembang',
              2 => 'Pontianak',
              3 => 'Surabaya',
              4 => 'Banjarmasin',
              5 => 'Palangkaraya',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'enny.puji',
              'name' => 'Enny Puji Astuti',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          10 => [
            'step_order' => 6,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      40 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU – Board of Director',
        'module_name' => 'Cash Advance',
        'description' => 'Pengajuan anggota BOD di-counter-sign anggota BOD lain, lalu disetujui Finance Manager. CEO melewati counter-sign karena tidak ada anggota BOD yang berada di atasnya.',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => NULL,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Board of Director',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Counter-sign BOD',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'DEPARTMENT',
              'ref' => 'Board of Director',
            ],
            'allow_peer_approval' => true,
            'skip_for_roles' => [
              0 => 'Chief Executive Officer',
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      41 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU FIN - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      42 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU FIN - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Operasional Kantor) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      43 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU FIN - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      44 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU FIN - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 5,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      45 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU FIN - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      46 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU FIN - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      47 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU FIN - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Perjalanan Dinas) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      48 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      49 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal 2.000.000 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      50 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      51 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 5,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      52 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      53 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      54 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Perjalanan Dinas) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      55 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - HO RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal 1 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'HOD',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      56 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU GA - HO RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'HOD',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 4,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      57 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU HR - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow HR-HO (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      58 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU HR - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow HR-HO (Operasional Kantor) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      59 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU HR - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      60 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU HR - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 5,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      61 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU HR - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow HR-HO (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      62 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU HR - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow HR-HO (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      63 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU HR - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow HR-HO (Perjalanan Dinas) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      64 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU IT - HO Non Rutin (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HR&GAM',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      65 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU IT - HO Non Rutin (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 5,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      66 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU IT - HO Non Rutin (Operational Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Operasional Kantor) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      67 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU IT - HO Non Rutin (Operational Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      68 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU IT - HO Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      69 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU IT - HO Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      70 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU IT - HO Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Operasional Kantor) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      71 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU IT - HO Non Rutin (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HOD',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'vica',
              'name' => 'Vica Krisdianatha',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 4,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      72 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU IT - HO RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal 1 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HOD',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'vica',
              'name' => 'Vica Krisdianatha',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      73 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU LOG - HO RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal 1 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HOD',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      74 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU LOG - HO RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HOD',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 4,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      75 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU PROC - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow PROC-HO (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Procurement',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'General Manager Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      76 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU PROC - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow PROC-HO (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Procurement',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'General Manager Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      77 => [
        'document_type' => 'FPU',
        'name' => 'Approval FPU PROC - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow PROC-HO (Perjalanan Dinas) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Procurement',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'General Manager Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 5,
            'label' => 'CFO/CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      78 => [
        'document_type' => 'PERDIN',
        'name' => 'Approval PERDIN – Board of Director',
        'module_name' => 'Perjalanan Dinas',
        'description' => 'Perdin anggota BOD diurus HRGA Manager lebih dulu, lalu di-counter-sign anggota BOD lain. CEO berhenti di HRGA Manager karena tidak ada anggota BOD yang berada di atasnya.',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => NULL,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'business_trip',
        'departments' => [
          0 => 'Board of Director',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'Counter-sign BOD',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'DEPARTMENT',
              'ref' => 'Board of Director',
            ],
            'allow_peer_approval' => true,
            'skip_for_roles' => [
              0 => 'Chief Executive Officer',
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      79 => [
        'document_type' => 'PERDIN',
        'name' => 'Approval Perdin HO - FIN',
        'module_name' => 'Perjalanan Dinas',
        'description' => 'Approval perjalanan dinas HO - FIN',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => NULL,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'business_trip',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      80 => [
        'document_type' => 'PERDIN',
        'name' => 'Approval Perdin HO - GA',
        'module_name' => 'Perjalanan Dinas',
        'description' => 'Approval perjalanan dinas HO - GA',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => NULL,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'business_trip',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'HRDGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      81 => [
        'document_type' => 'PERDIN',
        'name' => 'Approval Perdin HO - HR',
        'module_name' => 'Perjalanan Dinas',
        'description' => 'Approval perjalanan dinas HO - HR',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => NULL,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'business_trip',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      82 => [
        'document_type' => 'PERDIN',
        'name' => 'Approval Perdin HO - IT',
        'module_name' => 'Perjalanan Dinas',
        'description' => 'Approval perjalanan dinas HO - IT',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => NULL,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'business_trip',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      83 => [
        'document_type' => 'PERDIN',
        'name' => 'Approval Perdin HO - LOG',
        'module_name' => 'Perjalanan Dinas',
        'description' => 'Approval perjalanan dinas HO - LOG',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => NULL,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'business_trip',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Logistic Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'HOD/SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'ridho',
              'name' => 'Muhammad Ridho Husaini',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'GA SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 3,
            'label' => 'HRGA Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      84 => [
        'document_type' => 'PO',
        'name' => 'Approval PO 10 Juta sampai 50 Juta',
        'module_name' => 'Procurement',
        'description' => 'Tambahan approval CFO',
        'area_type' => NULL,
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_order',
        'departments' => [
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'donna',
              'name' => 'Donna Delaila',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
              0 => [
                'document_type_code' => 'KAPAL',
                'approver' => [
                  'type' => 'USER',
                  'ref' => 'vica',
                  'name' => 'Vica Krisdianatha',
                ],
              ],
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      85 => [
        'document_type' => 'PO',
        'name' => 'Approval PO Di Atas 50 Juta',
        'module_name' => 'Procurement',
        'description' => 'Tambahan approval CEO',
        'area_type' => NULL,
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_order',
        'departments' => [
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'GM Procurement',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'donna',
              'name' => 'Donna Delaila',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
              0 => [
                'document_type_code' => 'KAPAL',
                'approver' => [
                  'type' => 'USER',
                  'ref' => 'vica',
                  'name' => 'Vica Krisdianatha',
                ],
              ],
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      86 => [
        'document_type' => 'PO',
        'name' => 'Approval PO Semua Nilai',
        'module_name' => 'Procurement',
        'description' => 'Approval wajib untuk semua PO',
        'area_type' => NULL,
        'cabang' => NULL,
        'min_amount' => NULL,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_order',
        'departments' => [
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'GM Procurement',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'donna',
              'name' => 'Donna Delaila',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
              0 => [
                'document_type_code' => 'KAPAL',
                'approver' => [
                  'type' => 'USER',
                  'ref' => 'vica',
                  'name' => 'Vica Krisdianatha',
                ],
              ],
            ],
          ],
        ],
      ],
      87 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – Cabang – > Rp 50.000.001',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Finance',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'HRGA Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          10 => [
            'step_order' => 6,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      88 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – Cabang – > Rp 50.000.001',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Finance',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'Logistik Head',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'ridho',
              'name' => 'Muhammad Ridho Husaini',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          10 => [
            'step_order' => 6,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      89 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – Cabang – > Rp 50.000.001',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Finance',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'IT Head',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          10 => [
            'step_order' => 6,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      90 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – Cabang – Rp 1.000.000 s/d Rp 10.000.000',
        'module_name' => 'Procurement',
        'description' => 'PR Cabang GA',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1000000.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Finance',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Sulawesi',
              1 => 'Samarinda',
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Sulawesi',
              1 => 'Samarinda',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'HRDGA Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      91 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – Cabang – Rp 1.000.000 s/d Rp 10.000.000',
        'module_name' => 'Procurement',
        'description' => 'PR Cabang Logistik',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1000000.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Adm  / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Finance',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Adm  / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'Adm  / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'Mgr Log',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      92 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – Cabang – Rp 1.000.000 s/d Rp 10.000.000',
        'module_name' => 'Procurement',
        'description' => 'PR CABANG IT',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1000000.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Finance',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Sulawesi',
              1 => 'Samarinda',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'Manager IT',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'vica',
              'name' => 'Vica Krisdianatha',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      93 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – Cabang – Rp 10.000.001 s/d Rp 50.000.000',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Finance',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'HRGA Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      94 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – Cabang – Rp 10.000.001 s/d Rp 50.000.000',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Finance',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'Logistik Head',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'ridho',
              'name' => 'Muhammad Ridho Husaini',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      95 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – Cabang – Rp 10.000.001 s/d Rp 50.000.000',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Finance',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 1,
            'label' => 'Adm / ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
          6 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Sulawesi',
              1 => 'Samarinda',
            ],
            'special_approvers' => [
            ],
          ],
          7 => [
            'step_order' => 3,
            'label' => 'IT Head',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          8 => [
            'step_order' => 4,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          9 => [
            'step_order' => 5,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      96 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – HO – > Rp 50.000.001',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Spv GA / GA PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Spv GA / GA PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 5,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      97 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – HO – > Rp 50.000.001',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Spv Log / Log PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Spv Log / Log PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'arief',
              'name' => 'Arief Nugroho',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Atasan Dept Pemohon',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 5,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      98 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – HO – > Rp 50.000.001',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Spv IT / IT PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'Atasan Dept Pemohon',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager IT',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Atasan Dept Pemohon',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'vica',
              'name' => 'Vica Krisdianatha',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 5,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      99 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – HO – > Rp 50.000.001',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Spv FIN / FIN PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Spv FIN / FIN PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'ade.restiani',
              'name' => 'Ade Restiani',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      100 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – HO – Rp 1.000.000 s/d Rp 10.000.000',
        'module_name' => 'Procurement',
        'description' => 'PR HO GA',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1000000.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Spv GA / GA PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Spv GA / GA PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      101 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – HO – Rp 1.000.000 s/d Rp 10.000.000',
        'module_name' => 'Procurement',
        'description' => 'PR HO Logistik',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1000000.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Spv Log / Log PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Spv Log / Log PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'arief',
              'name' => 'Arief Nugroho',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Mgr Log',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      102 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – HO – Rp 1.000.000 s/d Rp 10.000.000',
        'module_name' => 'Procurement',
        'description' => 'PR HO IT',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1000000.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Spv IT / IT PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'Manager IT',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'vica',
              'name' => 'Vica Krisdianatha',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      103 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – HO – Rp 10.000.001 s/d Rp 50.000.000',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Spv GA / GA PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Spv GA / GA PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'chris',
              'name' => 'Muhammad Chrisnaldi Abimanyu',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'HRGA Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HRGA Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      104 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – HO – Rp 10.000.001 s/d Rp 50.000.000',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Spv Log / Log PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Spv Log / Log PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'arief',
              'name' => 'Arief Nugroho',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Atasan Dept Pemohon',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      105 => [
        'document_type' => 'PR',
        'name' => 'Approval PR – HO – Rp 10.000.001 s/d Rp 50.000.000',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Spv IT / IT PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'Atasan Dept Pemohon',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager IT',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Atasan Dept Pemohon',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'vica',
              'name' => 'Vica Krisdianatha',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      106 => [
        'document_type' => 'PR',
        'name' => 'Matrix Approval PR – HO – > Rp 50.000.001',
        'module_name' => 'Procurement',
        'description' => NULL,
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Spv Fin / Finance PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'Spv Fin / Finance PIC',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'larasati',
              'name' => 'Larasati',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Finance Mgr',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 3,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 4,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      107 => [
        'document_type' => 'PR',
        'name' => 'PR Kapal',
        'module_name' => 'Procurement',
        'description' => 'PR Kapal 1 - 10 Juta',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1000000.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Procurement',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'GM Procurement',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'donna',
              'name' => 'Donna Delaila',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
              0 => [
                'document_type_code' => 'KAPAL',
                'approver' => [
                  'type' => 'USER',
                  'ref' => 'vica',
                  'name' => 'Vica Krisdianatha',
                ],
              ],
            ],
          ],
        ],
      ],
      108 => [
        'document_type' => 'PR',
        'name' => 'PR Kapal',
        'module_name' => 'Procurement',
        'description' => 'PR Kapal 10,1 - 50 Juta',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Procurement',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'GM Procurement',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'donna',
              'name' => 'Donna Delaila',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
              0 => [
                'document_type_code' => 'KAPAL',
                'approver' => [
                  'type' => 'USER',
                  'ref' => 'vica',
                  'name' => 'Vica Krisdianatha',
                ],
              ],
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      109 => [
        'document_type' => 'PR',
        'name' => 'PR Kapal',
        'module_name' => 'Procurement',
        'description' => 'PR Kapal > 50 Juta',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'purchase_request',
        'departments' => [
          0 => 'Procurement',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'GM Procurement',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'donna',
              'name' => 'Donna Delaila',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
              0 => [
                'document_type_code' => 'KAPAL',
                'approver' => [
                  'type' => 'USER',
                  'ref' => 'vica',
                  'name' => 'Vica Krisdianatha',
                ],
              ],
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'CFO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Finance Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 3,
            'label' => 'CEO',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Chief Executive Officer',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      110 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI ALL - CABANG Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow ALL-CABANG (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
          1 => 'Logistic',
          2 => 'Finance',
          3 => 'Commercial',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      111 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI ALL - CABANG Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow ALL-CABANG (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
          1 => 'Logistic',
          2 => 'Finance',
          3 => 'Commercial',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      112 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI ALL - CABANG Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow ALL-CABANG (Perjalanan Dinas) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
          1 => 'Logistic',
          2 => 'Finance',
          3 => 'Commercial',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      113 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI COM - CABANG NON RUTIN (Entertain, Sumbangan, Parcel)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow COM-CABANG(Entertain, Sumbangan, Parcel) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Commercial',
        ],
        'transaction_categories' => [
          0 => 'ENTERTAIN',
          1 => 'SUMBANGAN',
          2 => 'PARCEL',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      114 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI COM - CABANG NON RUTIN (Entertain, Sumbangan, Parcel)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow COM-CABANG(Entertain, Sumbangan, Parcel) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Commercial',
        ],
        'transaction_categories' => [
          0 => 'ENTERTAIN',
          1 => 'SUMBANGAN',
          2 => 'PARCEL',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      115 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI COM - CABANG NON RUTIN (Entertain, Sumbangan, Parcel)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow COM-CABANG(Entertain, Sumbangan, Parcel) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Commercial',
        ],
        'transaction_categories' => [
          0 => 'ENTERTAIN',
          1 => 'SUMBANGAN',
          2 => 'PARCEL',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      116 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      117 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG (Operasional Kantor) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      118 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      119 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      120 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      121 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      122 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Banjarmasin',
              2 => 'Pontianak',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      123 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal diatas 50.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 50000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      124 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI LOG - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-CABANG (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      125 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI LOG - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-CABANG (Operasional Kantor) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      126 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI LOG - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-CABANG (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      127 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI LOG - CABANG NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-CABANG (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      128 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI LOG - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      129 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI LOG - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Banjarmasin',
              2 => 'Pontianak',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      130 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI LOG - CABANG RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-CABANG(Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary, Service Kendaraan Operasional/Truk, Sumbangan perayaan) RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'CABANG',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
          6 => 'SERVICE_KENDARAAN',
          7 => 'SUMBANGAN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Admin Head',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'ADH',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'samuel.andre',
              'name' => 'Renhoar Samuel Piter Andre',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          2 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'lukman.prayoga',
              'name' => 'Lukman Prayoga',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          3 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SAME_BRANCH',
            'approver' => [
              'type' => 'USER',
              'ref' => 'nicko.putra',
              'name' => 'Nicko Putra',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          4 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'freza',
              'name' => 'Freza Mahaztra Sandi',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Samarinda',
              1 => 'Sulawesi',
            ],
            'special_approvers' => [
            ],
          ],
          5 => [
            'step_order' => 2,
            'label' => 'Kacab',
            'approval_mode' => 'ANY',
            'approver_scope' => 'SELECTED_BRANCHES',
            'approver' => [
              'type' => 'USER',
              'ref' => 'sony.hartono',
              'name' => 'Sony Hartono',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
              0 => 'Palembang',
              1 => 'Pontianak',
              2 => 'Banjarmasin',
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      131 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI – Board of Director',
        'module_name' => 'Realisasi FPU',
        'description' => 'Pengajuan anggota BOD di-counter-sign anggota BOD lain, lalu disetujui Finance Manager. CEO melewati counter-sign karena tidak ada anggota BOD yang berada di atasnya.',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => NULL,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'cash_advance_realization',
        'departments' => [
          0 => 'Board of Director',
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Counter-sign BOD',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'DEPARTMENT',
              'ref' => 'Board of Director',
            ],
            'allow_peer_approval' => true,
            'skip_for_roles' => [
              0 => 'Chief Executive Officer',
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 2,
            'label' => 'Finance Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Manager',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      132 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI FIN - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      133 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI FIN - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Operasional Kantor) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      134 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI FIN - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      135 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI FIN - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      136 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI FIN - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      137 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI FIN - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      138 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI FIN - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow FIN-HO (Perjalanan Dinas) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Finance',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Spv',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Finance Accounting Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      139 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      140 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal 2.000.000 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      141 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      142 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      143 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      144 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      145 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Perjalanan Dinas) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      146 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - HO RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal 1 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      147 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI GA - HO RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'General Affair',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor General Affair',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      148 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI HR - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow HR-HO (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      149 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI HR - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow HR-HO (Operasional Kantor) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      150 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI HR - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      151 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI HR - HO NON RUTIN (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow GA-HO (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      152 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI HR - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow HR-HO (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      153 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI HR - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow HR-HO (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      154 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI HR - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow HR-HO (Perjalanan Dinas) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Human Resource Development',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Comben Industrial Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'HR Recruitment Development Supervisor',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      155 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI IT - HO Non Rutin (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Operasional Kantor) NON RUTIN
    Nominal 1 s/d 2.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 2000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      156 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI IT - HO Non Rutin (Operasional Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Operasional Kantor) NON RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      157 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI IT - HO Non Rutin (Operational Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Operasional Kantor) NON RUTIN
    Nominal 2.000.001 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 2000001.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      158 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI IT - HO Non Rutin (Operational Kantor)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Operasional Kantor) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'OPERASIONAL_KANTOR',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      159 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI IT - HO Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      160 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI IT - HO Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      161 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI IT - HO Non Rutin (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Operasional Kantor) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      162 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI IT - HO Non Rutin (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      163 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI IT - HO RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow IT-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal 1 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Information Technology',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'IT Infrastructure SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Programmer SPV',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      164 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI LOG - HO RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal 1 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      165 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI LOG - HO RUTIN (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow LOG-HO (Telpon, Listrik, Pantry, Legalitas Kendaraan, UPL, Stationary) RUTIN
    Nominal diatas 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Logistic',
        ],
        'transaction_categories' => [
          0 => 'TELPON',
          1 => 'LISTRIK',
          2 => 'PANTRY',
          3 => 'LEGALITAS_KENDARAAN',
          4 => 'UPL',
          5 => 'STATIONARY',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'SPV',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Manager Logistik',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      166 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI PROC - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow PROC-HO (Perjalanan Dinas) NON RUTIN
    Nominal 1 s/d 5.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 1.0,
        'max_amount' => 5000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Procurement',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'General Manager Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      167 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI PROC - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow PROC-HO (Perjalanan Dinas) NON RUTIN
    Nominal 5.000.001 s/d 10.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 5000001.0,
        'max_amount' => 10000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Procurement',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'General Manager Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      168 => [
        'document_type' => 'REALISASI',
        'name' => 'Approval REALISASI PROC - HO NON RUTIN (Perjalanan Dinas)',
        'module_name' => 'FPU (Form Pengajuan Uang)',
        'description' => 'Approval flow PROC-HO (Perjalanan Dinas) NON RUTIN
    Nominal 10.000.001 s/d 50.000.000',
        'area_type' => 'HO',
        'cabang' => NULL,
        'min_amount' => 10000001.0,
        'max_amount' => 50000000.0,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => false,
        'permission_module' => 'cash_advance',
        'departments' => [
          0 => 'Procurement',
        ],
        'transaction_categories' => [
          0 => 'PERDIN',
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'General Manager Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
          1 => [
            'step_order' => 1,
            'label' => 'HOD/Manager',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'ROLE',
              'ref' => 'Supervisor Procurement',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
      169 => [
        'document_type' => 'Vendor',
        'name' => 'Approval master vendor',
        'module_name' => 'Master Vendor',
        'description' => NULL,
        'area_type' => NULL,
        'cabang' => NULL,
        'min_amount' => NULL,
        'max_amount' => NULL,
        'is_active' => true,
        'all_departments' => false,
        'all_transaction_categories' => true,
        'permission_module' => 'vendor',
        'departments' => [
        ],
        'transaction_categories' => [
        ],
        'steps' => [
          0 => [
            'step_order' => 1,
            'label' => 'Finance',
            'approval_mode' => 'ANY',
            'approver_scope' => 'GLOBAL',
            'approver' => [
              'type' => 'USER',
              'ref' => 'ade.restiani',
              'name' => 'Ade Restiani',
            ],
            'allow_peer_approval' => false,
            'skip_for_roles' => [
            ],
            'branches' => [
            ],
            'special_approvers' => [
            ],
          ],
        ],
      ],
    ];
}
