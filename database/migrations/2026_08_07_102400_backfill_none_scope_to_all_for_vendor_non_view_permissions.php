<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sebelum ini, scope untuk action selain 'view' selalu dipaksa 'NONE' saat
 * disimpan (lihat RolePermissionController::bulkUpdate()), sehingga baris
 * role_permissions untuk vendor.update/delete/submit yang sudah aktif hari
 * ini semuanya tersimpan dengan scope='NONE'.
 *
 * Sekarang scope untuk action selain 'view' benar-benar ditegakkan di
 * level row (lihat MasterVendorController::destroy/update/submit). Kalau
 * baris-baris NONE ini dibiarkan, semua role yang tadinya bisa
 * update/delete/submit vendor akan langsung kehilangan akses begitu
 * migrasi ini di-deploy.
 *
 * Backfill ini mengubah scope NONE -> ALL khusus untuk kombinasi
 * (module=vendor, action IN update/delete/submit) yang statusnya aktif,
 * supaya perilaku hari ini (siapa saja yang sekarang bisa update/delete/
 * submit vendor) tetap sama persis setelah enforcement diaktifkan.
 * Role/permission baru ke depannya akan memakai scope yang benar-benar
 * dipilih admin lewat halaman Role Permission.
 */
return new class extends Migration
{
    public function up()
    {
        $permissionIds = DB::table('permissions')
            ->where('module', 'vendor')
            ->whereIn('action', ['update', 'delete', 'submit'])
            ->pluck('id');

        DB::table('role_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->where('scope', 'NONE')
            ->update(['scope' => 'ALL']);
    }

    public function down()
    {
        $permissionIds = DB::table('permissions')
            ->where('module', 'vendor')
            ->whereIn('action', ['update', 'delete', 'submit'])
            ->pluck('id');

        DB::table('role_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->where('scope', 'ALL')
            ->update(['scope' => 'NONE']);
    }
};
