<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_commitments', function (Blueprint $table) {
            $table->foreignId('suggested_service_order_id')->nullable()->constrained('service_orders')->nullOnDelete();
            $table->unsignedInteger('suggestion_count')->default(0);
            $table->json('match_diagnostics')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->index(['company_id', 'status'], 'commitment_match_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_commitments', function (Blueprint $table) {
            $table->dropForeign(['suggested_service_order_id']);
            $table->dropIndex('commitment_match_status_index');
            $table->dropColumn(['suggested_service_order_id', 'suggestion_count', 'match_diagnostics', 'matched_at']);
        });
    }
};
