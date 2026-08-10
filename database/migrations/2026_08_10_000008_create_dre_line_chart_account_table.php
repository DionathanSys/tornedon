<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dre_line_chart_account', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dre_line_id')->constrained('dre_lines')->cascadeOnDelete();
            $table->foreignId('chart_account_id')->constrained('chart_accounts')->cascadeOnDelete();
            $table->boolean('include_descendants')->default(true);
            $table->timestamps();

            $table->unique(['dre_line_id', 'chart_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dre_line_chart_account');
    }
};
