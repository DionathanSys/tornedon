<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_partner', function (Blueprint $table) {
            $table->boolean('notify_production_order_closed')
                ->default(false)
                ->after('notify_requisition_closed');
            $table->boolean('notify_invoice_confirmed')
                ->default(false)
                ->after('notify_production_order_closed');
        });
    }

    public function down(): void
    {
        Schema::table('company_partner', function (Blueprint $table) {
            $table->dropColumn([
                'notify_production_order_closed',
                'notify_invoice_confirmed',
            ]);
        });
    }
};
