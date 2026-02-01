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
        Schema::create('error_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('error_code', 50)->unique()->index();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->string('url')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('status');
            $table->string('priority');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('error_tickets');
    }
};
