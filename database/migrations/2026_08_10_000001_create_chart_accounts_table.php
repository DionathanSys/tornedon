<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('chart_accounts')
                ->nullOnDelete();
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('type');
            $table->string('nature')->nullable();
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('parent_id');
            $table->index(['company_id', 'parent_id']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_accounts');
    }
};
