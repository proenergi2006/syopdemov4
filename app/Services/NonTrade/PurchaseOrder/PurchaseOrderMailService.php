<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Mail\PurchaseOrderApprovalMail;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PurchaseOrderMailService
{
    public function sendApprovalRequest(PurchaseOrder $po): void
    {
        $nextApproval = PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->where('status', 'PENDING')
            ->orderBy('step_order')
            ->first();

        if (!$nextApproval) {
            return;
        }

        if (
            $nextApproval->approver_type !== 'USER'
            || !$nextApproval->approver_id
        ) {
            return;
        }

        $approver = User::find($nextApproval->approver_id);

        if (!$approver || !$approver->email) {
            return;
        }

        Mail::to($approver->email)
            ->queue(new PurchaseOrderApprovalMail(
                po: $po,
                recipient: $approver,
                mode: 'approval_request',
            ));
    }

    public function sendApprovalStep(
        PurchaseOrder $po,
        User $approver,
        bool $hasPendingApproval
    ): void {
        if (!$po->requester_signed_by) {
            return;
        }

        $requester = User::find($po->requester_signed_by);

        if (!$requester || !$requester->email) {
            return;
        }

        Mail::to($requester->email)
            ->queue(new PurchaseOrderApprovalMail(
                po: $po,
                recipient: $requester,
                mode: $hasPendingApproval
                    ? 'step_approved'
                    : 'final_approved',
                actor: $approver,
                isFinalApproved: !$hasPendingApproval,
            ));
    }

    public function sendRejected(
        PurchaseOrder $po,
        User $rejecter,
        ?string $notes = null
    ): void {
        if (!$po->requester_signed_by) {
            return;
        }

        $requester = User::find($po->requester_signed_by);

        if (!$requester || !$requester->email) {
            return;
        }

        Mail::to($requester->email)
            ->queue(new PurchaseOrderApprovalMail(
                po: $po,
                recipient: $requester,
                mode: 'rejected',
                actor: $rejecter,
                notes: $notes,
            ));
    }
}
