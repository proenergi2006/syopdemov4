<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OngkosAngkutKapal extends Model
{
    protected $table = 'ongkos_angkut_kapal';

    public $timestamps = false;
    protected $fillable = [
        'id_transportir',
        'nama_kapal',
        'tipe_kapal',
        'max_kapal',
        'asal_angkut',
        'tujuan_angkut',
        'harga_angkut',
        'volume_angkut',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
        'lastupdate_ip',
        'lastupdate_by',
    ];

    protected $casts = [
        'max_kapal' => 'integer',
        'harga_angkut' => 'integer',
        'volume_angkut' => 'integer',
        'created_time' => 'datetime',
        'lastupdate_time' => 'datetime',
    ];

    public function transportir()
    {
        return $this->belongsTo(Transportir::class,'id_transportir' ,'id');
    }
}