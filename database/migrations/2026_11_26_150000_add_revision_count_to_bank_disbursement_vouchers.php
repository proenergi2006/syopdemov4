<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| BDV ikut bisa dikembalikan untuk direvisi
|--------------------------------------------------------------------------
| Satu kolom saja, dan ia bukan hiasan: DocumentRevisionService membaca
| revision_count untuk menomori revisinya dan untuk menegakkan batas
| config('fund_request.revisions.max'). Tanpa kolom ini, modelnya memakai
| HasRevisions tetapi setiap pengembalian akan tercatat sebagai revisi
| pertama -- dan batasnya tidak pernah tercapai.
|
| Riwayatnya sendiri tidak perlu tabel baru. fund_request_revisions sudah
| polimorfik, jadi BDV tinggal menumpang dengan document_type berisi nama
| kelasnya, persis seperti FPU, Claim, Realisasi, PR, PO, dan Perdin.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bank_disbursement_vouchers', 'revision_count')) {
            return;
        }

        Schema::table('bank_disbursement_vouchers', function (Blueprint $table): void {
            /*
            | Bernilai 0, bukan null, dan itu disengaja.
            |
            | Servisnya membaca kolom ini sebagai angka dan membandingkannya
            | dengan batas. Null yang lolos ke sana akan terbaca 0 juga,
            | tetapi hanya karena kebetulan cast-nya begitu -- dan voucher
            | lama yang nilainya null akan tampil sebagai "Revisi ke-" tanpa
            | angka pada layar yang membacanya apa adanya.
            */
            $table->unsignedInteger('revision_count')
                ->default(0)
                ->after('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('bank_disbursement_vouchers', 'revision_count')) {
            return;
        }

        Schema::table('bank_disbursement_vouchers', function (Blueprint $table): void {
            $table->dropColumn('revision_count');
        });
    }
};
