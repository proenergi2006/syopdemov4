<?php

namespace App\Mail;

use App\Models\BusinessTrip;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;

/*
|--------------------------------------------------------------------------
| Email approval Perdin
|--------------------------------------------------------------------------
| ShouldQueue: pengiriman email tidak boleh menahan response ajukan/approve.
| Bila SMTP lambat atau mati, dokumennya tetap tersimpan dan email dicoba
| ulang oleh queue worker.
|
| Angka percobaan dan jedanya disamakan dengan modul lain -- lihat
| ClaimApprovalMail untuk alasan tiap angkanya.
|--------------------------------------------------------------------------
*/
class BusinessTripApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /*
    | Dokumen yang sudah dihapus tidak perlu dikirimi email; tanpa penanda ini
    | job-nya gagal tiga kali lalu mengendap di failed_jobs, membuat kegagalan
    | yang sungguhan tenggelam di antara yang tidak perlu ditangani siapa pun.
    */
    public bool $deleteWhenMissingModels = true;

    public string $approvalUrl;

    public function __construct(
        public BusinessTrip $trip,
        public User $recipient,
        public string $mode = 'approval_request',
        public ?User $actor = null,
        public ?string $notes = null,
        public ?int $stepOrder = null,
        public ?string $stepLabel = null,
    ) {
        /*
        | Job baru dilepas setelah transaksi commit -- kalau tidak, worker bisa
        | membaca dokumen yang belum ada saat transaksinya masih terbuka.
        */
        $this->afterCommit();

        /*
        | Bahasa email mengikuti preferensi penerima. Wajib di constructor,
        | bukan di build(), karena job dijalankan worker di luar konteks
        | request yang menentukan locale.
        */
        $this->locale($recipient->locale ?? 'id');

        $encryptedId = $this->trip->encrypted_id
            ?? Crypt::encryptString((string) $this->trip->id);

        $this->approvalUrl = url(
            '/business_trip/perdin?reference=' . urlencode($encryptedId),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->getSubject());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.business_trip_approval',
            with: [
                'trip' => $this->trip,
                'recipient' => $this->recipient,
                'mode' => $this->mode,
                'actor' => $this->actor,
                'notes' => $this->notes,
                'stepOrder' => $this->stepOrder,
                'stepLabel' => $this->stepLabel,
                'approvalUrl' => $this->approvalUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    private function getSubject(): string
    {
        $subjectKey = match ($this->mode) {
            'final_approved' => 'mail.business_trip.subject.final_approved',
            'rejected' => 'mail.business_trip.subject.rejected',
            default => 'mail.business_trip.subject.default',
        };

        return __($subjectKey, [
            'trip_number' => $this->trip->trip_number ?: '-',
        ]);
    }
}
