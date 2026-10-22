<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
| Satu orang yang ikut dalam sebuah perjalanan dinas, selain pemohonnya.
|
| Nama, department, dan jabatannya adalah SALINAN keadaan saat dokumennya
| dibuat -- aturan yang sama dengan identitas pemohon pada BusinessTrip.
| user_id tetap disimpan untuk menunjuk akunnya, tetapi yang dicetak dan
| ditampilkan adalah salinannya: dokumen yang sudah disetujui tidak boleh
| berubah sendiri mengikuti mutasi jabatan orangnya.
|
| Tanpa soft delete. Daftar peserta ditulis ulang seluruhnya setiap kali
| dokumennya disunting, dan baris yang sudah dibuang tidak menyimpan apa pun
| yang perlu dikenang -- jejaknya justru ada di riwayat revisi, yang
| memotretnya sebelum perubahan.
*/
class BusinessTripParticipant extends Model
{
    use HasFactory;

    protected $table = 'business_trip_participants';

    protected $fillable = [
        'business_trip_id',
        'user_id',
        'employee_name',
        'department_id',
        'department_name',
        'position_name',
        'sort_no',
    ];

    protected $casts = [
        'business_trip_id' => 'integer',
        'user_id' => 'integer',
        'department_id' => 'integer',
        'sort_no' => 'integer',
    ];

    public function businessTrip()
    {
        return $this->belongsTo(BusinessTrip::class, 'business_trip_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
