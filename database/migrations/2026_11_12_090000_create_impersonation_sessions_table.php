<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();

            /*
            |------------------------------------------------------------------
            | Siapa menyamar menjadi siapa
            |------------------------------------------------------------------
            | restrictOnDelete, bukan cascade: baris ini adalah catatan audit.
            | Menghapus user tidak boleh ikut menghapus jejak bahwa akunnya
            | pernah dimasuki, atau bahwa ia pernah memasuki akun orang lain.
            |------------------------------------------------------------------
            */
            $table->foreignId('admin_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('target_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            /*
            |------------------------------------------------------------------
            | Token penyamaran
            |------------------------------------------------------------------
            | id dari personal_access_tokens. Inilah satu-satunya dasar yang
            | dipakai untuk mengizinkan /auth/impersonate/stop: hanya token
            | yang id-nya tercatat di sini yang bisa ditukar kembali menjadi
            | token admin. Lihat ImpersonationService::stop().
            |
            | Sengaja TANPA foreign key, karena token dihapus saat penyamaran
            | berakhir sementara baris ini harus tetap ada. Dibuat unique agar
            | satu token mustahil terikat ke dua sesi sekaligus -- jaminan dari
            | basis data, bukan hanya dari kode.
            |
            | nullable karena setelah sesi ditutup nilainya dikosongkan: token
            | sudah tidak ada, dan membiarkan id mati di sini hanya mengundang
            | salah tafsir. NULL tidak saling bentrok pada unique index Postgres.
            |------------------------------------------------------------------
            */
            $table->unsignedBigInteger('token_id')->nullable()->unique();

            /* Alasan wajib diisi. Inilah yang membuat log bisa dibaca ulang. */
            $table->string('reason', 255);

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            /* manual | logout | expired -- lihat ImpersonationSession. */
            $table->string('ended_reason', 20)->nullable();

            $table->timestamps();

            $table->index(['admin_user_id', 'started_at']);
            $table->index(['target_user_id', 'started_at']);
            $table->index('ended_at');
        });

        /*
        |----------------------------------------------------------------------
        | Satu admin, satu sesi terbuka
        |----------------------------------------------------------------------
        | Partial unique index: penyamaran bersarang dan sesi ganda ditolak
        | oleh basis data, bukan hanya oleh pemeriksaan di service. Pemeriksaan
        | di service bisa kalah balapan antara dua request yang datang
        | bersamaan; index ini tidak bisa.
        |
        | Blueprint tidak punya partial index, jadi DDL mentah -- tetap di
        | dalam migration, bukan dijalankan manual.
        |----------------------------------------------------------------------
        */
        DB::statement(
            'CREATE UNIQUE INDEX impersonation_sessions_admin_open_unique
             ON impersonation_sessions (admin_user_id)
             WHERE ended_at IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS impersonation_sessions_admin_open_unique');

        Schema::dropIfExists('impersonation_sessions');
    }
};
