<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu slot tanda tangan digital milik seorang user.
 *
 * Satu user boleh menyimpan beberapa tanda tangan, tetapi hanya satu yang
 * aktif. Yang aktif itulah yang dicap ke dokumen saat ia mengajukan atau
 * menyetujui; dokumen yang sudah tercap tidak ikut berubah ketika ia
 * berganti tanda tangan, karena dokumen menyimpan jalur filenya sendiri dan
 * file lama tidak pernah ditimpa.
 */
class UserSignature extends Model
{
    use SoftDeletes;

    /** Batas slot per user. */
    public const MAKS_PER_USER = 3;

    public const SOURCE_DRAW = 'draw';
    public const SOURCE_UPLOAD = 'upload';

    protected $table = 'user_signatures';

    protected $fillable = [
        'user_id',
        'label',
        'file_path',
        'source',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->file_path
            ? asset('storage/' . $this->file_path)
            : null;
    }
}
