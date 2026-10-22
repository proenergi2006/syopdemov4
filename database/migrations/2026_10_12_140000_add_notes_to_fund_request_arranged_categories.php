<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Catatan pemohon pada kategori yang diurus GA
|--------------------------------------------------------------------------
| Penandanya sudah menjawab "kenapa kategori ini kosong". Yang belum terjawab:
| APA yang sebenarnya diminta pemohon dari GA.
|
| "Tiket diurus GA" tidak cukup bagi yang mengurusnya -- berangkat pagi atau
| malam, kereta atau pesawat, pulang hari yang sama atau menginap. Tanpa
| tempat menuliskannya, keterangan itu berpindah lewat pesan terpisah yang
| tidak menempel pada dokumennya, dan yang menyetujui tidak pernah
| melihatnya.
|
| OPSIONAL, DAN MEMANG HARUS OPSIONAL.
|
| Penandanya sendiri sudah sah berdiri sendiri: "hotelnya dipesankan GA"
| kadang memang tidak perlu keterangan apa pun. Mewajibkannya hanya akan
| melahirkan isian berisi tanda hubung.
|
| MENEMPEL PADA PENANDANYA, BUKAN PADA DOKUMENNYA.
|
| Satu dokumen bisa punya beberapa kategori yang diurus GA, dan tiap kategori
| punya permintaan yang berbeda. Satu kolom catatan di tingkat dokumen akan
| memaksa ketiganya ditulis berdesakan di satu kotak, lalu dibaca oleh orang
| yang hanya mengurus salah satunya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fund_request_arranged_categories', 'notes')) {
            return;
        }

        Schema::table('fund_request_arranged_categories', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('expense_category_id');
        });

        DB::statement(
            "COMMENT ON COLUMN fund_request_arranged_categories.notes IS "
            . "'Catatan pemohon untuk GA pada kategori ini. Opsional: null berarti tidak ada permintaan khusus, bukan belum diisi.'",
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('fund_request_arranged_categories', 'notes')) {
            return;
        }

        Schema::table('fund_request_arranged_categories', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
