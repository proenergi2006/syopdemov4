<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
| Penanda bahwa sebuah kategori biaya diurus GA, bukan ditagihkan pemohon.
|
| Satu baris = satu kategori pada satu dokumen. Ketiadaan baris berarti
| kategorinya memang ditagihkan; adanya baris berarti kosongnya disengaja.
| Perbedaan itulah yang tidak bisa dibaca dari rincian yang sekadar kosong.
*/
class FundRequestArrangedCategory extends Model
{
    use HasFactory;

    protected $table = 'fund_request_arranged_categories';

    public const DOC_CASH_ADVANCE = 'FPU';
    public const DOC_REALIZATION = 'REALISASI';

    protected $fillable = [
        'document_type',
        'document_id',
        'expense_category_id',
    ];

    protected $casts = [
        'document_id' => 'integer',
        'expense_category_id' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(
            BusinessTripExpenseCategory::class,
            'expense_category_id',
        );
    }

    public function scopeFor($query, string $documentType, int $documentId)
    {
        return $query
            ->where('document_type', $documentType)
            ->where('document_id', $documentId);
    }
}
