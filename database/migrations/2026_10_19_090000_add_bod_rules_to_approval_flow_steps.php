<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Dua sifat baru sebuah step approval
|--------------------------------------------------------------------------
| Lahir dari ketentuan BOD: pengajuan anggota BOD disetujui anggota BOD lain
| (counter-sign), kecuali bila pemohonnya CEO -- ia langsung ke Finance
| Manager.
|
| KEDUANYA MATI SECARA BAWAAN, DAN ITU INTINYA.
|
| Alur yang berjalan sekarang tidak boleh berubah perilakunya sedikit pun
| karena pekerjaan ini. Selama tidak ada yang menyalakannya di master, mesin
| approval berjalan persis seperti kemarin.
|
| allow_peer_approval
| -------------------
| Mati: aturan lama. Pada Claim, step yang memuat pemohon digugurkan beserta
| seluruh step di bawahnya -- "rekan setingkat bukan atasan". Pada FPU dan
| Realisasi memang belum ada aturan semacam itu.
|
| Hidup: step tetap hidup, tetapi BARIS PEMOHON dikeluarkan darinya. Yang
| menyetujui adalah rekan setingkatnya. Bila sesudah dikeluarkan tidak ada
| approver lain yang tersisa, step itu DILEWATI -- bukan memblokir
| pengajuannya, karena rantai di atasnya masih ada.
|
| skip_for_role_ids
| -----------------
| Daftar role pemohon yang membuat step ini dilewati. Dipakai untuk CEO pada
| step counter-sign BOD: tidak ada anggota BOD yang di atasnya, jadi ia
| langsung ke step berikutnya.
|
| JSON, BUKAN TABEL PIVOT.
|
| Step approval disimpan ulang seluruhnya setiap kali flow-nya disunting --
| baris lama dihapus, baris baru dibuat. Pivot yang menggantung pada id step
| akan ikut lenyap pada setiap penyuntingan, dan yang menyimpannya harus
| ingat menyusunnya kembali. Satu kolom ikut terbawa dengan sendirinya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('approval_flow_steps', 'allow_peer_approval')) {
            return;
        }

        Schema::table('approval_flow_steps', function (Blueprint $table): void {
            $table->boolean('allow_peer_approval')->default(false)->after('approver_scope');
            $table->jsonb('skip_for_role_ids')->nullable()->after('allow_peer_approval');
        });

        DB::statement(
            "COMMENT ON COLUMN approval_flow_steps.allow_peer_approval IS "
            . "'Step boleh disetujui sesama anggotanya: baris pemohon dikeluarkan, "
            . "stepnya tetap hidup. Bila tidak ada approver lain yang tersisa, step dilewati. "
            . "Mati = aturan lama (pada Claim: step pemohon dan seluruh step di bawahnya gugur).'",
        );

        DB::statement(
            "COMMENT ON COLUMN approval_flow_steps.skip_for_role_ids IS "
            . "'Daftar id role. Bila pemohon memegang salah satunya, step ini dilewati. "
            . "Kosong/null berarti tidak pernah dilewati. Dipakai mis. CEO pada step counter-sign BOD.'",
        );

        /*
        | approver_type mendapat nilai baru: DEPARTMENT.
        |
        | Kolomnya varchar tanpa constraint, jadi tidak ada yang perlu diubah
        | di sini -- komentarnya yang diperbarui, supaya yang membaca struktur
        | tabelnya tahu nilai itu sah.
        */
        DB::statement(
            "COMMENT ON COLUMN approval_flow_steps.approver_type IS "
            . "'USER, ROLE, atau DEPARTMENT. DEPARTMENT berarti approver_id menunjuk departments.id "
            . "dan step itu diisi seluruh akun aktif department tersebut saat approval dibuat.'",
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('approval_flow_steps', 'allow_peer_approval')) {
            return;
        }

        Schema::table('approval_flow_steps', function (Blueprint $table): void {
            $table->dropColumn(['allow_peer_approval', 'skip_for_role_ids']);
        });
    }
};
