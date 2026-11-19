<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Satu peristiwa pada proses Finance sebuah Realisasi
|--------------------------------------------------------------------------
| Tahap Finance hanya punya tiga status, supaya yang mengerjakannya tidak
| perlu menghafal lima nama. Tiga status itu menjawab "sekarang di mana";
| baris-baris di sini yang menjawab "kapan, oleh siapa, dan apa katanya".
|
| Tidak pernah ditimpa. Penerimaan yang ditarik lalu diterima lagi
| meninggalkan TIGA baris -- diterima, ditarik, diterima lagi -- bukan satu
| baris yang berubah dua kali.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationFinanceLog extends Model
{
    use HasFactory;

    protected $table = 'cash_advance_realization_finance_logs';

    /** Finance menerima berkasnya. */
    public const EVENT_RECEIVED = 'RECEIVED';

    /** Penerimaannya ditarik kembali. */
    public const EVENT_UNRECEIVED = 'UNRECEIVED';

    /** Pemohon menyatakan sisa dananya sudah dikembalikan. */
    public const EVENT_RETURN_DECLARED = 'RETURN_DECLARED';

    /** Finance menolak bukti pengembaliannya. */
    public const EVENT_RETURN_REJECTED = 'RETURN_REJECTED';

    /** Finance menyetujui -- ujung ketiga cabang. */
    public const EVENT_APPROVED = 'APPROVED';

    /**
     * Diteruskan ke meja pembayar.
     *
     * Hanya pada cabang kekurangan, dan hanya SESUDAH disetujui. Tidak
     * mengubah status: dokumennya sudah Approved sejak disetujui, yang
     * berpindah hanya mejanya.
     */
    public const EVENT_FORWARDED = 'FORWARDED';

    /** Kekurangannya dibayarkan kepada pemohon. Tidak mengubah status. */
    public const EVENT_REIMBURSED = 'REIMBURSED';

    protected $fillable = [
        'cash_advance_realization_id',
        'event',
        'from_status',
        'to_status',
        'actor_id',
        'occurred_at',
        'notes',
    ];

    protected $casts = [
        'cash_advance_realization_id' => 'integer',
        'actor_id' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function realization()
    {
        return $this->belongsTo(
            CashAdvanceRealization::class,
            'cash_advance_realization_id',
        );
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
