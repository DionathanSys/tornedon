<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('operation_nature');
            $table->string('default_cfop', 4);
            $table->json('cfop_exceptions')
                ->nullable();
            $table->boolean('is_active')
                ->default(true);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'operation_nature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_rules');
    }
};
