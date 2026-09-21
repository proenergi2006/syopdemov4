<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
| Satu baris pengeluaran pada Realisasi FPU.
|
| advance_amount adalah snapshot nominal pengajuan dari baris FPU asalnya,
| supaya perbandingan per baris tetap benar walau FPU-nya berubah.
| cash_advance_item_id NULL berarti pengeluaran di luar rencana.
*/
class CashAdvanceRealizationItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cash_advance_realization_items';

    protected $fillable = [
        'cash_advance_realization_id',
        'cash_advance_item_id',
        'date',
        'description',
        'advance_amount',
        'realization_amount',
        'notes',
    ];

    protected $casts = [
        'cash_advance_realization_id' => 'integer',
        'cash_advance_item_id' => 'integer',
        'date' => 'date',
        'advance_amount' => 'decimal:2',
        'realization_amount' => 'decimal:2',
    ];

    public function realization()
    {
        return $this->belongsTo(
            CashAdvanceRealization::class,
            'cash_advance_realization_id',
        );
    }

    public function cashAdvanceItem()
    {
        return $this->belongsTo(CashAdvanceItem::class, 'cash_advance_item_id');
    }

    /**
     * Selisih baris: positif berarti hemat, negatif berarti membengkak.
     */
    public function getDifferenceAmountAttribute(): float
    {
        return round(
            (float) $this->advance_amount - (float) $this->realization_amount,
            2,
        );
    }
}
