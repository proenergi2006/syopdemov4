<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
|--------------------------------------------------------------------------
| Satu dokumen sumber yang dicairkan sebuah BDV
|--------------------------------------------------------------------------
| Satu voucher bisa mencairkan beberapa FPU/Claim sekaligus; tiap dokumen
| menjadi satu baris di sini, dan rincian itemnya menggantung padanya.
|
| ISINYA SALINAN BEKU. branch, department, keterangan transaksi, dan nominal
| disalin pada saat dokumennya dipilih -- bukan dibaca ulang dari dokumennya
| setiap kali ditampilkan. Dokumen sumber masih bisa direvisi Finance
| sesudahnya, dan voucher yang sudah diajukan tidak boleh berubah diam-diam
| di bawah tanda tangan orang yang menyetujuinya.
|
| released_at adalah NISAN: terisi berarti voucher yang memegangnya sudah
| dihapus atau dibatalkan, sehingga dokumennya bebas diangkat voucher lain.
| Jejaknya sengaja tidak ikut dibuang. Lihat migrasi 2026_11_24_120000.
|--------------------------------------------------------------------------
*/
class BdvSource extends Model
{
    use HasFactory;

    protected $table = 'bdv_sources';

    protected $fillable = [
        'bdv_id',
        'document_type',
        'source_id',
        'source_number',
        'branch',
        'department_id',
        'transaction_category_id',
        'total_amount',
        'sort_order',
        'released_at',
    ];

    protected $casts = [
        'bdv_id' => 'integer',
        'source_id' => 'integer',
        'department_id' => 'integer',
        'transaction_category_id' => 'integer',
        'total_amount' => 'decimal:2',
        'sort_order' => 'integer',
        'released_at' => 'datetime',
    ];

    public function bdv(): BelongsTo
    {
        return $this->belongsTo(BankDisbursementVoucher::class, 'bdv_id');
    }

    /**
     * Rincian biaya milik dokumen ini.
     *
     * Menyaring yang bernisan, sama seperti BankDisbursementVoucher::items().
     * Baris bernisan hanya menunggu dihapus di Accurate dan tidak boleh ikut
     * terhitung, tertampil, atau terjumlah di mana pun.
     */
    public function items(): HasMany
    {
        return $this->hasMany(BdvItem::class, 'bdv_source_id')
            ->whereNull('removed_at')
            ->orderBy('line_no');
    }
}
