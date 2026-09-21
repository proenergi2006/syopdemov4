<?php

namespace App\Mail;

use App\Models\MasterVendor;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MasterVendorApprovalMail extends Mailable implements ShouldQueue
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

    public function __construct(
        public MasterVendor $vendor,
        public User $recipient,
        public string $type,
        public ?User $actor = null,
        public ?string $notes = null,
    ) {
        /*
        | Job baru dilepas setelah transaksi commit -- kalau tidak, worker bisa
        | membaca dokumen yang belum ada saat transaksinya masih terbuka.
        */
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->resolveSubject(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.master_vendor_approval',
            with: [
                'vendor' => $this->vendor,
                'recipient' => $this->recipient,
                'type' => $this->type,
                'actor' => $this->actor,
                'notes' => $this->notes,
                'url' => url('/master/vendor'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    private function resolveSubject(): string
    {
        return match ($this->type) {
            'approval_request'
            => 'Approval Master Vendor - '
                . $this->vendor->nama_vendor,

            'submitted'
            => 'Master Vendor Berhasil Disubmit - '
                . $this->vendor->nama_vendor,

            'approved'
            => 'Master Vendor Telah Disetujui - '
                . $this->vendor->nama_vendor,

            'final_approved'
            => 'Master Vendor Selesai Disetujui - '
                . $this->vendor->nama_vendor,

            'rejected'
            => 'Master Vendor Ditolak - '
                . $this->vendor->nama_vendor,

            default
            => 'Informasi Master Vendor - '
                . $this->vendor->nama_vendor,
        };
    }
}
