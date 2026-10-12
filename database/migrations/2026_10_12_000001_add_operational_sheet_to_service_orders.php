<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table): void {
            $table->string('reported_by_name')->nullable();
            $table->string('reported_by_role', 20)->nullable();
            $table->unsignedInteger('entry_mileage')->nullable();
        });

        Schema::create('service_order_defects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
            $table->text('report');
            $table->text('diagnosis')->nullable();
            $table->text('solution')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_defects');
        Schema::table('service_orders', function (Blueprint $table): void {
            $table->dropColumn(['reported_by_name', 'reported_by_role', 'entry_mileage']);
        });
    }
};
