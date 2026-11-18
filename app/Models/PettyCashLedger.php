<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Buku mutasi kas kecil
|--------------------------------------------------------------------------
| Satu baris per pergerakan uang. Baris tidak pernah diubah dan tidak pernah
| dihapus -- pembatalan pun dicatat sebagai baris baru. Itulah sebabnya tidak
| ada SoftDeletes di sini: yang sudah terjadi tidak bisa jadi tidak terjadi.
|
| Nominalnya bertanda, jadi saldo = SUM(amount) tanpa syarat apa pun.
|--------------------------------------------------------------------------
*/
class PettyCashLedger extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_ledgers';

    protected $fillable = [
        'petty_cash_ceiling_id',
        'branch_id',
        'entry_type',
        'amount',
        'reference_type',
        'reference_id',
        'notes',
        'balance_after',
        'created_by',
    ];

    protected $casts = [
        'petty_cash_ceiling_id' => 'integer',
        'branch_id' => 'integer',
        'reference_id' => 'integer',
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    /* Saldo awal saat plafon dibuat. */
    public const ENTRY_OPENING = 'OPENING';

    /* Petty cash dicairkan ADH -- uang keluar dari kas. */
    public const ENTRY_DISBURSEMENT = 'DISBURSEMENT';

    /* Pencairan dibatalkan, uangnya kembali ke kas. */
    public const ENTRY_REVERSAL = 'REVERSAL';

    /* Pengisian kembali disetujui. */
    public const ENTRY_TOPUP = 'TOPUP';

    /* Plafon diubah di master, atau koreksi manual. */
    public const ENTRY_ADJUSTMENT = 'ADJUSTMENT';

    public const REFERENCE_PETTY_CASH = 'PETTY_CASH';
    public const REFERENCE_TOPUP = 'PETTY_CASH_TOPUP';
    public const REFERENCE_CEILING = 'PETTY_CASH_CEILING';

    public function ceiling()
    {
        return $this->belongsTo(PettyCashCeiling::class, 'petty_cash_ceiling_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
