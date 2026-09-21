<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
| Bukti pengeluaran yang dilampirkan pada Realisasi FPU.
| Validasi filenya sama dengan lampiran PR dan FPU.
*/
class CashAdvanceRealizationAttachment extends Model
{
    use HasFactory;

    protected $table = 'cash_advance_realization_attachments';

    /*
    |--------------------------------------------------------------------------
    | Jenis lampiran
    |--------------------------------------------------------------------------
    | REQUEST    : bukti pengeluaran yang diunggah pemohon saat merealisasi.
    | SETTLEMENT : bukti penyelesaian selisih dari Finance saat menutup dokumen.
    |
    | Dibedakan supaya bukti penyelesaian tidak ikut terhapus ketika pemohon
    | menyunting lampiran draft-nya.
    |--------------------------------------------------------------------------
    */
    public const TYPE_REQUEST = 'REQUEST';
    public const TYPE_RECEIPT = 'RECEIPT';
    public const TYPE_SETTLEMENT = 'SETTLEMENT';

    protected $fillable = [
        'cash_advance_realization_id',
        'cash_advance_realization_item_id',
        'attachment_type',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'filepath',
    ];

    protected $casts = [
        'cash_advance_realization_id' => 'integer',
        'cash_advance_realization_item_id' => 'integer',
        'file_size' => 'integer',
    ];

    public function realization()
    {
        return $this->belongsTo(
            CashAdvanceRealization::class,
            'cash_advance_realization_id',
        );
    }

    /**
     * Baris rincian pemilik berkas ini.
     *
     * Kosong untuk bukti penyelesaian selisih dan lampiran lama yang dibuat
     * sebelum lampiran dipindah ke tingkat baris.
     */
    public function item()
    {
        return $this->belongsTo(
            CashAdvanceRealizationItem::class,
            'cash_advance_realization_item_id',
        );
    }
}
