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
        Schema::table('company_partner', function (Blueprint $table) {
            $table->boolean('notify_service_order_closed')
                ->default(false)
                ->after('is_active');
            $table->boolean('notify_requisition_closed')
                ->default(false)
                ->after('notify_service_order_closed');
            $table->boolean('notify_fiscal_document_confirmed')
                ->default(false)
                ->after('notify_requisition_closed');

            //TODO Remover os três campos abaixo, deve ser utilizado o email que esta no contacts marcado como notify = true
            $table->text('email_to_override')
                ->nullable()
                ->after('notify_fiscal_document_confirmed');
            $table->text('email_cc_override')
                ->nullable()
                ->after('email_to_override');
            $table->text('email_bcc_override')
                ->nullable()
                ->after('email_cc_override');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_partner', function (Blueprint $table) {
            $table->dropColumn([
                'notify_service_order_closed',
                'notify_requisition_closed',
                'notify_fiscal_document_confirmed',
                'email_to_override',
                'email_cc_override',
                'email_bcc_override',
            ]);
        });
    }
};
