<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Alur approval awal Petty Cash dan Top Up
|--------------------------------------------------------------------------
| Empat alur: petty cash dan top up, masing-masing untuk HO dan cabang.
|
| INI TITIK AWAL, BUKAN KEPUTUSAN AKHIR. Seluruhnya bisa diubah lewat layar
| Approval Flow. Yang dipilih di sini adalah susunan yang paling tidak
| mengejutkan, yaitu orang-orang yang sudah menjadi penyetuju claim kecil di
| cabangnya hari ini -- diambil dari alur "Approval Claim Cabang" (#189) step
| HOD/SPV, bukan dikarang baru.
|
| all_departments = true, berbeda dari alur Claim yang menyebut satu
| department. Alasannya: pemohon petty cash SELALU pemegang kas kecil, yang
| selalu Finance. Menyebut department tertentu hanya menambah satu cara bagi
| dokumen untuk kehilangan alurnya tanpa ada yang diperoleh.
|
| Aman dijalankan berulang: alur dicari dari nama, step-nya dibersihkan lalu
| ditulis ulang. Yang sudah diubah admin lewat layar akan TERTIMPA, jadi
| seeder ini hanya dijalankan sekali saat fitur dipasang.
|--------------------------------------------------------------------------
*/
class PettyCashApprovalFlowSeeder extends Seeder
{
    /*
    | Penyetuju tingkat cabang, apa adanya seperti pada alur Claim Cabang.
    | Bentuknya: [username, scope, [inisial cabang yang ditangani]].
    |
    | SAME_BRANCH berarti ia hanya menyetujui dokumen cabangnya sendiri;
    | SELECTED_BRANCHES berarti ia menangani cabang yang disebut.
    */
    private const PENYETUJU_CABANG = [
        ['lukman.prayoga', ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH, []],
        ['nicko.putra', ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH, []],
        ['freza', ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES, ['SMD']],
        ['sony.hartono', ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES, ['PLB', 'PTK', 'BJM']],
        ['ezar.mafazi', ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES, ['SLW']],
    ];

    /* Penyetuju terakhir top up: Finance di HO yang mengirim uangnya. */
    private const PENYETUJU_KAS = 'ade.restiani';

    private const ROLE_FINANCE_MANAGER = 'Finance Manager';

    public function run(): void
    {
        $modulPettyCash = DB::table('permission_modules')->where('code', 'petty_cash')->value('id');
        $modulTopup = DB::table('permission_modules')->where('code', 'petty_cash_topup')->value('id');

        if (!$modulPettyCash || !$modulTopup) {
            $this->command?->error(
                'Permission module petty cash belum ada. Jalankan PettyCashModuleSeeder lebih dulu.'
            );

            return;
        }

        $this->buatAlur(
            nama: 'Approval Petty Cash Cabang',
            documentType: ApprovalFlow::DOCUMENT_TYPE_PETTY_CASH,
            areaType: ApprovalFlow::AREA_CABANG,
            permissionModuleId: (int) $modulPettyCash,
            keterangan: 'Pembayaran claim kecil dari kas kecil cabang. Disetujui pimpinan cabang.',
            steps: [$this->stepPimpinanCabang(1)],
        );

        $this->buatAlur(
            nama: 'Approval Petty Cash HO',
            documentType: ApprovalFlow::DOCUMENT_TYPE_PETTY_CASH,
            areaType: ApprovalFlow::AREA_HO,
            permissionModuleId: (int) $modulPettyCash,
            keterangan: 'Pembayaran claim kecil dari kas kecil Kantor Pusat.'
                . ' HO tidak punya pimpinan cabang, jadi yang menyetujui Finance Manager.',
            steps: [$this->stepRole(1, self::ROLE_FINANCE_MANAGER, 'Finance Manager')],
        );

        $this->buatAlur(
            nama: 'Approval Top Up Petty Cash Cabang',
            documentType: ApprovalFlow::DOCUMENT_TYPE_PETTY_CASH_TOPUP,
            areaType: ApprovalFlow::AREA_CABANG,
            permissionModuleId: (int) $modulTopup,
            keterangan: 'Pengisian kembali kas kecil cabang. Disetujui pimpinan cabang, lalu Finance di HO yang mengirim uangnya.',
            steps: [
                $this->stepPimpinanCabang(1),
                $this->stepPenyetujuKas(2),
            ],
        );

        $this->buatAlur(
            nama: 'Approval Top Up Petty Cash HO',
            documentType: ApprovalFlow::DOCUMENT_TYPE_PETTY_CASH_TOPUP,
            areaType: ApprovalFlow::AREA_HO,
            permissionModuleId: (int) $modulTopup,
            keterangan: 'Pengisian kembali kas kecil Kantor Pusat.',
            steps: [
                $this->stepRole(1, self::ROLE_FINANCE_MANAGER, 'Finance Manager'),
                $this->stepPenyetujuKas(2),
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Susunan step
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function stepPimpinanCabang(int $order): array
    {
        $baris = [];

        foreach (self::PENYETUJU_CABANG as [$username, $scope, $cabang]) {
            $userId = DB::table('users')->where('username', $username)->value('id');

            if (!$userId) {
                $this->command?->warn("Penyetuju cabang \"{$username}\" tidak ditemukan -- dilewati.");

                continue;
            }

            $baris[] = [
                'approver_type' => ApprovalFlowStep::APPROVER_TYPE_USER,
                'approver_id' => (int) $userId,
                'approver_scope' => $scope,
                'cabang' => $cabang,
            ];
        }

        return [
            'step_order' => $order,
            'label' => 'Kacab / HOD',
            'rows' => $baris,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stepRole(int $order, string $roleName, string $label): array
    {
        $roleId = DB::table('roles')->where('nama', $roleName)->value('id');

        if (!$roleId) {
            $this->command?->warn("Role \"{$roleName}\" tidak ditemukan -- step dilewati.");

            return ['step_order' => $order, 'label' => $label, 'rows' => []];
        }

        return [
            'step_order' => $order,
            'label' => $label,
            'rows' => [[
                'approver_type' => ApprovalFlowStep::APPROVER_TYPE_ROLE,
                'approver_id' => (int) $roleId,
                'approver_scope' => ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                'cabang' => [],
            ]],
        ];
    }

    /**
     * Step terakhir top up: Finance di HO yang melepas uangnya.
     *
     * allow_peer_approval DINYALAKAN, dan itu perlu dijelaskan karena namanya
     * menyesatkan di sini.
     *
     * Ada aturan yang menggugurkan sebuah step bila tingkat penyetujunya tidak
     * lebih tinggi daripada pemohon DAN departmentnya sama (aturanTingkat di
     * ApprovalStepRuleService). Aturan itu masuk akal untuk persetujuan
     * berjenjang: atasan menyetujui bawahan.
     *
     * Top up tidak begitu. Pemohonnya ADH -- role Admin Head, tingkat 2,
     * department Finance. Penyetuju terakhirnya Admin Finance, tingkat 1,
     * department Finance juga. Tingkatnya LEBIH RENDAH dan departmentnya SAMA,
     * jadi tanpa pengecualian step ini akan digugurkan pada setiap top up yang
     * diajukan ADH -- gerbang terakhir sebelum uang dilepas justru yang hilang.
     *
     * Wewenangnya di sini memang bukan wewenang jabatan, melainkan wewenang
     * pemegang kas: ia yang mengirim uangnya, bukan atasan yang menyetujui
     * bawahannya. allow_peer_approval adalah satu-satunya pengecualian yang
     * sudah disediakan aturan itu, jadi itulah yang dipakai. Efek sampingnya
     * justru dikehendaki: kalau yang mengajukan top up adalah pemegang kas itu
     * sendiri, barisnya dikeluarkan dan ia tidak menyetujui pengajuannya
     * sendiri.
     *
     * Cara yang lebih bersih adalah menaikkan tingkat role-nya, atau membuat
     * role bendahara tersendiri bertingkat lebih tinggi. Keduanya keputusan
     * master data, bukan keputusan fitur ini.
     *
     * @return array<string, mixed>
     */
    private function stepPenyetujuKas(int $order): array
    {
        $userId = DB::table('users')->where('username', self::PENYETUJU_KAS)->value('id');

        if (!$userId) {
            $this->command?->error(
                'Penyetuju kas "' . self::PENYETUJU_KAS . '" tidak ditemukan -- step terakhir top up kosong.'
            );

            return ['step_order' => $order, 'label' => 'Finance HO', 'rows' => []];
        }

        return [
            'step_order' => $order,
            'label' => 'Finance HO',
            'allow_peer_approval' => true,
            'rows' => [[
                'approver_type' => ApprovalFlowStep::APPROVER_TYPE_USER,
                'approver_id' => (int) $userId,
                'approver_scope' => ApprovalFlowStep::APPROVER_SCOPE_GLOBAL,
                'cabang' => [],
            ]],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Penyimpanan
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function buatAlur(
        string $nama,
        string $documentType,
        string $areaType,
        int $permissionModuleId,
        string $keterangan,
        array $steps,
    ): void {
        $now = now();

        DB::table('approval_flows')->updateOrInsert(
            ['name' => $nama],
            [
                'module_name' => 'Petty Cash',
                'document_type' => $documentType,
                'area_type' => $areaType,
                'cabang' => null,
                'permission_module_id' => $permissionModuleId,
                'all_departments' => true,
                'all_transaction_categories' => true,
                'creator_department_id' => null,

                /*
                | Tidak berbatas ke atas. Besar kecilnya petty cash sudah
                | dibatasi plafon kas kecilnya sendiri, dan top up pun tidak
                | pernah melebihi plafon -- memasang batas kedua di sini hanya
                | menambah satu cara bagi dokumen untuk kehilangan alurnya.
                */
                'min_amount' => 1,
                'max_amount' => null,

                'description' => $keterangan,
                'is_active' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('approval_flows')
            ->where('name', $nama)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);

        $flowId = (int) DB::table('approval_flows')->where('name', $nama)->value('id');

        /*
        | Step lama dibersihkan, bukan ditambahi. Tanpa ini, menjalankan seeder
        | dua kali melahirkan approver ganda -- dan dokumen yang butuh satu
        | persetujuan jadi tampak butuh dua.
        */
        $stepIdLama = DB::table('approval_flow_steps')->where('approval_flow_id', $flowId)->pluck('id');

        if ($stepIdLama->isNotEmpty()) {
            DB::table('approval_flow_step_branches')->whereIn('approval_flow_step_id', $stepIdLama)->delete();
            DB::table('approval_flow_steps')->whereIn('id', $stepIdLama)->delete();
        }

        $jumlahBaris = 0;

        foreach ($steps as $step) {
            foreach ($step['rows'] as $row) {
                $stepId = DB::table('approval_flow_steps')->insertGetId([
                    'approval_flow_id' => $flowId,
                    'step_order' => $step['step_order'],
                    'label' => $step['label'],
                    'approver_type' => $row['approver_type'],
                    'approver_id' => $row['approver_id'],
                    'approver_scope' => $row['approver_scope'],
                    'approval_mode' => ApprovalFlowStep::APPROVAL_MODE_ANY,
                    'is_required' => true,
                    'allow_peer_approval' => $step['allow_peer_approval'] ?? false,
                    'skip_for_role_ids' => '[]',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($row['cabang'] as $inisial) {
                    $cabangId = DB::table('cabang')->where('inisial_cabang', $inisial)->value('id');

                    if (!$cabangId) {
                        $this->command?->warn("Cabang \"{$inisial}\" tidak ditemukan -- tidak dipetakan.");

                        continue;
                    }

                    DB::table('approval_flow_step_branches')->insert([
                        'approval_flow_step_id' => $stepId,
                        'cabang_id' => (int) $cabangId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $jumlahBaris++;
            }
        }

        $this->command?->info(sprintf(
            '  %-38s flow #%-4s %s step, %s baris approver',
            $nama,
            $flowId,
            count($steps),
            $jumlahBaris,
        ));
    }
}
