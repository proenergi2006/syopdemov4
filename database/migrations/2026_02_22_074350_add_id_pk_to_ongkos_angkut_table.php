<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Tambah kolom id (PostgreSQL: BIGSERIAL)
        DB::statement('ALTER TABLE ongkos_angkut ADD COLUMN IF NOT EXISTS id BIGSERIAL');

        // 2) Drop primary key lama (composite) kalau ada
        DB::statement('ALTER TABLE ongkos_angkut DROP CONSTRAINT IF EXISTS ongkos_angkut_pkey');

        // 3) Set primary key baru ke kolom id
        DB::statement('ALTER TABLE ongkos_angkut ADD CONSTRAINT ongkos_angkut_pkey PRIMARY KEY (id)');

        // 4) Pastikan unique combo tetap ada (pengganti PK composite)
        DB::statement("
            DO $$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM pg_constraint
                WHERE conname = 'ongkos_angkut_uq1'
              ) THEN
                ALTER TABLE ongkos_angkut
                ADD CONSTRAINT ongkos_angkut_uq1 UNIQUE (id_transportir, id_wil_angkut, id_vol_angkut);
              END IF;
            END $$;
        ");
    }

    public function down(): void
    {
        // rollback aman: drop pk id, drop column id (akan hilang data id)
        DB::statement('ALTER TABLE ongkos_angkut DROP CONSTRAINT IF EXISTS ongkos_angkut_pkey');
        DB::statement('ALTER TABLE ongkos_angkut DROP CONSTRAINT IF EXISTS ongkos_angkut_uq1');
        DB::statement('ALTER TABLE ongkos_angkut DROP COLUMN IF EXISTS id');

        // (opsional) kamu bisa restore pk composite kalau mau, tapi biasanya tidak perlu
        // DB::statement('ALTER TABLE ongkos_angkut ADD CONSTRAINT ongkos_angkut_pkey PRIMARY KEY (id_transportir, id_wil_angkut, id_vol_angkut)');
    }
};