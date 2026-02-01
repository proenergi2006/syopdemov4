<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cabang extends Model
{
  protected $fillable = [
    'wilayah_id',
    'kode',
    'nama',
    'alamat',
    // 'telp',
    'is_active',
  ];

  protected $casts = [
    'is_active' => 'boolean',
  ];

  public function wilayah(): BelongsTo
  {
    return $this->belongsTo(Wilayah::class);
  }
}
