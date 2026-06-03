<?php

namespace App\Mail;

use App\Models\MasterVendor;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class MasterVendorApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public MasterVendor $vendor;
    public User $recipient;
    public string $mode;
    public ?User $actor;
    public ?string $notes;

    public function __construct(
        MasterVendor $vendor,
        User $recipient,
        string $mode = 'approval_request',
        ?User $actor = null,
        ?string $notes = null
    ) {
        $this->vendor = $vendor;
        $this->recipient = $recipient;
        $this->mode = $mode;
        $this->actor = $actor;
        $this->notes = $notes;
    }

    public function build()
    {
        $subject = match ($this->mode) {
            'approved' => 'Master Vendor Disetujui - ' . $this->vendor->nama_vendor,
            'rejected' => 'Master Vendor Ditolak - ' . $this->vendor->nama_vendor,
            default => 'Approval Master Vendor - ' . $this->vendor->nama_vendor,
        };

        return $this->subject($subject)
            ->view('emails.master_vendor_approval');
    }
}
