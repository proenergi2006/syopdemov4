<?php

namespace App\Console\Commands;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Matriks approval FPU -> matriks approval Realisasi FPU
|--------------------------------------------------------------------------
| Realisasi mengikuti FPU: lingkupnya sama persis -- area, cabang,
| department, keterangan transaksi, dan rentang nominal -- dan approvernya
| orang yang sama. Yang berbeda hanya PANJANG rantainya:
|
|   HO      : 1 step saja, yaitu SPV/HOD-nya (step pertama pada flow FPU)
|   CABANG  : 2 step, ADH lalu Kacab (step pertama dan kedua pada flow FPU)
|
| Sisanya -- Manager, Finance Manager, CFO, CEO -- tidak ikut. Realisasi
| adalah pertanggungjawaban uang yang sudah cair, bukan permintaan uang
| baru; yang perlu memastikannya adalah atasan langsung pemohon, bukan
| seluruh rantai yang dulu memutuskan pencairannya.
|
| KENAPA DITURUNKAN, BUKAN DIISI TANGAN.
|
| 59 flow FPU, masing-masing dengan daftar department, daftar keterangan
| transaksi, dan beberapa baris approver bercakupan cabang. Mengisinya
| kembali lewat layar berarti puluhan kesempatan untuk salah satu baris
| tertinggal -- dan yang tertinggal itu baru terasa pada hari dokumen
| seseorang berhenti tanpa approver.
|
| Alat ini dipakai di komputer pengembang. Hasilnya diekspor menjadi seeder
| lewat approval-flow:export-seeder, dan seeder itulah yang dijalankan di
| production.
|
| AMAN DIJALANKAN BERULANG: flow realisasi yang lingkupnya sudah sama
| persis dilewati, bukan dibuat kembali.
|--------------------------------------------------------------------------
*/
class MirrorRealisasiApprovalFlow extends Command
{
    protected $signature = 'approval-flow:mirror-realisasi
        {--dry-run : Hanya menampilkan rencananya, tanpa menulis apa pun}
        {--skip-flow=* : Id flow FPU yang tidak ikut dicerminkan}';

    protected $description = 'Menurunkan matriks approval Realisasi FPU dari matriks approval FPU';

    /**
     * Label step yang menandai flow khusus BOD.
     *
     * Flow BOD punya aturannya sendiri -- pengaju BOD di-counter-sign BOD
     * lain -- dan flow realisasinya sudah dibuat tersendiri. Mencerminkannya
     * di sini hanya akan melahirkan flow kembar yang saling bersaing.
     */
    private const LABEL_BOD = 'Counter-sign BOD';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $dilewati = array_map('intval', (array) $this->option('skip-flow'));

        $sumber = ApprovalFlow::query()
            ->where('document_type', 'FPU')
            ->orderBy('id')
            ->get();

        if ($sumber->isEmpty()) {
            $this->warn('Tidak ada approval flow FPU untuk dicerminkan.');

            return self::SUCCESS;
        }

        $dibuat = 0;
        $dilewatiBod = 0;
        $dilewatiAda = 0;
        $dilewatiManual = 0;
        $tanpaStep = 0;

        foreach ($sumber as $fpu) {
            $area = strtoupper(trim((string) $fpu->area_type));

            $stepAwal = ApprovalFlowStep::query()
                ->where('approval_flow_id', $fpu->id)
                ->orderBy('step_order')
                ->orderBy('id')
                ->first();

            if (!$stepAwal) {
                $this->warn("  FPU #{$fpu->id} dilewati: belum punya satu pun step.");
                $tanpaStep++;

                continue;
            }

            if (trim((string) $stepAwal->label) === self::LABEL_BOD) {
                $dilewatiBod++;

                continue;
            }

            if (in_array((int) $fpu->id, $dilewati, true)) {
                $this->line("  FPU #{$fpu->id} dilewati atas permintaan.");
                $dilewatiManual++;

                continue;
            }

            /* Step mana yang dibawa: HO satu, cabang dua. */
            $stepDibawa = $area === 'HO' ? [1] : [1, 2];

            $steps = ApprovalFlowStep::query()
                ->with(['branchMappings', 'specialApprovers'])
                ->where('approval_flow_id', $fpu->id)
                ->whereIn('step_order', $stepDibawa)
                ->orderBy('step_order')
                ->orderBy('id')
                ->get();

            if ($steps->isEmpty()) {
                $this->warn("  FPU #{$fpu->id} dilewati: step yang dibutuhkan tidak ada.");
                $tanpaStep++;

                continue;
            }

            /*
            | Cabang tanpa step kedua tidak bisa dicerminkan apa adanya:
            | aturannya ADH lalu Kacab, dan yang hanya punya ADH akan
            | melahirkan flow satu step yang tidak diminta siapa pun.
            */
            if ($area !== 'HO' && $steps->where('step_order', 2)->isEmpty()) {
                $this->warn("  FPU #{$fpu->id} dilewati: flow cabang ini tidak punya step 2 (Kacab).");
                $tanpaStep++;

                continue;
            }

            $deptIds = DB::table('approval_flow_departments')
                ->where('approval_flow_id', $fpu->id)
                ->orderBy('department_id')
                ->pluck('department_id')
                ->map(fn ($i): int => (int) $i)
                ->all();

            $katIds = DB::table('approval_flow_transaction_categories')
                ->where('approval_flow_id', $fpu->id)
                ->orderBy('transaction_category_id')
                ->pluck('transaction_category_id')
                ->map(fn ($i): int => (int) $i)
                ->all();

            if ($this->sudahAda($fpu, $deptIds, $katIds)) {
                $dilewatiAda++;

                continue;
            }

            $nama = $this->namaRealisasi((string) $fpu->name);

            /*
            | Tahap dan baris approver dihitung terpisah.
            |
            | Satu tahap bisa memuat beberapa baris -- step Kacab pada flow
            | cabang berisi empat orang dengan cakupan cabang yang berbeda.
            | Menyebut "6 step" untuk flow dua tahap membuat orang mengira
            | aturannya tidak diikuti.
            */
            $this->line(sprintf(
                '  %-6s FPU #%-4d -> %s  (%d tahap, %d baris approver)',
                $area,
                $fpu->id,
                $nama,
                $steps->pluck('step_order')->unique()->count(),
                $steps->count(),
            ));

            if ($dryRun) {
                $dibuat++;

                continue;
            }

            DB::transaction(function () use ($fpu, $steps, $deptIds, $katIds, $nama): void {
                $baru = ApprovalFlow::create([
                    'module_name' => $fpu->module_name,
                    'name' => $nama,
                    'document_type' => 'REALISASI',
                    'is_active' => $fpu->is_active,
                    'description' => $fpu->description,
                    'area_type' => $fpu->area_type,
                    'cabang' => $fpu->cabang,
                    'creator_department_id' => $fpu->creator_department_id,
                    'permission_module_id' => $fpu->permission_module_id,
                    'all_departments' => $fpu->all_departments,
                    'all_transaction_categories' => $fpu->all_transaction_categories,
                    'min_amount' => $fpu->min_amount,
                    'max_amount' => $fpu->max_amount,
                    'created_by' => $fpu->created_by,
                    'updated_by' => $fpu->updated_by,
                ]);

                foreach ($deptIds as $id) {
                    DB::table('approval_flow_departments')->insert([
                        'approval_flow_id' => $baru->id,
                        'department_id' => $id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach ($katIds as $id) {
                    DB::table('approval_flow_transaction_categories')->insert([
                        'approval_flow_id' => $baru->id,
                        'transaction_category_id' => $id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach ($steps as $step) {
                    $stepBaru = ApprovalFlowStep::create([
                        'approval_flow_id' => $baru->id,
                        'step_order' => $step->step_order,
                        'approver_type' => $step->approver_type,
                        'approver_id' => $step->approver_id,
                        'label' => $step->label,
                        'is_required' => $step->is_required,
                        'approval_mode' => $step->approval_mode,
                        'approver_scope' => $step->approver_scope,
                        'allow_peer_approval' => $step->allow_peer_approval,
                        'skip_for_role_ids' => $step->skip_for_role_ids,
                    ]);

                    /*
                    | Daftar cabang per baris approver ikut disalin.
                    |
                    | Inilah bagian yang paling mudah tertinggal kalau diisi
                    | tangan: satu baris Kacab berlaku untuk tiga cabang
                    | tertentu, dan tanpa daftarnya ia menjadi berlaku untuk
                    | semua cabang -- orang yang menyetujui dokumen cabang
                    | yang bukan wilayahnya.
                    */
                    foreach ($step->branchMappings as $cabang) {
                        DB::table('approval_flow_step_branches')->insert([
                            'approval_flow_step_id' => $stepBaru->id,
                            'cabang_id' => $cabang->cabang_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    /* Approver khusus per jenis dokumen, bila ada. */
                    foreach ($step->specialApprovers as $khusus) {
                        DB::table('approval_flow_step_special_approvers')->insert([
                            'approval_flow_step_id' => $stepBaru->id,
                            'special_document_type_id' => $khusus->special_document_type_id,
                            'approver_type' => $khusus->approver_type,
                            'approver_id' => $khusus->approver_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

            $dibuat++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[rencana] ' : '') . "{$dibuat} flow realisasi " . ($dryRun ? 'akan dibuat' : 'dibuat') . '.');

        if ($dilewatiBod) {
            $this->line("  {$dilewatiBod} flow BOD dilewati (punya flow realisasinya sendiri).");
        }

        if ($dilewatiManual) {
            $this->line("  {$dilewatiManual} flow dilewati atas permintaan.");
        }

        if ($dilewatiAda) {
            $this->line("  {$dilewatiAda} flow dilewati: lingkup yang sama sudah ada di realisasi.");
        }

        if ($tanpaStep) {
            $this->line("  {$tanpaStep} flow dilewati: stepnya tidak lengkap.");
        }

        return self::SUCCESS;
    }

    /**
     * Nama flow realisasinya.
     *
     * Diturunkan dari nama FPU-nya supaya keduanya bisa dibaca
     * berpasangan di layar master. "FPU" diganti, bukan ditambahi: nama
     * seperti "Approval REALISASI FPU IT" terbaca seolah ada dua dokumen
     * sekaligus.
     */
    private function namaRealisasi(string $namaFpu): string
    {
        if (str_contains($namaFpu, 'FPU')) {
            return str_replace('FPU', 'REALISASI', $namaFpu);
        }

        return 'Approval REALISASI - ' . $namaFpu;
    }

    /**
     * Sudah ada flow realisasi dengan lingkup yang sama persis?
     *
     * Lingkupnya dibandingkan seluruhnya -- area, cabang, department,
     * keterangan transaksi, dan rentang nominal -- bukan namanya. Nama bisa
     * diubah orang; yang menentukan dokumen jatuh ke flow mana adalah
     * lingkupnya.
     *
     * @param  array<int, int>  $deptIds
     * @param  array<int, int>  $katIds
     */
    private function sudahAda(ApprovalFlow $fpu, array $deptIds, array $katIds): bool
    {
        $kandidat = ApprovalFlow::query()
            ->where('document_type', 'REALISASI')
            ->where('area_type', $fpu->area_type)
            ->where('cabang', $fpu->cabang)
            ->where('all_departments', $fpu->all_departments)
            ->where('all_transaction_categories', $fpu->all_transaction_categories)
            ->where('min_amount', $fpu->min_amount)
            ->where('max_amount', $fpu->max_amount)
            ->get();

        foreach ($kandidat as $flow) {
            $d = DB::table('approval_flow_departments')
                ->where('approval_flow_id', $flow->id)
                ->orderBy('department_id')
                ->pluck('department_id')
                ->map(fn ($i): int => (int) $i)
                ->all();

            $k = DB::table('approval_flow_transaction_categories')
                ->where('approval_flow_id', $flow->id)
                ->orderBy('transaction_category_id')
                ->pluck('transaction_category_id')
                ->map(fn ($i): int => (int) $i)
                ->all();

            if ($d === $deptIds && $k === $katIds) {
                return true;
            }
        }

        return false;
    }
}
