<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Alur approval untuk department Board of Director
|--------------------------------------------------------------------------
| Ketentuannya:
|
| - Anggota BOD mengajukan   -> disetujui anggota BOD LAIN, lalu Finance
|                               Manager.
| - CEO mengajukan           -> langsung Finance Manager; tidak ada anggota
|                               BOD yang berada di atasnya.
| - Nilai tidak membatasi    -> flow ini tanpa rentang nominal, jadi tidak ada
|                               eskalasi karena besar kecilnya pengajuan.
|
| Tiga modul sekaligus: FPU, Realisasi, dan Claim. Alur ketiganya memang sama;
| yang berbeda hanya dokumennya.
|
| HANYA MENAMBAH, TIDAK MENYENTUH YANG LAIN.
|
| Seeder ini hanya membuat flow untuk department BOD, dan hanya bila belum
| ada. Flow department lain tidak dibaca, tidak diubah, tidak dihapus.
|
| Step 2 boleh dihapus lewat master bila nanti Finance Manager tidak
| diperlukan -- rantainya berhenti di sesama BOD, tanpa menyentuh kode.
|--------------------------------------------------------------------------
*/
class BodApprovalFlowSeeder extends Seeder
{
    public function run(): void
    {
        $bod = DB::table('departments')->where('nama', 'Board of Director')->value('id');

        $financeManager = DB::table('roles')->where('nama', 'Finance Manager')->value('id');

        $ceo = DB::table('roles')->where('nama', 'Chief Executive Officer')->value('id');

        if (!$bod || !$financeManager || !$ceo) {
            $this->command?->warn(
                '[BOD] Department Board of Director, role Finance Manager, atau role'
                . ' Chief Executive Officer tidak ditemukan. Seeder dilewati.',
            );

            return;
        }

        $modul = [
            'FPU' => ['module_name' => 'Cash Advance', 'permission_code' => 'cash_advance'],
            'REALISASI' => ['module_name' => 'Realisasi FPU', 'permission_code' => 'cash_advance_realization'],
            'CLAIM' => ['module_name' => 'Claim', 'permission_code' => 'claim'],
        ];

        foreach ($modul as $documentType => $info) {
            $sudahAda = ApprovalFlow::query()
                ->whereNull('deleted_at')
                ->whereRaw('UPPER(TRIM(document_type)) = ?', [$documentType])
                ->whereHas('departments', fn ($q) => $q->where('departments.id', $bod))
                ->exists();

            if ($sudahAda) {
                $this->command?->info("[BOD] Flow {$documentType} untuk BOD sudah ada, dilewati.");

                continue;
            }

            $permissionModuleId = DB::table('permission_modules')
                ->where('code', $info['permission_code'])
                ->value('id');

            $flow = ApprovalFlow::create([
                'module_name' => $info['module_name'],
                'name' => "Approval {$documentType} – Board of Director",
                'document_type' => $documentType,
                'area_type' => 'HO',
                'is_active' => true,

                /*
                | Tanpa rentang nominal: "unlimited" bagi BOD berarti tidak ada
                | eskalasi karena nilai. Dibiarkan null, bukan diisi angka besar
                | -- angka besar berbohong, dan suatu hari ada yang menyentuhnya.
                */
                'min_amount' => null,
                'max_amount' => null,

                'all_departments' => false,
                'all_transaction_categories' => true,
                'creator_department_id' => $bod,
                'permission_module_id' => $permissionModuleId,

                'description' => 'Pengajuan anggota BOD di-counter-sign anggota BOD lain,'
                    . ' lalu disetujui Finance Manager. CEO melewati counter-sign'
                    . ' karena tidak ada anggota BOD yang berada di atasnya.',
            ]);

            DB::table('approval_flow_departments')->insert([
                'approval_flow_id' => $flow->id,
                'department_id' => $bod,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
            | Step 1 -- counter-sign sesama BOD.
            |
            | Approver-nya DEPARTMENT, bukan daftar role: ada akun BOD yang
            | belum punya role, dan daftar berbasis role akan melewatkannya
            | diam-diam. Pemohon dikeluarkan sendiri oleh allow_peer_approval.
            */
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'step_order' => 1,
                'label' => 'Counter-sign BOD',
                'approver_type' => ApprovalFlowStep::APPROVER_TYPE_DEPARTMENT,
                'approver_id' => $bod,
                'approval_mode' => ApprovalFlowStep::APPROVAL_MODE_ANY,
                'approver_scope' => ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                'allow_peer_approval' => true,
                'skip_for_role_ids' => [$ceo],
                'is_required' => true,
            ]);

            /* Step 2 -- Finance Manager, tingkat terakhir. */
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'step_order' => 2,
                'label' => 'Finance Manager',
                'approver_type' => ApprovalFlowStep::APPROVER_TYPE_ROLE,
                'approver_id' => $financeManager,
                'approval_mode' => ApprovalFlowStep::APPROVAL_MODE_ANY,
                'approver_scope' => ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                'allow_peer_approval' => false,
                'skip_for_role_ids' => [],
                'is_required' => true,
            ]);

            $this->command?->info("[BOD] Flow {$documentType} untuk BOD dibuat (id {$flow->id}).");
        }

        $this->alurPerdin($bod, $ceo);

        $this->batasTanpaBatas($bod);
    }

    /**
     * Perdin BOD: HRGA Manager dulu, baru counter-sign sesama BOD.
     *
     * Urutannya memang terbalik dari FPU/Realisasi/Claim, dan itu disengaja --
     * perdin diurus HRGA lebih dulu. CEO melewati counter-sign dengan cara
     * yang sama: tidak ada anggota BOD yang berada di atasnya.
     *
     * Berdiri sendiri, bukan ikut perulangan di atas, supaya perbedaan urutan
     * itu terbaca sebagai keputusan -- bukan sebagai pengecualian yang
     * terselip di dalam perulangan.
     */
    private function alurPerdin(int $bod, int $ceo): void
    {
        $hrga = DB::table('roles')->where('nama', 'HRGA Manager')->value('id');

        if (!$hrga) {
            $this->command?->warn('[BOD] Role HRGA Manager tidak ditemukan. Flow Perdin dilewati.');

            return;
        }

        $sudahAda = ApprovalFlow::query()
            ->whereNull('deleted_at')
            ->whereRaw('UPPER(TRIM(document_type)) = ?', ['PERDIN'])
            ->whereHas('departments', fn ($q) => $q->where('departments.id', $bod))
            ->exists();

        if ($sudahAda) {
            $this->command?->info('[BOD] Flow PERDIN untuk BOD sudah ada, dilewati.');

            return;
        }

        $flow = ApprovalFlow::create([
            'module_name' => 'Perjalanan Dinas',
            'name' => 'Approval PERDIN – Board of Director',
            'document_type' => 'PERDIN',
            'area_type' => 'HO',
            'is_active' => true,
            'min_amount' => null,
            'max_amount' => null,
            'all_departments' => false,
            'all_transaction_categories' => true,
            'creator_department_id' => $bod,
            'permission_module_id' => DB::table('permission_modules')
                ->where('code', 'business_trip')
                ->value('id'),

            'description' => 'Perdin anggota BOD diurus HRGA Manager lebih dulu, lalu'
                . ' di-counter-sign anggota BOD lain. CEO berhenti di HRGA Manager'
                . ' karena tidak ada anggota BOD yang berada di atasnya.',
        ]);

        DB::table('approval_flow_departments')->insert([
            'approval_flow_id' => $flow->id,
            'department_id' => $bod,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'step_order' => 1,
            'label' => 'HRGA Manager',
            'approver_type' => ApprovalFlowStep::APPROVER_TYPE_ROLE,
            'approver_id' => $hrga,
            'approval_mode' => ApprovalFlowStep::APPROVAL_MODE_ANY,
            'approver_scope' => ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
            'allow_peer_approval' => false,
            'skip_for_role_ids' => [],
            'is_required' => true,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'step_order' => 2,
            'label' => 'Counter-sign BOD',
            'approver_type' => ApprovalFlowStep::APPROVER_TYPE_DEPARTMENT,
            'approver_id' => $bod,
            'approval_mode' => ApprovalFlowStep::APPROVAL_MODE_ANY,
            'approver_scope' => ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
            'allow_peer_approval' => true,
            'skip_for_role_ids' => [$ceo],
            'is_required' => true,
        ]);

        $this->command?->info("[BOD] Flow PERDIN untuk BOD dibuat (id {$flow->id}).");
    }

    /**
     * Batas FPU untuk BOD: ada barisnya, tetapi tidak membatasi apa pun.
     *
     * Dibuat sebagai BARIS SENDIRI, bukan dengan mengubah Batas Umum. Batas
     * umum berlaku untuk seluruh department lain, dan menyentuhnya berarti
     * membebaskan semua orang karena ingin membebaskan dua orang.
     */
    private function batasTanpaBatas(int $bod): void
    {
        $sudahAda = DB::table('fund_request_limits')
            ->where('department_id', $bod)
            ->exists();

        if ($sudahAda) {
            $this->command?->info('[BOD] Batas FPU untuk BOD sudah ada, dilewati.');

            return;
        }

        DB::table('fund_request_limits')->insert([
            'name' => 'Board of Director – tanpa batas',
            'area_type' => null,
            'department_id' => $bod,

            /*
            | Kedua angka tetap diisi masuk akal walau tidak dipakai: penanda
            | di bawahnya bisa dimatikan sewaktu-waktu, dan saat itu terjadi
            | batasnya harus langsung berlaku tanpa ada yang menebak angkanya.
            */
            'max_outstanding' => 3,
            'max_realization_days' => 14,
            'is_unlimited' => true,

            'is_active' => true,
            'notes' => 'Unlimited berarti bebas dari batas NILAI dan dari batas jumlah'
                . ' FPU berjalan serta umur realisasi -- bukan bebas dari PERSETUJUAN.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command?->info('[BOD] Batas FPU tanpa batas untuk BOD dibuat.');
    }
}
