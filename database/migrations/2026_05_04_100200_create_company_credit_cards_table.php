<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_credit_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('issuer', 120)
                ->nullable();
            $table->foreignId('issuer_partner_id')
                ->nullable()
                ->constrained('partners')
                ->nullOnDelete();
            $table->string('last_four', 4)
                ->nullable();
            $table->decimal('credit_limit', 15, 4)
                ->nullable();
            $table->unsignedTinyInteger('closing_day');
            $table->unsignedTinyInteger('due_day');
            $table->unsignedTinyInteger('statement_cutoff_business_days')
                ->default(0);
            $table->foreignId('default_financial_account_id')
                ->constrained('financial_accounts')
                ->restrictOnDelete();
            $table->boolean('active')
                ->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'company_credit_cards_company_name_unique');
            $table->index(['company_id', 'active'], 'company_credit_cards_company_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_credit_cards');
    }
};
