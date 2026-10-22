<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/*
|--------------------------------------------------------------------------
| Satu kali revisi dokumen
|--------------------------------------------------------------------------
| Dipakai FPU, Realisasi, Claim, dan Perdin lewat relasi morph.
|
| Umur satu baris di sini ada dua tahap:
|
|   1. Dibuat saat dokumennya dikembalikan. resubmitted_at masih kosong, dan
|      selama kosong itulah dokumennya berstatus "Butuh Revisi". Baris inilah
|      yang dibaca layar pemohon untuk menampilkan alasannya.
|
|   2. Ditutup saat dokumennya diajukan ulang: snapshot sesudahnya, daftar
|      perubahannya, dan siapa yang mengajukannya ditulis sekaligus.
|
| Karena itu "sedang butuh revisi" tidak pernah perlu disimpan dua kali: ia
| adalah baris terbuka, bukan kolom penanda yang bisa berbeda dari statusnya.
|--------------------------------------------------------------------------
*/
class FundRequestRevision extends Model
{
    protected $table = 'fund_request_revisions';

    protected $fillable = [
        'document_type',
        'document_id',
        'revision_number',

        'returned_by',
        'returned_at',
        'returned_from_status',
        'returned_step_order',
        'returned_step_label',
        'return_reason',

        'snapshot_before',
        'approvals_before',

        'revised_at',

        'resubmitted_by',
        'resubmitted_at',
        'snapshot_after',
        'changes',
    ];

    protected $casts = [
        'document_id' => 'integer',
        'revision_number' => 'integer',

        'returned_by' => 'integer',
        'returned_at' => 'datetime',
        'returned_step_order' => 'integer',

        'snapshot_before' => 'array',
        'approvals_before' => 'array',

        'revised_at' => 'datetime',

        'resubmitted_by' => 'integer',
        'resubmitted_at' => 'datetime',
        'snapshot_after' => 'array',
        'changes' => 'array',
    ];

    /*
    |--------------------------------------------------------------------------
    | Asal pengembalian
    |--------------------------------------------------------------------------
    | Dua pintu, dan keduanya ditampilkan berbeda kepada pemohon: yang satu
    | datang dari approver yang belum menyetujui, yang lain dari Finance yang
    | berkasnya sudah ada di meja.
    |--------------------------------------------------------------------------
    */

    public const FROM_APPROVAL = 'IN PROGRESS';
    public const FROM_RECEIPT = 'RECEIVED';

    public function document(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'document_type', 'document_id');
    }

    public function returnedBy()
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function resubmittedBy()
    {
        return $this->belongsTo(User::class, 'resubmitted_by');
    }

    /** Masih menunggu pemohon mengajukannya ulang. */
    public function getIsOpenAttribute(): bool
    {
        return $this->resubmitted_at === null;
    }

    /** Dikembalikan Finance, bukan oleh approver di tengah alur. */
    public function getIsFromReceiptAttribute(): bool
    {
        return $this->returned_from_status === self::FROM_RECEIPT;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeOpen($query)
    {
        return $query->whereNull('resubmitted_at');
    }

    public function scopeForDocument($query, Model $document)
    {
        return $query
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey());
    }
}
