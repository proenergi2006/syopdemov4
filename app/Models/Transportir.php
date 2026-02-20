<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transportir extends Model
{
    protected $table = 'transportir';
    public $timestamps = false; // karena kamu pakai created_time/lastupdate_time (bukan created_at/updated_at)

    protected $fillable = [
        'nama_transportir',
        'nama_suplier',
        'lokasi_suplier',
        'alamat_suplier',
        'att_suplier',
        'telp_suplier',
        'fax_suplier',
        'is_fleet',
        'terms_suplier',
        'catatan',
        'is_active',
        'tipe_angkutan',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
        'lastupdate_ip',
        'lastupdate_by',
        'owner_suplier',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_fleet' => 'integer',
        'owner_suplier' => 'integer',
        'created_time' => 'datetime',
        'lastupdate_time' => 'datetime',
    ];

    public function sopirs()
    {
        return $this->hasMany(TransportirSopir::class, 'id_transportir', 'id');
        // kalau PK transportir kamu id_master, ganti 'id' -> 'id_master'
    }
}
