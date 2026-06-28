<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | User penerima direct permission
            |--------------------------------------------------------------------------
            */
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Permission yang diberikan
            |--------------------------------------------------------------------------
            */
            $table->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Scope direct permission
            |--------------------------------------------------------------------------
            |
            | NONE
            | OWN_DATA
            | OWN_DEPARTMENT
            | OWN_CABANG
            | ASSIGNED_DEPARTMENTS
            | ALL
            |
            | ASSIGNED_DEPARTMENTS menggunakan tabel:
            | user_permission_departments
            |--------------------------------------------------------------------------
            */
            $table->string('scope', 50)
                ->default('NONE');

            /*
            |--------------------------------------------------------------------------
            | Status direct permission
            |--------------------------------------------------------------------------
            |
            | true:
            | Direct permission aktif dan menjadi prioritas.
            |
            | false:
            | Direct permission dianggap tidak digunakan.
            | Sistem nantinya kembali memakai role permission.
            |
            | Belum ada konsep explicit DENY.
            |--------------------------------------------------------------------------
            */
            $table->boolean('is_active')
                ->default(true);

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Constraint dan index
            |--------------------------------------------------------------------------
            |
            | Satu user hanya memiliki satu konfigurasi langsung
            | untuk satu permission.
            |--------------------------------------------------------------------------
            */
            $table->unique(
                ['user_id', 'permission_id'],
                'user_permissions_user_permission_unique',
            );

            $table->index(
                ['user_id', 'is_active'],
                'user_permissions_user_active_idx',
            );

            $table->index(
                ['permission_id', 'scope'],
                'user_permissions_permission_scope_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
