<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Produk extends Model
{
    use HasFactory;

    protected $table = 'produk';

    public $timestamps = false;

    protected $fillable = [
        'jenis_produk',
        'merk_dagang',
        'catatan_produk',
        'is_active',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
        'lastupdate_ip',
        'lastupdate_by',
        'no_urut'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_time' => 'datetime',
        'lastupdate_time' => 'datetime',
    ];
}
