<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryGainLoss extends Model
{
    protected $table = 'inventory_gain_loss';
    protected $primaryKey = 'id_master';
    public $timestamps = false; // karena kamu pakai created_time manual

    protected $fillable = [
        'id_po_supplier',
        'volume_po',
        'volume_terima',
        'jenis',
        'volume',
        'file_upload',
        'file_upload_ori',
        'ket',
        'disposisi_gain_loss',
        'ceo_result',
        'ceo_pic',
        'ceo_tanggal',
        'ceo_summary',
        'created_time',
        'created_ip',
        'created_by',
    ];

    protected $casts = [
        'volume_po' => 'decimal:4',
        'volume_terima' => 'decimal:4',
        'volume' => 'decimal:4',
        'ceo_tanggal' => 'datetime',
        'created_time' => 'datetime',
    ];

 

    public function po()
    {
        return $this->belongsTo(
            InventoryVendorPo::class,
            'id_po_supplier',
            'id_master'
        );
    }
}