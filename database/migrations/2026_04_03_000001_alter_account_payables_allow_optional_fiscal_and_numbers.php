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
        Schema::table('account_payables', function (Blueprint $table) {
            $table->string('bank_slip_number', 100)->nullable()->after('document_number');
            $table->string('note_number', 100)->nullable()->after('bank_slip_number');

            $table->dropForeign(['fiscal_document_id']);
            $table->unsignedBigInteger('fiscal_document_id')->nullable()->change();
            $table->foreign('fiscal_document_id')
                ->references('id')
                ->on('fiscal_documents')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_payables', function (Blueprint $table) {
            $table->dropForeign(['fiscal_document_id']);
            $table->unsignedBigInteger('fiscal_document_id')->nullable(false)->change();
            $table->foreign('fiscal_document_id')
                ->references('id')
                ->on('fiscal_documents')
                ->cascadeOnDelete();

            $table->dropColumn(['bank_slip_number', 'note_number']);
        });
    }
};
