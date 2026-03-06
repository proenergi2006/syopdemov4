<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_counters', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('doc_code', 10);
            $table->string('department', 10);
            $table->string('branch', 10)->nullable();
            $table->integer('year');
            $table->integer('last_number')->default(0);

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->unique(
                ['doc_code', 'department', 'branch', 'year'],
                'doc_counter_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('document_counters', function (Blueprint $table) {
            $table->dropUnique('doc_counter_unique');
        });

        Schema::dropIfExists('document_counters');
    }
};
