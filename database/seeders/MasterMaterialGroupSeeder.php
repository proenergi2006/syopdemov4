<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterMaterialGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $materialGroups = [
            [
                'code' => 'OFFICE',
                'name' => 'Office Supplies',
                'description' => 'Kertas, pulpen, binder, toner',
            ],
            [
                'code' => 'IT',
                'name' => 'IT Equipment & Accessories',
                'description' => 'Laptop, monitor, mouse, kabel',
            ],
            [
                'code' => 'FURNITURE',
                'name' => 'Furniture',
                'description' => 'Meja, kursi, lemari',
            ],
            [
                'code' => 'ELECTRICAL',
                'name' => 'Electrical',
                'description' => 'Kabel listrik, lampu, MCB, UPS',
            ],
            [
                'code' => 'TOOLS',
                'name' => 'Tools & Equipment',
                'description' => 'Bor, tang, obeng, alat kerja',
            ],
            [
                'code' => 'SPAREPART',
                'name' => 'Spare Parts',
                'description' => 'Bearing, filter, belt, seal',
            ],
            [
                'code' => 'SAFETY',
                'name' => 'Safety & PPE',
                'description' => 'Helm, sepatu safety, sarung tangan',
            ],
            [
                'code' => 'CLEANING',
                'name' => 'Cleaning Supplies',
                'description' => 'Sapu, mop, chemical cleaner',
            ],
            [
                'code' => 'PANTRY',
                'name' => 'Pantry Supplies',
                'description' => 'Kopi, gula, air mineral',
            ],
            [
                'code' => 'UNIFORM',
                'name' => 'Uniform & Apparel',
                'description' => 'Seragam, wearpack',
            ],
            [
                'code' => 'VEHICLE',
                'name' => 'Vehicle Related',
                'description' => 'Ban, aki, sparepart kendaraan',
            ],
            [
                'code' => 'PACKAGING',
                'name' => 'Packaging',
                'description' => 'Kardus, pallet, drum, plastik',
            ],
            [
                'code' => 'MAINTENANCE',
                'name' => 'Maintenance Service',
                'description' => 'Service AC, gedung, mesin',
            ],
            [
                'code' => 'RENTAL',
                'name' => 'Rental / Lease',
                'description' => 'Sewa kendaraan, alat, gudang',
            ],
            [
                'code' => 'TRANSPORT',
                'name' => 'Transportation',
                'description' => 'Trucking, courier, pengiriman',
            ],
            [
                'code' => 'WAREHOUSE',
                'name' => 'Warehouse Service',
                'description' => 'Sewa gudang, handling',
            ],
            [
                'code' => 'CONSULTING',
                'name' => 'Consulting Service',
                'description' => 'Konsultan IT, pajak, management',
            ],
            [
                'code' => 'LEGAL',
                'name' => 'Legal Service',
                'description' => 'Notaris, lawyer',
            ],
            [
                'code' => 'TRAINING',
                'name' => 'Training & Certification',
                'description' => 'Training, seminar, sertifikasi',
            ],
            [
                'code' => 'MARKETING',
                'name' => 'Marketing & Promotion',
                'description' => 'Banner, souvenir, iklan',
            ],
            [
                'code' => 'TRAVEL',
                'name' => 'Travel & Accommodation',
                'description' => 'Hotel, tiket, perjalanan dinas',
            ],
            [
                'code' => 'SECURITY',
                'name' => 'Security',
                'description' => 'Security service, CCTV',
            ],
            [
                'code' => 'INSURANCE',
                'name' => 'Insurance',
                'description' => 'Asuransi kendaraan, aset, cargo',
            ],
            [
                'code' => 'GENERAL',
                'name' => 'General / Others',
                'description' => 'Item yang belum punya group khusus',
            ],
        ];

        foreach ($materialGroups as $group) {
            DB::table('master_material_groups')->updateOrInsert(
                [
                    'code' => $group['code'],
                ],
                [
                    'name' => $group['name'],
                    'description' => $group['description'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
