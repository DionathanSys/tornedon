<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'auto_pix_charge_issuance')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->boolean('auto_pix_charge_issuance')->nullable()->after('auto_bank_slip_issuance');
            });
        }

        if (! Schema::hasColumn('account_receivable_installments', 'auto_pix_charge_issuance')) {
            Schema::table('account_receivable_installments', function (Blueprint $table): void {
                $table->boolean('auto_pix_charge_issuance')->nullable()->after('auto_bank_slip_issuance');
            });
        }

        Schema::create('pix_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'pix_charges_company_fk')
                ->cascadeOnDelete();
            $table->foreignId('account_receivable_installment_id')
                ->constrained('account_receivable_installments', indexName: 'pix_charges_installment_fk')
                ->restrictOnDelete();
            $table->foreignId('bank_account_connection_id')
                ->constrained('bank_account_connections', indexName: 'pix_charges_connection_fk')
                ->restrictOnDelete();
            $table->string('status', 40)->index('pix_charges_status_idx');
            $table->string('provider_identification', 120);
            $table->string('provider_charge_id', 160)->nullable();
            $table->decimal('amount', 15, 4);
            $table->date('due_date');
            $table->text('qr_code')->nullable();
            $table->text('pix_copy_paste')->nullable();
            $table->string('provider_status_code', 30)->nullable();
            $table->string('provider_status_message')->nullable();
            $table->dateTime('registered_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('last_synchronized_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();

            $table->unique(['bank_account_connection_id', 'provider_identification'], 'pix_charges_provider_identification_unique');
            $table->index(['company_id', 'status'], 'pix_charges_company_status_idx');
            $table->index(['account_receivable_installment_id', 'status'], 'pix_charges_installment_status_idx');
        });

        if (! Schema::hasColumn('account_receivable_installment_payments', 'pix_charge_id')) {
            Schema::table('account_receivable_installment_payments', function (Blueprint $table): void {
                $table->foreignId('pix_charge_id')
                    ->nullable()
                    ->after('bank_slip_event_id')
                    ->constrained('pix_charges', indexName: 'arip_pix_charge_fk')
                    ->nullOnDelete();
            });
        }

        DB::table('billing_providers')
            ->where('key', 'integrabancos')
            ->update(['capabilities' => json_encode(['bank_slip' => true, 'pix' => true])]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('account_receivable_installment_payments', 'pix_charge_id')) {
            Schema::table('account_receivable_installment_payments', function (Blueprint $table): void {
                $table->dropForeign('arip_pix_charge_fk');
                $table->dropColumn('pix_charge_id');
            });
        }

        Schema::dropIfExists('pix_charges');

        if (Schema::hasColumn('account_receivable_installments', 'auto_pix_charge_issuance')) {
            Schema::table('account_receivable_installments', function (Blueprint $table): void {
                $table->dropColumn('auto_pix_charge_issuance');
            });
        }

        if (Schema::hasColumn('invoices', 'auto_pix_charge_issuance')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropColumn('auto_pix_charge_issuance');
            });
        }

        DB::table('billing_providers')
            ->where('key', 'integrabancos')
            ->update(['capabilities' => json_encode(['bank_slip' => true])]);
    }
};
