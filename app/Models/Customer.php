<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';
    protected $primaryKey = 'id';
    public $timestamps = false; // karena pakai created_time/lastupdate_time

    protected $fillable = [
        'marketing_id',
        'nama_perusahaan',
        'email',
        'alamat_perusahaan',
        'provinsi_id',
        'kabupaten_id',
        'postal_code',
        'telepon',
        'fax',
        'jenis_customer',
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

    public function marketing()
    {
        return $this->belongsTo(User::class, 'marketing_id');
    }

    public function provinsi()
    {
        return $this->belongsTo(Provinsi::class, 'provinsi_id');
    }

    public function kabupaten()
    {
        return $this->belongsTo(Kabupaten::class, 'kabupaten_id');
    }
}