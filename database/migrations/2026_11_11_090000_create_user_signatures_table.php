<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Tanda tangan digital: dari satu per user menjadi maksimal tiga
|--------------------------------------------------------------------------
| Sebelumnya tanda tangan user hanya ada di users.signature_path, dan selalu
| ditulis ke nama file yang sama: signature.png di dalam folder user. Begitu
| user membuat tanda tangan baru, file lamanya tertimpa.
|
| Dokumen yang sudah diajukan atau disetujui hanya menyimpan JALUR filenya,
| bukan gambarnya. Jadi menimpa file itu berarti mengubah tanda tangan pada
| setiap dokumen lama yang pernah ia tanda tangani -- tanpa ada yang meminta,
| dan tanpa jejak.
|
| Tabel ini memberi setiap tanda tangan barisnya sendiri dan filenya sendiri,
| sehingga tanda tangan baru tidak pernah menyentuh file yang sudah dipakai
| dokumen lama. users.signature_path tetap dipertahankan sebagai cermin tanda
| tangan yang sedang aktif, supaya seluruh modul yang sudah membacanya tidak
| perlu diubah sama sekali.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_signatures', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            /* Nama bebas yang dipilih user, supaya ia bisa membedakan slotnya. */
            $table->string('label', 60);

            $table->string('file_path');

            /* 'draw' untuk yang ditulis langsung di layar, 'upload' untuk pindaian. */
            $table->string('source', 10)->default('draw');

            $table->boolean('is_active')->default(false);

            $table->timestamps();

            /*
            | Dihapus secara lunak: filenya sengaja tidak pernah dibuang, karena
            | dokumen lama masih menunjuk ke sana. Yang dibebaskan hanya slotnya.
            */
            $table->softDeletes();

            $table->index(['user_id', 'deleted_at']);
        });

        $this->pindahkanTandaTanganLama();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_signatures');
    }

    /*
    | Tanda tangan yang sudah ada menjadi slot pertama, TANPA memindahkan
    | filenya: jalur itulah yang dipegang dokumen-dokumen lama.
    */
    private function pindahkanTandaTanganLama(): void
    {
        if (!Schema::hasColumn('users', 'signature_path')) {
            return;
        }

        $sekarang = now();

        $baris = DB::table('users')
            ->whereNotNull('signature_path')
            ->where('signature_path', '!=', '')
            ->select(['id', 'signature_path', 'signature_uploaded_at'])
            ->get()
            ->map(fn($user) => [
                'user_id' => $user->id,
                'label' => 'Tanda tangan 1',
                'file_path' => $user->signature_path,
                'source' => 'draw',
                'is_active' => true,
                'created_at' => $user->signature_uploaded_at ?: $sekarang,
                'updated_at' => $sekarang,
            ])
            ->all();

        foreach (array_chunk($baris, 500) as $potongan) {
            DB::table('user_signatures')->insert($potongan);
        }
    }
};
