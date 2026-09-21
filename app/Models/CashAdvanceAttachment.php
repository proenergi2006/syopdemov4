<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashAdvanceAttachment extends Model
{
    use HasFactory;

    protected $table = 'cash_advance_attachments';

    /*
    |--------------------------------------------------------------------------
    | Jenis lampiran
    |--------------------------------------------------------------------------
    | REQUEST      : berkas pendukung yang diunggah pemohon saat mengajukan.
    | DISBURSEMENT : bukti transfer yang dilampirkan Finance saat mencairkan.
    |
    | Dibedakan supaya bukti transfer tidak ikut terhapus ketika pemohon
    | menyunting lampiran draft-nya.
    |--------------------------------------------------------------------------
    */
    public const TYPE_REQUEST = 'REQUEST';
    /* Berkas yang menyertai penandaan dokumen sudah diterima PIC. */
    public const TYPE_RECEIPT = 'RECEIPT';

    public const TYPE_DISBURSEMENT = 'DISBURSEMENT';

    protected $fillable = [
        'cash_advance_id',
        'cash_advance_item_id',
        'attachment_type',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'filepath',
    ];

    protected $casts = [
        'cash_advance_id' => 'integer',
        'cash_advance_item_id' => 'integer',
        'file_size' => 'integer',
    ];

    public function cashAdvance()
    {
        return $this->belongsTo(
            CashAdvance::class,
            'cash_advance_id',
        );
    }

    /**
     * Baris rincian pemilik berkas ini.
     *
     * Kosong untuk bukti transfer dan lampiran lama yang dibuat sebelum
     * lampiran dipindah ke tingkat baris.
     */
    public function item()
    {
        return $this->belongsTo(
            CashAdvanceItem::class,
            'cash_advance_item_id',
        );
    }
}
