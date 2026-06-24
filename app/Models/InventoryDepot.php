<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryDepot extends Model
{
    use HasFactory;
    protected $table = 'inventory_depot';

    public $timestamps = false; 
    protected $fillable = [
        'id_accurate',
        'id_datanya',
        'id_jenis',
        'id_produk',
        'id_terminal',
        'id_vendor',
        'id_po_supplier',
        'id_po_receive',

        'tanggal_inven',
        'harga',

        'awal_inven',
        'in_inven',
        'out_inven',
        'adj_inven',
        'out_inven_virtual',

        'keterangan',

        'created_time',
        'created_ip',
        'created_by',

        'lastupdate_time',
        'lastupdate_ip',
        'lastupdate_by',

        'id_dsd',
        'id_dsk',
        'id_pr',
        'id_prd',
        'id_pr_ti',
        'id_prd_ti',
        'id_pengisian_solar',
    ];

    protected $casts = [
        'tanggal_inven' => 'date',
        'created_time' => 'datetime',
        'lastupdate_time' => 'datetime',

        'awal_inven' => 'decimal:4',
        'in_inven' => 'decimal:4',
        'out_inven' => 'decimal:4',
        'adj_inven' => 'decimal:4',
        'out_inven_virtual' => 'decimal:4',
    ];
}
