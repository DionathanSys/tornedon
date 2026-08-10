<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dre_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dre_model_id')->constrained('dre_models')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('dre_lines')->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('line_type');
            $table->string('operation');
            $table->string('display_sign')->default('natural');
            $table->unsignedInteger('display_depth')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_bold')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index(['dre_model_id', 'parent_id']);
            $table->index(['dre_model_id', 'sort_order']);
            $table->index(['dre_model_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dre_lines');
    }
};
