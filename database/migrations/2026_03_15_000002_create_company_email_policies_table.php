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
        Schema::create('company_email_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('document_type', 40);
            $table->string('event', 40);
            $table->boolean('enabled')->default(true);

            //TODO Remover, deve ser utilizado o email que esta no contacts marcado como notify = true
            $table->text('default_to')->nullable();
            $table->text('default_cc')->nullable();
            $table->text('default_bcc')->nullable();

            $table->string('subject_template')->nullable();
            $table->text('body_template')->nullable();

            $table->json('required_attachments')->nullable();
            $table->json('optional_attachments')->nullable();
            $table->unsignedInteger('max_total_size_mb')->default(20);
            $table->json('allowed_mime_types')->nullable();
            $table->string('fallback_mode', 40)->default('signed_link');
            $table->timestamps();

            $table->unique(['company_id', 'document_type', 'event'], 'company_email_policy_unique');
            $table->index(['company_id', 'enabled'], 'company_email_policy_company_enabled_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_email_policies');
    }
};

