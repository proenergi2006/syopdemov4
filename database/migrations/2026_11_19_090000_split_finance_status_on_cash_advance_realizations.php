<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Status approval dan status proses Finance dipisah
|--------------------------------------------------------------------------
| Satu kolom status selama ini menampung DUA pertanyaan yang berbeda:
|
|   "sudah disetujui siapa?"      -> DRAFT, IN PROGRESS, APPROVED,
|                                    NEED REVISION, REJECTED, CANCELLED
|   "sudah sampai mana di Finance?" -> RECEIVED, ON CHECKING, VERIFIED,
|                                      RETURN DECLARED, SETTLED
|
| Dua belas nilai dalam satu kolom, dan satu daftar saring di layar yang
| mencampur keduanya. Yang mencari "dokumen yang sudah disetujui" harus
| mencentang enam nilai sekaligus, sebab dokumen yang sedang diperiksa
| Finance tidak lagi berstatus APPROVED -- padahal persetujuannya tidak
| pernah dicabut.
|
| Sesudah ini:
|
|   status         : hanya perjalanan approval. Berhenti di APPROVED dan
|                    tidak bergerak lagi sesudahnya.
|   finance_status : hanya perjalanan di Finance. Tiga nilai saja.
|
|   WAITING APPROVAL -> dokumen menunggu diterima Finance   (merah)
|   ON CHECKING      -> sedang diproses Finance             (kuning)
|   APPROVED         -> proses Finance selesai              (hijau)
|
| Ketiga cabang -- nilainya pas, kurang, dan lebih -- memakai ketiga nilai
| yang sama. Yang membedakan hanya siapa yang mengerjakan dan berapa aksinya,
| bukan berapa statusnya.
|
| KOLOM LAMA TIDAK DIBUANG. received_at, verified_at, settled_at dan
| kawan-kawannya tetap merekam siapa mengerjakan apa dan kapan; yang hilang
| hanya kebiasaan membaca perjalanan itu dari kolom status.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    /*
    | Status lama yang sebenarnya adalah tahap Finance, beserta tempat
    | barunya. Semuanya berpangkal pada satu kenyataan yang sama: dokumennya
    | SUDAH disetujui -- karena itu status approval-nya menjadi APPROVED.
    */
    private const PEMETAAN = [
        'RECEIVED' => 'ON CHECKING',
        'ON CHECKING' => 'ON CHECKING',
        'VERIFIED' => 'ON CHECKING',
        'RETURN DECLARED' => 'ON CHECKING',
        'SETTLED' => 'APPROVED',
    ];

    public function up(): void
    {
        Schema::table('cash_advance_realizations', function (Blueprint $table): void {
            $table->string('finance_status', 20)->nullable()->after('status');

            /*
            | Disaring pada hampir setiap daftar dan setiap hitungan lencana
            | sidebar, selalu berdampingan dengan status approval-nya.
            */
            $table->index(['status', 'finance_status'], 'car_status_finance_idx');
        });

        /*
        | Yang sudah masuk tahap Finance dipindahkan lebih dulu, baru yang
        | baru disetujui. Urutannya penting: baris SETTLED dan RECEIVED akan
        | berubah menjadi APPROVED, dan kalau yang APPROVED dikerjakan duluan
        | ia akan menyapu mereka sekali lagi dengan nilai yang keliru.
        */
        foreach (self::PEMETAAN as $lama => $baru) {
            DB::table('cash_advance_realizations')
                ->whereRaw('UPPER(TRIM(status)) = ?', [$lama])
                ->update([
                    'status' => 'APPROVED',
                    'finance_status' => $baru,
                ]);
        }

        /*
        | Yang disetujui tetapi belum tersentuh Finance. Dikenali dari
        | finance_status yang masih kosong -- bukan dari status saja, sebab
        | baris hasil pemindahan di atas kini juga berstatus APPROVED.
        */
        DB::table('cash_advance_realizations')
            ->whereRaw('UPPER(TRIM(status)) = ?', ['APPROVED'])
            ->whereNull('finance_status')
            ->update(['finance_status' => 'WAITING APPROVAL']);
    }

    public function down(): void
    {
        /*
        | Dikembalikan sebisanya. RECEIVED, VERIFIED, dan RETURN DECLARED
        | sama-sama menjadi ON CHECKING saat naik, jadi turunnya tidak bisa
        | membedakan ketiganya lagi -- semuanya pulang sebagai RECEIVED,
        | status tertua di antara mereka. Jejak aslinya tetap terbaca dari
        | received_at, verified_at, dan return_declared_at.
        */
        DB::table('cash_advance_realizations')
            ->whereRaw('UPPER(TRIM(finance_status)) = ?', ['APPROVED'])
            ->update(['status' => 'SETTLED']);

        DB::table('cash_advance_realizations')
            ->whereRaw('UPPER(TRIM(finance_status)) = ?', ['ON CHECKING'])
            ->update(['status' => 'RECEIVED']);

        Schema::table('cash_advance_realizations', function (Blueprint $table): void {
            $table->dropIndex('car_status_finance_idx');
            $table->dropColumn('finance_status');
        });
    }
};
