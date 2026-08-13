<?php

namespace App\Services\NonTrade\PurchaseRequest;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class PurchaseRequestRollbackService
{
    /**
     * Batalkan Purchase Requisition yang sudah APPROVED selama tidak ada
     * Purchase Order aktif yang masih terkait dengannya. Purchase Order
     * dengan status REJECTED atau CANCELLED tidak dianggap aktif karena
     * qty alokasinya sudah dikembalikan ke PR (lihat
     * PurchaseOrderRollbackService::rollbackPurchaseRequestItems()).
     */
    public function cancel(PurchaseRequest $pr, User $user, string $notes): void
    {
        DB::transaction(function () use ($pr, $user, $notes) {
            $pr = PurchaseRequest::query()
                ->lockForUpdate()
                ->findOrFail($pr->id);

            if (strtoupper((string) $pr->status) !== PurchaseRequest::STATUS_APPROVED) {
                throw new Exception(
                    'Purchase Requisition hanya dapat dibatalkan jika status APPROVED.',
                );
            }

            $hasActivePurchaseOrder = $pr->purchaseOrders()
                ->whereRaw('UPPER(TRIM(purchase_orders.status)) NOT IN (?, ?)', [
                    PurchaseOrder::STATUS_REJECTED,
                    PurchaseOrder::STATUS_CANCELLED,
                ])
                ->exists();

            if ($hasActivePurchaseOrder) {
                throw new Exception(
                    'Purchase Requisition tidak dapat dibatalkan karena masih ada Purchase Order yang aktif.',
                );
            }

            $pr->status = PurchaseRequest::STATUS_CANCELLED;
            $pr->cancelled_by = $user->id;
            $pr->cancelled_at = now();
            $pr->cancel_notes = $notes;
            $pr->save();
        });
    }
}
