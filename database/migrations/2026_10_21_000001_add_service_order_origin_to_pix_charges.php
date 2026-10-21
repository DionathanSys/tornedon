<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pix_charges', function (Blueprint $table): void {
            $table->foreignId('service_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pix_charges', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('service_order_id');
        });
    }
};
