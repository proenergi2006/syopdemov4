<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OngkosAngkut extends Model
{
    protected $table = 'ongkos_angkut';
protected $primaryKey = 'id';
public $incrementing = true;
protected $keyType = 'int';
public $timestamps = false;

    protected $fillable = [
        'id_transportir',
        'id_wil_angkut',
        'id_prov_angkut',
        'id_kab_angkut',
        'id_vol_angkut',
        'ongkos_angkut',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
        'lastupdate_ip',
        'lastupdate_by',
    ];

    protected $casts = [
        'ongkos_angkut' => 'integer',
        'created_time' => 'datetime',
        'lastupdate_time' => 'datetime',
    ];

    public function transportir()
    {
        return $this->belongsTo(Transportir::class, 'id_transportir');
    }

    public function wilayahAngkut()
    {
        return $this->belongsTo(WilayahAngkut::class, 'id_wil_angkut');
    }

    public function provinsi()
    {
        return $this->belongsTo(Provinsi::class, 'id_prov_angkut');
    }

    public function kabupaten()
    {
        return $this->belongsTo(Kabupaten::class, 'id_kab_angkut');
    }

    public function volume()
    {
        return $this->belongsTo(Volume::class, 'id_vol_angkut');
    }
}