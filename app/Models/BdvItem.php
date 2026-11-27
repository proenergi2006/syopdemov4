<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris rincian Bank Disbursement Voucher.
 *
 * Isinya dua hal yang berbeda sifatnya, dan perbedaan itu penting:
 *
 *   SALINAN  item_date, description, category, qty, unit_price, amount
 *            Diambil dari dokumen sumber pada saat voucher dibuat, lalu
 *            dibekukan. Nilai dokumen sumber masih bisa direvisi sesudahnya;
 *            voucher yang sudah disetujui tidak boleh ikut berubah diam-diam.
 *
 *   ISIAN    accurate_account_no, accurate_account_name
 *            Satu-satunya yang benar-benar diputuskan manusia di sini.
 *
 * Yang disimpan pada accurate_account_no adalah NOMOR akun Accurate, bukan id
 * internalnya -- lihat keterangan pada migrasi 2026_11_23_090000. Namanya ikut
 * disalin karena akunnya hidup di sistem lain yang bisa berganti nama atau
 * sedang tidak bisa dihubungi saat voucher ini dicetak.
 */
class BdvItem extends Model
{
    use HasFactory;

    protected $table = 'bdv_items';

    protected $fillable = [
        'bdv_id',

        /*
        | Dokumen sumber mana yang menurunkan baris ini. Nomor barisnya
        | berulang antar dokumen -- FPU A punya baris 1, Claim B juga --
        | jadi inilah yang membedakannya.
        */
        'bdv_source_id',

        'line_no',
        'item_date',
        'description',
        'category',
        'qty',
        'unit_price',
        'amount',
        'accurate_account_no',
        'accurate_account_name',

        /*
        | Id baris ini di sisi Accurate. Wajib disertakan saat memperbarui,
        | kalau tidak Accurate memperlakukannya sebagai baris baru.
        */
        'accurate_detail_id',

        /*
        | Terisi berarti barisnya sudah hilang dari dokumen sumber, tetapi
        | penghapusannya di Accurate belum dikonfirmasi. Lihat migrasi
        | 2026_11_23_120000.
        */
        'removed_at',
    ];

    protected $casts = [
        'bdv_id' => 'integer',
        'bdv_source_id' => 'integer',
        'line_no' => 'integer',
        'item_date' => 'date',
        'qty' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'removed_at' => 'datetime',
    ];

    public function bdv(): BelongsTo
    {
        return $this->belongsTo(BankDisbursementVoucher::class, 'bdv_id');
    }
}
