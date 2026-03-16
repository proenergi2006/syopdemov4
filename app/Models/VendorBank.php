<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorBank extends Model
{
    use HasFactory;

    protected $table = 'vendor_banks';

    protected $fillable = [
        'vendor_id',
        'nama_bank',
        'atas_nama',
        'nomor_rekening',
        'cabang',
        'alamat_bank',
        'swift_code',
    ];

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id');
    }
}
