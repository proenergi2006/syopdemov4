<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterDokumenPendukung extends Model
{
    use HasFactory;

    protected $table = 'master_dokumen_pendukung';

    protected $fillable = [
        'nama_dokumen',
        'deskripsi',
        'is_required',
        'is_active',
    ];

    /**
     * Relasi ke tabel vendor_dokumen_pendukung
     */
    public function vendorDokumen()
    {
        return $this->hasMany(VendorDokumenPendukung::class, 'dokumen_id');
    }

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
    ];
}
