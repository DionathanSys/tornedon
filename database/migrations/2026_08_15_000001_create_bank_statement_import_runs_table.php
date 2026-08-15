<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_import_id')
                ->constrained('bank_statement_imports')
                ->cascadeOnDelete();
            $table->char('file_hash', 64);
            $table->string('file_name')->nullable();
            $table->string('status')->default('pending');
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['bank_statement_import_id', 'created_at'], 'bsir_import_created_idx');
            $table->index(['bank_statement_import_id', 'file_hash'], 'bsir_import_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_import_runs');
    }
};
