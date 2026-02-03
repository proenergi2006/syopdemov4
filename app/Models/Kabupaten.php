<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kabupaten extends Model
{
  protected $table = 'kabupaten';

  protected $fillable = [
    'provinsi_id',
    'kode',
    'nama',
    'is_active',
  ];

  protected $casts = [
    'is_active' => 'boolean',
  ];

  public function provinsi()
  {
    return $this->belongsTo(Provinsi::class, 'provinsi_id');
  }
}
