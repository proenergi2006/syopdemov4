<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Models\Notification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\User;

class PurchaseOrderNotificationService
{
    public function notifyApprovalRequest(PurchaseOrder $po): void
    {
        $nextApproval = PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->where('status', 'PENDING')
            ->orderBy('step_order')
            ->first();

        if (!$nextApproval) {
            return;
        }

        if ($nextApproval->approver_type !== 'USER' || !$nextApproval->approver_id) {
            return;
        }

        Notification::create([
            'user_id' => $nextApproval->approver_id,
            'type' => 'purchase_order_approval',
            'title' => 'Approval Purchase Order',
            'message' => 'Purchase Order ' . $po->nomor_po . ' menunggu approval Anda.',
            'module' => 'purchase_order',
            'reference_type' => PurchaseOrder::class,
            'reference_id' => $po->id,
            'reference_public_id' => $po->encrypted_id,
            'url' => '/non_trade/purchase_order',
        ]);
    }

    public function notifyApprovalStep(
        PurchaseOrder $po,
        User $approver,
        PurchaseOrderApproval $approval,
        bool $hasPendingApproval
    ): void {
        if (!$po->requester_signed_by) {
            return;
        }

        Notification::create([
            'user_id' => $po->requester_signed_by,
            'type' => $hasPendingApproval
                ? 'purchase_order_approval_step_approved'
                : 'purchase_order_approved',
            'title' => $hasPendingApproval
                ? 'Tahap Approval PO Disetujui'
                : 'Purchase Order Disetujui',
            'message' => $hasPendingApproval
                ? 'Purchase Order ' . $po->nomor_po . ' telah disetujui oleh ' . ($approver->name ?? '-') . ' dan masih menunggu approval berikutnya.'
                : 'Purchase Order ' . $po->nomor_po . ' telah final disetujui oleh ' . ($approver->name ?? '-') . '.',
            'module' => 'purchase_order',
            'reference_type' => PurchaseOrder::class,
            'reference_id' => $po->id,
            'reference_public_id' => $po->encrypted_id,
            'url' => '/non_trade/purchase_order',
        ]);
    }

    public function notifyRejected(
        PurchaseOrder $po,
        User $rejecter
    ): void {
        if (!$po->requester_signed_by) {
            return;
        }

        Notification::create([
            'user_id' => $po->requester_signed_by,
            'type' => 'purchase_order_rejected',
            'title' => 'Purchase Order Ditolak',
            'message' => 'Purchase Order ' . $po->nomor_po . ' telah ditolak oleh ' . ($rejecter->name ?? '-') . '.',
            'module' => 'purchase_order',
            'reference_type' => PurchaseOrder::class,
            'reference_id' => $po->id,
            'reference_public_id' => $po->encrypted_id,
            'url' => '/non_trade/purchase_order',
        ]);
    }
}
