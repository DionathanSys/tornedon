<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_dispatches', function (Blueprint $table) {
            $table->dropUnique('email_dispatch_idempotency_unique');

            $table->string('rendered_subject')->nullable()->after('subject');
            $table->longText('rendered_body')->nullable()->after('rendered_subject');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_dispatches', function (Blueprint $table) {
            $table->dropColumn(['rendered_subject', 'rendered_body']);

            $table->unique(['company_id', 'document_type', 'document_id', 'event'], 'email_dispatch_idempotency_unique');
        });
    }
};
