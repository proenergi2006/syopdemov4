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
        'date',
        'description',
        'amount',
    ];

    protected $casts = [
        'claim_id' => 'integer',
        'date' => 'date',
        'amount' => 'decimal:2',
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
