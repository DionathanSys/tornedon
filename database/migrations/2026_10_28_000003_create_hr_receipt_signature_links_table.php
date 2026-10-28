<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_receipt_signatures', function (Blueprint $table) {
            $table->unsignedBigInteger('recorded_by')->nullable()->change();
        });
        Schema::create('hr_receipt_signature_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('hr_receipt_id')->constrained('hr_receipts')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('hr_receipt_signatures', function (Blueprint $table) {
            $table->unsignedBigInteger('recorded_by')->nullable(false)->change();
        });
        Schema::dropIfExists('hr_receipt_signature_links');
    }
};
