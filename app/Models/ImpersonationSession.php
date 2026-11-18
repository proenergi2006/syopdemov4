<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan satu sesi penyamaran: super admin masuk ke akun user lain.
 *
 * Baris ini bukan pelengkap, tapi penanggung beban utama fitur ini. Dua
 * perannya:
 *
 * 1. IZIN KEMBALI. token_id di baris yang masih terbuka adalah satu-satunya
 *    dasar yang membolehkan sebuah token ditukar menjadi token admin.
 * 2. JEJAK. Karena penyamaran ini penuh tanpa batas aksi dan user yang
 *    disamari tidak diberi tahu, baris inilah -- bersama entri activity_log
 *    yang menunjuk ke id-nya -- satu-satunya bukti apa yang terjadi.
 */
class ImpersonationSession extends Model
{
    public const ENDED_MANUAL = 'manual';
    public const ENDED_LOGOUT = 'logout';
    public const ENDED_EXPIRED = 'expired';

    protected $table = 'impersonation_sessions';

    protected $fillable = [
        'admin_user_id',
        'target_user_id',
        'token_id',
        'reason',
        'ip',
        'user_agent',
        'started_at',
        'ended_at',
        'ended_reason',
    ];

    protected $casts = [
        'token_id' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function sedangBerjalan(): bool
    {
        return $this->ended_at === null;
    }
}
