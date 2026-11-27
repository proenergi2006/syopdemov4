<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Satu BDV bisa mencairkan BANYAK dokumen sumber
|--------------------------------------------------------------------------
| Sebelumnya dokumen sumbernya ditunjuk langsung dari kepala voucher lewat
| sepasang kolom (document_type, source_id). Satu voucher, satu dokumen.
|
| Kenyataannya Finance membayar beberapa FPU/Claim sekaligus dalam satu
| pencairan. Tabel ini yang menampungnya, dan kolom lama di kepala voucher
| dibuang supaya tidak ada dua sumber kebenaran tentang hal yang sama.
|
| ISINYA SALINAN BEKU, bukan acuan.
|
| branch, department_id, keterangan transaksi, dan nominal tiap dokumen
| disalin pada saat dipilih. Dokumen sumbernya masih bisa direvisi Finance
| sesudahnya, dan voucher yang sudah diajukan tidak boleh ikut berubah
| diam-diam di bawah tanda tangan orang yang menyetujuinya.
|
| released_at: NISAN, SEPERTI bdv_items.removed_at
|
| Satu dokumen hanya boleh diangkat satu voucher yang berlaku. Dulu dijaga
| indeks parsial di kepala voucher, yang bisa membaca status dan deleted_at
| pada baris yang sama. Di tabel anak itu tidak mungkin -- statusnya ada di
| induknya. Karena itu pelepasannya ditandai di sini: BDV yang dihapus atau
| dibatalkan mengisi released_at seluruh sumbernya, dan indeks uniknya hanya
| berlaku untuk yang belum dilepas.
|
| Dengan begitu dokumennya bisa diangkat voucher baru, sementara jejak
| "pernah diangkat voucher X" tidak ikut hilang.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bdv_sources')) {
            Schema::create('bdv_sources', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('bdv_id');

                /* FPU atau CLAIM -- lihat config/bdv.php. */
                $table->string('document_type', 20);
                $table->unsignedBigInteger('source_id');
                $table->string('source_number', 100)->nullable();

                /*
                | Salinan beku keadaan dokumen saat dipilih. Dipakai
                | menampilkan rincian tanpa membaca ulang dokumennya, dan
                | menjaga pemeriksaan "seluruh dokumen harus satu cabang".
                */
                /*
                | varchar, mengikuti bank_disbursement_vouchers.branch yang memang
                | bertipe itu. Disamakan supaya perbandingan antar keduanya tidak
                | perlu pengecoran -- dan tidak diam-diam gagal cocok.
                */
                $table->string('branch', 50)->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->unsignedBigInteger('transaction_category_id')->nullable();
                $table->decimal('total_amount', 18, 2)->default(0);

                /* Urutan tampil, mengikuti urutan pemilihan. */
                $table->integer('sort_order')->default(1);

                /* Nisan: lihat keterangan di kepala berkas. */
                $table->timestamp('released_at')->nullable();

                $table->timestamps();

                $table->foreign('bdv_id')
                    ->references('id')
                    ->on('bank_disbursement_vouchers')
                    ->cascadeOnDelete();

                /* Satu dokumen tidak boleh dipilih dua kali pada voucher yang sama. */
                $table->unique(['bdv_id', 'document_type', 'source_id'], 'bdv_sources_bdv_document_unique');

                $table->index(['bdv_id', 'sort_order']);
                $table->index(['document_type', 'source_id']);
            });

            /*
            | Satu dokumen hanya boleh dipegang SATU voucher yang masih
            | berlaku. Dijaga basis data, bukan hanya pemeriksaan di kode:
            | dua orang yang menekan Simpan pada saat hampir bersamaan untuk
            | FPU yang sama lolos dari pemeriksaan kode, tetapi tidak dari
            | indeks ini.
            */
            DB::statement("
                CREATE UNIQUE INDEX bdv_sources_document_active_unique
                ON bdv_sources (document_type, source_id)
                WHERE released_at IS NULL
            ");
        }

        /*
        |----------------------------------------------------------------------
        | Memindahkan data lama
        |----------------------------------------------------------------------
        | Voucher yang sudah ada dipindahkan menjadi satu baris sumber.
        | Dikerjakan sebelum kolom lamanya dibuang, dan hanya bila kolomnya
        | memang masih ada -- supaya migrasi ini aman dijalankan ulang.
        |----------------------------------------------------------------------
        */
        if (Schema::hasColumn('bank_disbursement_vouchers', 'source_id')) {
            DB::statement("
                INSERT INTO bdv_sources (
                    bdv_id, document_type, source_id, source_number,
                    branch, department_id, transaction_category_id, total_amount,
                    sort_order, released_at, created_at, updated_at
                )
                SELECT
                    v.id, v.document_type, v.source_id, v.source_number,
                    v.branch, v.department_id, v.transaction_category_id, v.total_amount,
                    1,
                    CASE
                        WHEN v.deleted_at IS NOT NULL OR v.status = 'CANCELLED'
                        THEN COALESCE(v.deleted_at, v.updated_at, NOW())
                        ELSE NULL
                    END,
                    COALESCE(v.created_at, NOW()),
                    COALESCE(v.updated_at, NOW())
                FROM bank_disbursement_vouchers v
                WHERE v.source_id IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM bdv_sources s WHERE s.bdv_id = v.id
                  )
            ");
        }

        /*
        |----------------------------------------------------------------------
        | Rincian item ikut menunjuk sumbernya
        |----------------------------------------------------------------------
        | Tanpa ini tidak ada cara tahu satu baris biaya milik dokumen yang
        | mana -- dan itulah yang membuat layar bisa mengelompokkannya.
        |
        | Nomor barisnya pun berulang antar dokumen: FPU A punya baris 1,
        | Claim B juga. Keunikannya karena itu pindah dari (bdv_id, line_no)
        | menjadi (bdv_source_id, line_no).
        |----------------------------------------------------------------------
        */
        if (!Schema::hasColumn('bdv_items', 'bdv_source_id')) {
            Schema::table('bdv_items', function (Blueprint $table) {
                $table->unsignedBigInteger('bdv_source_id')->nullable()->after('bdv_id');
            });

            DB::statement('
                UPDATE bdv_items i
                SET bdv_source_id = s.id
                FROM bdv_sources s
                WHERE s.bdv_id = i.bdv_id
            ');

            /*
            | CONSTRAINT dulu, baru INDEX -- urutannya menentukan.
            |
            | ->unique() milik Laravel menghasilkan UNIQUE CONSTRAINT pada
            | PostgreSQL, dan DROP INDEX menolak menjatuhkan indeks yang
            | menopang sebuah constraint ("dependent objects still exist").
            | Menjatuhkan constraint-nya ikut menjatuhkan indeksnya; DROP
            | INDEX di bawah hanya untuk basis data yang indeksnya memang
            | dibuat lepas.
            */
            DB::statement('
                ALTER TABLE bdv_items
                DROP CONSTRAINT IF EXISTS bdv_items_bdv_line_unique
            ');

            DB::statement('DROP INDEX IF EXISTS bdv_items_bdv_line_unique');

            Schema::table('bdv_items', function (Blueprint $table) {
                $table->foreign('bdv_source_id')
                    ->references('id')
                    ->on('bdv_sources')
                    ->cascadeOnDelete();

                $table->unique(['bdv_source_id', 'line_no'], 'bdv_items_source_line_unique');
            });
        }

        /*
        |----------------------------------------------------------------------
        | Kolom lama di kepala voucher dibuang
        |----------------------------------------------------------------------
        | Sudah tidak terpakai sejak sumbernya pindah ke tabel sendiri, dan
        | membiarkannya berarti dua tempat yang sama-sama mengaku tahu dokumen
        | sumber voucher ini.
        |----------------------------------------------------------------------
        */
        DB::statement('DROP INDEX IF EXISTS bank_disbursement_vouchers_source_unique');
        DB::statement('DROP INDEX IF EXISTS bank_disbursement_vouchers_document_type_source_id_index');

        foreach (['source_number', 'source_id', 'document_type'] as $kolom) {
            if (Schema::hasColumn('bank_disbursement_vouchers', $kolom)) {
                Schema::table('bank_disbursement_vouchers', function (Blueprint $table) use ($kolom) {
                    $table->dropColumn($kolom);
                });
            }
        }

        /*
        | department_id di kepala voucher kini boleh kosong: ia hanya terisi
        | bila SELURUH dokumen sumbernya berasal dari department yang sama.
        | Kalau campur, pencocokan approval jatuh ke flow "Semua Divisi".
        */
        DB::statement('ALTER TABLE bank_disbursement_vouchers ALTER COLUMN department_id DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('bank_disbursement_vouchers', function (Blueprint $table) {
            $table->string('document_type', 20)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_number', 100)->nullable();
        });

        /* Dikembalikan dari sumber pertama tiap voucher. */
        DB::statement('
            UPDATE bank_disbursement_vouchers v
            SET document_type = s.document_type,
                source_id = s.source_id,
                source_number = s.source_number
            FROM (
                SELECT DISTINCT ON (bdv_id) bdv_id, document_type, source_id, source_number
                FROM bdv_sources
                ORDER BY bdv_id, sort_order, id
            ) s
            WHERE s.bdv_id = v.id
        ');

        if (Schema::hasColumn('bdv_items', 'bdv_source_id')) {
            Schema::table('bdv_items', function (Blueprint $table) {
                $table->dropForeign(['bdv_source_id']);
                $table->dropUnique('bdv_items_source_line_unique');
                $table->dropColumn('bdv_source_id');
            });

            Schema::table('bdv_items', function (Blueprint $table) {
                $table->unique(['bdv_id', 'line_no'], 'bdv_items_bdv_line_unique');
            });
        }

        Schema::dropIfExists('bdv_sources');
    }
};
