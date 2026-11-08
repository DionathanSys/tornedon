<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_document_id')->constrained()->restrictOnDelete();
            $table->string('reason');
            $table->text('message');
            $table->string('status')->default('open');
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->json('history');
            $table->foreignId('evidence_attachment_id')->nullable()->constrained('attachments')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['fiscal_document_id', 'active_slot'], 'fiscal_issues_one_active_per_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_issues');
    }
};
