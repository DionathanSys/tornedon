<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commitment_payment_lines', function (Blueprint $table) {
            $table->json('match_diagnostics')->nullable();
            $table->json('corrected_data')->nullable();
            $table->timestamp('matched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commitment_payment_lines', fn (Blueprint $table) => $table->dropColumn(['match_diagnostics', 'corrected_data', 'matched_at']));
    }
};
