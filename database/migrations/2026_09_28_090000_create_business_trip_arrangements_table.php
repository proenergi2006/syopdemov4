<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Pemesanan perjalanan: hotel, tiket, dan transport
|--------------------------------------------------------------------------
| Diisi GA setelah sebuah perdin tuntas disetujui. Dua tabel, bukan satu:
| satu pemesanan hotel bisa membawa voucher DAN invoice, dan satu tiket
| pesawat bisa dua berkas -- berangkat dan pulang. Dengan satu tabel saja,
| "Aston Banjarmasin 23-24 Sep" harus diketik ulang pada tiap berkas.
|
| TIDAK ADA PENGHAPUSAN DAN TIDAK ADA PENIMPAAN.
|
| Begitu sebuah pemesanan dicatat, pemohonnya langsung dikabari -- dan
| sesudah itu tidak ada yang boleh lenyap diam-diam. Pemesanan yang batal
| ditandai DIBATALKAN beserta alasannya; penggantinya baris baru yang
| menunjuk baris lama lewat replaces_id. Riwayatnya adalah daftar itu
| sendiri, terurut waktu, tanpa tabel riwayat terpisah.
|
| Berkas pemesanan yang dibatalkan pun tetap disimpan: ia bukti bahwa
| pemesanan itu pernah ada. Pembatalan hotel kadang berbiaya, dan biaya itu
| harus bisa dipertanggungjawabkan di Realisasi -- menghapus vouchernya
| berarti menghapus bukti tagihannya.
|
| Sengaja TIDAK ada kolom biaya. Begitu ada angka di sini, orang mengira ia
| cocok dengan FPU -- padahal FPU perkiraan dan Realisasi kenyataan berbukti
| kuitansi. Dua angka yang mirip tetapi tak pernah sama persis hanya
| melahirkan pertanyaan yang tidak ada jawabannya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('business_trip_arrangements')) {
            Schema::create('business_trip_arrangements', function (Blueprint $table): void {
                $table->id();

                $table->foreignId('business_trip_id')
                    ->constrained('business_trips')
                    ->cascadeOnDelete();

                /* PENGINAPAN, TIKET, TRANSPORT, LAINNYA. */
                $table->string('type', 30);

                /*
                | Keterangan ringkas -- seluruhnya boleh kosong. Vouchernya
                | sendiri sudah memuat semuanya; kolom ini hanya supaya isinya
                | terbaca di daftar tanpa membuka berkasnya satu per satu.
                */
                $table->string('vendor_name', 190)->nullable();
                $table->string('reference_no', 100)->nullable();
                $table->date('starts_at')->nullable();
                $table->date('ends_at')->nullable();
                $table->text('notes')->nullable();

                $table->string('status', 20)->default('AKTIF');

                /* Pemesanan yang digantikan baris ini, bila ia sebuah pengganti. */
                $table->foreignId('replaces_id')
                    ->nullable()
                    ->constrained('business_trip_arrangements')
                    ->nullOnDelete();

                $table->foreignId('created_by')->nullable()->constrained('users');

                $table->foreignId('cancelled_by')->nullable()->constrained('users');
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_notes')->nullable();

                $table->timestamps();

                $table->index(['business_trip_id', 'status']);
            });

            DB::statement(
                "COMMENT ON TABLE business_trip_arrangements IS "
                . "'Pemesanan hotel, tiket, dan transport untuk sebuah perdin. Tidak pernah dihapus atau ditimpa; yang batal ditandai DIBATALKAN beserta alasannya.'",
            );
        }

        if (!Schema::hasTable('business_trip_arrangement_files')) {
            Schema::create('business_trip_arrangement_files', function (Blueprint $table): void {
                $table->id();

                $table->foreignId('business_trip_arrangement_id')
                    ->constrained('business_trip_arrangements')
                    ->cascadeOnDelete();

                $table->string('filename', 255);
                $table->string('original_filename', 255);
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('filepath', 500);

                $table->foreignId('uploaded_by')->nullable()->constrained('users');

                $table->timestamps();
            });

            DB::statement(
                "COMMENT ON TABLE business_trip_arrangement_files IS "
                . "'Berkas bukti pemesanan. Tetap disimpan walau pemesanannya dibatalkan -- ia bukti tagihan pembatalannya.'",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_trip_arrangement_files');
        Schema::dropIfExists('business_trip_arrangements');
    }
};
