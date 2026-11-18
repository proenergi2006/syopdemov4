<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PettyCashAttachment extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_attachments';

    protected $fillable = [
        'petty_cash_id',
        'attachment_type',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'filepath',
    ];

    protected $casts = [
        'petty_cash_id' => 'integer',
        'file_size' => 'integer',
    ];

    /* Lampiran yang disertakan ADH saat mengajukan. */
    public const TYPE_REQUEST = 'REQUEST';

    /* Bukti serah terima tunai kepada para pemohon. */
    public const TYPE_DISBURSEMENT = 'DISBURSEMENT';

    public function pettyCash()
    {
        return $this->belongsTo(PettyCash::class, 'petty_cash_id');
    }
}
