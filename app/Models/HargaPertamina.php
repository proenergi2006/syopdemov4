<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HargaPertamina extends Model
{
    use HasFactory;

     protected $table = 'harga_pertamina';

    // Karena tidak ada kolom id 
    // public $incrementing = false;

    // protected $keyType = 'string'; // karena ada date di primary key

    public $timestamps = false;

    protected $fillable = [
        'periode_awal',
        'periode_akhir',
        'id_area',
        'id_produk',
        'harga_minyak',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
        'lastupdate_ip',
        'lastupdate_by',
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

    public function produk()
    {
        return $this->belongsTo(Produk::class, 'id_produk', 'id');
    }
}
