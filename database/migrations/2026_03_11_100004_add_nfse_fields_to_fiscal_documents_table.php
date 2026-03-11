<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->string('nfse_model', 10)->nullable()->after('nfe_sequence_id');
            $table->string('nfse_status', 20)->nullable()->after('nfse_model');
            $table->json('nfse_payload')->nullable()->after('nfse_status');
            $table->string('nfse_protocol')->nullable()->after('nfse_payload');
            $table->string('rps_number', 15)->nullable()->after('nfse_protocol');
            $table->string('rps_series', 5)->nullable()->after('rps_number');
            $table->string('rps_type', 1)->nullable()->default('1')->after('rps_series');
            $table->foreignId('nfse_sequence_id')->nullable()->after('rps_type')
                ->constrained('nfse_sequences')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropForeign(['nfse_sequence_id']);
            $table->dropColumn([
                'nfse_model',
                'nfse_status',
                'nfse_payload',
                'nfse_protocol',
                'rps_number',
                'rps_series',
                'rps_type',
                'nfse_sequence_id',
            ]);
        });
    }
};
