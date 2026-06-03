<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequestItem;

class PurchaseOrderRollbackService
{
    public function rollbackPurchaseRequestItems(PurchaseOrder $po): void
    {
        $po->loadMissing(['items', 'purchaseRequests.items']);

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

            $qtyPo = (float) ($item->qty ?? 0);

            $prItem->qty_po = max(((float) ($prItem->qty_po ?? 0)) - $qtyPo, 0);
            $prItem->qty_outstanding = ((float) ($prItem->qty_outstanding ?? 0)) + $qtyPo;
            $prItem->save();
        }

        foreach ($po->purchaseRequests as $pr) {
            $pr->loadMissing('items');

            $totalQtyPo = $pr->items->sum(function ($item) {
                return (float) ($item->qty_po ?? 0);
            });

            $totalQtyOutstanding = $pr->items->sum(function ($item) {
                return (float) ($item->qty_outstanding ?? 0);
            });

            $totalQtyRequest = $pr->items->sum(function ($item) {
                return (float) ($item->qty ?? 0);
            });

            if ($totalQtyPo <= 0) {
                $pr->status_po = 'OPEN';
            } elseif ($totalQtyOutstanding > 0 && $totalQtyPo < $totalQtyRequest) {
                $pr->status_po = 'PARTIAL PO';
            } else {
                $pr->status_po = 'COMPLETED';
            }

            $pr->save();
        }
    }
}
