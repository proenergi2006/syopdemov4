<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $units = [
            // === Satuan Umum ===
            ['PCS', 'Pieces', 'umum'],
            ['UNIT', 'Unit', 'umum'],
            ['SET', 'Set', 'umum'],
            ['PACK', 'Pack', 'umum'],
            ['BOX', 'Box', 'umum'],
            ['ROLL', 'Roll', 'umum'],
            ['PAIR', 'Pair', 'umum'],

            // === Berat ===
            ['KG', 'Kilogram', 'berat'],
            ['G', 'Gram', 'berat'],
            ['MG', 'Miligram', 'berat'],
            ['TON', 'Ton', 'berat'],
            ['Q', 'Kwintal', 'berat'],
            ['LB', 'Pound', 'berat'],

            // === Volume Cair ===
            ['L', 'Liter', 'cair'],
            ['ML', 'Mililiter', 'cair'],
            ['KL', 'Kiloliter', 'cair'],
            ['BBL', 'Barrel', 'cair'],
            ['GAL', 'Gallon', 'cair'],

            // === Panjang ===
            ['M', 'Meter', 'panjang'],
            ['CM', 'Centimeter', 'panjang'],
            ['MM', 'Millimeter', 'panjang'],
            ['KM', 'Kilometer', 'panjang'],
            ['FT', 'Feet', 'panjang'],
            ['IN', 'Inch', 'panjang'],

            // === Luas ===
            ['M2', 'Meter Persegi', 'luas'],
            ['CM2', 'Centimeter Persegi', 'luas'],
            ['FT2', 'Feet Persegi', 'luas'],
            ['HA', 'Hektar', 'luas'],

            // === Volume Padat ===
            ['M3', 'Meter Kubik', 'volume'],
            ['CM3', 'Centimeter Kubik', 'volume'],
            ['YD3', 'Yard Kubik', 'volume'],

            // === Gas ===
            ['SCF', 'Standard Cubic Feet', 'gas'],
            ['NM3', 'Normal Meter Kubik', 'gas'],

            // === Waktu (opsional) ===
            ['HOUR', 'Hours', 'waktu'],
            ['DAY', 'Day', 'waktu'],
        ];

        foreach ($units as $u) {
            DB::table('units')->insert([
                'kode' => $u[0],
                'nama' => $u[1],
                'kategori' => $u[2],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
