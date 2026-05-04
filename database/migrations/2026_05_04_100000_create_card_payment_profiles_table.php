<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_payment_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('brand', 60)
                ->nullable();
            $table->string('acquirer', 120)
                ->nullable();
            $table->decimal('fee_percent', 10, 4)
                ->default(0);
            $table->decimal('fee_fixed', 15, 4)
                ->default(0);
            $table->unsignedInteger('settlement_days')
                ->default(0);
            $table->boolean('active')
                ->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'card_payment_profiles_company_name_unique');
            $table->index(['company_id', 'active'], 'card_payment_profiles_company_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_payment_profiles');
    }
};
