<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
| Kategori biaya pada rincian perjalanan dinas: Transportasi, Penginapan,
| Uang Saku. Hanya berlaku pada dokumen berketerangan transaksi perdin --
| lihat keterangan pada migrasinya.
*/
class BusinessTripExpenseCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'business_trip_expense_categories';

    protected $fillable = [
        'name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Urutan tampil yang berlaku di mana-mana.
     *
     * Diurutkan sort_order lebih dulu, lalu id -- bukan menurut abjad. Abjad
     * akan menaruh Penginapan sebelum Transportasi, dan yang membaca
     * cetakannya berhenti mengenali urutan formulir kertasnya.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
