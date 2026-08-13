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
