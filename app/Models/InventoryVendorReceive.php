<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryVendorReceive extends Model
{
    use HasFactory;
    protected $table = 'inventory_vendor_receive';
    protected $primaryKey = 'id_po_receive';
    public $timestamps = false;

    protected $fillable = [
        'id_po_supplier',
        'id_accurate',
        'no_terima',
        'nama_pic',
        'tgl_terima',
        'volume_bol',
        'volume_terima',
        'harga_tebus',
        'file_upload',
        'file_upload_ori',
        'is_aktif',
        'is_updated',
        'updated_count',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
        'lastupdate_ip',
        'lastupdate_by',
    ];
    
    // FK 
    public function po_supplier()
    {
        return $this->belongsTo(
            InventoryVendorPo::class,
            'id_po_supplier',
            'id_master'
        );
    }
}
