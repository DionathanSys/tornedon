<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_xml_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->index();
            $table->string('date_column', 32);
            $table->date('starts_at');
            $table->date('ends_at');
            $table->unsignedInteger('total_documents')->default(0);
            $table->unsignedInteger('successful_documents')->default(0);
            $table->unsignedInteger('failed_documents')->default(0);
            $table->string('zip_disk')->nullable();
            $table->string('zip_path')->nullable();
            $table->string('download_token', 80)->nullable()->unique();
            $table->timestamp('download_expires_at')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_xml_exports');
    }
};
