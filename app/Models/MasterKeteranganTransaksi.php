<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterKeteranganTransaksi extends Model
{
    protected $table = 'master_keterangan_transaksi';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'kategori',
        'pasal_pajak',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
