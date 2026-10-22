<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Master jadwal pembayaran Finance
|--------------------------------------------------------------------------
| Satu jadwal = sekumpulan batas setor yang berlaku bersama, beserta
| cakupannya. Lihat migrasi 2026_09_12_090000 untuk penjelasan bentuknya.
|--------------------------------------------------------------------------
*/
class PaymentSchedule extends Model
{
    use HasFactory;

    protected $table = 'payment_schedules';

    protected $fillable = [
        'name',
        'document_type',
        'transaction_category_id',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'transaction_category_id' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Modul yang bisa dicakup sebuah jadwal.
     *
     * Sengaja tidak diambil dari daftar approval flow: yang itu memuat PR, PO,
     * dan Vendor -- dokumen yang tidak melewati meja pembayaran Finance sama
     * sekali. Menawarkannya di sini hanya akan membuat orang membuat jadwal
     * yang tidak pernah terpakai.
     *
     * Yang disimpan hanya kodenya; labelnya diambil dari berkas bahasa supaya
     * layar berbahasa Inggris tidak menerima istilah Indonesia.
     */
    public const DOCUMENT_TYPES = ['FPU', 'REALISASI', 'CLAIM'];

    /*
    |----------------------------------------------------------------------
    | Modul mana memakai daftar keterangan transaksi milik siapa
    |----------------------------------------------------------------------
    | Master keterangan transaksi menampung dua daftar pada satu tabel,
    | dipisah kolom document_type-nya: 'ADVANCE' untuk FPU, 'CLAIM' untuk
    | Claim.
    |
    | Realisasi TIDAK punya daftar sendiri -- keterangan transaksinya
    | disalin dari FPU yang direalisasikan, jadi yang boleh ditunjuk di
    | sini pun daftar FPU. Memberinya daftar sendiri berarti menjanjikan
    | cakupan yang tidak akan pernah cocok dengan dokumen mana pun.
    |----------------------------------------------------------------------
    */
    /*
    | Petanya tinggal di FundRequestTransactionCategory -- pemilik daftarnya
    | -- karena master approval flow menanyakan hal yang sama persis.
    | Tetap dipajang di sini supaya yang sudah membacanya tidak perlu tahu
    | ia pindah.
    */
    public const KATEGORI_MODUL = FundRequestTransactionCategory::DOCUMENT_TYPE_BY_MODULE;

    /**
     * Daftar keterangan transaksi milik siapa yang boleh dipakai modul ini.
     *
     * null untuk jadwal tanpa modul: cakupannya seluruh modul, dan tidak
     * ada satu daftar pun yang bisa mewakili semuanya.
     */
    public static function kategoriModul(?string $documentType): ?string
    {
        if ($documentType === null) {
            return null;
        }

        return FundRequestTransactionCategory::documentTypeForModule($documentType);
    }

    /**
     * @return array<string, string>
     */
    public static function documentTypeOptions(): array
    {
        $label = (array) __('payment_schedule_messages.document_types');

        return collect(self::DOCUMENT_TYPES)
            ->mapWithKeys(fn (string $kode): array => [$kode => $label[$kode] ?? $kode])
            ->all();
    }

    public static function documentTypeLabel(?string $kode): ?string
    {
        if ($kode === null) {
            return null;
        }

        return self::documentTypeOptions()[$kode] ?? $kode;
    }

    /**
     * Nama hari dalam penomoran ISO: 1 = Senin ... 7 = Minggu.
     *
     * Dibaca dari berkas bahasa, bukan ditanam sebagai konstanta -- layar,
     * email, dan notifikasi semuanya lewat sini, jadi semuanya menyebut hari
     * yang sama dengan istilah yang sama, dalam bahasa pembacanya.
     */
    public static function namaHari(?int $iso, ?string $locale = null): string
    {
        if ($iso === null) {
            return '-';
        }

        $daftar = (array) __('payment_schedule_messages.days', [], $locale);

        return $daftar[$iso] ?? '-';
    }

    /**
     * Seberapa khusus cakupan jadwal ini.
     *
     * Dipakai memilih jadwal mana yang menang saat sebuah dokumen cocok ke
     * lebih dari satu. Keterangan transaksi diberi bobot lebih besar daripada
     * modul karena keterangan itulah yang menentukan sifat pembayarannya --
     * Perdin tetap Perdin, diajukan lewat FPU maupun Claim.
     */
    public function getScopeWeightAttribute(): int
    {
        return ($this->transaction_category_id !== null ? 2 : 0)
            + ($this->document_type !== null ? 1 : 0);
    }

    public function cutoffs()
    {
        return $this->hasMany(PaymentScheduleCutoff::class, 'payment_schedule_id')
            ->orderBy('cutoff_day')
            ->orderBy('cutoff_time');
    }

    public function transactionCategory()
    {
        return $this->belongsTo(
            FundRequestTransactionCategory::class,
            'transaction_category_id',
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
