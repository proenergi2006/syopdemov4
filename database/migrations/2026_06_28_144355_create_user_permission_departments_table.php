<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'user_permission_departments',
            function (Blueprint $table) {
                $table->id();

                /*
                |--------------------------------------------------------------------------
                | Direct permission induk
                |--------------------------------------------------------------------------
                |
                | Jika user_permission dihapus, seluruh department assignment
                | terkait ikut dihapus.
                |--------------------------------------------------------------------------
                */
                $table->foreignId('user_permission_id')
                    ->constrained('user_permissions')
                    ->cascadeOnDelete();

                /*
                |--------------------------------------------------------------------------
                | Department yang diizinkan
                |--------------------------------------------------------------------------
                */
                $table->foreignId('department_id')
                    ->constrained('departments')
                    ->cascadeOnDelete();

                $table->timestamps();

                /*
                |--------------------------------------------------------------------------
                | Satu department tidak boleh tersimpan dua kali
                | pada direct permission yang sama.
                |--------------------------------------------------------------------------
                */
                $table->unique(
                    [
                        'user_permission_id',
                        'department_id',
                    ],
                    'user_permission_departments_unique',
                );

                $table->index(
                    'department_id',
                    'user_permission_departments_department_idx',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'user_permission_departments',
        );
    }
};
