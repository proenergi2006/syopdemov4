<?php

namespace App\Services\FundRequest\Realization;

use App\Models\CashAdvanceRealization;
use App\Models\CashAdvanceRealizationApproval;
use App\Models\Notification;
use App\Models\User;
use App\Services\FundRequest\PaymentScheduleService;
use App\Services\Permission\PermissionRecipientService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Notifikasi aplikasi untuk Realisasi FPU
|--------------------------------------------------------------------------
| sumber penerima adalah baris cash_advance_realization_approvals hasil generator, bukan
| approval_flow_steps -- sehingga penggantian approver apa pun otomatis ikut
| terbawa ke notifikasi.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationNotificationService
{
    private const MODULE = 'cash_advance_realization';

    private const URL = '/fund_request/cash_advance_realization';

    /*
    | Penyelesaian selisih dipecah mengikuti arah uangnya, jadi penerimanya pun
    | berbeda: yang berwenang mencatat pengembalian belum tentu yang berwenang
    | mencatat pembayaran kekurangan.
    */
    private const PERMISSION_BY_DIFFERENCE = [
        CashAdvanceRealization::DIFFERENCE_RETURN => 'cash_advance_realization.return',
        CashAdvanceRealization::DIFFERENCE_REIMBURSE => 'cash_advance_realization.reimburse',
    ];

    /*
    | Penerimaan berdiri di luar approval flow: yang diberi tahu adalah
    | pemegang permission ini, bukan approver berikutnya.
    */
    private const PERMISSION_RECEIVE = 'cash_advance_realization.receive';

    /*
    | Memeriksa pengembalian yang dinyatakan pemohon. Izinnya sendiri, bukan
    | .receive: menerima berkas dan menyatakan uang sudah masuk ke rekening
    | perusahaan adalah dua penilaian berbeda.
    */
    private const PERMISSION_CONFIRM_RETURN = 'cash_advance_realization.confirm_return';

    public function __construct(
        private readonly PermissionRecipientService $permissionRecipients,
    ) {
    }

    /**
     * Dipanggil setelah Realisasi FPU disubmit atau setelah step berikutnya aktif.
     */
    public function notifyApprovalRequest(CashAdvanceRealization $realization): void
    {
        $currentStepOrder = CashAdvanceRealizationApproval::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->where('status', CashAdvanceRealizationApproval::STATUS_WAITING)
            ->min('step_order');

        if ($currentStepOrder === null) {
            Log::warning('[Realisasi FPU Notification] Approval WAITING tidak ditemukan', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
            ]);

            return;
        }

        $currentApprovals = CashAdvanceRealizationApproval::query()
            ->where('cash_advance_realization_id', $realization->id)
            ->where('step_order', (int) $currentStepOrder)
            ->where('status', CashAdvanceRealizationApproval::STATUS_WAITING)
            ->orderBy('id')
            ->get();

        if ($currentApprovals->isEmpty()) {
            return;
        }

        /*
        | unique('id') mencegah notifikasi ganda bila satu user dipilih
        | langsung sekaligus ter-resolve lewat role.
        */
        $approverUsers = $currentApprovals
            ->flatMap(
                fn(CashAdvanceRealizationApproval $approval): Collection =>
                $this->resolveApproverUsers($approval),
            )
            ->filter(fn($user) => $user instanceof User)
            ->unique('id')
            ->values();

        if ($approverUsers->isEmpty()) {
            Log::warning('[Realisasi FPU Notification] Approver user tidak ditemukan', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
                'step_order' => (int) $currentStepOrder,
                'approvals' => $currentApprovals
                    ->map(fn(CashAdvanceRealizationApproval $approval) => [
                        'approval_id' => $approval->id,
                        'approver_type' => $approval->approver_type,
                        'approver_id' => $approval->approver_id,
                        'label' => $approval->label,
                    ])
                    ->values()
                    ->all(),
            ]);

            return;
        }

        $stepLabel = $currentApprovals->pluck('label')->filter()->first();

        foreach ($approverUsers as $user) {
            // Cegah duplikasi notifikasi aktif untuk dokumen yang sama.
            $alreadyExists = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', 'cash_advance_realization_approval')
                ->where('reference_type', CashAdvanceRealization::class)
                ->where('reference_id', $realization->id)
                ->whereNull('read_at')
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            $messageParams = [
                'realization_number' => $realization->realization_number,
                'step_order' => (int) $currentStepOrder,
            ];

            if ($stepLabel) {
                $messageParams['step_label'] = $stepLabel;
            }

            $messageKey = $stepLabel
                ? 'notification_messages.cash_advance_realization.approval_request.message_with_label'
                : 'notification_messages.cash_advance_realization.approval_request.message_without_label';

            Notification::create([
                'user_id' => $user->id,
                'type' => 'cash_advance_realization_approval',
                'title' => __('notification_messages.cash_advance_realization.approval_request.title'),
                'title_key' => 'notification_messages.cash_advance_realization.approval_request.title',
                'message' => __($messageKey, $messageParams),
                'message_key' => $messageKey,
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => CashAdvanceRealization::class,
                'reference_id' => $realization->id,
                'reference_public_id' => $realization->encrypted_id,
                'url' => self::URL,
            ]);
        }
    }

    /**
     * Dikirim ke pemohon setelah satu tahap disetujui.
     */
    public function notifyApprovalStep(
        CashAdvanceRealization $realization,
        User $approver,
        CashAdvanceRealizationApproval $approval,
        bool $hasPendingApproval,
    ): void {
        $requesterId = $this->getRequesterUserId($realization);

        if (!$requesterId) {
            return;
        }

        $translationGroup = $hasPendingApproval
            ? 'approval_step_pending'
            : 'approval_step_final';

        $messageParams = [
            'realization_number' => $realization->realization_number,
            'approver_name' => $approver->name ?? '-',
            'step_order' => $approval->step_order,
        ];

        $titleKey = "notification_messages.cash_advance_realization.{$translationGroup}.title";
        $messageKey = "notification_messages.cash_advance_realization.{$translationGroup}.message";

        Notification::create([
            'user_id' => $requesterId,

            'type' => $hasPendingApproval
                ? 'cash_advance_realization_approval_step_approved'
                : 'cash_advance_realization_approved',

            'title' => __($titleKey),
            'title_key' => $titleKey,

            'message' => __($messageKey, $messageParams),
            'message_key' => $messageKey,
            'message_params' => $messageParams,

            'module' => self::MODULE,
            'reference_type' => CashAdvanceRealization::class,
            'reference_id' => $realization->id,
            'reference_public_id' => $realization->encrypted_id,
            'url' => self::URL,
        ]);
    }

    public function notifyRejected(
        CashAdvanceRealization $realization,
        User $rejecter,
    ): void {
        $requesterId = $this->getRequesterUserId($realization);

        if (!$requesterId) {
            return;
        }

        $messageParams = [
            'realization_number' => $realization->realization_number,
            'rejecter_name' => $rejecter->name ?? '-',
        ];

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'cash_advance_realization_rejected',
            'title' => __('notification_messages.cash_advance_realization.rejected.title'),
            'title_key' => 'notification_messages.cash_advance_realization.rejected.title',
            'message' => __('notification_messages.cash_advance_realization.rejected.message', $messageParams),
            'message_key' => 'notification_messages.cash_advance_realization.rejected.message',
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => CashAdvanceRealization::class,
            'reference_id' => $realization->id,
            'reference_public_id' => $realization->encrypted_id,
            'url' => self::URL,
        ]);
    }

    /**
     * Dikirim ke pemohon setelah Finance menyelesaikan selisih.
     */
    /**
     * Dikirim ke pemegang permission penyelesaian setelah Realisasi disetujui.
     *
     * Berada di luar approval flow, jadi penerimanya bukan baris approval
     * melainkan pemegang permission aksinya. Realisasi tanpa selisih tidak
     * memberitahu siapa pun -- memang tidak ada yang perlu dikerjakan.
     */
    /**
     * Dikirim ke pemegang permission penerimaan setelah approval tuntas.
     */
    public function notifyReceiptRequest(CashAdvanceRealization $realization): void
    {
        $recipients = $this->permissionRecipients
            ->usersWithPermission(self::PERMISSION_RECEIVE);

        if ($recipients->isEmpty()) {
            Log::warning('[Realisasi FPU Notification] Pemegang permission penerimaan tidak ditemukan', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
                'permission' => self::PERMISSION_RECEIVE,
            ]);

            return;
        }

        $messageParams = [
            'realization_number' => $realization->realization_number,
            'total_amount' => angka((float) $realization->total_realization_amount, 0),
        ];

        foreach ($recipients as $user) {
            /* Cegah tumpukan notifikasi untuk dokumen yang sama. */
            $alreadyExists = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', 'cash_advance_realization_receipt_request')
                ->where('reference_type', CashAdvanceRealization::class)
                ->where('reference_id', $realization->id)
                ->whereNull('read_at')
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            Notification::create([
                'user_id' => $user->id,
                'type' => 'cash_advance_realization_receipt_request',
                'title' => __('notification_messages.cash_advance_realization.receipt_request.title'),
                'title_key' => 'notification_messages.cash_advance_realization.receipt_request.title',
                'message' => __('notification_messages.cash_advance_realization.receipt_request.message', $messageParams),
                'message_key' => 'notification_messages.cash_advance_realization.receipt_request.message',
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => CashAdvanceRealization::class,
                'reference_id' => $realization->id,
                'reference_public_id' => $realization->encrypted_id,
                'url' => self::URL,
            ]);
        }
    }

    /**
     * Dikirim ke pemohon setelah PIC menandai dokumennya diterima.
     */
    /**
     * Dikirim ke pemohon ketika Finance menarik kembali penerimaan berkasnya.
     *
     * Tanggal pembayaran yang batal disebut terang-terangan: itulah yang
     * sudah ia catat dari email sebelumnya, dan tanpa menyebutnya kabar ini
     * hanya memberi tahu bahwa "ada sesuatu yang berubah".
     *
     * Dokumennya sendiri tidak hilang -- ia kembali ke status disetujui dan
     * masih bisa diterima ulang. Itu pun perlu dikatakan, kalau tidak pemohon
     * akan mengira pengajuannya batal.
     */
    public function notifyReceiptReverted(
        CashAdvanceRealization $realization,
        User $actor,
        ?string $scheduledDate,
        string $reason,
    ): void {
        $requesterId = $this->getRequesterUserId($realization);

        if (!$requesterId) {
            return;
        }

        /*
        | Dokumen lama bisa saja tidak pernah punya tanggal pembayaran.
        | Kalimatnya dipilih menurut itu, bukan diisi tanda hubung -- "tanggal
        | pembayaran - dibatalkan" tidak berarti apa-apa bagi pembacanya.
        */
        $bagian = $scheduledDate ? 'with_date' : 'without_date';

        $messageParams = [
            'realization_number' => $realization->realization_number,
            'actor_name' => $actor->name ?? '-',
            'scheduled_date' => $scheduledDate ?? '-',
            'reason' => trim($reason),
        ];

        $titleKey = 'notification_messages.cash_advance_realization.receipt_reverted.title';
        $messageKey = 'notification_messages.cash_advance_realization.receipt_reverted.' . $bagian;

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'cash_advance_realization_receipt_reverted',
            'title' => __($titleKey),
            'title_key' => $titleKey,
            'message' => __($messageKey, $messageParams),
            'message_key' => $messageKey,
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => CashAdvanceRealization::class,
            'reference_id' => $realization->id,
            'reference_public_id' => $realization->encrypted_id,
            'url' => self::URL,
        ]);
    }

    public function notifyReceived(
        CashAdvanceRealization $realization,
        User $receiver,
    ): void {
        $requesterId = $this->getRequesterUserId($realization);

        if (!$requesterId) {
            return;
        }

        $messageParams = [
            'realization_number' => $realization->realization_number,
            'receiver_name' => $receiver->name ?? '-',
            'payment_date' => PaymentScheduleService::formatTanggal($realization->scheduled_payment_date),
        ];

        /*
        | Dokumen tanpa jadwal tetap memakai kalimat lama. Menyebut tanggal
        | pembayaran pada dokumen yang memang tidak dibayarkan hanya
        | membingungkan yang membacanya.
        */
        $kunci = $realization->scheduled_payment_date
            ? 'notification_messages.cash_advance_realization.received.message_scheduled'
            : 'notification_messages.cash_advance_realization.received.message';

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'cash_advance_realization_received',
            'title' => __('notification_messages.cash_advance_realization.received.title'),
            'title_key' => 'notification_messages.cash_advance_realization.received.title',
            'message' => __($kunci, $messageParams),
            'message_key' => $kunci,
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => CashAdvanceRealization::class,
            'reference_id' => $realization->id,
            'reference_public_id' => $realization->encrypted_id,
            'url' => self::URL,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Selisihnya menunggu diselesaikan
    |--------------------------------------------------------------------------
    | DUA ARAH, DUA PENERIMA, DAN ITU BUKAN KEBETULAN.
    |
    | Kekurangan dibayarkan perusahaan, jadi yang perlu dipanggil adalah
    | pemegang izin pembayarannya -- pemohon tidak mengerjakan apa pun di sana
    | selain menunggu.
    |
    | Sisa dana dikembalikan PEMOHON. Yang harus bertindak adalah orang yang
    | memegang uangnya, dan hanya ada satu orang seperti itu untuk tiap
    | dokumen. Dahulu cabang ini pun dikirim ke pemegang izin, sehingga 38
    | akun dari 15 role diberi tahu tentang uang yang bukan urusan mereka,
    | sementara satu-satunya orang yang berutang tidak diberi tahu sama sekali.
    |--------------------------------------------------------------------------
    */
    public function notifyDifferenceSettlementRequest(
        CashAdvanceRealization $realization,
    ): void {
        $differenceType = strtoupper(trim((string) $realization->difference_type));

        if ($differenceType === CashAdvanceRealization::DIFFERENCE_RETURN) {
            $this->notifyReturnRequestToRequester($realization);

            return;
        }

        $permission = self::PERMISSION_BY_DIFFERENCE[$differenceType] ?? null;

        if (!$permission) {
            return;
        }

        $recipients = $this->permissionRecipients->usersWithPermission($permission);

        if ($recipients->isEmpty()) {
            Log::warning('[Realisasi FPU Notification] Pemegang permission penyelesaian tidak ditemukan', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
                'difference_type' => $differenceType,
                'permission' => $permission,
            ]);

            return;
        }

        $group = $differenceType === CashAdvanceRealization::DIFFERENCE_RETURN
            ? 'return_request'
            : 'reimburse_request';

        $type = "cash_advance_realization_{$group}";

        $titleKey = "notification_messages.cash_advance_realization.{$group}.title";
        $messageKey = "notification_messages.cash_advance_realization.{$group}.message";

        $messageParams = [
            'realization_number' => $realization->realization_number,
            'difference_amount' => angka(abs((float) $realization->difference_amount), 0),
        ];

        foreach ($recipients as $user) {
            $alreadyExists = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', $type)
                ->where('reference_type', CashAdvanceRealization::class)
                ->where('reference_id', $realization->id)
                ->whereNull('read_at')
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => __($titleKey),
                'title_key' => $titleKey,
                'message' => __($messageKey, $messageParams),
                'message_key' => $messageKey,
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => CashAdvanceRealization::class,
                'reference_id' => $realization->id,
                'reference_public_id' => $realization->encrypted_id,
                'url' => self::URL,
            ]);
        }
    }

    /** Pemohon diberi tahu sisa dananya perlu dikembalikan ke Finance. */
    private function notifyReturnRequestToRequester(
        CashAdvanceRealization $realization,
    ): void {
        $requesterId = $this->getRequesterUserId($realization);

        if (!$requesterId) {
            Log::warning('[Realisasi FPU Notification] Pemohon tidak ditemukan untuk pengembalian', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
            ]);

            return;
        }

        $this->buatSekali(
            $requesterId,
            'cash_advance_realization_return_request',
            $realization,
            'return_request',
            [
                'realization_number' => $realization->realization_number,
                'difference_amount' => angka(abs((float) $realization->difference_amount), 0),
            ],
        );
    }

    /**
     * Finance diberi tahu pemohon menyatakan sudah mengembalikan dananya.
     *
     * Penerimanya pemegang izin PENERIMAAN, bukan izin pengembalian: meja
     * yang menerima berkasnya pula yang menghitung uangnya masuk.
     */
    public function notifyReturnDeclared(
        CashAdvanceRealization $realization,
        User $declarer,
    ): void {
        $recipients = $this->permissionRecipients
            ->usersWithPermission(self::PERMISSION_CONFIRM_RETURN);

        if ($recipients->isEmpty()) {
            Log::warning('[Realisasi FPU Notification] Pemegang izin konfirmasi pengembalian tidak ditemukan', [
                'cash_advance_realization_id' => $realization->id,
                'realization_number' => $realization->realization_number,
            ]);

            return;
        }

        $params = [
            'realization_number' => $realization->realization_number,
            'difference_amount' => angka(abs((float) $realization->difference_amount), 0),
            'requester_name' => $declarer->name ?? '-',
        ];

        foreach ($recipients as $user) {
            $this->buatSekali(
                (int) $user->id,
                'cash_advance_realization_return_declared',
                $realization,
                'return_declared',
                $params,
            );
        }
    }

    /**
     * Pemohon diberi tahu realisasinya yang nilainya pas sudah disetujui.
     *
     * Terpisah dari notifySettled, dan kalimatnya pun berbeda. "Selisih
     * Realisasi telah diselesaikan" pada dokumen yang tidak punya selisih
     * menyuruh pembacanya mencari perpindahan uang yang tidak pernah ada.
     */
    public function notifyFinalized(
        CashAdvanceRealization $realization,
        User $approver,
    ): void {
        $requesterId = $this->getRequesterUserId($realization);

        if (!$requesterId) {
            return;
        }

        $this->buatSekali(
            $requesterId,
            'cash_advance_realization_finalized',
            $realization,
            'finalized',
            [
                'realization_number' => $realization->realization_number,
                'actor_name' => $approver->name ?? '-',
            ],
        );
    }

    /**
     * Pemohon diberi tahu Finance sudah menyetujui realisasinya.
     *
     | Satu aksi, tiga kalimat. Yang disetujui memang satu pekerjaan yang
     | sama, tetapi yang perlu diketahui pemohon berbeda per cabang:
     |
     |   Nilainya PAS    : selesai, tidak ada uang yang berpindah.
     |   Realisasi LEBIH : uang yang ia kembalikan dinyatakan sudah masuk.
     |   Realisasi KURANG: belum selesai -- kekurangannya baru akan dibayar.
     |
     | Menyeragamkannya menjadi "disetujui" saja akan membuat pemohon cabang
     | kekurangan mengira uangnya sudah dikirim.
     */
    public function notifyFinanceApproved(
        CashAdvanceRealization $realization,
        User $approver,
    ): void {
        $requesterId = $this->getRequesterUserId($realization);

        if (!$requesterId) {
            return;
        }

        $grup = match (strtoupper(trim((string) $realization->difference_type))) {
            CashAdvanceRealization::DIFFERENCE_REIMBURSE => 'finance_approved_reimburse',
            CashAdvanceRealization::DIFFERENCE_RETURN => 'finance_approved_return',
            default => 'finalized',
        };

        $this->buatSekali(
            $requesterId,
            'cash_advance_realization_' . $grup,
            $realization,
            $grup,
            [
                'realization_number' => $realization->realization_number,
                'actor_name' => $approver->name ?? '-',
                'difference_amount' => angka(abs((float) $realization->difference_amount), 0),
            ],
        );
    }

    /** Pemohon diberi tahu pernyataannya ditolak Finance. */
    public function notifyReturnRejected(
        CashAdvanceRealization $realization,
        User $actor,
        ?string $reason = null,
    ): void {
        $requesterId = $this->getRequesterUserId($realization);

        if (!$requesterId) {
            return;
        }

        $this->buatSekali(
            $requesterId,
            'cash_advance_realization_return_rejected',
            $realization,
            'return_rejected',
            [
                'realization_number' => $realization->realization_number,
                'actor_name' => $actor->name ?? '-',
                'reason' => $reason ?: '-',
            ],

            /*
            | Penolakan TIDAK boleh dilewati walau ada yang belum dibaca.
            | Dokumen yang sama bisa ditolak lebih dari sekali dengan alasan
            | berbeda, dan yang kedua justru alasan yang masih berlaku.
            */
            sekaliSaja: false,
        );
    }

    /**
     * Membuat satu notifikasi, melewatinya bila yang sama masih belum dibaca.
     *
     * Penjagaan "belum dibaca" itu yang mencegah daftar notifikasi dipenuhi
     * kalimat yang sama ketika satu aksi dijalankan berulang -- terima,
     * batalkan terima, terima lagi.
     *
     * @param  array<string, mixed>  $params
     */
    private function buatSekali(
        int $userId,
        string $type,
        CashAdvanceRealization $realization,
        string $group,
        array $params,
        bool $sekaliSaja = true,
    ): void {
        if ($sekaliSaja) {
            $sudahAda = Notification::query()
                ->where('user_id', $userId)
                ->where('type', $type)
                ->where('reference_type', CashAdvanceRealization::class)
                ->where('reference_id', $realization->id)
                ->whereNull('read_at')
                ->exists();

            if ($sudahAda) {
                return;
            }
        }

        $titleKey = "notification_messages.cash_advance_realization.{$group}.title";
        $messageKey = "notification_messages.cash_advance_realization.{$group}.message";

        Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => __($titleKey),
            'title_key' => $titleKey,
            'message' => __($messageKey, $params),
            'message_key' => $messageKey,
            'message_params' => $params,
            'module' => self::MODULE,
            'reference_type' => CashAdvanceRealization::class,
            'reference_id' => $realization->id,
            'reference_public_id' => $realization->encrypted_id,
            'url' => self::URL,
        ]);
    }

    public function notifySettled(
        CashAdvanceRealization $realization,
        User $settler,
    ): void {
        $requesterId = $this->getRequesterUserId($realization);

        if (!$requesterId) {
            return;
        }

        $messageParams = [
            'realization_number' => $realization->realization_number,
            'settler_name' => $settler->name ?? '-',
        ];

        Notification::create([
            'user_id' => $requesterId,
            'type' => 'cash_advance_realization_settled',
            'title' => __('notification_messages.cash_advance_realization.settled.title'),
            'title_key' => 'notification_messages.cash_advance_realization.settled.title',
            'message' => __('notification_messages.cash_advance_realization.settled.message', $messageParams),
            'message_key' => 'notification_messages.cash_advance_realization.settled.message',
            'message_params' => $messageParams,
            'module' => self::MODULE,
            'reference_type' => CashAdvanceRealization::class,
            'reference_id' => $realization->id,
            'reference_public_id' => $realization->encrypted_id,
            'url' => self::URL,
        ]);
    }

    public function resolveApproverUsers(
        CashAdvanceRealizationApproval $approval,
    ): Collection {
        $approverType = strtoupper(trim((string) $approval->approver_type));

        if (!$approval->approver_id) {
            return collect();
        }

        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_USER) {
            return User::query()
                ->whereKey($approval->approver_id)
                ->get();
        }

        if ($approverType === CashAdvanceRealizationApproval::APPROVER_TYPE_ROLE) {
            return $this->resolveUsersByRoleId((int) $approval->approver_id);
        }

        return collect();
    }

    private function resolveUsersByRoleId(int $roleId): Collection
    {
        if ($roleId <= 0) {
            return collect();
        }

        $userIds = collect();

        if (Schema::hasTable('user_roles')) {
            $userIds = $userIds->merge(
                DB::table('user_roles')
                    ->where('role_id', $roleId)
                    ->pluck('user_id'),
            );
        }

        // Kompatibilitas struktur lama.
        if (Schema::hasTable('role_user')) {
            $userIds = $userIds->merge(
                DB::table('role_user')
                    ->where('role_id', $roleId)
                    ->pluck('user_id'),
            );
        }

        if (Schema::hasColumn('users', 'role_id')) {
            $userIds = $userIds->merge(
                User::query()
                    ->where('role_id', $roleId)
                    ->pluck('id'),
            );
        }

        $userIds = $userIds
            ->filter(fn($id) => $id !== null && (int) $id > 0)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        $query = User::query()->whereIn('id', $userIds);

        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Pemohon adalah penekan tombol Submit; created_by dipakai sebagai cadangan.
     */
    private function getRequesterUserId(CashAdvanceRealization $realization): ?int
    {
        $requesterId = $realization->submitted_by
            ?? $realization->created_by
            ?? null;

        return $requesterId ? (int) $requesterId : null;
    }
}
