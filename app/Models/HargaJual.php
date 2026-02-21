<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HargaJual extends Model
{
    use HasFactory;
     
      protected $table = 'harga_jual';
    public $timestamps = false;
    //  public $incrementing = false;

    // protected $keyType = 'string'; 

    protected $fillable = [
        'periode_awal',
        'periode_akhir',
        'id_area',
        'pajak',
        'produk',
        'harga_normal',
        'loco',
        'skp',
        'harga_sm',
        'harga_om',
        'note_jual',
        'is_approved',
        'is_evaluated',
        'tanggal_persetujuan',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
        'lastupdate_ip',
        'lastupdate_by',
        'is_edited',
        'harga_ceo',
        'harga_coo',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_time' => 'datetime',
        'lastupdate_time' => 'datetime',
    ];

    public function area()
    {
        return $this->belongsTo(Area::class, 'id_area', 'id');
    }

    public function getproduk()
    {
        return $this->belongsTo(Produk::class, 'produk', 'id');
    }
    public function pbbkb()
    {
        return $this->belongsTo(Pbbkb::class, 'pajak', 'id');
    }
}
