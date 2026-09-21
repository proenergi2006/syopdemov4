<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class PurchaseOrderApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

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

    public PurchaseOrder $po;
    public User $recipient;
    public ?User $actor;
    public string $mode;
    public bool $isFinalApproved;
    public ?string $notes;

    public function __construct(
        PurchaseOrder $po,
        User $recipient,
        string $mode = 'approval_request',
        ?User $actor = null,
        bool $isFinalApproved = false,
        ?string $notes = null
    ) {
        $this->po = $po;
        $this->recipient = $recipient;
        $this->actor = $actor;
        $this->mode = $mode;
        $this->isFinalApproved = $isFinalApproved;
        $this->notes = $notes;

        /*
        | Job baru dilepas setelah transaksi commit -- kalau tidak, worker bisa
        | membaca dokumen yang belum ada saat transaksinya masih terbuka.
        */
        $this->afterCommit();
        /*
        |--------------------------------------------------------------------------
        | Bahasa email mengikuti preferensi penerima
        |--------------------------------------------------------------------------
        | Wajib di-set di constructor (bukan di build()), karena Mailable::send()
        | membungkus build() di dalam withLocale($this->locale, ...) -- kalau
        | $this->locale baru di-set di dalam build(), sudah terlambat.
        | Ini juga penting untuk mail yang di-queue: locale ikut ter-serialize
        | bersama job, jadi tetap benar walau diproses oleh queue worker.
        |--------------------------------------------------------------------------
        */
        $this->locale($recipient->locale ?? 'id');
    }

    public function build()
    {
        $subjectKey = match ($this->mode) {
            'final_approved' => 'mail.po.subject.final_approved',
            'step_approved' => 'mail.po.subject.step_approved',
            'rejected' => 'mail.po.subject.rejected',
            default => 'mail.po.subject.default',
        };

        $subject = __($subjectKey, ['nomor_po' => $this->po->nomor_po]);

        return $this->subject($subject)
            ->view('emails.purchase_order_approval');
    }
}
