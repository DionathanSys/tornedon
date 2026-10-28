<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_receipt_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('hr_receipt_id')->unique()->constrained('hr_receipts')->restrictOnDelete();
            $table->string('signed_by_name');
            $table->longText('signature');
            $table->timestamp('signed_at');
            $table->string('document_hash', 64);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_receipt_signatures');
    }
};
