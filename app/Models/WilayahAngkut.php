<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WilayahAngkut extends Model
{
    use HasFactory;
    protected $table = 'wilayah_angkut';

    
    public $timestamps = false;

    protected $fillable = [
        'id_prov',
        'id_kab',
        'wilayah_angkut',
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

     public function provinsi()
    {
        return $this->belongsTo(Provinsi::class,'id_prov',  'id');
    }
     public function kabupaten()
    {
        return $this->belongsTo(Kabupaten::class,'id_kab', 'id');
    }
}
