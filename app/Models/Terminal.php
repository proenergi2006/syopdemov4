<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Terminal extends Model
{
    protected $table = 'terminal';

    public $timestamps = false; // karena kamu pakai created_time / lastupdate_time

    protected $fillable = [
        'nama_terminal',
        'tanki_terminal',
        'lokasi_terminal',
        'kategori_terminal',
        'batas_atas',
        'batas_bawah',
        'latitude',
        'longitude',
        'alamat_terminal',
        'telp_terminal',
        'fax_terminal',
        'cc_terminal',
        'catatan_terminal',
        'att_terminal',
        'is_active',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
        'lastupdate_by',
        'inisial_terminal',
        'id_cabang',
        'id_area',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'batas_atas' => 'float',
        'batas_bawah' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
        'created_time' => 'datetime',
        'lastupdate_time' => 'datetime',
    ];

    // optional relasi (kalau kamu punya Model Cabang/Area)
    public function cabang()
    {
        return $this->belongsTo(Cabang::class, 'id_cabang');
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'id_area');
    }

    // supaya UI bisa dapat link download
    protected $appends = ['att_terminal_url'];

    public function getAttTerminalUrlAttribute()
    {
        return $this->att_terminal ? asset('storage/' . $this->att_terminal) : null;
    }
}
