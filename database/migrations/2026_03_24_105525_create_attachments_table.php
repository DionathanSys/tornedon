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
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->morphs('attachable');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            
            $table->string('type', 50);
            $table->string('idempotency_key', 100)->nullable();
            
            $table->integer('version')->default(1);
            $table->boolean('is_current')->default(true);
            
            $table->string('disk', 50);
            $table->string('path');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('extension', 20)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64)->nullable();
            
            $table->json('metadata')->nullable();
            
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes according to plan
            $table->index(['attachable_type', 'attachable_id', 'type', 'is_current'], 'idx_attach_type_current');
            $table->unique(['attachable_type', 'attachable_id', 'type', 'version'], 'unq_attach_type_version');
            // A unique index on a nullable column only allows one null in some SQL dialects, but Laravel handles this or we specify it if idempotency_key is filled.
            // On MySQL nullable unique behaves correctly: allows multiple NULLs.
            $table->unique(['attachable_type', 'attachable_id', 'type', 'idempotency_key'], 'unq_attach_type_idem');
            
            $table->index(['company_id', 'created_at'], 'idx_company_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
