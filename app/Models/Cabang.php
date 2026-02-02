<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cabang extends Model
{
    protected $table = 'cabang';

    protected $fillable = [
        'kode',
        'nama',
        'wilayah_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
