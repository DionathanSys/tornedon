<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')
                ->constrained('fiscal_profiles')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('operation_type', 30)->nullable(); // sale, return, transfer, remittance, bonus, repair, service
            $table->unsignedInteger('priority')->default(100);
            $table->json('conditions');
            $table->json('result');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['fiscal_profile_id', 'is_enabled', 'priority'], 'fiscal_rules_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_rules');
    }
};
