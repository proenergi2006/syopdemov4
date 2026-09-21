<?php

namespace App\Services\BusinessTrip;

use App\Mail\BusinessTripApprovalMail;
use App\Models\BusinessTrip;
use App\Models\BusinessTripApproval;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Email Perdin
|--------------------------------------------------------------------------
| Penerima diambil dari baris business_trip_approvals hasil generator, bukan
| dari approval_flow_steps -- sehingga penggantian approver apa pun otomatis
| ikut terbawa, sama seperti notifikasi in-app.
|
| Semua email dikirim lewat queue: kegagalan SMTP tidak boleh membuat ajukan
| atau approve ikut gagal.
|
| Seperti notifikasi, isinya sengaja hanya tiga peristiwa. Perdin tidak punya
| tahap penerimaan berkas maupun pembayaran.
|--------------------------------------------------------------------------
*/
class BusinessTripMailService
{
    public function __construct(
        private readonly BusinessTripNotificationService $notifications,
    ) {
    }

    /**
     * Email ke approver pada step yang sedang aktif.
     *
     * Dipanggil setelah Perdin diajukan dan setelah step berikutnya aktif.
     */
    public function sendApprovalRequest(BusinessTrip $trip): void
    {
        $currentStepOrder = BusinessTripApproval::query()
            ->where('business_trip_id', $trip->id)
            ->where('status', BusinessTripApproval::STATUS_WAITING)
            ->min('step_order');

        if ($currentStepOrder === null) {
            Log::warning('[Perdin Mail] Approval WAITING tidak ditemukan', [
                'business_trip_id' => $trip->id,
                'trip_number' => $trip->trip_number,
            ]);

            return;
        }

        $currentApprovals = BusinessTripApproval::query()
            ->where('business_trip_id', $trip->id)
            ->where('step_order', (int) $currentStepOrder)
            ->where('status', BusinessTripApproval::STATUS_WAITING)
            ->orderBy('id')
            ->get();

        if ($currentApprovals->isEmpty()) {
            return;
        }

        $approvers = $this->resolveRecipients($currentApprovals);

        if ($approvers->isEmpty()) {
            Log::warning('[Perdin Mail] Approver ber-email tidak ditemukan', [
                'business_trip_id' => $trip->id,
                'trip_number' => $trip->trip_number,
                'step_order' => (int) $currentStepOrder,
            ]);

            return;
        }

        $stepLabel = $currentApprovals->pluck('label')->filter()->first();

        /* Rundown ikut dikirim di badan email. */
        $trip->loadMissing('itineraries');

        foreach ($approvers as $approver) {
            $this->queue(
                $approver,
                new BusinessTripApprovalMail(
                    trip: $trip,
                    recipient: $approver,
                    mode: 'approval_request',
                    stepOrder: (int) $currentStepOrder,
                    stepLabel: $stepLabel,
                ),
                $trip,
                'approval request',
            );
        }
    }

    /**
     * Email ke pemohon ketika seluruh proses approval selesai.
     *
     * Tahap yang belum final tidak dikirimi email -- pemohon cukup diberi tahu
     * lewat notifikasi in-app agar kotak masuknya tidak penuh.
     */
    public function sendApprovalStep(
        BusinessTrip $trip,
        User $approver,
        bool $hasPendingApproval,
    ): void {
        if ($hasPendingApproval) {
            return;
        }

        $requester = $this->resolveRequester($trip);

        if (!$requester) {
            return;
        }

        $trip->loadMissing('itineraries');

        $this->queue(
            $requester,
            new BusinessTripApprovalMail(
                trip: $trip,
                recipient: $requester,
                mode: 'final_approved',
                actor: $approver,
            ),
            $trip,
            'final approved',
        );
    }

    public function sendRejected(
        BusinessTrip $trip,
        User $approver,
        ?string $notes = null,
    ): void {
        $requester = $this->resolveRequester($trip);

        if (!$requester) {
            return;
        }

        $trip->loadMissing('itineraries');

        $this->queue(
            $requester,
            new BusinessTripApprovalMail(
                trip: $trip,
                recipient: $requester,
                mode: 'rejected',
                actor: $approver,
                notes: $notes,
            ),
            $trip,
            'rejected',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian dalam
    |--------------------------------------------------------------------------
    */

    /**
     * Penerima yang benar-benar bisa dikirimi email.
     *
     * Disaring dua kali: sekali per akun, sekali per ALAMAT. Dua akun yang
     * berbagi satu alamat email hanya menerima satu surat -- kalau tidak, satu
     * orang menerima dua salinan yang sama persis dan mengira ada dua dokumen.
     */
    private function resolveRecipients(Collection $approvals): Collection
    {
        return $approvals
            ->flatMap(
                fn (BusinessTripApproval $approval): Collection => $this->notifications->resolveApproverUsers($approval),
            )
            ->filter(fn ($user) => $user instanceof User && filled($user->email))
            ->unique('id')
            ->unique(fn (User $user): string => strtolower(trim((string) $user->email)))
            ->values();
    }

    private function resolveRequester(BusinessTrip $trip): ?User
    {
        $requesterId = $trip->submitted_by ?? $trip->created_by ?? null;

        if (!$requesterId) {
            return null;
        }

        $requester = User::find($requesterId);

        return $requester && filled($requester->email) ? $requester : null;
    }

    private function queue(
        User $recipient,
        BusinessTripApprovalMail $mail,
        BusinessTrip $trip,
        string $context,
    ): void {
        try {
            Log::info('[Perdin Mail] Queue email ' . $context, [
                'business_trip_id' => $trip->id,
                'trip_number' => $trip->trip_number,
                'user_id' => $recipient->id,
                'to' => $recipient->email,
                'queue_connection' => config('queue.default'),
            ]);

            Mail::to($recipient->email)->queue($mail);
        } catch (\Throwable $e) {
            Log::error('[Perdin Mail] Gagal queue email ' . $context, [
                'business_trip_id' => $trip->id,
                'trip_number' => $trip->trip_number,
                'user_id' => $recipient->id,
                'to' => $recipient->email,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
