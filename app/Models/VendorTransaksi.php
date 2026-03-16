<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorTransaksi extends Model
{
    use HasFactory;

    protected $table = 'vendor_transaksi';

    protected $fillable = [
        'vendor_id',
        'transaksi_id',
    ];

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id');
    }

    public function masterTransaksi()
    {
        return $this->belongsTo(MasterKeteranganTransaksi::class, 'transaksi_id');
    }
}
