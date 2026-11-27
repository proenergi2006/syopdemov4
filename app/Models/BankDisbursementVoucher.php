<?php

namespace App\Models;

use App\Models\Concerns\HasRevisions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/**
 * Bank Disbursement Voucher.
 *
 * Dokumen pencairan lewat bank. Selalu mengangkat satu dokumen sumber yang
 * sudah disetujui penuh dan berkasnya sudah diterima Finance -- untuk sekarang
 * FPU atau Claim, lihat config/bdv.php.
 *
 * Dokumen sumbernya ditunjuk oleh pasangan (document_type, source_id), bukan
 * relasi Eloquent biasa, karena jenisnya memang dirancang untuk bertambah.
 * Yang tahu cara membacanya adalah BdvSourceRegistry.
 */
class BankDisbursementVoucher extends Model
{
    use HasFactory, HasRevisions, SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_IN_PROGRESS = 'IN PROGRESS';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_NEED_REVISION = 'NEED REVISION';

    /** Urutan untuk pilihan penyaring di daftar. */
    public const SEMUA_STATUS = [
        self::STATUS_DRAFT,
        self::STATUS_IN_PROGRESS,
        self::STATUS_NEED_REVISION,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'bank_disbursement_vouchers';

    protected $fillable = [
        'bdv_number',
        'date',
        /*
        | Dokumen sumbernya TIDAK lagi di sini. Satu voucher bisa mencairkan
        | beberapa FPU/Claim sekaligus, dan daftarnya ada di bdv_sources --
        | lihat relasi sources() di bawah.
        */
        'branch',
        'department_id',
        'transaction_category_id',
        'total_amount',

        /*
        | Kas/bank tempat uangnya keluar. Yang disimpan NOMOR akun Accurate,
        | bukan id internalnya -- lihat keterangan panjang pada migrasi
        | 2026_11_23_090000. Namanya ikut disalin supaya voucher lama tetap
        | menyebut akun yang dulu dipilih walau Accurate sedang tidak bisa
        | dihubungi.
        */
        'cash_bank_account_no',
        'cash_bank_account_name',

        'check_number',
        'payee',

        'notes',
        'status',

        /*
        | Berapa kali voucher ini pernah dikembalikan untuk direvisi.
        | Ditulis DocumentRevisionService, bukan oleh formulir mana pun --
        | lihat keterangan pada trait HasRevisions.
        */
        'revision_count',

        'created_by',
        'updated_by',
        'submitted_by',
        'submitted_at',
        'final_approved_by',
        'final_approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_notes',
        'cancelled_by',
        'cancelled_at',
        'cancellation_notes',

        /*
        | Jejak pengiriman ke Accurate. accurate_id terisi berarti dokumennya
        | SUDAH masuk, dan itu pula yang menahan pengiriman kedua.
        */
        'accurate_id',
        'accurate_number',
        'accurate_sent_at',

        /*
        | Apakah ISI TERAKHIR sudah sampai Accurate -- berbeda dari
        | accurate_id, yang hanya menjawab "dokumennya pernah terbentuk di
        | sana". Lihat migrasi 2026_11_24_110000.
        */
        'accurate_in_sync',
    ];

    protected $casts = [
        'date' => 'date',

        'department_id' => 'integer',
        'transaction_category_id' => 'integer',
        'revision_count' => 'integer',
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'final_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'accurate_sent_at' => 'datetime',
        'accurate_in_sync' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    | Nama relasinya sengaja disamakan dengan FPU dan Claim -- branchData,
    | departmentData, creator -- supaya kode yang menampilkan ketiga dokumen
    | ini tidak perlu mengingat perbedaan nama.
    |--------------------------------------------------------------------------
    */
    public function branchData(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'branch', 'id');
    }

    public function departmentData(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(
            FundRequestTransactionCategory::class,
            'transaction_category_id',
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Rincian voucher: salinan baris dokumen sumber beserta akun Accurate-nya.
     *
     * Diurutkan menurut nomor baris dokumen asalnya, bukan menurut urutan
     * penyimpanan -- itu yang membuat cetakannya sama dengan dokumen yang
     * dicairkannya.
     */
    public function items(): HasMany
    {
        return $this->hasMany(BdvItem::class, 'bdv_id')
            ->whereNull('removed_at')
            ->orderBy('line_no');
    }

    /**
     * Baris yang sudah hilang dari dokumen sumber tetapi belum dihapus di
     * Accurate.
     *
     * Dipisahkan dari items() dengan sengaja: ia tidak boleh ikut terhitung,
     * tertampil, atau terjumlah di mana pun. Satu-satunya yang berkepentingan
     * adalah pengiriman ke Accurate, yang memakainya untuk menitipkan
     * _status "delete". Lihat migrasi 2026_11_23_120000.
     */
    public function itemsDihapus(): HasMany
    {
        return $this->hasMany(BdvItem::class, 'bdv_id')
            ->whereNotNull('removed_at')
            ->orderBy('line_no');
    }

    /**
     * Dokumen sumber yang dicairkan voucher ini, terurut sebagaimana dipilih.
     *
     * Menyaring yang bernisan: sumber yang sudah dilepas (released_at terisi)
     * milik voucher yang dihapus atau dibatalkan, dan dokumennya sudah bebas
     * diangkat voucher lain. Ia tidak boleh ikut terhitung di mana pun.
     */
    public function sources(): HasMany
    {
        return $this->hasMany(BdvSource::class, 'bdv_id')
            ->whereNull('released_at')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Rantai approval, terurut sebagaimana ia dijalani.
     *
     * Urutannya step_order lalu id, bukan menurut waktu disetujui: tahap
     * yang dilewati maupun yang dibatalkan tidak punya waktu, dan riwayat
     * yang diurutkan menurut waktu akan menyembunyikan keduanya di ujung.
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(BdvApproval::class, 'bdv_id')
            ->orderBy('step_order')
            ->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Potret untuk riwayat revisi
    |--------------------------------------------------------------------------
    | Dua penyesuaian atas bawaan HasRevisions, keduanya karena BDV tidak
    | berbentuk seperti FPU dan Claim.
    |--------------------------------------------------------------------------
    */

    /**
     * Anak yang ikut dipotret.
     *
     * Bawaannya items + attachments. BDV tidak punya lampiran sendiri --
     * buktinya menempel pada dokumen sumbernya -- dan sebagai gantinya
     * punya DAFTAR DOKUMEN yang dicairkan. Justru daftar itulah yang paling
     * sering menjadi sebab sebuah voucher dikembalikan, jadi ia harus ikut
     * terekam.
     *
     * @return array<int, string>
     */
    public function revisionSnapshotRelations(): array
    {
        return ['sources', 'items'];
    }

    /**
     * Kolom yang dibuang dari potret, di luar aturan bersama.
     *
     * Seluruhnya jejak pembukuan Accurate dan penandaan internal. Namanya
     * tidak mengikuti satu pun pola <peristiwa>_by/_at/_notes yang dikenali
     * trait-nya, jadi harus disebut di sini -- kalau tidak, daftar perubahan
     * tiap revisi akan dipenuhi baris seperti "accurate_in_sync: true ->
     * false", yang berubah karena pengiriman ke Accurate, bukan karena
     * pemohon mengetik sesuatu.
     *
     * @return array<int, string>
     */
    public function revisionSnapshotSkip(): array
    {
        return [
            'accurate_id',
            'accurate_number',
            'accurate_sent_at',
            'accurate_in_sync',
            'accurate_detail_id',

            /*
            | Nisan dua tabel anak: baris rincian yang sudah hilang dari
            | dokumen sumber, dan dokumen sumber yang dikeluarkan dari
            | voucher. Keduanya ditandai, bukan dihapus, demi pembukuan
            | Accurate -- dan bukan isi yang dinilai approver.
            */
            'removed_at',
            'released_at',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Area untuk pencocokan approval flow
    |--------------------------------------------------------------------------
    | HO untuk kantor pusat, CABANG untuk selainnya -- persis seperti FPU dan
    | Claim, dan memang harus persis: satu matriks approval yang sama diisi
    | admin untuk ketiganya, dan kata yang berbeda artinya di satu modul
    | adalah jebakan yang baru ketahuan setelah dokumen tersangkut.
    |--------------------------------------------------------------------------
    */
    public const HO_BRANCH_ID = 1;

    public function getApprovalAreaType(): string
    {
        return (string) $this->branch === (string) self::HO_BRANCH_ID
            ? 'HO'
            : 'CABANG';
    }

    /*
    |--------------------------------------------------------------------------
    | Id yang aman ditampilkan di URL
    |--------------------------------------------------------------------------
    | Sama seperti modul lain: id asli tidak pernah muncul di alamat halaman.
    |--------------------------------------------------------------------------
    */
    public function getEncryptedIdAttribute(): string
    {
        return Crypt::encryptString((string) $this->id);
    }
}
