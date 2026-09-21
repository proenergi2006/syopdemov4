<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClaimAttachment extends Model
{
    use HasFactory;

    protected $table = 'claim_attachments';

    /*
    |--------------------------------------------------------------------------
    | Jenis lampiran
    |--------------------------------------------------------------------------
    | REQUEST : bukti pengeluaran yang diunggah pemohon, melekat pada baris.
    | PAYMENT : bukti transfer yang dilampirkan Finance saat mengganti uangnya,
    |           melekat pada dokumen dan tidak menunjuk baris mana pun.
    |
    | Dibedakan supaya bukti transfer tidak ikut terhapus ketika pemohon
    | menyunting lampiran draft-nya.
    |--------------------------------------------------------------------------
    */
    public const TYPE_REQUEST = 'REQUEST';
    public const TYPE_RECEIPT = 'RECEIPT';
    public const TYPE_PAYMENT = 'PAYMENT';

    protected $fillable = [
        'claim_id',
        'claim_item_id',
        'attachment_type',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'filepath',
    ];

    protected $casts = [
        'claim_id' => 'integer',
        'claim_item_id' => 'integer',
        'file_size' => 'integer',
    ];

    public function claim()
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }

    /**
     * Baris rincian pemilik berkas ini. Kosong untuk bukti pembayaran.
     */
    public function item()
    {
        return $this->belongsTo(ClaimItem::class, 'claim_item_id');
    }
}
