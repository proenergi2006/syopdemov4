<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class PurchaseOrderRollbackService
{
    /**
     * Batalkan Purchase Order yang sudah APPROVED selama:
     * - tidak ada Goods Receipt yang masih DRAFT (pekerjaan penerimaan yang
     *   belum selesai); dan
     * - status_receive PO masih OPEN, yaitu tidak ada qty yang secara net
     *   masih benar-benar diterima. PO dengan Goods Receipt yang sudah
     *   POSTED tapi seluruh qty-nya sudah diretur lewat Goods Return
     *   (status_receive kembali OPEN) tetap boleh dibatalkan, karena stock
     *   sudah balik ke vendor.
     *
     * Qty PR item yang sudah dialokasikan ke PO ini dikembalikan lewat
     * rollbackPurchaseRequestItems().
     */
    public function cancel(PurchaseOrder $po, User $user, string $notes): void
    {
        DB::transaction(function () use ($po, $user, $notes) {
            $po = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($po->id);

            if (strtoupper((string) $po->status) !== PurchaseOrder::STATUS_APPROVED) {
                throw new Exception(
                    'Purchase Order hanya dapat dibatalkan jika status APPROVED.',
                );
            }

            $hasDraftGoodsReceive = $po->goodsReceives()
                ->where('status', 'DRAFT')
                ->exists();

            if ($hasDraftGoodsReceive) {
                throw new Exception(
                    'Purchase Order tidak dapat dibatalkan karena masih ada Goods Receipt berstatus DRAFT.',
                );
            }

            if (strtoupper((string) $po->status_receive) !== 'OPEN') {
                throw new Exception(
                    'Purchase Order tidak dapat dibatalkan karena masih ada qty yang sudah diterima (Goods Receipt).',
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Status diubah ke CANCELLED dulu sebelum rollback
            |--------------------------------------------------------------------------
            | refreshPurchaseRequestPOStatus() menghitung status_po PR dari qty PO
            | yang statusnya APPROVED. Kalau rollback dijalankan sebelum status
            | PO ini diubah, PO yang sedang dibatalkan masih ikut terhitung
            | APPROVED sehingga status_po PR jadi salah.
            |--------------------------------------------------------------------------
            */
            $po->status = PurchaseOrder::STATUS_CANCELLED;
            $po->cancelled_by = $user->id;
            $po->cancelled_at = now();
            $po->cancel_notes = $notes;
            $po->save();

            $this->rollbackPurchaseRequestItems($po);
        });
    }

    public function rollbackPurchaseRequestItems(PurchaseOrder $po): void
    {
        $po->loadMissing(['items']);

        $affectedPurchaseRequestIds = [];

        /*
        |--------------------------------------------------------------------------
        | 1. Rollback qty_po dan qty_outstanding di PR item
        |--------------------------------------------------------------------------
        */
        foreach ($po->items as $item) {
            if (!$item->purchase_request_item_id) {
                continue;
            }

            $prItem = PurchaseRequestItem::where('id', $item->purchase_request_item_id)
                ->lockForUpdate()
                ->first();

            if (!$prItem) {
                continue;
            }

            $qtyPoRollback = (float) ($item->qty ?? 0);
            $qtyRequest = (float) ($prItem->qty ?? 0);
            $currentQtyPo = (float) ($prItem->qty_po ?? 0);

            /*
            |--------------------------------------------------------------------------
            | Qty PO dikurangi qty dari PO yang dihapus/reject.
            |--------------------------------------------------------------------------
            */
            $newQtyPo = max($currentQtyPo - $qtyPoRollback, 0);

            /*
            |--------------------------------------------------------------------------
            | Outstanding dihitung ulang dari qty request - qty_po terbaru.
            |--------------------------------------------------------------------------
            */
            $newQtyOutstanding = max($qtyRequest - $newQtyPo, 0);

            $prItem->update([
                'qty_po' => $newQtyPo,
                'qty_outstanding' => $newQtyOutstanding,
            ]);

            if ($prItem->purchase_request_id) {
                $affectedPurchaseRequestIds[] = (int) $prItem->purchase_request_id;
            }
        }

        $affectedPurchaseRequestIds = array_values(array_unique($affectedPurchaseRequestIds));

        /*
        |--------------------------------------------------------------------------
        | 2. Refresh status_po PR
        |--------------------------------------------------------------------------
        */
        foreach ($affectedPurchaseRequestIds as $purchaseRequestId) {
            $this->refreshPurchaseRequestPOStatus($purchaseRequestId);
        }
    }

    /**
     * Hitung ulang status_po PR berdasarkan qty PO yang statusnya sudah
     * APPROVED saja. PO yang masih DRAFT/IN PROGRESS sengaja tidak dihitung
     * di sini, supaya status_po PR tidak berubah jadi PARTIAL/COMPLETED
     * sebelum PO-nya benar-benar final approved.
     */
    public function refreshPurchaseRequestPOStatus(int $purchaseRequestId): void
    {
        $pr = PurchaseRequest::where('id', $purchaseRequestId)
            ->lockForUpdate()
            ->first();

        if (!$pr) {
            return;
        }

        $totalQtyRequest = (float) PurchaseRequestItem::query()
            ->where('purchase_request_id', $purchaseRequestId)
            ->whereNull('deleted_at')
            ->sum('qty');

        $totalQtyPoApproved = (float) PurchaseOrderItem::query()
            ->join(
                'purchase_orders',
                'purchase_orders.id',
                '=',
                'purchase_order_items.purchase_order_id',
            )
            ->join(
                'purchase_request_items',
                'purchase_request_items.id',
                '=',
                'purchase_order_items.purchase_request_item_id',
            )
            ->where('purchase_request_items.purchase_request_id', $purchaseRequestId)
            ->whereNull('purchase_order_items.deleted_at')
            ->whereNull('purchase_orders.deleted_at')
            ->whereNull('purchase_request_items.deleted_at')
            ->where('purchase_orders.status', PurchaseOrder::STATUS_APPROVED)
            ->sum('purchase_order_items.qty');

        if ($totalQtyPoApproved <= 0) {
            $statusPo = PurchaseRequest::STATUS_PO_OPEN;
        } elseif ($totalQtyPoApproved < $totalQtyRequest) {
            $statusPo = PurchaseRequest::STATUS_PO_PARTIAL;
        } else {
            $statusPo = PurchaseRequest::STATUS_PO_COMPLETED;
        }

        $pr->update([
            'status_po' => $statusPo,
        ]);
    }
}
