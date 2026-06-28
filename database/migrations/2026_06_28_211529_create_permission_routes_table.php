<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'permission_routes',
            function (Blueprint $table): void {
                $table->id();

                /*
                |--------------------------------------------------------------------------
                | Permission yang Dibutuhkan
                |--------------------------------------------------------------------------
                | Contoh:
                | auth.user.view
                | auth.user.create
                | auth.user.update
                |--------------------------------------------------------------------------
                */
                $table
                    ->foreignId('permission_id')
                    ->constrained('permissions')
                    ->cascadeOnDelete();

                /*
                |--------------------------------------------------------------------------
                | Path Halaman Frontend
                |--------------------------------------------------------------------------
                | Contoh:
                | /auth/users
                | /auth/users/create
                | /auth/users/:id/edit
                |--------------------------------------------------------------------------
                */
                $table->string(
                    'route_path',
                    255,
                );

                /*
                |--------------------------------------------------------------------------
                | Cara Pencocokan Route
                |--------------------------------------------------------------------------
                | EXACT
                |   Path harus sama persis.
                |
                | PREFIX
                |   Path dan seluruh halaman turunannya.
                |
                | PARAMETERIZED
                |   Route dengan parameter seperti :id.
                |--------------------------------------------------------------------------
                */
                $table
                    ->string(
                        'match_type',
                        30,
                    )
                    ->default('EXACT');

                /*
                |--------------------------------------------------------------------------
                | Prioritas
                |--------------------------------------------------------------------------
                | Nilai lebih tinggi akan diperiksa lebih dahulu.
                | Route create/edit sebaiknya lebih tinggi dari route umum.
                |--------------------------------------------------------------------------
                */
                $table
                    ->integer('priority')
                    ->default(0);

                $table
                    ->boolean('is_active')
                    ->default(true);

                $table->timestamps();

                /*
                |--------------------------------------------------------------------------
                | Constraints dan Index
                |--------------------------------------------------------------------------
                */
                $table->unique(
                    [
                        'route_path',
                        'match_type',
                    ],
                    'permission_routes_path_match_unique',
                );

                $table->index(
                    [
                        'permission_id',
                        'is_active',
                    ],
                    'permission_routes_permission_active_idx',
                );

                $table->index(
                    [
                        'is_active',
                        'priority',
                    ],
                    'permission_routes_active_priority_idx',
                );

                $table->index(
                    'match_type',
                    'permission_routes_match_type_idx',
                );
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'permission_routes',
        );
    }
};
