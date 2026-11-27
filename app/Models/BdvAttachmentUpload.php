<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu bukti yang sudah benar-benar diterima Accurate untuk satu voucher.
 *
 * Catatannya dibuat SESUDAH Accurate menjawab berhasil -- lihat keterangan
 * panjang pada migrasi 2026_11_25_090000.
 */
class BdvAttachmentUpload extends Model
{
    protected $table = 'bdv_attachment_uploads';

    protected $fillable = [
        'bdv_id',
        'document_type',
        'attachment_id',
        'accurate_id',
        'filename',
        'uploaded_at',
    ];

    protected $casts = [
        'bdv_id' => 'integer',
        'attachment_id' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function bdv(): BelongsTo
    {
        return $this->belongsTo(BankDisbursementVoucher::class, 'bdv_id');
    }
}
