<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
| Pasangan antara formulir petty cash dan claim yang dibayarnya.
|
| released_at yang menjadikan aturan "satu claim satu formulir hidup" bisa
| dijaga indeks database, bukan hanya kode -- lihat migrasinya. Pasangan tidak
| pernah dihapus saat formulirnya batal; ia dilepas, supaya jejaknya tetap ada.
*/
class PettyCashClaim extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_claims';

    protected $fillable = [
        'petty_cash_id',
        'claim_id',
        'amount',
        'released_at',
    ];

    protected $casts = [
        'petty_cash_id' => 'integer',
        'claim_id' => 'integer',
        'amount' => 'decimal:2',
        'released_at' => 'datetime',
    ];

    public function pettyCash()
    {
        return $this->belongsTo(PettyCash::class, 'petty_cash_id');
    }

    public function claim()
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }
}
