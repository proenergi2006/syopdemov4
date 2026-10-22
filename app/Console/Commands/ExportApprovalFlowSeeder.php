<?php

namespace App\Console\Commands;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Matriks approval yang sudah diisi -> seeder siap jalan di production
|--------------------------------------------------------------------------
| Dipakai di komputer pengembang, BUKAN di production: ia membaca approval
| flow yang sudah diisi lewat layar, lalu menuliskannya kembali sebagai
| seeder. Di production cukup seeder hasilnya yang dijalankan.
|
| SELURUH ACUAN DITULIS SEBAGAI NAMA, BUKAN ID.
|
| Ini inti alat ini. Id di basis data production hampir pasti berbeda dengan
| id di sini -- role nomor 20 di sini bisa jadi orang lain sama sekali di
| sana. Seeder yang menyalin id akan tersimpan tanpa satu pun galat, lalu
| mengirim dokumen ke approver yang salah.
|
| Acuannya:
|
| department              -> departments.nama
| role                    -> roles.nama
| user                    -> users.username  (name hanya sebagai keterangan)
| cabang                  -> cabang.nama_cabang
| keterangan transaksi    -> fund_request_transaction_categories.code
| modul permission        -> permission_modules.code
| tipe dokumen khusus     -> special_document_types.code
|
| Yang acuannya tidak ketemu saat seeder dijalankan membuat flow itu
| DILEWATI dengan peringatan -- bukan tersimpan setengah jadi.
|--------------------------------------------------------------------------
*/
class ExportApprovalFlowSeeder extends Command
{
    protected $signature = 'approval-flow:export-seeder
        {--class=ApprovalFlowMatrixSeeder : Nama kelas seeder yang dihasilkan}
        {--document-type=* : Batasi pada jenis dokumen tertentu, mis. PR PO FPU}
        {--include-inactive : Ikut mengekspor flow yang nonaktif}';

    protected $description = 'Menulis approval flow yang ada sekarang menjadi seeder, dengan acuan nama bukan id.';

    public function handle(): int
    {
        $kelas = (string) $this->option('class');

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $kelas)) {
            $this->error('Nama kelas tidak sah.');

            return self::FAILURE;
        }

        $query = ApprovalFlow::query()
            ->whereNull('deleted_at')
            ->with(['departments', 'transactionCategories'])
            ->orderBy('document_type')
            ->orderBy('area_type')
            ->orderBy('name')
            ->orderBy('id');

        if (!$this->option('include-inactive')) {
            $query->where('is_active', true);
        }

        $jenis = array_map('strtoupper', (array) $this->option('document-type'));

        if ($jenis !== []) {
            $query->whereIn(DB::raw('UPPER(TRIM(document_type))'), $jenis);
        }

        $flows = $query->get();

        if ($flows->isEmpty()) {
            $this->warn('Tidak ada approval flow yang cocok untuk diekspor.');

            return self::SUCCESS;
        }

        $data = [];
        $tanpaAcuan = [];

        foreach ($flows as $flow) {
            $baris = $this->susunFlow($flow, $tanpaAcuan);

            if ($baris !== null) {
                $data[] = $baris;
            }
        }

        $berkas = database_path('seeders/' . $kelas . '.php');

        file_put_contents($berkas, $this->tulisSeeder($kelas, $data));

        $this->info("Seeder ditulis: {$berkas}");
        $this->info(count($data) . ' flow diekspor dari ' . $flows->count() . ' yang dibaca.');

        /*
        | Yang tidak bisa dinamai dilaporkan sekarang, bukan dibiarkan menjadi
        | seeder yang gagal diam-diam di production.
        */
        if ($tanpaAcuan !== []) {
            $this->newLine();
            $this->error('Ada acuan yang tidak punya nama. Flow terkait TIDAK ikut diekspor:');

            foreach ($tanpaAcuan as $pesan) {
                $this->line('  - ' . $pesan);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Satu flow menjadi satu larik acuan-nama.
     *
     * @param  array<int, string>  $tanpaAcuan
     * @return array<string, mixed>|null
     */
    private function susunFlow(ApprovalFlow $flow, array &$tanpaAcuan): ?array
    {
        $gagal = [];

        $nama = fn (string $tabel, string $kolom, $id): ?string => $id
            ? (DB::table($tabel)->where('id', $id)->value($kolom) ?: null)
            : null;

        $permissionModule = $nama('permission_modules', 'code', $flow->permission_module_id);

        if ($flow->permission_module_id && !$permissionModule) {
            $gagal[] = "modul permission id {$flow->permission_module_id}";
        }

        $departments = $flow->departments->pluck('nama')->filter()->values()->all();

        if ($flow->departments->count() !== count($departments)) {
            $gagal[] = 'ada department tanpa nama';
        }

        $kategori = $flow->transactionCategories->pluck('code')->filter()->values()->all();

        if ($flow->transactionCategories->count() !== count($kategori)) {
            $gagal[] = 'ada keterangan transaksi tanpa kode';
        }

        $steps = ApprovalFlowStep::query()
            ->with(['branchMappings', 'specialApprovers'])
            ->where('approval_flow_id', $flow->id)
            ->orderBy('step_order')
            ->orderBy('id')
            ->get()
            /*
            | Closure biasa, BUKAN arrow function: arrow function menangkap
            | $gagal sebagai salinan, sehingga acuan yang tidak ketemu di dalam
            | step tidak pernah sampai ke luar -- dan flow yang rusak ikut
            | terekspor dengan acuan kosong.
            */
            ->map(function (ApprovalFlowStep $step) use (&$gagal): array {
                return $this->susunStep($step, $gagal);
            })
            ->all();

        if ($steps === []) {
            $gagal[] = 'tidak punya step sama sekali';
        }

        if ($gagal !== []) {
            $tanpaAcuan[] = sprintf(
                '%s "%s" (id %d): %s',
                $flow->document_type,
                $flow->name,
                $flow->id,
                implode('; ', $gagal),
            );

            return null;
        }

        return [
            'document_type' => (string) $flow->document_type,
            'name' => (string) $flow->name,
            'module_name' => (string) $flow->module_name,
            'description' => $flow->description,
            'area_type' => $flow->area_type,
            'cabang' => $flow->cabang,
            'min_amount' => $flow->min_amount !== null ? (float) $flow->min_amount : null,
            'max_amount' => $flow->max_amount !== null ? (float) $flow->max_amount : null,
            'is_active' => (bool) $flow->is_active,
            'all_departments' => (bool) $flow->all_departments,
            'all_transaction_categories' => (bool) $flow->all_transaction_categories,
            'permission_module' => $permissionModule,
            'departments' => $departments,
            'transaction_categories' => $kategori,
            'steps' => $steps,
        ];
    }

    /**
     * @param  array<int, string>  $gagal
     * @return array<string, mixed>
     */
    private function susunStep(ApprovalFlowStep $step, array &$gagal): array
    {
        $acuan = $this->acuanApprover(
            (string) $step->approver_type,
            (int) $step->approver_id,
            $gagal,
            (string) ($step->label ?: 'step ' . $step->step_order),
        );

        $cabang = $step->branchMappings
            ->map(fn ($m) => DB::table('cabang')->where('id', $m->cabang_id)->value('nama_cabang'))
            ->filter()
            ->values()
            ->all();

        if ($step->branchMappings->count() !== count($cabang)) {
            $gagal[] = 'ada cabang tanpa nama pada step ' . $step->step_order;
        }

        $khusus = $step->specialApprovers
            ->map(function ($special) use (&$gagal, $step): ?array {
                $kode = DB::table('special_document_types')
                    ->where('id', $special->special_document_type_id)
                    ->value('code');

                if (!$kode) {
                    $gagal[] = 'tipe dokumen khusus tanpa kode pada step ' . $step->step_order;

                    return null;
                }

                return [
                    'document_type_code' => (string) $kode,
                    'approver' => $this->acuanApprover(
                        (string) $special->approver_type,
                        (int) $special->approver_id,
                        $gagal,
                        'pengganti ' . $kode,
                    ),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'step_order' => (int) $step->step_order,
            'label' => $step->label,
            'approval_mode' => (string) ($step->approval_mode ?: 'ANY'),
            'approver_scope' => (string) ($step->approver_scope ?: 'GLOBAL'),
            'approver' => $acuan,
            'allow_peer_approval' => (bool) $step->allow_peer_approval,
            'skip_for_roles' => collect((array) ($step->skip_for_role_ids ?? []))
                ->map(fn ($id) => DB::table('roles')->where('id', $id)->value('nama'))
                ->filter()
                ->values()
                ->all(),
            'branches' => $cabang,
            'special_approvers' => $khusus,
        ];
    }

    /**
     * Acuan seorang approver: tipenya, dan namanya -- bukan idnya.
     *
     * @param  array<int, string>  $gagal
     * @return array<string, string>
     */
    private function acuanApprover(string $tipe, int $id, array &$gagal, string $letak): array
    {
        $tipe = strtoupper(trim($tipe));

        $acuan = match ($tipe) {
            ApprovalFlowStep::APPROVER_TYPE_ROLE => DB::table('roles')->where('id', $id)->value('nama'),
            ApprovalFlowStep::APPROVER_TYPE_DEPARTMENT => DB::table('departments')->where('id', $id)->value('nama'),
            ApprovalFlowStep::APPROVER_TYPE_USER => DB::table('users')->where('id', $id)->value('username'),
            default => null,
        };

        if (!$acuan) {
            $gagal[] = "approver {$tipe} id {$id} pada {$letak} tidak punya nama";

            return ['type' => $tipe, 'ref' => ''];
        }

        $baris = ['type' => $tipe, 'ref' => (string) $acuan];

        /* Nama orangnya ikut, sebagai keterangan bagi yang membaca seedernya. */
        if ($tipe === ApprovalFlowStep::APPROVER_TYPE_USER) {
            $baris['name'] = (string) DB::table('users')->where('id', $id)->value('name');
        }

        return $baris;
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     */
    private function tulisSeeder(string $kelas, array $data): string
    {
        $isi = var_export($data, true);

        /* Bentuk larik pendek, dan indentasi yang enak dibaca. */
        $isi = preg_replace('/^(\s*)array \(/m', '$1[', $isi);
        $isi = preg_replace('/^(\s*)\),$/m', '$1],', $isi);
        $isi = preg_replace('/^(\s*)\)$/m', '$1]', $isi);
        $isi = str_replace("=> \n", '=> ', $isi);
        $isi = preg_replace('/=>\s+\[/', '=> [', $isi);
        $isi = preg_replace('/^/m', '    ', (string) $isi);
        $isi = ltrim((string) $isi);

        $tanggal = now()->toDateTimeString();
        $jumlah = count($data);

        return <<<PHP
<?php

namespace Database\\Seeders;

use App\\Models\\ApprovalFlow;
use App\\Models\\ApprovalFlowStep;
use Illuminate\\Database\\Seeder;
use Illuminate\\Support\\Facades\\DB;

/*
|--------------------------------------------------------------------------
| Matriks approval flow
|--------------------------------------------------------------------------
| DIHASILKAN OLEH `php artisan approval-flow:export-seeder` pada {$tanggal}.
| Jangan disunting dengan tangan -- isi matriksnya lewat layar master, lalu
| jalankan perintah itu lagi.
|
| {$jumlah} flow.
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
class {$kelas} extends Seeder
{
    public function run(): void
    {
        \$dilewati = 0;
        \$dibuat = 0;

        /*
        | Dikelompokkan menurut CAKUPANNYA, lalu dihitung berapa yang sudah
        | ada.
        |
        | Matriksnya memang bisa memuat beberapa flow dengan cakupan yang
        | sama persis -- stepnya yang berbeda. Memeriksa "sudah ada atau
        | belum" satu per satu akan membuat yang kedua dan seterusnya dianggap
        | sudah ada, lalu tidak pernah dibuat.
        */
        \$kelompok = [];

        foreach (self::MATRIKS as \$flow) {
            \$kelompok[\$this->tandaTanganData(\$flow)][] = \$flow;
        }

        foreach (\$kelompok as \$tanda => \$daftar) {
            \$sudahAda = \$this->jumlahYangAda(\$daftar[0], \$tanda);

            \$dilewati += min(\$sudahAda, count(\$daftar));

            for (\$i = \$sudahAda; \$i < count(\$daftar); \$i++) {
                if (\$this->buat(\$daftar[\$i])) {
                    \$dibuat++;
                }
                else {
                    \$dilewati++;
                }
            }
        }

        \$this->command?->info("[Approval Flow] {\$dibuat} dibuat, {\$dilewati} dilewati.");
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
     * @param  array<string, mixed>  \$flow
     */
    private function jumlahYangAda(array \$contoh, string \$tanda): int
    {
        return ApprovalFlow::query()
            ->whereNull('deleted_at')
            ->whereRaw('UPPER(TRIM(document_type)) = ?', [strtoupper(\$contoh['document_type'])])
            ->where('name', \$contoh['name'])
            ->get()
            ->filter(fn (ApprovalFlow \$ada): bool => \$this->tandaTanganDb(\$ada) === \$tanda)
            ->count();
    }

    /**
     * @param  array<string, mixed>  \$flow
     */
    private function tandaTanganData(array \$flow): string
    {
        \$dept = \$flow['departments'];
        \$kat = \$flow['transaction_categories'];

        sort(\$dept);
        sort(\$kat);

        return \$this->tandaTangan(
            \$flow['document_type'],
            \$flow['name'],
            \$flow['area_type'],
            \$flow['min_amount'],
            \$flow['max_amount'],
            (bool) \$flow['all_departments'],
            \$dept,
            (bool) \$flow['all_transaction_categories'],
            \$kat,
        );
    }

    private function tandaTanganDb(ApprovalFlow \$flow): string
    {
        \$dept = DB::table('approval_flow_departments as p')
            ->join('departments as d', 'd.id', '=', 'p.department_id')
            ->where('p.approval_flow_id', \$flow->id)
            ->pluck('d.nama')
            ->all();

        \$kat = DB::table('approval_flow_transaction_categories as p')
            ->join('fund_request_transaction_categories as c', 'c.id', '=', 'p.transaction_category_id')
            ->where('p.approval_flow_id', \$flow->id)
            ->pluck('c.code')
            ->all();

        sort(\$dept);
        sort(\$kat);

        return \$this->tandaTangan(
            (string) \$flow->document_type,
            (string) \$flow->name,
            \$flow->area_type,
            \$flow->min_amount !== null ? (float) \$flow->min_amount : null,
            \$flow->max_amount !== null ? (float) \$flow->max_amount : null,
            (bool) \$flow->all_departments,
            \$dept,
            (bool) \$flow->all_transaction_categories,
            \$kat,
        );
    }

    /**
     * @param  array<int, string>  \$departments
     * @param  array<int, string>  \$kategori
     */
    private function tandaTangan(
        string \$documentType,
        string \$name,
        ?string \$areaType,
        ?float \$min,
        ?float \$max,
        bool \$semuaDepartment,
        array \$departments,
        bool \$semuaKategori,
        array \$kategori,
    ): string {
        \$angka = fn (?float \$nilai): string => \$nilai === null ? '-' : number_format(\$nilai, 2, '.', '');

        return implode('|', [
            strtoupper(trim(\$documentType)),
            trim(\$name),
            strtoupper((string) \$areaType),
            \$angka(\$min),
            \$angka(\$max),
            \$semuaDepartment ? 'SEMUA-DEPT' : implode(',', \$departments),
            \$semuaKategori ? 'SEMUA-KAT' : implode(',', \$kategori),
        ]);
    }

    /**
     * @param  array<string, mixed>  \$flow
     */
    private function buat(array \$flow): bool
    {
        \$departmentIds = [];

        foreach (\$flow['departments'] as \$nama) {
            \$id = DB::table('departments')->where('nama', \$nama)->value('id');

            if (!\$id) {
                return \$this->gagal(\$flow, "department \\"{\$nama}\\" tidak ada");
            }

            \$departmentIds[] = (int) \$id;
        }

        \$kategoriIds = [];

        foreach (\$flow['transaction_categories'] as \$kode) {
            \$id = DB::table('fund_request_transaction_categories')
                ->where('code', \$kode)
                ->whereNull('deleted_at')
                ->value('id');

            if (!\$id) {
                return \$this->gagal(\$flow, "keterangan transaksi \\"{\$kode}\\" tidak ada");
            }

            \$kategoriIds[] = (int) \$id;
        }

        \$permissionModuleId = null;

        if (\$flow['permission_module']) {
            \$permissionModuleId = DB::table('permission_modules')
                ->where('code', \$flow['permission_module'])
                ->value('id');

            if (!\$permissionModuleId) {
                return \$this->gagal(\$flow, "modul permission \\"{\$flow['permission_module']}\\" tidak ada");
            }
        }

        /*
        | Seluruh acuan step diperiksa SEBELUM satu baris pun ditulis.
        | Flow yang separuh jadi lebih sulit diperbaiki daripada flow yang
        | tidak pernah dibuat.
        */
        \$stepSiap = [];

        foreach (\$flow['steps'] as \$step) {
            \$approverId = \$this->cariApprover(\$step['approver']);

            if (!\$approverId) {
                return \$this->gagal(
                    \$flow,
                    "approver {\$step['approver']['type']} \\"{\$step['approver']['ref']}\\" tidak ada",
                );
            }

            \$skipRoleIds = [];

            foreach (\$step['skip_for_roles'] as \$namaRole) {
                \$id = DB::table('roles')->where('nama', \$namaRole)->value('id');

                if (!\$id) {
                    return \$this->gagal(\$flow, "role \\"{\$namaRole}\\" (pengecualian step) tidak ada");
                }

                \$skipRoleIds[] = (int) \$id;
            }

            \$cabangIds = [];

            foreach (\$step['branches'] as \$namaCabang) {
                \$id = DB::table('cabang')->where('nama_cabang', \$namaCabang)->value('id');

                if (!\$id) {
                    return \$this->gagal(\$flow, "cabang \\"{\$namaCabang}\\" tidak ada");
                }

                \$cabangIds[] = (int) \$id;
            }

            \$khusus = [];

            foreach (\$step['special_approvers'] as \$special) {
                \$tipeId = DB::table('special_document_types')
                    ->where('code', \$special['document_type_code'])
                    ->value('id');

                if (!\$tipeId) {
                    return \$this->gagal(
                        \$flow,
                        "tipe dokumen khusus \\"{\$special['document_type_code']}\\" tidak ada",
                    );
                }

                \$penggantiId = \$this->cariApprover(\$special['approver']);

                if (!\$penggantiId) {
                    return \$this->gagal(
                        \$flow,
                        "approver pengganti \\"{\$special['approver']['ref']}\\" tidak ada",
                    );
                }

                \$khusus[] = [
                    'special_document_type_id' => (int) \$tipeId,
                    'approver_type' => \$special['approver']['type'],
                    'approver_id' => \$penggantiId,
                ];
            }

            \$stepSiap[] = [
                'step' => \$step,
                'approver_id' => \$approverId,
                'skip_role_ids' => \$skipRoleIds,
                'cabang_ids' => \$cabangIds,
                'special' => \$khusus,
            ];
        }

        DB::transaction(function () use (\$flow, \$departmentIds, \$kategoriIds, \$permissionModuleId, \$stepSiap): void {
            \$baru = ApprovalFlow::create([
                'module_name' => \$flow['module_name'],
                'name' => \$flow['name'],
                'description' => \$flow['description'],
                'document_type' => \$flow['document_type'],
                'area_type' => \$flow['area_type'],
                'cabang' => \$flow['cabang'],
                'min_amount' => \$flow['min_amount'],
                'max_amount' => \$flow['max_amount'],
                'is_active' => \$flow['is_active'],
                'all_departments' => \$flow['all_departments'],
                'all_transaction_categories' => \$flow['all_transaction_categories'],
                'permission_module_id' => \$permissionModuleId,
                'creator_department_id' => \$departmentIds[0] ?? null,
            ]);

            foreach (\$departmentIds as \$id) {
                DB::table('approval_flow_departments')->insert([
                    'approval_flow_id' => \$baru->id,
                    'department_id' => \$id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (\$kategoriIds as \$id) {
                DB::table('approval_flow_transaction_categories')->insert([
                    'approval_flow_id' => \$baru->id,
                    'transaction_category_id' => \$id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (\$stepSiap as \$siap) {
                \$step = \$siap['step'];

                \$flowStep = ApprovalFlowStep::create([
                    'approval_flow_id' => \$baru->id,
                    'step_order' => \$step['step_order'],
                    'label' => \$step['label'],
                    'approver_type' => \$step['approver']['type'],
                    'approver_id' => \$siap['approver_id'],
                    'approval_mode' => \$step['approval_mode'],
                    'approver_scope' => \$step['approver_scope'],
                    'allow_peer_approval' => \$step['allow_peer_approval'],
                    'skip_for_role_ids' => \$siap['skip_role_ids'],
                    'is_required' => true,
                ]);

                foreach (\$siap['cabang_ids'] as \$cabangId) {
                    DB::table('approval_flow_step_branches')->insert([
                        'approval_flow_step_id' => \$flowStep->id,
                        'cabang_id' => \$cabangId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach (\$siap['special'] as \$special) {
                    DB::table('approval_flow_step_special_approvers')->insert([
                        'approval_flow_step_id' => \$flowStep->id,
                        'special_document_type_id' => \$special['special_document_type_id'],
                        'approver_type' => \$special['approver_type'],
                        'approver_id' => \$special['approver_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        return true;
    }

    /**
     * @param  array<string, string>  \$acuan
     */
    private function cariApprover(array \$acuan): ?int
    {
        \$id = match (strtoupper(\$acuan['type'])) {
            'ROLE' => DB::table('roles')->where('nama', \$acuan['ref'])->value('id'),
            'DEPARTMENT' => DB::table('departments')->where('nama', \$acuan['ref'])->value('id'),
            'USER' => DB::table('users')->where('username', \$acuan['ref'])->value('id'),
            default => null,
        };

        return \$id ? (int) \$id : null;
    }

    /**
     * @param  array<string, mixed>  \$flow
     */
    private function gagal(array \$flow, string \$sebab): bool
    {
        \$this->command?->warn(
            "[Approval Flow] \\"{\$flow['name']}\\" ({\$flow['document_type']}) dilewati: {\$sebab}.",
        );

        return false;
    }

    /**
     * @var array<int, array<string, mixed>>
     */
    private const MATRIKS = {$isi};
}

PHP;
    }
}
