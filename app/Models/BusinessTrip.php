<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/*
|--------------------------------------------------------------------------
| Perjalanan Dinas (Perdin)
|--------------------------------------------------------------------------
| Kepala formulir. Rundown-nya ada di BusinessTripItinerary.
|
| Nama, department, dan jabatan disalin ke sini saat dokumen dibuat -- lihat
| alasannya pada migrasi. Relasi ke akunnya tetap ada untuk penelusuran, tetapi
| yang dicetak adalah salinannya.
|--------------------------------------------------------------------------
*/
class BusinessTrip extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'business_trips';

    /*
    | Kosakata statusnya sama persis dengan Claim dan FPU. Perdin tidak punya
    | tahap pembayaran -- perjalanan disetujui, bukan dibayar; dananya diurus
    | FPU yang menautkan diri padanya.
    */
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_IN_PROGRESS = 'IN PROGRESS';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'trip_number',
        'status',
        'submitted_by',
        'submitted_at',
        'requester_signed_by',
        'requester_signature_path',
        'requester_signed_at',
        'final_approved_by',
        'final_approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_notes',
        'cancelled_by',
        'cancelled_at',
        'cancellation_notes',
        'date',
        'user_id',
        'employee_name',
        'department_id',
        'department_name',
        'position_name',
        'branch',
        'destination',
        'depart_date',
        'depart_time',
        'return_date',
        'return_time',
        'purpose',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'user_id' => 'integer',
        'department_id' => 'integer',
        'depart_date' => 'date',
        'return_date' => 'date',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'submitted_at' => 'datetime',
        'requester_signed_at' => 'datetime',
        'final_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /*
    | Batas mengajukan perdin supaya FPU-nya boleh berjalan paralel: tujuh hari
    | kalender sebelum berangkat, batasnya sendiri ikut terhitung tepat waktu.
    |
    | Hari kalender, bukan hari kerja -- sama dengan batas pengajuan FPU,
    | supaya satu cara berhitung dipakai di seluruh aplikasi.
    */
    public const PARALLEL_DAYS = 7;

    /**
     * Diajukan tepat waktu: tujuh hari atau lebih sebelum berangkat.
     *
     * Dihitung dari dua kolom yang sudah tersimpan, bukan disimpan sendiri --
     * keduanya tidak bisa berubah setelah dokumen diajukan, jadi hasilnya
     * tetap sama kapan pun ditanyakan. Kolom tambahan hanya akan jadi salinan
     * yang bisa berbeda dari sumbernya.
     */
    public function getIsOnTimeAttribute(): bool
    {
        if (!$this->submitted_at || !$this->depart_date) {
            return false;
        }

        return $this->submitted_at->startOfDay()
            ->diffInDays($this->depart_date->startOfDay(), false) >= self::PARALLEL_DAYS;
    }

    /**
     * Boleh dijadikan dasar FPU walau persetujuannya belum selesai.
     *
     * Yang sudah disetujui selalu boleh. Yang masih berjalan hanya boleh bila
     * diajukan tepat waktu -- itulah keringanan yang diberikan kepada yang
     * tidak menunda.
     */
    public function getAllowsParallelCashAdvanceAttribute(): bool
    {
        $status = strtoupper((string) $this->status);

        if ($status === self::STATUS_APPROVED) {
            return true;
        }

        return $status === self::STATUS_IN_PROGRESS && $this->is_on_time;
    }

    /** Cabang pusat, penentu apakah dokumen ini masuk matriks HO atau CABANG. */
    public const HO_BRANCH_ID = 1;

    /**
     * Area untuk pencocokan approval flow.
     *
     * Bentuknya ditiru dari Claim supaya satu matriks yang sama bisa dibaca
     * dengan cara yang sama di semua modul.
     */
    public function getApprovalAreaType(): string
    {
        return (string) $this->branch === (string) self::HO_BRANCH_ID
            ? 'HO'
            : 'CABANG';
    }

    /**
     * Cabang disimpan sebagai teks berisi id -- mengikuti cash_advances.
     */
    public function branchData()
    {
        return $this->belongsTo(Cabang::class, 'branch', 'id');
    }

    /** Dinamai departmentData mengikuti Claim, supaya generatornya sebentuk. */
    public function departmentData()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function approvals()
    {
        return $this->hasMany(
            BusinessTripApproval::class,
            'business_trip_id',
        )->orderBy('step_order')->orderBy('id');
    }

    /**
     * FPU yang mendasarkan diri pada perdin ini.
     *
     * Jamak, bukan tunggal: satu perdin boleh punya beberapa FPU sepanjang
     * waktu -- yang ditolak, yang dibatalkan, lalu yang berjalan. Yang dilarang
     * hanya dua FPU hidup sekaligus.
     */
    public function cashAdvances()
    {
        return $this->hasMany(CashAdvance::class, 'business_trip_id');
    }

    /**
     * Id terenkripsi, dipakai di URL dan notifikasi.
     *
     * Dinamai sama dengan modul lain supaya notifikasi dan email bisa membaca
     * dokumen mana pun dengan cara yang sama.
     */
    public function getEncryptedIdAttribute(): string
    {
        return Crypt::encryptString((string) $this->id);
    }

    /** Masih bisa disunting pemohonnya. */
    public function getIsEditableAttribute(): bool
    {
        return in_array(
            $this->status,
            [self::STATUS_DRAFT, self::STATUS_REJECTED],
            true,
        );
    }

    public function itineraries()
    {
        return $this->hasMany(
            BusinessTripItinerary::class,
            'business_trip_id',
        )->orderBy('sort_no');
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * Lama perjalanan dalam hari kalender, berangkat dan pulang ikut dihitung.
     *
     * Satu hari pergi-pulang bernilai 1, bukan 0 -- itu tetap sehari perjalanan.
     */
    public function getDurationDaysAttribute(): int
    {
        if (!$this->depart_date || !$this->return_date) {
            return 0;
        }

        return $this->depart_date->diffInDays($this->return_date) + 1;
    }
}
