<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table): void {
            $table->string('customer_signed_by_name')->nullable()->after('customer_signed_at');
            $table->json('customer_signature_metadata')->nullable()->after('customer_signed_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table): void {
            $table->dropColumn(['customer_signed_by_name', 'customer_signature_metadata']);
        });
    }
};
