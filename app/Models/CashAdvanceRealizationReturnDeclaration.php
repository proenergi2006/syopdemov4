<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Satu percobaan pengembalian sisa dana
|--------------------------------------------------------------------------
| Pemohon menyatakan sudah mentransfer sisa dananya dan melampirkan buktinya;
| Finance memeriksa lalu menerima atau menolaknya. Yang ditolak melahirkan
| percobaan berikutnya, bukan menimpa yang sebelumnya.
|
| Barisnya TIDAK PERNAH DIHAPUS dan tidak pernah ditulis ulang isinya selain
| oleh pemeriksaan yang menutupnya. Di situlah seluruh gunanya: pemohon yang
| ditolak perlu membaca alasannya, dan yang memeriksa kelak perlu melihat
| bahwa dokumen ini sempat ditolak sebelum akhirnya diterima.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationReturnDeclaration extends Model
{
    use HasFactory;

    protected $table = 'cash_advance_realization_return_declarations';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'cash_advance_realization_id',
        'attempt',
        'declared_by',
        'declared_at',
        'declared_amount',
        'declaration_notes',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'cash_advance_realization_id' => 'integer',
        'attempt' => 'integer',
        'declared_by' => 'integer',
        'declared_at' => 'datetime',
        'declared_amount' => 'decimal:2',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function realization()
    {
        return $this->belongsTo(
            CashAdvanceRealization::class,
            'cash_advance_realization_id',
        );
    }

    /** Yang menyatakan sudah mengembalikan: pemohon dokumennya. */
    public function declarer()
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    /** Yang memeriksa pernyataannya: Finance. */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Bukti transfer yang diunggah pada percobaan INI.
     *
     * Tanpa relasi ini, bukti yang ditolak dan bukti yang diterima berbaur
     * dalam satu daftar pada dokumennya, dan yang membaca tidak punya cara
     * membedakannya.
     */
    public function attachments()
    {
        return $this->hasMany(
            CashAdvanceRealizationAttachment::class,
            'return_declaration_id',
        );
    }
}
