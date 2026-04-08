<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('financial_account_id')
                ->constrained('financial_accounts')
                ->cascadeOnDelete();
            $table->string('source')->default('manual');
            $table->string('reference')->nullable();
            $table->string('file_name')->nullable();
            $table->string('status');
            $table->timestamp('imported_at')->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_imports');
    }
};
