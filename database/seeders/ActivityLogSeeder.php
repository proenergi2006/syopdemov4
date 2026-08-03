<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class ActivityLogSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Activity Log bersifat read-only
        |--------------------------------------------------------------------------
        | Datanya ditulis otomatis oleh sistem (login/logout/request), bukan lewat
        | form admin. Jadi hanya permission view yang dibutuhkan, tidak ada
        | create/update/delete seperti module CRUD pada umumnya.
        |--------------------------------------------------------------------------
        */
        Permission::query()->updateOrCreate(
            [
                'code' => 'activity_log.view',
            ],
            [
                'module' => 'activity_log',
                'action' => 'view',
                'name' => 'Lihat Activity Log',
                'description' => 'Mengizinkan pengguna melihat catatan aktivitas seluruh user.',
                'is_active' => true,
            ],
        );
    }
}
