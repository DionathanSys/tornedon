<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commitment_imports', function (Blueprint $table) {
            $table->json('summary')->nullable();
        });
        Schema::table('commitment_import_files', function (Blueprint $table) {
            $table->uuid('processing_token')->nullable();
            $table->timestamp('processing_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commitment_import_files', fn (Blueprint $table) => $table->dropColumn(['processing_token', 'processing_started_at']));
        Schema::table('commitment_imports', fn (Blueprint $table) => $table->dropColumn('summary'));
    }
};
