<?php

namespace App\Services\FundRequest\PettyCash;

use App\Models\Claim;
use App\Models\Notification;
use App\Models\PettyCash;
use App\Models\PettyCashApproval;
use App\Models\PettyCashCeiling;
use App\Models\PettyCashTopup;
use App\Models\PettyCashTopupApproval;
use App\Models\User;
use App\Services\Permission\PermissionRecipientService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Notifikasi aplikasi untuk Petty Cash dan Top Up
|--------------------------------------------------------------------------
| Penerima diambil dari baris approval hasil generator, bukan dari
| approval_flow_steps -- sehingga penggantian approver apa pun otomatis ikut
| terbawa, sama seperti keempat modul lain.
|
| SELURUH METHOD DI SINI TIDAK BOLEH MENGGAGALKAN APA PUN. Notifikasi adalah
| kabar tentang sesuatu yang sudah terjadi; kegagalan mengirimnya tidak boleh
| membatalkan uang yang sudah berpindah. Karena itu setiap method dibungkus
| try/catch yang hanya mencatat ke log.
|--------------------------------------------------------------------------
*/
class PettyCashNotificationService
{
    private const MODULE = 'petty_cash';
    private const MODULE_TOPUP = 'petty_cash_topup';

    private const URL = '/fund_request/petty_cash';
    private const URL_TOPUP = '/fund_request/petty_cash_topup';

    public function __construct(
        private readonly PermissionRecipientService $penerimaIzin,
        private readonly PettyCashLedgerService $ledger,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Petty Cash
    |--------------------------------------------------------------------------
    */

    /**
     * Giliran penyetuju: dikirim setelah diajukan, dan setiap kali tingkat
     * berikutnya menjadi aktif.
     */
    public function notifyApprovalRequest(Model $dokumen): void
    {
        $this->aman(function () use ($dokumen): void {
            $topup = $dokumen instanceof PettyCashTopup;

            foreach ($this->penyetujuAktif($dokumen) as $penyetuju) {
                $params = [
                    'number' => $topup ? $dokumen->topup_number : $dokumen->petty_cash_number,
                    'amount' => $this->rupiah((float) ($topup ? $dokumen->amount : $dokumen->total_amount)),
                    'branch' => $dokumen->branchData?->nama_cabang ?? '-',
                ];

                $this->catat(
                    $penyetuju->id,
                    $topup ? 'petty_cash_topup_approval' : 'petty_cash_approval',
                    'notification_messages.petty_cash.' . ($topup ? 'topup_approval_request' : 'approval_request'),
                    $params,
                    $dokumen,
                );
            }
        }, 'notifyApprovalRequest');
    }

    /**
     * Dikirim ke pemohon setelah satu tingkat disetujui.
     */
    public function notifyApprovalStep(Model $dokumen, User $penyetuju, bool $masihAdaTahap): void
    {
        $this->aman(function () use ($dokumen, $penyetuju, $masihAdaTahap): void {
            $pemohon = (int) ($dokumen->submitted_by ?? $dokumen->created_by ?? 0);

            if ($pemohon <= 0) {
                return;
            }

            $topup = $dokumen instanceof PettyCashTopup;

            $kunci = $masihAdaTahap
                ? 'notification_messages.petty_cash.step_approved'
                : ($topup
                    ? 'notification_messages.petty_cash.topup_approved'
                    : 'notification_messages.petty_cash.approved');

            $this->catat(
                $pemohon,
                $masihAdaTahap ? 'petty_cash_step_approved' : 'petty_cash_approved',
                $kunci,
                [
                    'number' => $topup ? $dokumen->topup_number : $dokumen->petty_cash_number,
                    'approver' => $penyetuju->name ?? '-',
                    'amount' => $this->rupiah((float) ($topup ? $dokumen->amount : $dokumen->total_amount)),
                ],
                $dokumen,
            );
        }, 'notifyApprovalStep');
    }

    public function notifyRejected(Model $dokumen, User $penolak, ?string $alasan): void
    {
        $this->aman(function () use ($dokumen, $penolak, $alasan): void {
            $pemohon = (int) ($dokumen->submitted_by ?? $dokumen->created_by ?? 0);

            if ($pemohon <= 0) {
                return;
            }

            $topup = $dokumen instanceof PettyCashTopup;

            $this->catat(
                $pemohon,
                'petty_cash_rejected',
                'notification_messages.petty_cash.rejected',
                [
                    'number' => $topup ? $dokumen->topup_number : $dokumen->petty_cash_number,
                    'approver' => $penolak->name ?? '-',
                    'reason' => $alasan ?: '-',
                ],
                $dokumen,
            );
        }, 'notifyRejected');
    }

    /**
     * Dicairkan: dua arah, dan arah kedua yang paling penting.
     *
     * Pemohon petty cash diberi tahu formulirnya selesai. Tetapi yang
     * benar-benar menunggu kabar adalah PARA PEMOHON CLAIM di dalamnya --
     * uang mereka baru saja dibayarkan, dan tanpa kabar ini mereka tidak punya
     * cara mengetahuinya selain membuka daftar claim sendiri.
     */
    public function notifyDisbursed(PettyCash $pettyCash, User $pencair): void
    {
        $this->aman(function () use ($pettyCash, $pencair): void {
            $pemohon = (int) ($pettyCash->submitted_by ?? $pettyCash->created_by ?? 0);

            if ($pemohon > 0 && $pemohon !== (int) $pencair->id) {
                $this->catat(
                    $pemohon,
                    'petty_cash_disbursed',
                    'notification_messages.petty_cash.disbursed',
                    [
                        'number' => $pettyCash->petty_cash_number,
                        'amount' => $this->rupiah((float) $pettyCash->total_amount),
                    ],
                    $pettyCash,
                );
            }

            $claims = Claim::query()
                ->whereIn(
                    'id',
                    DB::table('petty_cash_claims')
                        ->where('petty_cash_id', $pettyCash->id)
                        ->whereNull('released_at')
                        ->pluck('claim_id'),
                )
                ->get();

            foreach ($claims as $claim) {
                $penerima = (int) ($claim->submitted_by ?? $claim->created_by ?? 0);

                if ($penerima <= 0) {
                    continue;
                }

                /*
                | Notifikasinya menunjuk CLAIM-nya, bukan formulir petty
                | cash-nya: yang ingin dibuka pemohon adalah dokumennya
                | sendiri, bukan formulir kas kecil yang mungkin tidak boleh
                | ia lihat.
                */
                Notification::create([
                    'user_id' => $penerima,
                    'type' => 'claim_paid_petty_cash',
                    'title' => __('notification_messages.petty_cash.claim_paid.title'),
                    'title_key' => 'notification_messages.petty_cash.claim_paid.title',
                    'message' => __('notification_messages.petty_cash.claim_paid.message', [
                        'number' => $claim->claim_number,
                        'amount' => $this->rupiah((float) $claim->total_amount),
                    ]),
                    'message_key' => 'notification_messages.petty_cash.claim_paid.message',
                    'message_params' => [
                        'number' => $claim->claim_number,
                        'amount' => $this->rupiah((float) $claim->total_amount),
                    ],
                    'module' => 'claim',
                    'reference_type' => Claim::class,
                    'reference_id' => $claim->id,
                    'reference_public_id' => $claim->encrypted_id,
                    'url' => '/fund_request/claim',
                ]);
            }
        }, 'notifyDisbursed');
    }

    /*
    |--------------------------------------------------------------------------
    | Peringatan saldo menipis
    |--------------------------------------------------------------------------
    */

    /**
     * Memperingatkan pemegang kas kecil bahwa saldonya di bawah ambang.
     *
     * Dikirim ke pemegang permission petty_cash.create yang cabangnya cocok --
     * bukan ke role tertentu. Pemegang kas kecil ditentukan permission, jadi
     * penerima peringatannya pun harus ditentukan hal yang sama; kalau tidak,
     * cabang yang pemegangnya bukan ADH tidak akan pernah diperingatkan.
     *
     * TIDAK MENGIRIM DUA KALI DALAM SEHARI untuk plafon yang sama. Peringatan
     * yang muncul berulang setiap jam akan berhenti dibaca dalam dua hari, dan
     * sesudah itu ia tidak lagi memperingatkan apa pun.
     *
     * @return int banyaknya notifikasi yang dibuat
     */
    public function notifyLowBalance(PettyCashCeiling $plafon): int
    {
        $dibuat = 0;

        $this->aman(function () use ($plafon, &$dibuat): void {
            $saldo = $this->ledger->balance($plafon->id);
            $ambang = $plafon->warningThreshold();

            if ($saldo >= $ambang) {
                return;
            }

            $params = [
                'branch' => $plafon->branchData?->nama_cabang ?? '-',
                'balance' => $this->rupiah($saldo),
                'ceiling' => $this->rupiah((float) $plafon->ceiling_amount),
                'percentage' => (int) $plafon->warning_percentage,
                'topup' => $this->rupiah(max(0.0, (float) $plafon->ceiling_amount - $saldo)),
            ];

            foreach ($this->pemegangKasKecil((int) $plafon->branch_id) as $pemegang) {
                $sudah = Notification::query()
                    ->where('user_id', $pemegang->id)
                    ->where('type', 'petty_cash_low_balance')
                    ->where('reference_type', PettyCashCeiling::class)
                    ->where('reference_id', $plafon->id)
                    ->whereDate('created_at', now()->toDateString())
                    ->exists();

                if ($sudah) {
                    continue;
                }

                Notification::create([
                    'user_id' => $pemegang->id,
                    'type' => 'petty_cash_low_balance',
                    'title' => __('notification_messages.petty_cash.low_balance.title'),
                    'title_key' => 'notification_messages.petty_cash.low_balance.title',
                    'message' => __('notification_messages.petty_cash.low_balance.message', $params),
                    'message_key' => 'notification_messages.petty_cash.low_balance.message',
                    'message_params' => $params,
                    'module' => self::MODULE_TOPUP,
                    'reference_type' => PettyCashCeiling::class,
                    'reference_id' => $plafon->id,
                    'reference_public_id' => $plafon->encrypted_id,
                    'url' => self::URL_TOPUP,
                ]);

                $dibuat++;
            }
        }, 'notifyLowBalance');

        return $dibuat;
    }

    /**
     * Pemegang kas kecil sebuah cabang.
     *
     * @return Collection<int, User>
     */
    public function pemegangKasKecil(int $branchId): Collection
    {
        return $this->penerimaIzin
            ->usersWithPermission(PettyCashEligibilityService::PERMISSION_PEMEGANG)
            ->filter(fn (User $u): bool => $u->accessibleBranchIds()->contains($branchId))
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Dalam
    |--------------------------------------------------------------------------
    */

    /**
     * Penyetuju pada tingkat yang sedang aktif.
     *
     * @return Collection<int, User>
     */
    private function penyetujuAktif(Model $dokumen): Collection
    {
        $topup = $dokumen instanceof PettyCashTopup;

        /** @var class-string<Model> $kelas */
        $kelas = $topup ? PettyCashTopupApproval::class : PettyCashApproval::class;
        $fk = $topup ? 'petty_cash_topup_id' : 'petty_cash_id';

        $step = $kelas::query()
            ->where($fk, $dokumen->id)
            ->where('status', PettyCashApproval::STATUS_WAITING)
            ->min('step_order');

        if ($step === null) {
            return collect();
        }

        $baris = $kelas::query()
            ->where($fk, $dokumen->id)
            ->where('step_order', (int) $step)
            ->where('status', PettyCashApproval::STATUS_WAITING)
            ->get();

        /*
        | unique('id') mencegah notifikasi ganda bila satu akun tercatat
        | langsung sebagai USER sekaligus terjangkau lewat ROLE.
        */
        return $baris
            ->flatMap(function ($approval): Collection {
                if (strtoupper((string) $approval->approver_type) === PettyCashApproval::APPROVER_TYPE_USER) {
                    $user = User::query()->where('is_active', true)->find($approval->approver_id);

                    return $user ? collect([$user]) : collect();
                }

                $dariPivot = DB::table('user_roles')
                    ->where('role_id', $approval->approver_id)
                    ->pluck('user_id');

                return User::query()
                    ->where('is_active', true)
                    ->where(function ($q) use ($approval, $dariPivot): void {
                        $q->where('id_role', $approval->approver_id)
                            ->orWhereIn('id', $dariPivot);
                    })
                    ->get();
            })
            ->unique('id')
            ->values();
    }

    private function catat(
        int $userId,
        string $type,
        string $kunciPesan,
        array $params,
        Model $dokumen,
    ): void {
        $topup = $dokumen instanceof PettyCashTopup;

        Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => __($kunciPesan . '.title'),
            'title_key' => $kunciPesan . '.title',
            'message' => __($kunciPesan . '.message', $params),
            'message_key' => $kunciPesan . '.message',
            'message_params' => $params,
            'module' => $topup ? self::MODULE_TOPUP : self::MODULE,
            'reference_type' => $dokumen::class,
            'reference_id' => $dokumen->id,
            'reference_public_id' => $dokumen->encrypted_id,
            'url' => $topup ? self::URL_TOPUP : self::URL,
        ]);
    }

    private function rupiah(float $nominal): string
    {
        return angka($nominal, 0);
    }

    /**
     * Menjalankan pengiriman tanpa pernah menggagalkan pemanggilnya.
     */
    private function aman(callable $aksi, string $nama): void
    {
        try {
            $aksi();
        } catch (\Throwable $e) {
            Log::error('[Petty Cash Notification] ' . $nama . ' gagal', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
