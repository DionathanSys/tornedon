<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->json('metadata');
            $table->timestamps();
        });
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->foreignId('bank_reconciliation_group_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::create('bank_statement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_movement_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->unique(['bank_statement_line_id', 'cash_movement_id'], 'bsa_line_movement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_allocations');
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_reconciliation_group_id');
        });
        Schema::dropIfExists('bank_reconciliation_groups');
    }
};
