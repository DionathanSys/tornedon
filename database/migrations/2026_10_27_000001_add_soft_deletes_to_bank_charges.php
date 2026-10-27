<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['bank_slips', 'pix_charges'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->softDeletes());
        }
        Schema::table('pix_charges', function (Blueprint $table): void {
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pix_charges', fn (Blueprint $table) => $table->dropColumn(['cancel_requested_at', 'canceled_at']));
        foreach (['bank_slips', 'pix_charges'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }
};
