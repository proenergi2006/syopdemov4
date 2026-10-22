<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
| Baris pada tabel form Claim: Date, Decriptions, Amount.
*/
class ClaimItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'claim_items';

    protected $fillable = [
        'claim_id',
        'transaction_category_id',
        'date',
        'description',
        'amount',
        'original_amount',
    ];

    /**
     * Keterangan transaksi baris ini.
     *
     * null berarti IKUT HEADERNYA, bukan belum diisi -- baris yang dibuat
     * sebelum keterangan transaksi pindah ke baris memang tidak punya
     * pilihannya sendiri.
     */
    public function transactionCategory()
    {
        return $this->belongsTo(
            \App\Models\FundRequestTransactionCategory::class,
            'transaction_category_id',
        );
    }

    protected $casts = [
        'claim_id' => 'integer',
        'date' => 'date',
        'amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
    ];

    public function claim()
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }

    /**
     * Bukti pengeluaran milik baris ini.
     */
    public function attachments()
    {
        return $this->hasMany(ClaimAttachment::class, 'claim_item_id');
    }
}
