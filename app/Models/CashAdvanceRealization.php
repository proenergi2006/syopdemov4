<?php

namespace App\Models;

use App\Models\Concerns\HasRevisions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/*
|--------------------------------------------------------------------------
| Realisasi FPU
|--------------------------------------------------------------------------
| Pertanggungjawaban atas uang yang sudah dicairkan lewat FPU. Hanya boleh
| dibuat dari FPU berstatus DISBURSED, dan satu FPU hanya punya satu
| realisasi yang hidup.
|
| SETTLED dipisahkan dari APPROVED: approval menyatakan angka realisasinya
| benar, penyelesaian menyatakan selisihnya sudah beres.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealization extends Model
{
    use HasFactory, HasRevisions, SoftDeletes;

    protected $table = 'cash_advance_realizations';

    protected $fillable = [
        'realization_number',
        'cash_advance_id',
        'date',
        'notes',

        /*
        | Disalin dari FPU induk. Ketiganya dipakai mesin approval untuk
        | memilih flow, sekaligus menjadi snapshot dokumen.
        */
        'branch',
        'department_id',
        'transaction_category_id',

        'total_advance_amount',
        'total_realization_amount',
        'difference_amount',
        'difference_type',

        'status',
        'revision_count',

        'created_by',
        'updated_by',

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

        'received_by',
        'received_at',
        'receipt_notes',

        'verified_by',
        'verified_at',
        'verification_notes',

        'return_declared_by',
        'return_declared_at',
        'return_declaration_notes',
        'finance_status',
        'receipt_reverted_by',
        'receipt_reverted_at',
        'receipt_reversal_notes',
        'scheduled_payment_date',
        'settled_by',
        'settled_at',
        'settlement_notes',
        'settlement_amount',
    ];

    protected $casts = [
        'revision_count' => 'integer',
        'date' => 'date',
        'cash_advance_id' => 'integer',
        'department_id' => 'integer',
        'transaction_category_id' => 'integer',

        'total_advance_amount' => 'decimal:2',
        'total_realization_amount' => 'decimal:2',
        'difference_amount' => 'decimal:2',
        'settlement_amount' => 'decimal:2',

        'submitted_at' => 'datetime',
        'requester_signed_at' => 'datetime',
        'final_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'received_at' => 'datetime',
        'verified_at' => 'datetime',
        'return_declared_at' => 'datetime',
        'receipt_reverted_at' => 'datetime',
        'scheduled_payment_date' => 'date',
        'settled_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_IN_PROGRESS = 'IN PROGRESS';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /*
    | Dikembalikan approver ke pemohon untuk diperbaiki.
    |
    | Statusnya HIDUP, dan justru di situ ia berbeda dari REJECTED yang
    | sudah selesai: dokumennya masih berjalan dan masih akan menjadi
    | uang, hanya sedang ada di tangan pemohonnya.
    |
    | Nomornya sengaja TIDAK dikembalikan ke DRAFT. Dokumen ini sudah
    | pernah beredar; approver yang pernah membukanya harus masih bisa
    | menemukannya dengan nomor yang sama, dan nomor yang dipakai ulang
    | adalah nomor yang menunjuk dua dokumen sekaligus.
    */
    public const STATUS_NEED_REVISION = 'NEED REVISION';
    /*
    |--------------------------------------------------------------------------
    | Status proses di Finance -- TERPISAH dari status approval di atas
    |--------------------------------------------------------------------------
    | Dahulu kelima tahap Finance ikut ditulis ke kolom status yang sama,
    | sehingga satu kolom menampung dua pertanyaan yang berbeda: sudah
    | disetujui siapa, dan sudah sampai mana di Finance. Akibatnya dokumen
    | yang sedang diperiksa Finance berhenti terbaca sebagai APPROVED --
    | padahal persetujuannya tidak pernah dicabut -- dan satu daftar saring
    | di layar harus mencampur dua belas nilai yang tidak sejenis.
    |
    | Sekarang status berhenti di APPROVED dan tidak bergerak lagi; yang
    | berjalan sesudahnya finance_status, dan nilainya hanya TIGA.
    |
    | Ketiga cabang selisih memakai ketiga nilai yang sama persis. Yang
    | membedakan mereka bukan jumlah statusnya, melainkan siapa yang
    | mengerjakan dan berapa aksi yang dibutuhkan:
    |
    |   Nilainya PAS    : Finance terima -> Finance approve
    |   Realisasi KURANG: Finance terima -> diteruskan -> pembayar membayar
    |   Realisasi LEBIH : pemohon unggah bukti -> Finance terima ->
    |                     Finance konfirmasi
    |--------------------------------------------------------------------------
    */

    /** Sudah disetujui, menunggu berkasnya diterima Finance. Merah. */
    public const FINANCE_WAITING = 'WAITING APPROVAL';

    /** Berkasnya di tangan Finance dan sedang dikerjakan. Kuning. */
    public const FINANCE_ON_CHECKING = 'ON CHECKING';

    /*
    | Proses Finance-nya tuntas. Hijau, dan inilah ujung dokumennya.
    |
    | Bernama APPROVED, bukan SETTLED atau SELESAI, dan itu permintaan yang
    | disengaja: bagi yang membacanya, "disetujui Finance" adalah kalimat
    | yang sama untuk ketiga cabang -- yang dibayarkan, yang dikembalikan,
    | maupun yang nilainya pas.
    */
    public const FINANCE_APPROVED = 'APPROVED';

    /** Ketiganya berurutan, dipakai menyusun pilihan saring di layar. */
    public const FINANCE_STATUSES = [
        self::FINANCE_WAITING,
        self::FINANCE_ON_CHECKING,
        self::FINANCE_APPROVED,
    ];

    /**
     * Status yang membuat realisasi dianggap sudah mati.
     *
     * Selama realisasinya belum berada di salah satu status ini, FPU induknya
     * masih terikat dan tidak boleh dibatalkan.
     */
    public const RELEASED_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /*
    |--------------------------------------------------------------------------
    | Kapan sebuah realisasi MELEPASKAN kuota FPU induknya
    |--------------------------------------------------------------------------
    | Satu-satunya tempat aturan ini ditulis. Tiga tempat membacanya: penjaga
    | batas pengajuan (FundRequestLimitService::outstandingQuery), dashboard
    | FPU, dan dashboard realisasi. Ketiganya WAJIB berbunyi sama -- kalau
    | berbeda, pemohon diblokir oleh angka yang tidak pernah terlihat di layar
    | mana pun.
    |
    | Aturannya (26 Nov 2026): disetujui final SAJA tidak cukup. Berkasnya
    | harus sudah sampai ke tangan Finance. Sebelumnya kuota lepas begitu
    | approver terakhir menyetujui, padahal berkas fisiknya bisa saja belum
    | diantar ke Finance sama sekali.
    |
    | Dua syarat, bukan satu:
    |   - received_at terisi -- Finance menerima berkasnya. Dikosongkan lagi
    |     oleh "Batalkan Terima", dan saat itu kuotanya MEMANG harus tertahan
    |     kembali.
    |   - ATAU finance_status sudah APPROVED -- jaring pengaman bagi dokumen
    |     yang prosesnya sudah tuntas tetapi received_at-nya kosong. Hari ini
    |     tidak ada satu pun; yang dijaga adalah dokumen yang SUDAH SELESAI
    |     jangan sampai menahan kuota lagi hanya karena satu kolom kosong.
    |--------------------------------------------------------------------------
    */

    /**
     * Menyaring kueri realisasi menjadi "yang melepaskan kuota FPU".
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $alias  Nama atau alias tabel realisasi di dalam kueri itu.
     */
    public static function saringPelepasKuota($query, string $alias = 'cash_advance_realizations'): void
    {
        $query
            ->where($alias . '.status', self::STATUS_APPROVED)
            ->where(function ($q) use ($alias): void {
                $q
                    ->whereNotNull($alias . '.received_at')
                    ->orWhere($alias . '.finance_status', self::FINANCE_APPROVED);
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Jenis selisih
    |--------------------------------------------------------------------------
    | RETURN    : realisasi lebih kecil, sisa dikembalikan pemohon.
    | REIMBURSE : realisasi lebih besar, kekurangan dibayarkan perusahaan.
    |--------------------------------------------------------------------------
    */

    public const DIFFERENCE_NONE = 'NONE';
    public const DIFFERENCE_RETURN = 'RETURN';
    public const DIFFERENCE_REIMBURSE = 'REIMBURSE';

    /**
     * Menentukan jenis selisih dari nilainya.
     */
    public static function resolveDifferenceType(float $differenceAmount): string
    {
        if (abs($differenceAmount) < 0.005) {
            return self::DIFFERENCE_NONE;
        }

        return $differenceAmount > 0
            ? self::DIFFERENCE_RETURN
            : self::DIFFERENCE_REIMBURSE;
    }

    /*
    |--------------------------------------------------------------------------
    | Area approval
    |--------------------------------------------------------------------------
    | Cabang ID 1 adalah kantor pusat, selebihnya cabang -- sama dengan FPU.
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
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function cashAdvance()
    {
        return $this->belongsTo(CashAdvance::class, 'cash_advance_id');
    }

    public function items()
    {
        return $this->hasMany(
            CashAdvanceRealizationItem::class,
            'cash_advance_realization_id',
        );
    }

    public function attachments()
    {
        return $this->hasMany(
            CashAdvanceRealizationAttachment::class,
            'cash_advance_realization_id',
        );
    }

    public function approvals()
    {
        return $this->hasMany(
            CashAdvanceRealizationApproval::class,
            'cash_advance_realization_id',
        );
    }

    /**
     * Cabang disimpan sebagai teks -- mengikuti FPU dan purchase_requests.
     */
    public function branchData()
    {
        return $this->belongsTo(Cabang::class, 'branch', 'id');
    }

    public function departmentData()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
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

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function finalApprover()
    {
        return $this->belongsTo(User::class, 'final_approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Yang memeriksa dan meneruskannya ke pembayaran. */
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function settler()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /** Pemohon yang menyatakan sisa dananya sudah dikembalikan. */
    public function returnDeclarer()
    {
        return $this->belongsTo(User::class, 'return_declared_by');
    }

    /**
     * Riwayat proses Finance, terlama lebih dulu.
     *
     * Urutannya dipasang di relasinya supaya tiap layar membacanya dengan
     * urutan yang sama. Diurutkan menurut WAKTU peristiwanya, bukan menurut
     * id: baris hasil pemindahan data lama lahir belakangan walau
     * peristiwanya terjadi lebih dulu.
     */
    public function financeLogs()
    {
        return $this->hasMany(
            CashAdvanceRealizationFinanceLog::class,
            'cash_advance_realization_id',
        )->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * Riwayat percobaan pengembalian sisa dana, terlama lebih dulu.
     *
     * Urutannya dipasang di relasinya, bukan diserahkan ke tiap pemanggil:
     * riwayat yang urutannya berbeda-beda antar layar membuat "percobaan
     * pertama" menunjuk baris yang berlainan di tiap tempat.
     */
    public function returnDeclarations()
    {
        return $this->hasMany(
            CashAdvanceRealizationReturnDeclaration::class,
            'cash_advance_realization_id',
        )->orderBy('attempt');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getEncryptedIdAttribute()
    {
        return Crypt::encryptString($this->id);
    }
}
