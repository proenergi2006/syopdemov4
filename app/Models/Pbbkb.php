<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pbbkb extends Model
{
    use HasFactory;

    protected $table = 'pbbkb';

    public $timestamps = false;

    protected $fillable = [
        'nilai_pbbkb',
        'ket_pbbkb',
        'is_active',
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
}
