<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dre_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('template_key')->nullable();
            $table->unsignedInteger('template_version')->default(1);
            $table->string('structure_hash')->nullable();
            $table->boolean('is_template_locked')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
            $table->index(['template_key', 'structure_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dre_models');
    }
};
