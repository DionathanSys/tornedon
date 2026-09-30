<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->prepareInvoiceAndInstallmentColumns();
        $this->createCompanyEntitlementsTable();
        $this->createBanksTable();
        $this->createBillingProvidersTable();
        $this->createBankAccountConnectionsTable();
        $this->createBankSlipsTable();
        $this->createBankSlipEventsTable();
        $this->prepareInstallmentPaymentColumns();
    }

    public function down(): void
    {
        $this->dropInstallmentPaymentColumns();
        Schema::dropIfExists('bank_slip_events');
        Schema::dropIfExists('bank_slips');
        Schema::dropIfExists('bank_account_connections');
        Schema::dropIfExists('billing_providers');
        Schema::dropIfExists('banks');
        Schema::dropIfExists('company_entitlements');

        if (Schema::hasColumn('account_receivable_installments', 'auto_bank_slip_issuance')) {
            Schema::table('account_receivable_installments', function (Blueprint $table): void {
                $table->dropColumn('auto_bank_slip_issuance');
            });
        }

        if (Schema::hasColumn('account_receivable_installments', 'financial_account_id')) {
            Schema::table('account_receivable_installments', function (Blueprint $table): void {
                $table->unsignedBigInteger('bank_account_id')->nullable()->after('balance_amount');
            });

            DB::statement(
                'UPDATE account_receivable_installments SET bank_account_id = financial_account_id'
            );

            Schema::table('account_receivable_installments', function (Blueprint $table): void {
                $table->dropForeign('ari_financial_account_fk');
                $table->dropColumn('financial_account_id');
            });
        }

        if (Schema::hasColumn('invoices', 'auto_bank_slip_issuance')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropColumn('auto_bank_slip_issuance');
            });
        }
    }

    private function prepareInvoiceAndInstallmentColumns(): void
    {
        if (! Schema::hasColumn('invoices', 'auto_bank_slip_issuance')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->boolean('auto_bank_slip_issuance')->nullable()->after('confirmed');
            });
        }

        if (! Schema::hasColumn('account_receivable_installments', 'financial_account_id')) {
            Schema::table('account_receivable_installments', function (Blueprint $table): void {
                $table->unsignedBigInteger('financial_account_id')
                    ->nullable()
                    ->after('balance_amount');
            });
        }

        if (Schema::hasColumn('account_receivable_installments', 'bank_account_id')) {
            DB::statement(
                'UPDATE account_receivable_installments SET financial_account_id = bank_account_id WHERE financial_account_id IS NULL'
            );

            Schema::table('account_receivable_installments', function (Blueprint $table): void {
                $table->dropColumn('bank_account_id');
            });
        }

        if (! $this->foreignKeyExists('account_receivable_installments', 'ari_financial_account_fk')) {
            Schema::table('account_receivable_installments', function (Blueprint $table): void {
                $table->foreign('financial_account_id', 'ari_financial_account_fk')
                    ->references('id')
                    ->on('financial_accounts')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('account_receivable_installments', 'auto_bank_slip_issuance')) {
            Schema::table('account_receivable_installments', function (Blueprint $table): void {
                $table->boolean('auto_bank_slip_issuance')->nullable()->after('financial_account_id');
            });
        }
    }

    private function createCompanyEntitlementsTable(): void
    {
        if (Schema::hasTable('company_entitlements')) {
            return;
        }

        Schema::create('company_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('feature', 100);
            $table->boolean('enabled')->default(false);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'feature']);
            $table->index(['feature', 'enabled']);
        });
    }

    private function createBanksTable(): void
    {
        if (Schema::hasTable('banks')) {
            return;
        }

        Schema::create('banks', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function createBillingProvidersTable(): void
    {
        if (Schema::hasTable('billing_providers')) {
            return;
        }

        Schema::create('billing_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name');
            $table->string('adapter_class')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });

        DB::table('billing_providers')->insert([
            'key' => 'integrabancos',
            'name' => 'IntegraBancos',
            'adapter_class' => 'App\\Services\\Financial\\Banking\\Providers\\IntegraBancosProvider',
            'is_active' => true,
            'capabilities' => json_encode(['bank_slip' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBankAccountConnectionsTable(): void
    {
        if (Schema::hasTable('bank_account_connections')) {
            return;
        }

        Schema::create('bank_account_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('bank_id')->constrained('banks')->restrictOnDelete();
            $table->foreignId('billing_provider_id')->constrained('billing_providers')->restrictOnDelete();
            $table->string('environment', 30)->default('production');
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->string('status', 30)->default('active');
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['financial_account_id', 'status']);
        });
    }

    private function createBankSlipsTable(): void
    {
        if (Schema::hasTable('bank_slips')) {
            return;
        }

        Schema::create('bank_slips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('account_receivable_installment_id')
                ->constrained('account_receivable_installments')
                ->restrictOnDelete();
            $table->foreignId('bank_account_connection_id')
                ->constrained('bank_account_connections')
                ->restrictOnDelete();
            $table->string('status', 40)->index();
            $table->string('provider_identification', 120);
            $table->string('provider_charge_id', 160)->nullable();
            $table->decimal('amount', 15, 4);
            $table->date('due_date');
            $table->text('pdf_url')->nullable();
            $table->string('digitable_line', 255)->nullable();
            $table->string('barcode', 255)->nullable();
            $table->string('provider_status_code', 30)->nullable();
            $table->string('provider_status_message')->nullable();
            $table->dateTime('registered_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('cancel_requested_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->dateTime('last_synchronized_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();

            $table->unique(['bank_account_connection_id', 'provider_identification'], 'bank_slips_provider_identification_unique');
            $table->index(['company_id', 'status']);
            $table->index(['account_receivable_installment_id', 'status'], 'bank_slips_installment_status_idx');
        });
    }

    private function createBankSlipEventsTable(): void
    {
        if (Schema::hasTable('bank_slip_events')) {
            return;
        }

        Schema::create('bank_slip_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('bank_account_connection_id')
                ->constrained('bank_account_connections')
                ->restrictOnDelete();
            $table->foreignId('billing_provider_id')
                ->constrained('billing_providers')
                ->restrictOnDelete();
            $table->foreignId('bank_slip_id')->nullable()->constrained('bank_slips')->nullOnDelete();
            $table->string('provider_event_id', 160);
            $table->string('event_type', 50)->nullable();
            $table->string('provider_status_code', 30)->nullable();
            $table->decimal('amount', 15, 4)->nullable();
            $table->json('payload');
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['billing_provider_id', 'provider_event_id'], 'bank_slip_events_provider_event_unique');
            $table->index(['bank_slip_id', 'processed_at']);
        });
    }

    private function prepareInstallmentPaymentColumns(): void
    {
        if (! Schema::hasColumn('account_receivable_installment_payments', 'bank_slip_id')) {
            Schema::table('account_receivable_installment_payments', function (Blueprint $table): void {
                $table->foreignId('bank_slip_id')
                    ->nullable()
                    ->after('financial_account_id');
            });
        }

        if (! $this->foreignKeyColumnExists('account_receivable_installment_payments', 'bank_slip_id')) {
            Schema::table('account_receivable_installment_payments', function (Blueprint $table): void {
                $table->foreign('bank_slip_id', 'arip_bank_slip_fk')
                    ->references('id')
                    ->on('bank_slips')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('account_receivable_installment_payments', 'bank_slip_event_id')) {
            Schema::table('account_receivable_installment_payments', function (Blueprint $table): void {
                $table->foreignId('bank_slip_event_id')
                    ->nullable()
                    ->after('bank_slip_id');
            });
        }

        if (! $this->foreignKeyColumnExists('account_receivable_installment_payments', 'bank_slip_event_id')) {
            Schema::table('account_receivable_installment_payments', function (Blueprint $table): void {
                $table->foreign('bank_slip_event_id', 'arip_bank_slip_event_fk')
                    ->references('id')
                    ->on('bank_slip_events')
                    ->nullOnDelete();
            });
        }

        if (! $this->indexExists('account_receivable_installment_payments', 'arip_bank_slip_event_unique')) {
            Schema::table('account_receivable_installment_payments', function (Blueprint $table): void {
                $table->unique('bank_slip_event_id', 'arip_bank_slip_event_unique');
            });
        }
    }

    private function dropInstallmentPaymentColumns(): void
    {
        if (! Schema::hasTable('account_receivable_installment_payments')) {
            return;
        }

        if (Schema::hasColumn('account_receivable_installment_payments', 'bank_slip_event_id')) {
            Schema::table('account_receivable_installment_payments', function (Blueprint $table): void {
                $table->dropUnique('arip_bank_slip_event_unique');
                $table->dropForeign('arip_bank_slip_event_fk');
                $table->dropColumn('bank_slip_event_id');
            });
        }

        if (Schema::hasColumn('account_receivable_installment_payments', 'bank_slip_id')) {
            Schema::table('account_receivable_installment_payments', function (Blueprint $table): void {
                $table->dropForeign('arip_bank_slip_fk');
                $table->dropColumn('bank_slip_id');
            });
        }
    }

    private function foreignKeyExists(string $tableName, string $foreignKeyName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return false;
        }

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tableName)
            ->where('CONSTRAINT_NAME', $foreignKeyName)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private function foreignKeyColumnExists(string $tableName, string $columnName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return false;
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tableName)
            ->where('COLUMN_NAME', $columnName)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return false;
        }

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tableName)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }
};
