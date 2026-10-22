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
        'transaction_category_id',
        'expense_category_id',
        'qty',
        'unit_price',
        'amount',
        'original_amount',
    ];

    protected $casts = [
        'cash_advance_id' => 'integer',
        'date' => 'date',
        'amount' => 'decimal:2',
        'expense_category_id' => 'integer',
        'qty' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'original_amount' => 'decimal:2',
    ];

    /**
     * Kategori biaya perjalanan dinas.
     *
     * Kosong untuk baris yang keterangan transaksinya bukan perdin --
     * di sana rinciannya memang tidak dikelompokkan.
     */
    /**
     * Keterangan transaksi baris ini.
     *
     * null berarti IKUT HEADERNYA, bukan belum diisi -- baris yang dibuat
     * sebelum keterangan transaksi pindah ke baris memang tidak punya
     * pilihannya sendiri. Jangan tertukar dengan expenseCategory(), yang
     * mengelompokkan biaya perjalanan dinas.
     */
    public function transactionCategory()
    {
        return $this->belongsTo(
            \App\Models\FundRequestTransactionCategory::class,
            'transaction_category_id',
        );
    }

    public function expenseCategory()
    {
        return $this->belongsTo(
            BusinessTripExpenseCategory::class,
            'expense_category_id',
        );
    }

    public function cashAdvance()
    {
        return $this->belongsTo(
            CashAdvance::class,
            'cash_advance_id',
        );
    }
}
