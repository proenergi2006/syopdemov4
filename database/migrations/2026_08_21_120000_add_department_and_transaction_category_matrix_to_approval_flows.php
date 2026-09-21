<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Approval flow: banyak department dan keterangan transaksi
|--------------------------------------------------------------------------
| Matriks approval FPU memuat baris seperti:
|
|   HO | GA - IT - LOG   | Telpon, Listrik, Pantry, ... | 0 - 10 Juta
|   HO | Semua Divisi    | Perdin                       | 0 - 5 Juta
|
| Artinya satu flow dapat berlaku untuk beberapa department sekaligus, atau
| untuk seluruh department. Kolom creator_department_id yang tunggal tidak
| cukup, jadi ditambahkan tabel pivot beserta penanda "berlaku untuk semua".
|
| Hal yang sama berlaku untuk keterangan transaksi.
|
| creator_department_id sengaja dipertahankan: kolom itu masih dibaca sebagai
| cadangan bila sebuah flow belum punya baris pivot sama sekali, sehingga data
| lama tidak pernah kehilangan pasangannya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_flows', function (Blueprint $table) {
            /*
            | true = "Semua Divisi" pada matriks; department mana pun cocok.
            */
            $table->boolean('all_departments')
                ->default(false)
                ->after('creator_department_id');

            /*
            | true = flow tidak dibatasi keterangan transaksi tertentu.
            | Default true supaya seluruh flow lama (PR, PO, Vendor) tetap
            | cocok tanpa perlu didaftarkan satu per satu.
            */
            $table->boolean('all_transaction_categories')
                ->default(true)
                ->after('all_departments');
        });

        Schema::create('approval_flow_departments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('approval_flow_id');
            $table->unsignedBigInteger('department_id');

            $table->timestamps();

            $table->foreign('approval_flow_id')
                ->references('id')
                ->on('approval_flows')
                ->cascadeOnDelete();

            $table->unique(['approval_flow_id', 'department_id'], 'approval_flow_department_unique');
        });

        Schema::create('approval_flow_transaction_categories', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('approval_flow_id');
            $table->unsignedBigInteger('transaction_category_id');

            $table->timestamps();

            $table->foreign('approval_flow_id')
                ->references('id')
                ->on('approval_flows')
                ->cascadeOnDelete();

            $table->foreign('transaction_category_id')
                ->references('id')
                ->on('fund_request_transaction_categories')
                ->cascadeOnDelete();

            $table->unique(
                ['approval_flow_id', 'transaction_category_id'],
                'approval_flow_transaction_category_unique',
            );
        });

        /*
        |----------------------------------------------------------------------
        | Backfill
        |----------------------------------------------------------------------
        | Setiap flow yang punya creator_department_id dipindahkan ke pivot,
        | supaya seluruh flow lama langsung berjalan lewat mekanisme baru dan
        | hasil pencocokannya persis sama seperti sebelum migrasi ini.
        |----------------------------------------------------------------------
        */
        $now = now();

        $existingFlows = DB::table('approval_flows')
            ->whereNotNull('creator_department_id')
            ->get(['id', 'creator_department_id']);

        foreach ($existingFlows->chunk(200) as $chunk) {
            DB::table('approval_flow_departments')->insert(
                $chunk->map(fn($flow) => [
                    'approval_flow_id' => $flow->id,
                    'department_id' => $flow->creator_department_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_flow_transaction_categories');
        Schema::dropIfExists('approval_flow_departments');

        Schema::table('approval_flows', function (Blueprint $table) {
            $table->dropColumn([
                'all_departments',
                'all_transaction_categories',
            ]);
        });
    }
};
