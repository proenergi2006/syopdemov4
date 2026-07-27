<?php

namespace App\Services\Trade\PurchaseOrder;

use App\Models\InventoryVendorPo;
use App\Models\Notification;
use App\Models\PurchaseOrderApproval;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class POInventoryNotificationService
{
    public function notifyCfo($po): void
    {
        $users = User::whereHas('roles', function ($q) {
            $q->where('nama', 'Chief Financial Officer');
        })->get();

        foreach ($users as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'purchase_order_trade.approval',
                'title' => 'Approval Purchase Order',
                'message' => "Purchase Order {$po} menunggu approval Anda.",
                'module' => 'purchase_order',
                'reference_type' => InventoryVendorPo::class,
                'reference_id' => $po,
                'reference_public_id' => Crypt::encryptString((string) $po),
                'url' => '/purchaseSupplier/po-supplier/approval',
            ]);
        }
    }

    public function notifyCeo(InventoryVendorPo $po): void
    {
        $users = User::whereHas('roles', function ($q) {
            $q->where('nama', 'Chief Executive Officer');
        })->get();

        $encryptedId = Crypt::encryptString(
            (string) $po->id_master,
        );
        foreach ($users as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'purchase_order_trade.approval',
                'title' => 'Approval Purchase Order Trade',
                'message' => "Purchase Order {$po->nomor_po} menunggu approval Anda.",
                'module' => 'purchase_order',
                'reference_type' => InventoryVendorPo::class,
                'reference_id' => $po->id_master,
                'reference_public_id' => $encryptedId,
                'url' => '/purchaseSupplier/po-supplier/approval',
            ]);
        }
    }

    public function notifyRequester(InventoryVendorPo $po): void
    {
        $users = User::whereHas('departemen', function ($q) {
            $q->where('kode', 'PROC');
        })->get();

        $encryptedId = Crypt::encryptString(
            (string) $po->id_master,
        );
        foreach ($users as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'purchase_order_trade.result',
                'title' => 'Approved Purchase Order Trade',
                'message' => "Purchase Order Trade {$po->nomor_po} sudah disetujui",
                'module' => 'purchase_order',
                'reference_type' => InventoryVendorPo::class,
                'reference_id' => $po->id_master,
                'reference_public_id' => $encryptedId,
                'url' => '/purchaseSupplier/po-supplier',
            ]);
        }
        
    }
}