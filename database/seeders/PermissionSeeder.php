<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $permissions = [
            /*
            |--------------------------------------------------------------------------
            | Master Vendor
            |--------------------------------------------------------------------------
            */
            [
                'module' => 'vendor',
                'action' => 'view',
                'code' => 'vendor.view',
                'name' => 'View Master Vendor',
                'description' => 'Melihat data master vendor.',
            ],
            [
                'module' => 'vendor',
                'action' => 'create',
                'code' => 'vendor.create',
                'name' => 'Create Master Vendor',
                'description' => 'Membuat data master vendor.',
            ],
            [
                'module' => 'vendor',
                'action' => 'update',
                'code' => 'vendor.update',
                'name' => 'Update Master Vendor',
                'description' => 'Mengubah data master vendor.',
            ],
            [
                'module' => 'vendor',
                'action' => 'submit',
                'code' => 'vendor.submit',
                'name' => 'Submit Master Vendor',
                'description' => 'Submit Master Vendor ke proses approval',
            ],
            [
                'module' => 'vendor',
                'action' => 'delete',
                'code' => 'vendor.delete',
                'name' => 'Delete Master Vendor',
                'description' => 'Menghapus atau menonaktifkan data master vendor.',
            ],

            /*
            |--------------------------------------------------------------------------
            | Purchase Requisition
            |--------------------------------------------------------------------------
            */
            [
                'module' => 'purchase_request',
                'action' => 'view',
                'code' => 'purchase_request.view',
                'name' => 'View Purchase Requisition',
                'description' => 'Melihat data Purchase Requisition.',
            ],
            [
                'module' => 'purchase_request',
                'action' => 'create',
                'code' => 'purchase_request.create',
                'name' => 'Create Purchase Requisition',
                'description' => 'Membuat data Purchase Requisition.',
            ],
            [
                'module' => 'purchase_request',
                'action' => 'update',
                'code' => 'purchase_request.update',
                'name' => 'Update Purchase Requisition',
                'description' => 'Mengubah data Purchase Requisition.',
            ],
            [
                'module' => 'purchase_request',
                'action' => 'submit',
                'code' => 'purchase_request.submit',
                'name' => 'Submit Purchase Requisition',
                'description' => 'Submit Purchase Requisition ke proses approval',
            ],
            [
                'module' => 'purchase_request',
                'action' => 'delete',
                'code' => 'purchase_request.delete',
                'name' => 'Delete Purchase Requisition',
                'description' => 'Menghapus atau membatalkan Purchase Requisition.',
            ],
            [
                'module' => 'purchase_request',
                'action' => 'cancel',
                'code' => 'purchase_request.cancel',
                'name' => 'Cancel Purchase Requisition',
                'description' => 'Membatalkan Purchase Requisition yang sudah approved.',
            ],

            /*
            | Sama seperti purchase_order.export: tanpa scope. Isi file mengikuti
            | visibility yang sudah berlaku pada daftar, jadi permission ini hanya
            | menentukan boleh-tidaknya menarik data keluar.
            */
            [
                'module' => 'purchase_request',
                'action' => 'export',
                'code' => 'purchase_request.export',
                'name' => 'Export Purchase Requisition',
                'description' => 'Export data Purchase Requisition ke Excel, sesuai data yang tampil pada daftar.',
                'requires_scope' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Purchase Order
            |--------------------------------------------------------------------------
            */
            [
                'module' => 'purchase_order',
                'action' => 'view',
                'code' => 'purchase_order.view',
                'name' => 'View Purchase Order',
                'description' => 'Melihat data Purchase Order.',
            ],
            [
                'module' => 'purchase_order',
                'action' => 'create',
                'code' => 'purchase_order.create',
                'name' => 'Create Purchase Order',
                'description' => 'Membuat data Purchase Order.',
            ],
            [
                'module' => 'purchase_order',
                'action' => 'update',
                'code' => 'purchase_order.update',
                'name' => 'Update Purchase Order',
                'description' => 'Mengubah data Purchase Order.',
            ],
            [
                'module' => 'purchase_order',
                'action' => 'submit',
                'code' => 'purchase_order.submit',
                'name' => 'Submit Purchase Order',
                'description' => 'Submit Purchase Order ke proses approval',
            ],
            [
                'module' => 'purchase_order',
                'action' => 'delete',
                'code' => 'purchase_order.delete',
                'name' => 'Delete Purchase Order',
                'description' => 'Menghapus atau membatalkan Purchase Order.',
            ],
            [
                'module' => 'purchase_order',
                'action' => 'cancel',
                'code' => 'purchase_order.cancel',
                'name' => 'Cancel Purchase Order',
                'description' => 'Membatalkan Purchase Order yang sudah approved.',
            ],

            /*
            | Permission export sengaja TIDAK memakai scope (requires_scope = false).
            | Isi file selalu mengikuti visibility PO yang sudah berlaku pada daftar,
            | jadi permission ini hanya menjawab "boleh menarik data keluar atau
            | tidak" -- bukan "boleh melihat data siapa".
            */
            [
                'module' => 'purchase_order',
                'action' => 'export',
                'code' => 'purchase_order.export',
                'name' => 'Export Purchase Order',
                'description' => 'Export data Purchase Order ke Excel, sesuai data yang tampil pada daftar.',
                'requires_scope' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Goods Receipt
            |--------------------------------------------------------------------------
            */
            [
                'module' => 'goods_receive',
                'action' => 'view',
                'code' => 'goods_receive.view',
                'name' => 'View Goods Receipt',
                'description' => 'Melihat data Goods Receipt.',
            ],
            [
                'module' => 'goods_receive',
                'action' => 'create',
                'code' => 'goods_receive.create',
                'name' => 'Create Goods Receipt',
                'description' => 'Membuat data Goods Receipt.',
            ],
            [
                'module' => 'goods_receive',
                'action' => 'update',
                'code' => 'goods_receive.update',
                'name' => 'Update Goods Receipt',
                'description' => 'Mengubah data Goods Receipt.',
            ],
            [
                'module' => 'goods_receive',
                'action' => 'delete',
                'code' => 'goods_receive.delete',
                'name' => 'Delete Goods Receipt',
                'description' => 'Menghapus atau membatalkan Goods Receipt.',
            ],
            [
                'module' => 'goods_receive',
                'action' => 'post',
                'code' => 'goods_receive.post',
                'name' => 'Posting Goods Receipt',
                'description' => 'Memposting penerimaan barang.',
            ],
            [
                'module' => 'goods_receive',
                'action' => 'cancel',
                'code' => 'goods_receive.cancel',
                'name' => 'Cancel Goods Receipt',
                'description' => 'Membatalkan Goods Receipt yang sudah posted.',
            ],
        ];

        foreach ($permissions as $permission) {
            $existingPermission = DB::table('permissions')
                ->where('code', $permission['code'])
                ->first();

            $payload = [
                'module' => $permission['module'],
                'action' => $permission['action'],
                'name' => $permission['name'],
                'description' => $permission['description'],
                'is_active' => true,

                /*
                |--------------------------------------------------------------------------
                | Created at tidak berubah ketika seeder dijalankan ulang
                |--------------------------------------------------------------------------
                */
                'created_at' => $existingPermission?->created_at
                    ?? $now,

                'updated_at' => $now,
            ];

            /*
            |--------------------------------------------------------------------------
            | requires_scope
            |--------------------------------------------------------------------------
            | Hanya ditulis saat permission BARU dibuat. Untuk permission yang sudah
            | ada, nilainya dibiarkan apa adanya supaya pengaturan yang sudah diubah
            | admin lewat menu Permission tidak tertimpa setiap seeder dijalankan.
            |--------------------------------------------------------------------------
            */
            if (
                !$existingPermission
                && array_key_exists('requires_scope', $permission)
            ) {
                $payload['requires_scope'] = (bool) $permission['requires_scope'];
            }

            DB::table('permissions')->updateOrInsert(
                [
                    'code' => $permission['code'],
                ],
                $payload,
            );
        }
    }
}
