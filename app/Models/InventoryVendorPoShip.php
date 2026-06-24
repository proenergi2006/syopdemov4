<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryVendorPoShip extends Model
{
    protected $table = 'inventory_vendor_po_ship';
    protected $primaryKey = 'id_master';
    public $timestamps = false; 

    protected $fillable = [
        'id_vendor_po',
        'tipe_kapal',
        'id_transportir',
        'id_vessel_tb',
        'id_vessel',
        'id_terminal_discharging',
        'loading_port',
        'flag',
        'quantity',
        'nomor_req',
        'nomor_si',
        'etl_date_first',
        'etl_date_last',
        'cargo_name',
        'bill_lading',
        'losstype',
        'loss_tolerance',
        'satuan',
        'freight',
        'demurrage',
        'leadtime',
        'country_origin',
        'shipper',
        'consignee',
        'bl_ship',
        'status',
        'ket_ship',
        'ket_log',
        'log_pic',
        'log_tanggal',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
        'mgrfin_result',
        'mgrfin_pic',
        'mgrfin_tanggal',
        'mgrfin_summary',
        'cfo_result',
        'cfo_pic',
        'cfo_tanggal',
        'cfo_summary',
        'ceo_result',
        'ceo_pic',
        'ceo_tanggal',
        'ceo_summary',
        'is_cancel',
        'ket_cancel',
    ];

     public function po_supplier()
    {
        return $this->belongsTo(
            InventoryVendorPo::class,
            'id_vendor_po',
            'id_master'
        );
    }
     public function load_port()
    {
        return $this->belongsTo(
            Terminal::class,
            'loading_port',
            'id'
        );
    }
     public function discharge_port()
    {
        return $this->belongsTo(
            Terminal::class,
            'id_terminal_discharging',
            'id'
        );
    }
     public function transportir()
    {
        return $this->belongsTo(
            Transportir::class,
            'id_transportir',
            'id'
        );
    }
     public function vessel()
    {
        return $this->belongsTo(
            OngkosAngkutKapal::class,
            'id_vessel',
            'id'
        );
    }
     public function vessel_tb()
    {
        return $this->belongsTo(
            OngkosAngkutKapal::class,
            'id_vessel_tb',
            'id'
        );
    }
}