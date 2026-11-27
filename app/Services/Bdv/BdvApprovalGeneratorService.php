<?php

namespace App\Services\Bdv;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\BankDisbursementVoucher;
use App\Models\BdvApproval;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalFlow\ApprovalDocumentTypeService;
use App\Services\FundRequest\ApprovalStepRuleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Membekukan rantai approval BDV
|--------------------------------------------------------------------------
| Satu flow yang paling cocok dipilih dari matriks approval, lalu seluruh
| step-nya DIBEKUKAN menjadi baris bdv_approvals. Sejak saat itu dokumennya
| berjalan di atas salinan beku tersebut, bukan di atas matriksnya.
|
| Mengapa dibekukan: matriks approval disunting sewaktu-waktu oleh admin.
| Kalau dokumen membaca matriks secara langsung, satu suntingan di sore hari
| memindahkan dokumen orang lain ke meja yang berbeda di tengah jalan --
| termasuk dokumen yang sudah disetujui separuh. Yang menandatangani
| kemarin menandatangani rantai yang sudah tidak ada lagi hari ini.
|
| Bentuknya mengikuti CashAdvanceApprovalGeneratorService dan saudaranya.
| Kesamaan itu disengaja dan perlu dijaga: layar approval, badge sidebar,
| dan notifikasi di seluruh aplikasi membaca bentuk baris yang sama.
|
| CATATAN UNTUK YANG MERAPIKAN NANTI: di repositori ini sudah ada lima
| generator yang serupa (FPU, Claim, Realisasi, Petty Cash, dan ini).
| Keempat *ApprovalService-nya bahkan hampir identik -- hanya delapan baris
| yang berbeda dari 391. Menyatukannya adalah pekerjaan yang sah, tetapi
| pekerjaan tersendiri: ia menyentuh empat modul yang sedang dipakai, dan
| tidak boleh diselundupkan ke dalam penambahan modul kelima.
|--------------------------------------------------------------------------
*/
class BdvApprovalGeneratorService
{
    /**
     * Membangun rantai approval untuk satu BDV.
     *
     * @throws ValidationException
     */
    public function generate(BankDisbursementVoucher $bdv): void
    {
        if (BdvApproval::query()->where('bdv_id', $bdv->id)->exists()) {
            throw ValidationException::withMessages([
                'approval' => [__('bdv_messages.approval_already_built')],
            ]);
        }

        $totalAmount = (float) ($bdv->total_amount ?? 0);

        if ($totalAmount <= 0) {
            throw ValidationException::withMessages([
                'total_amount' => [__('bdv_messages.approval_amount_zero')],
            ]);
        }

        $approvalFlow = $this->cariFlow($bdv, $totalAmount);

        if (!$approvalFlow) {
            /*
            | Yang disebut dalam pesan HANYA kriteria yang benar-benar dipakai
            | module ini. Menyebut area dan department pada module yang disetel
            | global mengirim admin menyunting matriks yang tidak pernah
            | dibaca -- persis keluhan yang melahirkan aturan ini.
            */
            throw ValidationException::withMessages([
                'approval_flow' => [$this->pesanFlowTidakAda($bdv, $totalAmount)],
            ]);
        }

        $flowSteps = ApprovalFlowStep::query()
            ->with(['branchMappings', 'specialApprovers'])
            ->where('approval_flow_id', $approvalFlow->id)
            ->orderBy('step_order')
            ->orderBy('id')
            ->get();

        if ($flowSteps->isEmpty()) {
            throw ValidationException::withMessages([
                'approval_flow' => [__('bdv_messages.approval_flow_no_steps')],
            ]);
        }

        /*
        | Rencana rantainya disusun SEBELUM satu baris pun ditulis.
        |
        | Sebuah step bisa gugur karena dua sebab: role pemohon masuk daftar
        | pengecualian step itu, atau step boleh disetujui rekan setingkat dan
        | tidak ada rekan lain yang tersisa. Keduanya harus diketahui sekarang,
        | sebab status "menunggu" melekat pada step pertama yang BERTAHAN --
        | step pertama yang gugur belakangan meninggalkan dokumen tanpa satu
        | pun baris yang menunggu, dan dokumen seperti itu diam selamanya.
        */
        $aturanStep = app(ApprovalStepRuleService::class);

        $pemohonId = (int) ($bdv->submitted_by ?? $bdv->created_by ?? 0);

        $rencanaStep = $aturanStep->planSteps(
            $flowSteps,
            $pemohonId,
            $aturanStep->roleIdsOf($pemohonId),

            /*
            | Step bercakupan cabang dicairkan lebih dulu supaya keputusan
            | "pemohon ada di dalamnya atau tidak" diambil atas orang yang
            | benar-benar akan menjadi approver dokumen ini -- bukan atas role
            | pada kertasnya, yang pemegangnya bisa berada di cabang lain dan
            | memang tidak pernah ikut.
            */
            function (ApprovalFlowStep $step) use ($bdv) {
                $scope = strtoupper(trim(
                    (string) ($step->approver_scope ?: ApprovalFlowStep::APPROVER_SCOPE_GLOBAL),
                ));

                $tipe = strtoupper(trim((string) $step->approver_type));

                if ($scope === ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH) {
                    return $this->approverSatuCabang($step, $bdv, $tipe);
                }

                if ($scope === ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES) {
                    return $this->approverCabangTerpilih($step, $bdv, $tipe);
                }

                return null;
            },
        );

        /*
        | Tingkat yang bertahan tetapi tidak punya seorang pun di dalamnya.
        | Ditahan di sini, bukan dibiarkan tersimpan: dokumen yang masuk ke
        | tingkat tanpa approver akan menunggu selamanya tanpa pesan.
        */
        $aturanStep->assertApproverAda($rencanaStep, 'BDV');

        $stepHidup = $flowSteps->reject(
            fn (ApprovalFlowStep $satu): bool => isset($rencanaStep['skipped'][(int) $satu->id]),
        );

        if ($stepHidup->isEmpty()) {
            throw ValidationException::withMessages([
                'approval_flow' => [__('bdv_messages.approval_no_approver_left')],
            ]);
        }

        $stepPertama = (int) $stepHidup->min('step_order');

        /** @var array<int, string> Step yang wajib menghasilkan minimal satu approver. */
        $stepWajib = [];

        /** @var array<int, int> Jumlah snapshot yang berhasil dibuat per step. */
        $jumlahPerStep = [];

        /*
        | Mencegah snapshot ganda pada step yang sama -- misalnya ketika satu
        | orang dipilih langsung DAN ikut tercairkan lewat role.
        */
        $kunciSnapshot = [];

        foreach ($flowSteps as $flowStep) {
            $stepOrder = (int) $flowStep->step_order;

            /*
            | Dilewati SEBELUM didaftarkan sebagai step yang wajib berisi.
            | Kalau didaftarkan lebih dulu, step yang sengaja dilewati akan
            | menolak dokumennya sendiri karena tidak punya approver.
            */
            if (isset($rencanaStep['skipped'][(int) $flowStep->id])) {
                Log::info('[BDV Approval] Step dilewati', [
                    'bdv_id' => $bdv->id,
                    'step_order' => $stepOrder,
                    'label' => $flowStep->label,
                    'sebab' => $rencanaStep['skipped'][(int) $flowStep->id],
                ]);

                continue;
            }

            $stepWajib[$stepOrder] = $stepWajib[$stepOrder]
                ?? ($flowStep->label ?: sprintf('Step %d', $stepOrder));

            $tipe = strtoupper(trim((string) $flowStep->approver_type));

            $scope = strtoupper(trim(
                (string) ($flowStep->approver_scope ?: ApprovalFlowStep::APPROVER_SCOPE_GLOBAL),
            ));

            $mode = strtoupper(trim(
                (string) ($flowStep->approval_mode ?: ApprovalFlowStep::APPROVAL_MODE_ANY),
            ));

            /* Mode yang tidak dikenal diperlakukan sebagai ANY, bukan ditolak. */
            if (!in_array($mode, [BdvApproval::APPROVAL_MODE_ANY, BdvApproval::APPROVAL_MODE_ALL], true)) {
                $mode = BdvApproval::APPROVAL_MODE_ANY;
            }

            $status = $stepOrder === $stepPertama
                ? BdvApproval::STATUS_WAITING
                : BdvApproval::STATUS_PENDING;

            /* SAME_BRANCH: approver hanya berlaku bila cabang akunnya sama. */
            if ($scope === ApprovalFlowStep::APPROVER_SCOPE_SAME_BRANCH) {
                $this->simpanSnapshotUser(
                    $bdv,
                    $approvalFlow,
                    $flowStep,
                    $this->approverSatuCabang($flowStep, $bdv, $tipe),
                    $stepOrder,
                    $mode,
                    $status,
                    $kunciSnapshot,
                    $jumlahPerStep,
                );

                continue;
            }

            /*
            | SELECTED_BRANCHES: pemetaan pada step menentukan cabang dokumen
            | mana yang ditangani approver ini. Cabang akun approver diabaikan.
            */
            if ($scope === ApprovalFlowStep::APPROVER_SCOPE_SELECTED_BRANCHES) {
                $this->simpanSnapshotUser(
                    $bdv,
                    $approvalFlow,
                    $flowStep,
                    $this->approverCabangTerpilih($flowStep, $bdv, $tipe),
                    $stepOrder,
                    $mode,
                    $status,
                    $kunciSnapshot,
                    $jumlahPerStep,
                );

                continue;
            }

            /*
            | Approver yang sudah dicairkan oleh rencana di atas: step bertipe
            | DEPARTMENT, atau step yang pemohonnya baru saja dikeluarkan.
            |
            | Hanya berlaku untuk scope GLOBAL. Kedua sifat itu bekerja pada
            | daftar orang, sementara scope cabang menyaring daftar yang sama
            | dengan aturan lain -- menggabungkan keduanya tanpa memikirkannya
            | menghasilkan daftar yang tidak pernah diminta siapa pun.
            */
            $rencanaBaris = $rencanaStep['users'][(int) $flowStep->id] ?? null;

            if ($rencanaBaris !== null) {
                if ($scope !== ApprovalFlowStep::APPROVER_SCOPE_GLOBAL) {
                    throw ValidationException::withMessages([
                        'approval_flow' => [__('bdv_messages.approval_step_scope_must_global', [
                            'step' => $flowStep->label ?? '-',
                        ])],
                    ]);
                }

                $this->simpanSnapshotUser(
                    $bdv,
                    $approvalFlow,
                    $flowStep,
                    $rencanaBaris,
                    $stepOrder,
                    $mode,
                    $status,
                    $kunciSnapshot,
                    $jumlahPerStep,
                );

                continue;
            }

            /* GLOBAL: USER disimpan sebagai USER, ROLE tetap sebagai ROLE. */
            if ($scope !== ApprovalFlowStep::APPROVER_SCOPE_GLOBAL) {
                throw ValidationException::withMessages([
                    'approval_flow' => [__('bdv_messages.approval_scope_unsupported', [
                        'scope' => $scope ?: '-',
                        'step' => $flowStep->label ?? '-',
                    ])],
                ]);
            }

            $approverId = (int) $flowStep->approver_id;

            if ($approverId <= 0) {
                throw ValidationException::withMessages([
                    'approval_flow' => [__('bdv_messages.approval_step_no_approver', [
                        'step' => $flowStep->label ?? '-',
                    ])],
                ]);
            }

            if (!in_array($tipe, [BdvApproval::APPROVER_TYPE_USER, BdvApproval::APPROVER_TYPE_ROLE], true)) {
                throw ValidationException::withMessages([
                    'approval_flow' => [__('bdv_messages.approval_type_unsupported', [
                        'type' => $tipe ?: '-',
                        'step' => $flowStep->label ?? '-',
                    ])],
                ]);
            }

            /*
            | Step yang menunjuk satu akun tertentu, dan akun itu sudah
            | nonaktif. Dilewati seperti kandidat nonaktif lainnya; bila
            | tingkatnya jadi tanpa penyetuju sama sekali, pemeriksaan "setiap
            | step wajib punya approver" di bawah yang akan melaporkannya.
            */
            if ($tipe === BdvApproval::APPROVER_TYPE_USER && !$this->akunAktif($approverId)) {
                continue;
            }

            $kunci = sprintf('%d-%s-%d', $stepOrder, $tipe, $approverId);

            if (isset($kunciSnapshot[$kunci])) {
                continue;
            }

            $kunciSnapshot[$kunci] = true;

            BdvApproval::create([
                'bdv_id' => $bdv->id,
                'approval_flow_id' => $approvalFlow->id,
                'approval_flow_step_id' => $flowStep->id,
                'step_order' => $stepOrder,
                'label' => $flowStep->label,
                'approver_type' => $tipe,
                'approver_id' => $approverId,
                'approver_name_snapshot' => $this->namaApprover($flowStep),
                'approval_mode' => $mode,
                'status' => $status,
            ]);

            $jumlahPerStep[$stepOrder] = ($jumlahPerStep[$stepOrder] ?? 0) + 1;
        }

        /*
        | Setiap step yang bertahan wajib punya minimal satu approver.
        | Diperiksa setelah seluruh kandidat step itu selesai diproses, karena
        | kandidat dari cabang lain memang sengaja dilewati di tengah jalan.
        */
        foreach ($stepWajib as $urutan => $label) {
            if ((int) ($jumlahPerStep[$urutan] ?? 0) > 0) {
                continue;
            }

            throw ValidationException::withMessages([
                'approval_flow' => [__('bdv_messages.approval_step_empty', ['step' => $label])],
            ]);
        }

        Log::info('[BDV Approval] Rantai approval dibuat', [
            'bdv_id' => $bdv->id,
            'bdv_number' => $bdv->bdv_number,
            'approval_flow_id' => $approvalFlow->id,
            'jumlah_baris' => array_sum($jumlahPerStep),
            'step_pertama' => $stepPertama,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Menyimpan snapshot dari daftar user yang sudah dicairkan
    |--------------------------------------------------------------------------
    | Hasil resolver SELALU disimpan sebagai USER konkret, tidak lagi sebagai
    | ROLE. Alasannya: daftar itu sudah disaring menurut cabang atau
    | department, dan menyimpannya kembali sebagai role akan membuat orang
    | di luar saringan itu ikut bisa menyetujui -- saringannya hilang begitu
    | barisnya tersimpan.
    |--------------------------------------------------------------------------
    */
    private function simpanSnapshotUser(
        BankDisbursementVoucher $bdv,
        ApprovalFlow $approvalFlow,
        ApprovalFlowStep $flowStep,
        Collection $kandidat,
        int $stepOrder,
        string $mode,
        string $status,
        array &$kunciSnapshot,
        array &$jumlahPerStep,
    ): void {
        $idAktif = $this->saringAkunAktif($kandidat);

        foreach ($kandidat as $orang) {
            $userId = (int) ($orang->id ?? 0);

            if ($userId <= 0) {
                continue;
            }

            /*
            | Akun nonaktif tidak masuk ke rantai approval. Orang yang sudah
            | tidak memegang akun tidak akan pernah menyetujui apa pun;
            | barisnya hanya menambah nama di riwayat dan membuat "cukup satu
            | dari tiga approver" terbaca lebih longgar daripada kenyataannya.
            */
            if (!in_array($userId, $idAktif, true)) {
                continue;
            }

            $kunci = sprintf('%d-%s-%d', $stepOrder, BdvApproval::APPROVER_TYPE_USER, $userId);

            if (isset($kunciSnapshot[$kunci])) {
                continue;
            }

            $kunciSnapshot[$kunci] = true;

            BdvApproval::create([
                'bdv_id' => $bdv->id,
                'approval_flow_id' => $approvalFlow->id,
                'approval_flow_step_id' => $flowStep->id,
                'step_order' => $stepOrder,
                'label' => $flowStep->label,
                'approver_type' => BdvApproval::APPROVER_TYPE_USER,
                'approver_id' => $userId,
                'approver_name_snapshot' => $orang->name ?? $orang->fullname ?? null,
                'approval_mode' => $mode,
                'status' => $status,
            ]);

            $jumlahPerStep[$stepOrder] = ($jumlahPerStep[$stepOrder] ?? 0) + 1;
        }
    }

    /**
     * Id akun yang masih aktif di antara kandidat yang diberikan.
     *
     * Diperiksa sekali di muara, bukan di tiap resolver: beberapa jalur
     * bermuara ke sini, sebagian membawa model User utuh sementara sebagian
     * lagi hanya id dan nama. Membaca $user->is_active langsung akan
     * menghasilkan null pada jalur yang kedua -- dan null berarti seluruh
     * kandidatnya terbuang diam-diam.
     *
     * @return array<int, int>
     */
    private function saringAkunAktif(Collection $kandidat): array
    {
        $ids = $kandidat
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids->all())
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function akunAktif(int $userId): bool
    {
        return $userId > 0
            && User::query()->whereKey($userId)->where('is_active', true)->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Approver bercakupan "cabang yang sama"
    |--------------------------------------------------------------------------
    | Hanya orang yang akunnya berada di cabang dokumen. Cabang dokumen BDV
    | diwarisi dari dokumen sumbernya, jadi "cabang yang sama" di sini berarti
    | cabang FPU/Claim yang sedang dicairkan -- bukan cabang orang yang
    | menerbitkan vouchernya.
    |--------------------------------------------------------------------------
    */
    private function approverSatuCabang(
        ApprovalFlowStep $flowStep,
        BankDisbursementVoucher $bdv,
        string $tipe,
    ): Collection {
        $branchId = (int) $bdv->branch;
        $approverId = (int) $flowStep->approver_id;

        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'branch' => [__('bdv_messages.approval_no_branch')],
            ]);
        }

        if ($approverId <= 0) {
            throw ValidationException::withMessages([
                'approval_flow' => [__('bdv_messages.approval_step_no_approver', [
                    'step' => $flowStep->label ?? '-',
                ])],
            ]);
        }

        /*
        | USER: dikembalikan hanya bila cabangnya sama. Bila berbeda, hasilnya
        | kosong dan kandidat ini dilewati -- bukan galat, sebab mungkin ada
        | approver lain pada step yang sama yang memang menanganinya.
        */
        if ($tipe === BdvApproval::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($approverId)
                ->where('users.cabang_id', $branchId)
                ->get();
        }

        if ($tipe === BdvApproval::APPROVER_TYPE_ROLE) {
            return User::query()
                ->where('users.cabang_id', $branchId)
                ->whereHas('roles', fn ($q) => $q->where('roles.id', $approverId))
                ->get();
        }

        throw ValidationException::withMessages([
            'approval_flow' => [__('bdv_messages.approval_type_unsupported', [
                'type' => $tipe ?: '-',
                'step' => $flowStep->label ?? '-',
            ])],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Approver bercakupan "cabang terpilih"
    |--------------------------------------------------------------------------
    | Pemetaan pada step menyebutkan cabang dokumen mana yang ditangani
    | approver ini. Cabang akun approver-nya sendiri tidak diperiksa -- ia
    | memang biasanya orang HO yang menangani beberapa cabang sekaligus.
    |--------------------------------------------------------------------------
    */
    private function approverCabangTerpilih(
        ApprovalFlowStep $flowStep,
        BankDisbursementVoucher $bdv,
        string $tipe,
    ): Collection {
        $branchId = (int) $bdv->branch;
        $approverId = (int) $flowStep->approver_id;

        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'branch' => [__('bdv_messages.approval_no_branch')],
            ]);
        }

        if ($approverId <= 0) {
            throw ValidationException::withMessages([
                'approval_flow' => [__('bdv_messages.approval_step_no_approver', [
                    'step' => $flowStep->label ?? '-',
                ])],
            ]);
        }

        $cabangDitangani = $flowStep
            ->branchMappings
            ->pluck('cabang_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($cabangDitangani->isEmpty()) {
            throw ValidationException::withMessages([
                'approval_flow' => [__('bdv_messages.approval_step_no_branches', [
                    'step' => $flowStep->label ?? '-',
                ])],
            ]);
        }

        /*
        | Cabang dokumen di luar cakupan approver ini BUKAN galat -- mungkin
        | ada approver lain pada step yang sama yang menanganinya.
        */
        if (!$cabangDitangani->contains($branchId)) {
            return collect();
        }

        if ($tipe === BdvApproval::APPROVER_TYPE_USER) {
            return User::query()->whereKey($approverId)->get();
        }

        if ($tipe === BdvApproval::APPROVER_TYPE_ROLE) {
            return User::query()
                ->whereHas('roles', fn ($q) => $q->where('roles.id', $approverId))
                ->get();
        }

        throw ValidationException::withMessages([
            'approval_flow' => [__('bdv_messages.approval_type_unsupported', [
                'type' => $tipe ?: '-',
                'step' => $flowStep->label ?? '-',
            ])],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Memilih flow yang paling cocok
    |--------------------------------------------------------------------------
    | Dicocokkan menurut empat hal: area (HO/CABANG), department, keterangan
    | transaksi, dan rentang nominal. Bila beberapa flow cocok sekaligus, yang
    | PALING SPESIFIK menang -- lihat keterangan pada ORDER BY di bawah.
    |
    | Aturannya disamakan persis dengan FPU dan Claim. Satu matriks yang sama
    | diisi admin untuk ketiganya, dan kriteria yang diam-diam berbeda di satu
    | modul adalah jebakan yang baru ketahuan setelah dokumen tersangkut.
    |--------------------------------------------------------------------------
    */
    private function cariFlow(BankDisbursementVoucher $bdv, float $totalAmount): ?ApprovalFlow
    {
        /*
        | Kriteria pencocokan ditentukan SETELAN MODULE, bukan kode ini.
        |
        | Dua sakelar pada Pengaturan Approval Flow module: "Pakai Kondisi
        | Matrix" (area + department) dan "Pakai Keterangan Transaksi".
        | Mati keduanya berarti alurnya berlaku global -- dan di situlah
        | pencocokan yang memaksakan area/department menjadi jebakan: alur
        | yang disimpan lewat layar itu punya area_type NULL, all_departments
        | false, dan tanpa satu pun baris department, jadi TIDAK AKAN PERNAH
        | cocok dengan perbandingan yang menuntut keduanya terisi. Dokumennya
        | ditolak dengan pesan "lengkapi matriks", padahal matriksnya memang
        | sengaja tidak dipakai.
        */
        $aturanDokumen = app(ApprovalDocumentTypeService::class);

        $pakaiMatriks = $aturanDokumen->usesAreaMatrix(ApprovalFlow::DOCUMENT_TYPE_BDV);

        $pakaiKategori = $aturanDokumen->usesTransactionCategory(
            ApprovalFlow::DOCUMENT_TYPE_BDV,
        );

        $areaType = $bdv->getApprovalAreaType();

        /*
        | BOLEH KOSONG, dan itu jaring pengaman -- bukan keadaan biasa.
        |
        | Sejak aturan "satu BDV, satu department" berlaku, department_id di
        | kepala voucher selalu terisi. Yang masih bisa kosong hanya voucher
        | lama: dokumen yang sempat terbit saat department boleh campur, dan
        | yang dipindahkan migrasi dari skema satu-sumber.
        |
        | Yang kosong dicocokkan hanya dengan flow "Semua Divisi" -- lihat
        | blok department di bawah. Kalau matriksnya tidak punya baris itu,
        | pengajuannya ditolak dengan pesan yang menyebut kombinasinya, bukan
        | diam-diam jatuh ke flow yang tidak dimaksudkan siapa pun.
        */
        $departmentId = $bdv->department_id ? (int) $bdv->department_id : null;

        $kategoriId = $bdv->transaction_category_id
            ? (int) $bdv->transaction_category_id
            : null;

        Log::info('[BDV Approval] Mencari flow', [
            'bdv_id' => $bdv->id,
            'document_type' => ApprovalFlow::DOCUMENT_TYPE_BDV,
            'branch_id' => $bdv->branch,
            'area_type' => $areaType,
            'department_id' => $departmentId,
            'transaction_category_id' => $kategoriId,
            'total_amount' => $totalAmount,
            'pakai_matriks' => $pakaiMatriks,
            'pakai_kategori' => $pakaiKategori,
        ]);

        $flow = ApprovalFlow::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereRaw('UPPER(TRIM(document_type)) = ?', [ApprovalFlow::DOCUMENT_TYPE_BDV])

            /* Dipakai oleh urutan di bawah, saat matriks tidak dipakai. */
            ->when($pakaiMatriks === false, fn ($query) => $query->withCount('departments'))

            /*
            | Area dokumen: HO = kantor pusat, CABANG = selain HO.
            | approval_flows.cabang sengaja tidak ikut dibandingkan.
            |
            | Dilewati bila module tidak memakai kondisi matriks: kolomnya
            | memang sengaja dibiarkan NULL oleh layar Approval Flow.
            */
            ->when(
                $pakaiMatriks,
                fn ($query) => $query->whereRaw('UPPER(TRIM(area_type)) = ?', [$areaType]),
            )

            /*
            | Department: flow "Semua Divisi" cocok untuk department mana pun.
            | Selain itu dicocokkan ke tabel pivot, dengan creator_department_id
            | sebagai cadangan bagi flow lama yang belum punya baris pivot.
            |
            | Ikut dilewati saat matriks tidak dipakai, dengan sebab yang sama.
            */
            ->when($pakaiMatriks, function ($query) use ($departmentId) {
                $query->where(function ($dalam) use ($departmentId) {
                    $dalam->where('all_departments', true);

                    /*
                    | Voucher berdepartment campur (null) berhenti di sini:
                    | hanya flow "Semua Divisi" yang cocok untuknya.
                    */
                    if ($departmentId === null) {
                        return;
                    }

                    $dalam
                        ->orWhereHas(
                            'departments',
                            fn ($q) => $q->where('departments.id', $departmentId),
                        )
                        ->orWhere(function ($lama) use ($departmentId) {
                            $lama
                                ->where('creator_department_id', $departmentId)
                                ->whereDoesntHave('departments');
                        });
                });
            })

            /*
            | Keterangan transaksi: flow yang tidak dibatasi kategori cocok
            | untuk semuanya. Dokumen tanpa kategori hanya cocok dengan flow
            | seperti itu. Dilewati bila module tidak memakainya.
            */
            ->when($pakaiKategori, function ($query) use ($kategoriId) {
                $query->where(function ($dalam) use ($kategoriId) {
                    $dalam->where('all_transaction_categories', true);

                    if ($kategoriId) {
                        $dalam->orWhereHas(
                            'transactionCategories',
                            fn ($q) => $q->where('fund_request_transaction_categories.id', $kategoriId),
                        );
                    }
                });
            })

            ->where(function ($query) use ($totalAmount) {
                $query->whereNull('min_amount')->orWhere('min_amount', '<=', $totalAmount);
            })

            /* max_amount null atau 0 berarti tidak berbatas. */
            ->where(function ($query) use ($totalAmount) {
                $query
                    ->whereNull('max_amount')
                    ->orWhere('max_amount', 0)
                    ->orWhere('max_amount', '>=', $totalAmount);
            })

            /*
            | Bila beberapa flow cocok, yang paling spesifik menang.
            |
            | Urutan kekhususan: keterangan transaksi tertentu mengalahkan
            | "semua kategori", department tertentu mengalahkan "Semua Divisi",
            | lalu rentang nominal yang paling sempit.
            |
            | false diurutkan lebih dulu daripada true pada PostgreSQL, jadi
            | ASC di sini berarti "yang dibatasi lebih dulu".
            */
            /*
            | Saat kriterianya dipakai: yang dibatasi menang atas yang "semua"
            | (false lebih dulu daripada true pada PostgreSQL ASC).
            |
            | Saat TIDAK dipakai, urutan itu justru berbahaya. Alur yang
            | tertinggal dari masa sakelarnya masih menyala tetap memegang
            | baris department/kategori, dan "yang dibatasi menang" akan
            | memilihkan alur milik department lain untuk dokumen ini. Maka
            | yang didahulukan dibalik: alur yang TIDAK terikat apa pun.
            */
            ->when(
                $pakaiKategori,
                fn ($query) => $query->orderBy('all_transaction_categories'),
                fn ($query) => $query->orderByDesc('all_transaction_categories'),
            )
            ->when(
                $pakaiMatriks,
                fn ($query) => $query->orderBy('all_departments'),
                fn ($query) => $query->orderBy('departments_count'),
            )

            /*
            | COALESCE dipakai karena PostgreSQL menempatkan NULL paling dulu
            | pada ORDER BY DESC -- tanpa itu, flow "semua nilai" justru
            | menang atas flow yang rentangnya lebih sempit.
            */
            ->orderByRaw('COALESCE(min_amount, 0) DESC')
            ->orderByRaw('COALESCE(NULLIF(max_amount, 0), 999999999999999999) ASC')
            ->orderByDesc('id')
            ->first();

        Log::info('[BDV Approval] Flow terpilih', [
            'bdv_id' => $bdv->id,
            'approval_flow_id' => $flow?->id,
            'approval_flow_name' => $flow?->name,
        ]);

        return $flow;
    }

    /*
    |--------------------------------------------------------------------------
    | Pesan saat tidak ada flow yang cocok
    |--------------------------------------------------------------------------
    | Yang disebut hanya kriteria yang BENAR-BENAR dipakai module ini. Pesan
    | lama selalu menyebut area, department, dan keterangan transaksi, padahal
    | pada module yang disetel global ketiganya tidak pernah dibandingkan --
    | admin dikirim menyunting matriks yang tidak ada hubungannya, dan yang
    | sebenarnya kurang (satu alur BDV aktif) tidak pernah disebut.
    |--------------------------------------------------------------------------
    */
    private function pesanFlowTidakAda(BankDisbursementVoucher $bdv, float $totalAmount): string
    {
        $nominal = number_format($totalAmount, 0, ',', '.');

        $aturanDokumen = app(ApprovalDocumentTypeService::class);

        $bagian = [];

        if ($aturanDokumen->usesAreaMatrix(ApprovalFlow::DOCUMENT_TYPE_BDV)) {
            $bdv->loadMissing('departmentData');

            $bagian[] = __('bdv_messages.approval_criteria_area', [
                'value' => $bdv->getApprovalAreaType() ?: '-',
            ]);

            $bagian[] = __('bdv_messages.approval_criteria_department', [
                'value' => $bdv->departmentData?->nama ?? '-',
            ]);
        }

        if ($aturanDokumen->usesTransactionCategory(ApprovalFlow::DOCUMENT_TYPE_BDV)) {
            $bdv->loadMissing('transactionCategory');

            $bagian[] = __('bdv_messages.approval_criteria_category', [
                'value' => $bdv->transactionCategory?->name ?? '-',
            ]);
        }

        /*
        | Tidak ada kriteria selain nominal: module berlaku global. Pesannya
        | mengatakan hal itu terang-terangan, supaya yang dicari admin alur
        | BDV-nya sendiri, bukan baris matriks.
        */
        if ($bagian === []) {
            return __('bdv_messages.approval_flow_missing_global', ['amount' => $nominal]);
        }

        $bagian[] = __('bdv_messages.approval_criteria_amount', ['value' => $nominal]);

        return __('bdv_messages.approval_flow_missing', [
            'criteria' => $this->rangkaiKriteria($bagian),
        ]);
    }

    /**
     * "a, b, dan c" -- bukan "a, b, c", yang terbaca seperti kalimat terpotong.
     *
     * @param array<int, string> $bagian
     */
    private function rangkaiKriteria(array $bagian): string
    {
        if (count($bagian) <= 1) {
            return (string) ($bagian[0] ?? '-');
        }

        $terakhir = array_pop($bagian);

        return implode(', ', $bagian)
            . ' ' . __('bdv_messages.approval_criteria_join') . ' ' . $terakhir;
    }

    private function namaApprover(ApprovalFlowStep $flowStep): ?string
    {
        $tipe = strtoupper(trim((string) $flowStep->approver_type));

        if ($tipe === BdvApproval::APPROVER_TYPE_USER) {
            return User::query()->whereKey($flowStep->approver_id)->value('name');
        }

        if ($tipe === BdvApproval::APPROVER_TYPE_ROLE) {
            return Role::query()->whereKey($flowStep->approver_id)->value('nama');
        }

        return null;
    }
}
