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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')                        // Cliente que comprou (obrigatório)
                ->constrained('partners');
            $table->foreignId('company_id')                         // Empresa prestadora
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->string('invoice_number')
                ->unique();
            $table->date('invoice_date');
            $table->decimal('total_amount', 15, 4)
                ->default(0);
            $table->decimal('discount_amount', 15, 4)
                ->default(0);
            $table->string('status');
            $table->boolean('pending')
                ->default(true);
            $table->boolean('confirmed')
                ->default(false);
            $table->boolean('canceled')
                ->default(false);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('confirmed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('canceled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->datetime('confirmed_at')
                ->nullable();
            $table->datetime('canceled_at')
                ->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
