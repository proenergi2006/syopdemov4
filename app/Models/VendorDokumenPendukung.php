<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorDokumenPendukung extends Model
{
    use HasFactory;

    protected $table = 'vendor_dokumen_pendukung';

    protected $fillable = [
        'vendor_id',
        'dokumen_id',
        'file_name',
        'file_path',
        'file_size',
        'file_type',
    ];

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id');
    }

    public function masterDokumen()
    {
        return $this->belongsTo(MasterDokumenPendukung::class, 'dokumen_id');
    }
}
