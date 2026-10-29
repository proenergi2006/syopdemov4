<?php

namespace App\Services\FundRequest;

use App\Models\ApprovalFlowStep;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Aturan step approval yang dipakai bersama
|--------------------------------------------------------------------------
| FPU, Realisasi, dan Claim punya generator sendiri-sendiri, tetapi tiga
| aturan di bawah ini harus berbunyi sama di ketiganya. Ditulis sekali di
| sini: aturan yang ditulis tiga kali cepat atau lambat berbeda di salah
| satunya, dan yang berbeda itu menentukan siapa yang boleh menyetujui uang.
|
| SELURUHNYA PASIF SECARA BAWAAN.
|
| Step yang tidak menyalakan apa pun keluar dari sini persis seperti saat
| masuk. Tidak ada satu pun alur yang berjalan sekarang berubah karena kelas
| ini ada.
|--------------------------------------------------------------------------
*/
class ApprovalStepRuleService
{
    /**
     * Role yang dipegang seseorang.
     *
     * PIVOT user_roles YANG MENENTUKAN. Kolom users.id_role hanya dipakai
     * sebagai cadangan, untuk akun lama yang belum punya satu pun baris
     * pivot.
     *
     * Keduanya dulu digabung, dan itu keliru: layar master user menulis ke
     * pivot dan TIDAK PERNAH memperbarui id_role, sehingga kolom itu menyimpan
     * role lama selamanya. Karena levelOf() mengambil tingkat TERTINGGI dari
     * gabungan itu, satu role basi diam-diam mengangkat tingkat jabatan
     * seseorang -- seorang Service Advisor (lv1) terbaca sebagai Operation
     * Manager (lv4), lalu aturan "bawahan tidak menyetujui atasan"
     * menggugurkan atasannya yang sebenarnya dan pengajuannya buntu.
     *
     * Permission sudah lama memperlakukan pivot sebagai satu-satunya sumber
     * -- getActiveRoleId() tidak pernah membaca id_role. Jadi perubahan ini
     * membuat keduanya sepakat, bukan menciptakan aturan baru.
     *
     * @return array<int, int>
     */
    public function roleIdsOf(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $dariPivot = array_values(array_unique(array_filter(
            array_map('intval', DB::table('user_roles')
                ->where('user_id', $userId)
                ->pluck('role_id')
                ->all()),
            fn (int $id): bool => $id > 0,
        )));

        if ($dariPivot !== []) {
            return $dariPivot;
        }

        $utama = (int) DB::table('users')
            ->where('id', $userId)
            ->value('id_role');

        return $utama > 0 ? [$utama] : [];
    }

    /**
     * Step ini dilewati karena pemohon memegang salah satu role pengecualian.
     *
     * Dipakai untuk CEO pada step counter-sign BOD: tidak ada anggota BOD yang
     * berada di atasnya, jadi ia langsung ke step berikutnya.
     *
     * @param  array<int, int>  $requesterRoleIds
     */
    public function stepSkipped(ApprovalFlowStep $step, array $requesterRoleIds): bool
    {
        $pengecualian = array_map('intval', (array) ($step->skip_for_role_ids ?? []));

        if ($pengecualian === [] || $requesterRoleIds === []) {
            return false;
        }

        return array_intersect($pengecualian, $requesterRoleIds) !== [];
    }

    /**
     * Akun aktif sebuah department, untuk step bertipe DEPARTMENT.
     *
     * Dicairkan menjadi baris USER di generatornya, sehingga seluruh pembaca
     * di hilir tidak perlu mengenal tipe ini sama sekali.
     */
    public function departmentApprovers(int $departmentId): Collection
    {
        if ($departmentId <= 0) {
            return collect();
        }

        return User::query()
            ->where('departemen_id', $departmentId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Pemegang sebuah role, untuk step berpasangan rekan setingkat.
     *
     * Step yang boleh disetujui rekan setingkat harus dicairkan menjadi USER
     * lebih dulu: baris ROLE tidak bisa mengeluarkan satu orang dari dalamnya
     * -- membuang barisnya berarti membuang seluruh pemegang role itu.
     */
    public function roleApprovers(int $roleId): Collection
    {
        if ($roleId <= 0) {
            return collect();
        }

        $dariPivot = DB::table('user_roles')->where('role_id', $roleId)->pluck('user_id');

        return User::query()
            ->where('is_active', true)
            ->where(function ($q) use ($roleId, $dariPivot): void {
                $q->where('id_role', $roleId)
                    ->orWhereIn('id', $dariPivot);
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Baris step, tanpa pemohon di dalamnya.
     *
     * Hanya dipanggil untuk step yang menyalakan allow_peer_approval, dan
     * hanya masuk akal pada baris yang sudah berupa USER konkret -- karena
     * itulah generatornya mencairkan ROLE dan DEPARTMENT lebih dulu.
     *
     * Hasil kosong BUKAN galat: artinya tidak ada rekan lain yang bisa
     * menyetujui, dan step itu dilewati. Pemohonnya tidak kehilangan apa pun
     * -- rantai di atasnya tetap berjalan.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function withoutRequester(array $rows, int $requesterId): array
    {
        if ($requesterId <= 0) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => !(
                strtoupper((string) ($row['approver_type'] ?? '')) === ApprovalFlowStep::APPROVER_TYPE_USER
                && (int) ($row['approver_id'] ?? 0) === $requesterId
            ),
        ));
    }

    /**
     * Step ini memakai aturan rekan setingkat.
     */
    public function peerApproval(ApprovalFlowStep $step): bool
    {
        return (bool) $step->allow_peer_approval;
    }

    /**
     * Rencana sebuah rantai step sebelum satu baris pun ditulis.
     *
     * Dipakai FPU dan Realisasi, yang menulis baris approval sambil berjalan.
     * Keduanya perlu tahu LEBIH DULU step mana yang gugur: step pertama yang
     * tersisa harus berstatus menunggu, dan kalau yang gugur baru ketahuan di
     * tengah jalan, dokumennya tersimpan dengan seluruh step berstatus
     * tertunda -- berdiri selamanya tanpa ada yang bisa menggerakkannya.
     *
     * Step biasa tidak muncul di kedua daftar: ia diproses seperti biasa.
     *
     * @param  Collection<int, ApprovalFlowStep>  $flowSteps
     * @param  array<int, int>  $requesterRoleIds
     * @return array{skipped: array<int, string>, users: array<int, Collection>}
     */
    public function planSteps(
        Collection $flowSteps,
        int $requesterId,
        array $requesterRoleIds,
        ?callable $resolver = null,
    ): array {
        $skipped = [];
        $users = [];

        /*
        | Siapa yang sebenarnya bisa menyetujui tiap step.
        |
        | Dicatat di sini -- saat orangnya memang sedang dihitung -- lalu
        | diperiksa di akhir, sesudah seluruh keputusan gugur diambil. Step
        | yang gugur tidak perlu punya orang.
        */
        $punyaOrang = [];

        foreach ($flowSteps as $step) {
            $stepId = (int) $step->id;

            if ($this->stepSkipped($step, $requesterRoleIds)) {
                $skipped[$stepId] = 'ROLE_PEMOHON';

                continue;
            }

            $rekan = $this->peerApproval($step);

            /*
            | Step bercakupan cabang sudah dicairkan menjadi orang oleh
            | generatornya; daftarnya dipinjam untuk mengambil keputusan, bukan
            | untuk menggantikan pencairannya.
            |
            | Keputusannya memang harus diambil di sini: status "menunggu"
            | melekat pada step pertama yang tersisa, dan step yang baru
            | ketahuan gugur di tengah penulisan akan meninggalkan dokumen
            | tanpa satu pun baris yang menunggu.
            */
            $dariCabang = $resolver !== null ? $resolver($step) : null;

            if ($dariCabang !== null) {
                /*
                | Scope cabang yang tidak menghasilkan seorang pun: pemegang
                | rolenya ada, tetapi tidak satu pun berada di cabang dokumen
                | ini.
                */
                $punyaOrang[$stepId] = $dariCabang->isNotEmpty();

                $keputusan = $this->putuskan($dariCabang, $rekan, $requesterId);

                if ($keputusan['skip'] !== null) {
                    $skipped[$stepId] = $keputusan['skip'];
                }
                elseif ($rekan) {
                    $users[$stepId] = $keputusan['users'];
                }

                continue;
            }

            $tipe = strtoupper(trim((string) $step->approver_type));

            $punyaOrang[$stepId] = $this->adaYangBisaMenyetujui($step);

            /*
            | DEPARTMENT selalu dicairkan -- tidak ada baris approval bertipe
            | department di hilir. ROLE pada step rekan setingkat juga, karena
            | baris ROLE tidak bisa mengeluarkan satu orang dari dalamnya.
            */
            $perluDicairkan = $rekan || $tipe === ApprovalFlowStep::APPROVER_TYPE_DEPARTMENT;

            if (!$perluDicairkan) {
                /*
                | Pemohon ada di step ini: stepnya DILEWATI seluruhnya.
                |
                | Bukan "dikeluarkan lalu rekannya menyetujui" -- rekan
                | setingkat bukan atasan, dan approval yang berhenti di
                | tingkat yang sama tidak benar-benar naik. Step yang memang
                | ingin di-counter-sign rekan selevel menyalakan
                | allow_peer_approval, dan jalannya berbeda (lihat di atas).
                */
                if ($this->requesterInStep($step, $requesterId, $requesterRoleIds)) {
                    $skipped[$stepId] = 'PEMOHON_SENDIRI';
                }

                continue;
            }

            $keputusan = $this->putuskan($this->concreteOrSelf($step), $rekan, $requesterId);

            if ($keputusan['skip'] !== null) {
                $skipped[$stepId] = $keputusan['skip'];

                continue;
            }

            if ($keputusan['users']->isNotEmpty()) {
                $users[$stepId] = $keputusan['users'];
            }
        }

        /*
        | Dua aturan, dijalankan berurutan dan berbeda jangkauannya:
        |
        | 1. Aturan pemohon menggugurkan SELURUH tingkat tempat ia berada --
        |    rekan sejawat bukan atasan.
        | 2. Aturan tingkat jabatan menggugurkan BARIS yang approvernya sama
        |    atau lebih rendah dalam satu garis department.
        */
        $skipped = $this->luaskanKeSeluruhStep($flowSteps, $skipped);

        $skipped = $this->aturanTingkat($flowSteps, $requesterId, $skipped);

        /*
        | Step yang bertahan tetapi tidak punya seorang pun yang bisa
        | menyetujuinya.
        |
        | Diperiksa paling akhir, sesudah seluruh keputusan gugur diambil:
        | step yang memang gugur tidak perlu punya orang.
        */
        /*
        | DINILAI PER TINGKAT, BUKAN PER BARIS.
        |
        | Satu tingkat bisa berisi beberapa baris dengan mode ANY -- misalnya
        | dua role SPV sekaligus. Cukup satu di antaranya punya pemegang, dan
        | tingkat itu bisa berjalan. Menilai baris demi baris akan menolak
        | pengajuan yang sebenarnya punya approver.
        */
        $kosong = [];
        $tingkat = [];

        foreach ($flowSteps as $step) {
            $stepId = (int) $step->id;

            if (isset($skipped[$stepId])) {
                continue;
            }

            $urutan = (int) $step->step_order;

            $tingkat[$urutan]['ada'] = ($tingkat[$urutan]['ada'] ?? false)
                || ($punyaOrang[$stepId] ?? true);

            $tingkat[$urutan]['sebutan'][] = $this->sebutanApprover($step);
        }

        foreach ($tingkat as $urutan => $isi) {
            if ($isi['ada']) {
                continue;
            }

            $kosong[$urutan] = implode(' / ', array_unique($isi['sebutan']));
        }

        return ['skipped' => $skipped, 'users' => $users, 'kosong' => $kosong];
    }

    /**
     * Step ini punya seseorang yang bisa menyetujuinya.
     *
     * Hanya untuk step bercakupan global -- yang bercakupan cabang dijawab
     * oleh pencairnya sendiri, karena jawabannya bergantung pada cabang
     * dokumen yang sedang dibuat.
     */
    private function adaYangBisaMenyetujui(ApprovalFlowStep $step): bool
    {
        $id = (int) $step->approver_id;

        return match (strtoupper(trim((string) $step->approver_type))) {
            ApprovalFlowStep::APPROVER_TYPE_ROLE => $this->roleApprovers($id)->isNotEmpty(),

            ApprovalFlowStep::APPROVER_TYPE_DEPARTMENT => $this->departmentApprovers($id)->isNotEmpty(),

            ApprovalFlowStep::APPROVER_TYPE_USER => User::query()
                ->whereKey($id)
                ->where('is_active', true)
                ->exists(),

            default => true,
        };
    }

    /**
     * Sebutan sebuah step untuk dibacakan kepada orang.
     *
     * Menyebut APA yang kosong, bukan sekadar nomor stepnya: yang membaca
     * penolakan perlu tahu role mana yang belum ada pemegangnya, supaya bisa
     * langsung membereskannya di master.
     */
    private function sebutanApprover(ApprovalFlowStep $step): string
    {
        $id = (int) $step->approver_id;

        $label = $step->label ? '"' . $step->label . '"' : 'Step ' . $step->step_order;

        $siapa = match (strtoupper(trim((string) $step->approver_type))) {
            ApprovalFlowStep::APPROVER_TYPE_ROLE => 'role "'
                . (DB::table('roles')->where('id', $id)->value('nama') ?? ('id ' . $id)) . '"',

            ApprovalFlowStep::APPROVER_TYPE_DEPARTMENT => 'department "'
                . (DB::table('departments')->where('id', $id)->value('nama') ?? ('id ' . $id)) . '"',

            ApprovalFlowStep::APPROVER_TYPE_USER => 'akun "'
                . (DB::table('users')->where('id', $id)->value('name') ?? ('id ' . $id)) . '"',

            default => 'approver id ' . $id,
        };

        return $label . ' (' . $siapa . ')';
    }

    /**
     * Menolak pengajuan yang salah satu stepnya tidak punya approver.
     *
     * Tanpa ini dokumennya TERSIMPAN dan berstatus menunggu, tetapi tidak ada
     * seorang pun yang bisa menggerakkannya -- berhenti diam-diam, tanpa
     * pesan, dan baru ketahuan ketika seseorang bertanya kenapa dokumennya
     * tidak maju-maju.
     *
     * Step bercakupan cabang sudah lama ditolak begini oleh generator FPU;
     * yang ini menyeragamkannya untuk step bercakupan global, yang selama ini
     * lolos karena barisnya memang bisa disimpan sebagai ROLE.
     *
     * @param  array{skipped: array<int, string>, users: array<int, Collection>, kosong: array<int, string>}  $rencana
     *
     * @throws ValidationException
     */
    public function assertApproverAda(array $rencana, string $modul): void
    {
        $kosong = $rencana['kosong'] ?? [];

        if ($kosong === []) {
            return;
        }

        throw ValidationException::withMessages([
            'approval_flow' => [
                sprintf(
                    'Approval flow %s ini memuat tingkat yang belum punya approver: %s.'
                    . ' Lengkapi pemegang role tersebut di master, atau ubah approver pada'
                    . ' matriks approval-nya.',
                    $modul,
                    implode(', ', array_unique(array_values($kosong))),
                ),
            ],
        ]);
    }

    /**
     * Aturan tingkat jabatan, dijalankan sesudah aturan pemohon.
     *
     * Bunyinya: approval tidak boleh turun ke bawah DALAM SATU GARIS
     * DEPARTMENT. Step yang approvernya bertingkat sama atau lebih rendah
     * daripada pemohon, dan berada di department yang sama, tidak ikut
     * digambar.
     *
     * Department berbeda tidak pernah digugurkan: di sana yang terjadi
     * pemeriksaan fungsi -- GA memeriksa perjalanan, HRGA memeriksa
     * kepegawaian -- bukan atasan menyetujui bawahan.
     *
     * BERLAKU PER BARIS, BUKAN PER TINGKAT.
     *
     * Satu tingkat bisa berisi dua role dari department berbeda. Menggugurkan
     * seluruh tingkat akan ikut membuang baris milik department lain yang
     * justru sah. Berbeda dari aturan pemohon, yang memang menggugurkan
     * seluruh tingkatnya karena rekan sejawat bukan atasan.
     *
     * Tidak berjalan sama sekali bila tingkat pemohon belum diatur.
     *
     * @param  Collection<int, ApprovalFlowStep>  $flowSteps
     * @param  array<int, string>  $skipped
     * @return array<int, string>
     */
    private function aturanTingkat(
        Collection $flowSteps,
        int $requesterId,
        array $skipped,
    ): array {
        $pemohon = $this->levelOf($requesterId);

        if ($pemohon === null) {
            return $skipped;
        }

        $departmentPemohon = (int) DB::table('users')
            ->where('id', $requesterId)
            ->value('departemen_id');

        if ($departmentPemohon <= 0) {
            return $skipped;
        }

        foreach ($flowSteps as $step) {
            $stepId = (int) $step->id;

            if (isset($skipped[$stepId]) || $this->peerApproval($step)) {
                continue;
            }

            $tingkat = $this->levelOfStep($step);

            if ($tingkat['level'] === null || $tingkat['department'] === null) {
                /*
                | Tingkat atau departmentnya tidak diketahui -- misalnya role
                | yang belum punya satu pun pemegang. Dibiarkan hidup: salah
                | membiarkan step berarti dokumen menunggu seseorang, salah
                | menggugurkannya berarti dokumen lolos tanpa diperiksa.
                */
                continue;
            }

            if (
                $tingkat['department'] === $departmentPemohon
                && $tingkat['level'] <= $pemohon
            ) {
                $skipped[$stepId] = 'TINGKAT_PEMOHON';
            }
        }

        return $skipped;
    }

    /**
     * Tingkat jabatan tertinggi yang dipegang seseorang.
     *
     * Tertinggi, bukan yang pertama: akun yang memegang dua role dinilai dari
     * jabatan tertingginya -- itulah kedudukannya di mata orang lain.
     */
    public function levelOf(int $userId): ?int
    {
        $roleIds = $this->roleIdsOf($userId);

        if ($roleIds === []) {
            return null;
        }

        $tertinggi = DB::table('roles')
            ->whereIn('id', $roleIds)
            ->whereNotNull('level')
            ->max('level');

        return $tertinggi !== null ? (int) $tertinggi : null;
    }

    /**
     * Tingkat dan department sebuah step.
     *
     * Department sebuah ROLE dibaca dari pemegangnya -- role memang tidak
     * menyimpan departmentnya sendiri. Bila pemegangnya belum ada, atau
     * tersebar di beberapa department, jawabannya null dan aturan tingkat
     * tidak berlaku untuk step itu.
     *
     * @return array{level: int|null, department: int|null}
     */
    private function levelOfStep(ApprovalFlowStep $step): array
    {
        $id = (int) $step->approver_id;

        $tipe = strtoupper(trim((string) $step->approver_type));

        if ($tipe === ApprovalFlowStep::APPROVER_TYPE_USER) {
            return [
                'level' => $this->levelOf($id),
                'department' => (int) DB::table('users')->where('id', $id)->value('departemen_id') ?: null,
            ];
        }

        if ($tipe !== ApprovalFlowStep::APPROVER_TYPE_ROLE) {
            /* DEPARTMENT: urusannya aturan rekan setingkat, bukan aturan ini. */
            return ['level' => null, 'department' => null];
        }

        $level = DB::table('roles')->where('id', $id)->value('level');

        $department = $this->roleApprovers($id)
            ->map(fn ($user): ?int => (int) DB::table('users')->where('id', $user->id)->value('departemen_id') ?: null)
            ->filter()
            ->unique()
            ->values();

        return [
            'level' => $level !== null ? (int) $level : null,
            'department' => $department->count() === 1 ? (int) $department->first() : null,
        ];
    }

    /**
     * Pemohon di sebuah step menggugurkan SELURUH step itu, bukan barisnya.
     *
     * Satu tingkat approval bisa berisi beberapa baris -- misalnya dua role
     * setingkat dengan mode ANY. Membuang baris pemohon saja menyisakan rekan
     * sejawatnya sebagai penyetuju, dan approval yang berhenti di tingkat yang
     * sama tidak benar-benar naik.
     *
     * Tidak berlaku pada step yang menyalakan allow_peer_approval -- di sana
     * rekan setingkat memang yang dimaksud, dan gugurnya satu baris tidak
     * boleh menyeret yang lain.
     *
     * @param  Collection<int, ApprovalFlowStep>  $flowSteps
     * @param  array<int, string>  $skipped
     * @return array<int, string>
     */
    private function luaskanKeSeluruhStep(Collection $flowSteps, array $skipped): array
    {
        $tingkatGugur = [];

        foreach ($flowSteps as $step) {
            if (($skipped[(int) $step->id] ?? null) === 'PEMOHON_SENDIRI') {
                $tingkatGugur[(int) $step->step_order] = true;
            }
        }

        if ($tingkatGugur === []) {
            return $skipped;
        }

        foreach ($flowSteps as $step) {
            if (!isset($tingkatGugur[(int) $step->step_order])) {
                continue;
            }

            if ($this->peerApproval($step)) {
                continue;
            }

            $skipped[(int) $step->id] = $skipped[(int) $step->id] ?? 'SETINGKAT_PEMOHON';
        }

        return $skipped;
    }

    /**
     * Nasib sebuah step yang approvernya sudah berupa orang.
     *
     * @param  Collection<int, mixed>  $daftar
     * @return array{skip: string|null, users: Collection}
     */
    private function putuskan(Collection $daftar, bool $rekan, int $requesterId): array
    {
        $adaPemohon = $daftar->contains(fn ($user): bool => (int) $user->id === $requesterId);

        if (!$rekan) {
            return [
                'skip' => $adaPemohon ? 'PEMOHON_SENDIRI' : null,
                'users' => $daftar,
            ];
        }

        $tersisa = $daftar->reject(fn ($user): bool => (int) $user->id === $requesterId)->values();

        return [
            /*
            | Tidak ada rekan lain: stepnya dilewati, bukan memblokir --
            | rantai berikutnya masih ada, dan itulah yang menyetujui.
            */
            'skip' => $tersisa->isEmpty() ? 'TANPA_REKAN' : null,
            'users' => $tersisa,
        ];
    }

    /**
     * Pemohon termasuk approver step ini.
     *
     * Dibaca dari bentuk stepnya, bukan dari baris yang sudah jadi: step
     * bertipe ROLE memang disimpan sebagai role, dan pemohon yang memegang
     * role itu adalah salah satu approvernya.
     *
     * @param  array<int, int>  $requesterRoleIds
     */
    public function requesterInStep(
        ApprovalFlowStep $step,
        int $requesterId,
        array $requesterRoleIds,
    ): bool {
        if ($requesterId <= 0) {
            return false;
        }

        $id = (int) $step->approver_id;

        return match (strtoupper(trim((string) $step->approver_type))) {
            ApprovalFlowStep::APPROVER_TYPE_USER => $id === $requesterId,
            ApprovalFlowStep::APPROVER_TYPE_ROLE => in_array($id, $requesterRoleIds, true),
            ApprovalFlowStep::APPROVER_TYPE_DEPARTMENT => $id === (int) DB::table('users')
                ->where('id', $requesterId)
                ->value('departemen_id'),
            default => false,
        };
    }

    /**
     * Approver konkret sebuah step, termasuk bila stepnya menunjuk satu user.
     */
    private function concreteOrSelf(ApprovalFlowStep $step): Collection
    {
        if (strtoupper(trim((string) $step->approver_type)) === ApprovalFlowStep::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey((int) $step->approver_id)
                ->get(['id', 'name']);
        }

        return $this->resolveConcreteApprovers($step);
    }

    /**
     * Daftar approver konkret sebuah step yang memakai aturan rekan setingkat.
     *
     * ROLE dan DEPARTMENT dicairkan; USER dibiarkan. Yang memanggil tinggal
     * membuang pemohonnya lewat withoutRequester().
     */
    public function resolveConcreteApprovers(ApprovalFlowStep $step): Collection
    {
        $tipe = strtoupper(trim((string) $step->approver_type));
        $id = (int) $step->approver_id;

        return match ($tipe) {
            ApprovalFlowStep::APPROVER_TYPE_DEPARTMENT => $this->departmentApprovers($id),
            ApprovalFlowStep::APPROVER_TYPE_ROLE => $this->roleApprovers($id),
            default => collect(),
        };
    }
}
