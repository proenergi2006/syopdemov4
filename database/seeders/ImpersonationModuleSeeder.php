<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Modul Masuk Sebagai User
|--------------------------------------------------------------------------
| Super admin masuk ke akun user lain tanpa password user tersebut, untuk
| menelusuri keluhan yang hanya muncul di akun orang itu.
|
| SEEDER INI TIDAK MEMBERIKAN PERMISSION KEPADA SIAPA PUN -- termasuk tidak
| kepada Super Administrator. Ia hanya mendaftarkan permission-nya supaya
| muncul di layar Role Permission.
|
| Untuk fitur ini sikap itu lebih penting daripada biasanya. auth_impersonation
| .create adalah permission paling kuat di aplikasi: pemegangnya bisa
| bertindak sebagai siapa pun, termasuk submit dan approve dokumen keuangan
| dengan tanda tangan digital orang lain. Siapa yang memegangnya harus
| merupakan keputusan yang terlihat dan tercatat di layar Role Permission,
| bukan sesuatu yang diam-diam menyala karena sebuah seeder dijalankan.
|
| Aman dijalankan berulang: seluruhnya updateOrInsert.
|--------------------------------------------------------------------------
*/
class ImpersonationModuleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $this->seedPermissionModule($now);
        $this->seedPermissions($now);
        $this->seedMenu($now);
    }

    /*
    |--------------------------------------------------------------------------
    | Permission module
    |--------------------------------------------------------------------------
    | route_prefix dipakai frontend untuk mencocokkan halaman dengan
    | permission. Nilainya harus sama persis dengan folder halaman Vue.
    |--------------------------------------------------------------------------
    */
    private function seedPermissionModule($now): void
    {
        $code = 'auth_impersonation';

        DB::table('permission_modules')->updateOrInsert(
            ['code' => $code],
            [
                'name' => 'Auth - Masuk Sebagai User',
                'description' => 'Masuk ke akun user lain tanpa password, beserta riwayat penyamarannya.',
                'route_prefix' => '/master/impersonation',
                'sort_order' => 160,

                /* Bukan dokumen -- tidak punya approval flow. */
                'approval_document_type' => null,
                'approval_document_label' => null,
                'approval_uses_area_matrix' => false,

                'is_active' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('permission_modules')
            ->where('code', $code)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);
    }

    /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    | Keduanya tanpa scope. Scope permission menyaring BARIS DATA menurut
    | cabang atau department pemiliknya, sementara dua permission ini tidak
    | menyaring baris apa pun: yang satu izin melakukan tindakan, yang satu
    | izin membuka catatan audit yang justru tidak boleh tersaring -- jejak
    | yang hanya menampilkan sebagian bukan jejak.
    |--------------------------------------------------------------------------
    */
    private function seedPermissions($now): void
    {
        $permissions = [
            /*
            | PERMISSION TERKUAT DI APLIKASI.
            |
            | Pemegangnya bisa bertindak sebagai user mana pun yang tidak juga
            | memegang permission ini: melihat, mengubah, submit, dan approve,
            | termasuk membubuhkan tanda tangan digital user tersebut pada
            | dokumen keuangan. Jangan diberikan ke role yang anggotanya banyak.
            */
            ['auth_impersonation', 'create', 'Masuk Sebagai User',
                'Masuk ke akun user lain tanpa password user tersebut. Pemegang permission ini bisa '
                . 'melakukan apa pun yang user itu bisa, termasuk submit dan approve dokumen dengan '
                . 'tanda tangan digital user tersebut. Setiap tindakannya tercatat di Activity Log '
                . 'beserta nama siapa yang sebenarnya melakukannya.', false],

            /*
            | Dipisah dari .create supaya pemeriksa bisa membaca jejaknya tanpa
            | ikut diberi kemampuan menyamar. Auditor melihat, tidak melakukan.
            */
            ['auth_impersonation', 'view', 'View Riwayat Masuk Sebagai User',
                'Melihat riwayat penyamaran: siapa masuk ke akun siapa, kapan, dengan alasan apa, '
                . 'dan tindakan apa saja yang dilakukan selama sesi itu.', false],
        ];

        foreach ($permissions as [$module, $action, $name, $description, $requiresScope]) {
            $code = $module . '.' . $action;

            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => $module,
                    'action' => $action,
                    'name' => $name,
                    'description' => $description,
                    'requires_scope' => $requiresScope,
                    'is_active' => true,
                    'updated_at' => $now,
                ],
            );

            DB::table('permissions')
                ->where('code', $code)
                ->whereNull('created_at')
                ->update(['created_at' => $now]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Menu
    |--------------------------------------------------------------------------
    | Menempel pada menu induk "Auth", bersama Users dan Role Permissions,
    | karena fitur ini menyangkut identitas dan hak akses -- bukan master data
    | dan bukan monitoring. Bila menu induknya tidak ditemukan, bagian ini
    | dilewati tanpa membatalkan sisa seeder; menunya bisa dirapikan sendiri
    | lewat Manajemen Menu.
    |--------------------------------------------------------------------------
    */
    private function seedMenu($now): void
    {
        $parentId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Auth')
            ->value('id');

        if (!$parentId) {
            $this->command?->warn(
                'Menu induk "Auth" tidak ditemukan -- menu Masuk Sebagai User dilewati.',
            );

            return;
        }

        $name = 'Masuk Sebagai User';

        DB::table('menus')->updateOrInsert(
            [
                'name' => $name,
                'parent_id' => $parentId,
            ],
            [
                'path' => '/master/impersonation',
                'route_name' => 'master-impersonation',
                'icon' => 'tabler-user-shield',
                'order_no' => 7,
                'is_active' => true,
                'show_in_sidebar' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('menus')
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);
    }
}
