<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
| Baris pada tabel form FPU: Date, Decriptions, Amount.
*/
class CashAdvanceItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cash_advance_items';

    protected $fillable = [
        'cash_advance_id',
        'date',
        'description',
        'amount',
    ];

    protected $casts = [
        'cash_advance_id' => 'integer',
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function cashAdvance()
    {
        return $this->belongsTo(
            CashAdvance::class,
            'cash_advance_id',
        );
    }
}
