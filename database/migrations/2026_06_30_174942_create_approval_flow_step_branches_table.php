<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_flow_step_branches', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Approver pada approval flow
            |--------------------------------------------------------------------------
            */
            $table->foreignId('approval_flow_step_id')
                ->constrained('approval_flow_steps')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Cabang yang boleh ditangani approver
            |--------------------------------------------------------------------------
            | Untuk sementara tidak diberi foreign key karena nama tabel master
            | cabang perlu disesuaikan dengan struktur project.
            |--------------------------------------------------------------------------
            */
            $table->bigInteger('cabang_id');

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Satu cabang tidak boleh didaftarkan dua kali pada approver yang sama
            |--------------------------------------------------------------------------
            */
            $table->unique(
                [
                    'approval_flow_step_id',
                    'cabang_id',
                ],
                'approval_step_branch_unique',
            );

            $table->index(
                'cabang_id',
                'approval_step_branch_cabang_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'approval_flow_step_branches',
        );
    }
};
