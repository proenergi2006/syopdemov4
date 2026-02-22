<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransportirMobil extends Model
{
    protected $table = 'master_transportir_mobil';
    protected $primaryKey = 'id_master';
    public $timestamps = false; // karena pakai created_time

    protected $fillable = [
        'id_transportir',
        'nomor_plat',
        'no_proyek',
        'max_kap',
        'komp_tanki',
        'photo',
        'photo_ori',
        'link_gps',
        'user_gps',
        'pass_gps',
        'membercode_gps',
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
        'max_kap' => 'integer',
        'created_time' => 'datetime',
        'lastupdate_time' => 'datetime',
    ];

    // 🔗 Relasi ke Transportir
    public function transportir()
    {
        return $this->belongsTo(Transportir::class, 'id_transportir');
    }
}