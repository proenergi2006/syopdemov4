<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionModuleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $modules = [
            /*
            |--------------------------------------------------------------------------
            | Dashboard
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'dashboard',
                'name' => 'Dashboard',
                'description' => 'Module halaman utama dashboard (launcher, CRM, PO, PR, GR, Goods Return).',
                'route_prefix' => '/dashboards',
                'sort_order' => 5,
                'is_active' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Master Vendor
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'vendor',
                'name' => 'Master Vendor',
                'description' => 'Module pengelolaan data master vendor.',
                'route_prefix' => '/master/vendor',
                'sort_order' => 10,
                'is_active' => true,

                /*
                | Mendaftarkan module ini sebagai jenis dokumen Approval Flow.
                | Inilah master yang mengisi dropdown "Jenis Dokumen".
                */
                'approval_document_type' => 'Vendor',
                'approval_document_label' => 'Master Vendor',
                'approval_uses_area_matrix' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Purchase Requisition
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'purchase_request',
                'name' => 'Purchase Requisition',
                'description' => 'Module pengajuan dan pengelolaan Purchase Requisition.',
                'route_prefix' => '/non_stock/purchase_request',
                'sort_order' => 20,
                'is_active' => true,

                // Flow PR dibedakan per area + department.
                'approval_document_type' => 'PR',
                'approval_document_label' => 'Purchase Requisition (PR)',
                'approval_uses_area_matrix' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Purchase Order
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'purchase_order',
                'name' => 'Purchase Order',
                'description' => 'Module pembuatan dan pengelolaan Purchase Order.',
                'route_prefix' => '/non_stock/purchase_order',
                'sort_order' => 30,
                'is_active' => true,

                'approval_document_type' => 'PO',
                'approval_document_label' => 'Purchase Order (PO)',
                'approval_uses_area_matrix' => false,
            ],

            /*
            |--------------------------------------------------------------------------
            | Goods Receipt
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'goods_receive',
                'name' => 'Goods Receipt',
                'description' => 'Module penerimaan barang berdasarkan Purchase Order.',
                'route_prefix' => '/non_stock/goods_receive',
                'sort_order' => 40,
                'is_active' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Goods Return
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'goods_return',
                'name' => 'Goods Return',
                'description' => 'Module pengembalian barang berdasarkan Goods Receipt.',
                'route_prefix' => '/non_stock/goods_return',
                'sort_order' => 45,
                'is_active' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Dashboard Module Management
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'dashboard_module',
                'name' => 'Dashboard Module Management',
                'description' => 'Module pengelolaan dashboard module dan dashboard module group.',
                'route_prefix' => '/master/dashboard-modules',
                'sort_order' => 50,
                'is_active' => true,
            ],

            /*
            |--------------------------------------------------------------------------
            | Activity Log
            |--------------------------------------------------------------------------
            */
            [
                'code' => 'activity_log',
                'name' => 'Activity Log',
                'description' => 'Module pemantauan catatan aktivitas seluruh user, mulai dari login sampai logout.',
                'route_prefix' => '/master/activity-log',
                'sort_order' => 55,
                'is_active' => true,
            ],
        ];

        foreach ($modules as $module) {
            $existingModule = DB::table('permission_modules')
                ->where('code', $module['code'])
                ->first();

            DB::table('permission_modules')->updateOrInsert(
                [
                    'code' => $module['code'],
                ],
                [
                    'name' => $module['name'],
                    'description' => $module['description'],
                    'route_prefix' => $module['route_prefix'],
                    'sort_order' => $module['sort_order'],
                    'is_active' => $module['is_active'],

                    /*
                    |--------------------------------------------------------------------------
                    | Pendaftaran sebagai jenis dokumen Approval Flow
                    |--------------------------------------------------------------------------
                    | NULL untuk module yang memang tidak punya approval flow.
                    |--------------------------------------------------------------------------
                    */
                    'approval_document_type' => $module['approval_document_type'] ?? null,
                    'approval_document_label' => $module['approval_document_label'] ?? null,
                    'approval_uses_area_matrix' => $module['approval_uses_area_matrix'] ?? false,

                    /*
                    |--------------------------------------------------------------------------
                    | Created at tidak berubah ketika seeder dijalankan ulang
                    |--------------------------------------------------------------------------
                    */
                    'created_at' => $existingModule?->created_at
                        ?? $now,

                    'updated_at' => $now,
                ],
            );
        }
    }
}
