<?php

namespace App\Mail;

use App\Models\CashAdvance;
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
| Email approval FPU
|--------------------------------------------------------------------------
| ShouldQueue: pengiriman email tidak boleh menahan response submit/approve.
| Bila SMTP lambat atau mati, dokumennya tetap tersimpan dan email dicoba
| ulang oleh queue worker.
|--------------------------------------------------------------------------
*/
class CashAdvanceApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /*
    |--------------------------------------------------------------------------
    | Percobaan ulang
    |--------------------------------------------------------------------------
    | Kegagalan pengiriman email hampir selalu bersifat sesaat: SMTP timeout,
    | jaringan putus sebentar, atau server email sedang membatasi laju. Tanpa
    | percobaan ulang, gangguan sedetik pun membuat approver tidak pernah
    | menerima pemberitahuannya.
    |
    | Bawaan queue:work hanya SATU percobaan, jadi angka ini harus dinyatakan
    | sendiri. Ditaruh sebagai properti kelas, bukan argumen baris perintah,
    | supaya tidak bergantung pada cara worker kebetulan dijalankan.
    |--------------------------------------------------------------------------
    */
    public int $tries = 3;

    /*
    | Jedanya menaik supaya percobaan ulang tidak menambah beban server email
    | yang justru sedang bermasalah: satu menit, lalu lima, lalu lima belas.
    | Total rentang percobaannya sekitar dua puluh satu menit -- cukup panjang
    | untuk melewati gangguan sesaat, cukup pendek untuk approval yang memang
    | ditunggu orang.
    */
    public array $backoff = [60, 300, 900];

    /*
    | Dokumen yang sudah dihapus tidak perlu dikirimi email.
    |
    | Email ini hanya menyimpan ID dokumennya, lalu mengambil ulang isinya saat
    | hendak dikirim. Bila dokumennya sudah tidak ada, tanpa penanda ini
    | job-nya akan gagal tiga kali lalu mengendap di failed_jobs -- membuat
    | kegagalan yang sungguhan tenggelam di antara yang tidak perlu ditangani
    | siapa pun.
    */
    public bool $deleteWhenMissingModels = true;

    public string $approvalUrl;

    public function __construct(
        public CashAdvance $fpu,
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
        | Bahasa email mengikuti preferensi penerima. Wajib di-set di
        | constructor, bukan di build(), karena job dijalankan worker di luar
        | konteks request yang menentukan locale.
        */
        $this->locale($recipient->locale ?? 'id');

        $encryptedId = $this->fpu->encrypted_id
            ?? Crypt::encryptString((string) $this->fpu->id);

        $this->approvalUrl = url(
            '/fund_request/cash_advance?reference=' . urlencode($encryptedId),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->getSubject());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cash_advance_approval',
            with: [
                'fpu' => $this->fpu,
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
            'final_approved' => 'mail.fpu.subject.final_approved',
            'rejected' => 'mail.fpu.subject.rejected',
            'disbursed' => 'mail.fpu.subject.disbursed',
            'received' => 'mail.fpu.subject.received',
            'receipt_request' => 'mail.fpu.subject.receipt_request',
            'disbursement_request' => 'mail.fpu.subject.disbursement_request',
            default => 'mail.fpu.subject.default',
        };

        return __($subjectKey, [
            'advance_number' => $this->fpu->advance_number ?: '-',
        ]);
    }
}
