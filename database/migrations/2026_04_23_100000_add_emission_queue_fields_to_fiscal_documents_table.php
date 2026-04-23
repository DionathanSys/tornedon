<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->timestamp('emission_requested_at')
                ->nullable()
                ->after('nfe_sequence_id');
            $table->timestamp('emission_attempted_at')
                ->nullable()
                ->after('emission_requested_at');
            $table->string('emission_group_key')
                ->nullable()
                ->after('emission_attempted_at');

            $table->index(['emission_group_key', 'nfe_status', 'emission_requested_at'], 'fd_emission_group_status_requested_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropIndex('fd_emission_group_status_requested_idx');
            $table->dropColumn([
                'emission_requested_at',
                'emission_attempted_at',
                'emission_group_key',
            ]);
        });
    }
};
