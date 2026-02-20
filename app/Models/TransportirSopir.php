<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransportirSopir extends Model
{
    protected $table = 'transportir_sopir';
    protected $primaryKey = 'id_master';
    public $timestamps = false;

    protected $fillable = [
        'id_transportir',
        'nama_sopir',
        'photo',
        'photo_ori',
        'is_active',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
        'lastupdate_ip',
        'lastupdate_by',
    ];

    protected $casts = [
        'is_active' => 'integer',
        'created_time' => 'datetime',
        'lastupdate_time' => 'datetime',
    ];

    public function transportir()
    {
        return $this->belongsTo(Transportir::class, 'id_transportir', 'id');
        // kalau PK transportir kamu id_master, ganti 'id' -> 'id_master'
    }
}
