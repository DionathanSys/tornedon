<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_card_statement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_card_statement_id')
                ->constrained('company_card_statements')
                ->cascadeOnDelete();
            $table->foreignId('company_card_transaction_id')
                ->constrained('company_card_transactions')
                ->cascadeOnDelete();
            $table->decimal('amount_allocated', 15, 4);
            $table->timestamps();

            $table->unique(['company_card_statement_id', 'company_card_transaction_id'], 'company_card_statement_items_statement_tx_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_card_statement_items');
    }
};
