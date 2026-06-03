<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseOrderApprovalService
{
    public function getCurrentPendingApproval(PurchaseOrder $po): ?PurchaseOrderApproval
    {
        return PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->where('status', 'PENDING')
            ->orderBy('step_order')
            ->lockForUpdate()
            ->first();
    }

    public function userCanApprove(PurchaseOrderApproval $approval, User $user): bool
    {
        if ($approval->approver_type === 'USER') {
            return (int) $approval->approver_id === (int) $user->id;
        }

        return false;
    }

    public function approveCurrentStep(
        PurchaseOrderApproval $approval,
        User $user,
        ?string $notes = null
    ): void {
        $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

        $approval->update([
            'status' => 'APPROVED',
            'approver_name_snapshot' => $user->name,
            'signature_path' => $user->signature_path,
            'signed_at' => now(),
            'approved_at' => now(),
            'notes' => $clean($notes),
        ]);
    }

    public function rejectCurrentStep(
        PurchaseOrderApproval $approval,
        User $user,
        ?string $notes = null
    ): void {
        $clean = fn($v) => htmlspecialchars(strip_tags(trim((string) $v)), ENT_QUOTES, 'UTF-8');

        $approval->update([
            'status' => 'REJECTED',
            'approver_name_snapshot' => $user->name,
            'signed_at' => now(),
            'approved_at' => null,
            'rejected_at' => now(),
            'notes' => $clean($notes),
        ]);
    }

    public function cancelRemainingPendingApprovals(PurchaseOrder $po): void
    {
        PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->where('status', 'PENDING')
            ->update([
                'status' => 'CANCELLED',
                'notes' => 'Cancelled karena Purchase Order direject.',
            ]);
    }

    public function hasPendingApproval(PurchaseOrder $po): bool
    {
        return PurchaseOrderApproval::where('purchase_order_id', $po->id)
            ->where('status', 'PENDING')
            ->exists();
    }

    public function markPurchaseOrderApproved(PurchaseOrder $po, User $user): void
    {

        $po->update([
            'status' => 'APPROVED',
            'status_receive' => 'OPEN',
            'approved_at' => now(),
            'approved_by' => $user->name,
        ]);

        DB::table('purchase_order_items')
            ->where('purchase_order_id', $po->id)
            ->whereNull('deleted_at')
            ->update([
                'qty_received' => 0,
                'qty_outstanding_receive' => DB::raw('qty'),
                'updated_at' => now(),
            ]);
    }

    public function markPurchaseOrderRejected(PurchaseOrder $po): void
    {
        $po->status = 'REJECTED';
        $po->save();
    }
}
