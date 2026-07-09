<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_access_assignments', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | User
            |--------------------------------------------------------------------------
            */
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Branch & Department Access
            |--------------------------------------------------------------------------
            | Note:
            | - branch_id mengarah ke table existing cabang
            | - department_id mengarah ke table existing departments
            |--------------------------------------------------------------------------
            */
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('department_id');

            /*
            |--------------------------------------------------------------------------
            | Flags
            |--------------------------------------------------------------------------
            */
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Foreign Keys
            |--------------------------------------------------------------------------
            */
            $table->foreign('branch_id')
                ->references('id')
                ->on('cabang')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index('user_id');
            $table->index('branch_id');
            $table->index('department_id');
            $table->index('is_active');

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate access
            |--------------------------------------------------------------------------
            */
            $table->unique(
                ['user_id', 'branch_id', 'department_id'],
                'uaa_user_branch_department_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_access_assignments');
    }
};
