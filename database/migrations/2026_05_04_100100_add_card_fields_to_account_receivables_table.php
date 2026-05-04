<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_receivables', function (Blueprint $table) {
            $table->foreignId('card_payment_profile_id')
                ->nullable()
                ->after('payment_method')
                ->constrained('card_payment_profiles')
                ->nullOnDelete();
            $table->decimal('gross_amount', 15, 4)
                ->default(0)
                ->after('card_payment_profile_id');
            $table->decimal('card_fee_percent_snapshot', 10, 4)
                ->nullable()
                ->after('gross_amount');
            $table->decimal('card_fee_fixed_snapshot', 15, 4)
                ->nullable()
                ->after('card_fee_percent_snapshot');
            $table->decimal('card_fee_amount', 15, 4)
                ->default(0)
                ->after('card_fee_fixed_snapshot');
            $table->decimal('net_amount', 15, 4)
                ->default(0)
                ->after('card_fee_amount');
            $table->date('payment_date')
                ->nullable()
                ->after('net_amount');
            $table->unsignedInteger('settlement_days_snapshot')
                ->nullable()
                ->after('payment_date');
            $table->date('expected_settlement_date')
                ->nullable()
                ->after('settlement_days_snapshot');
            $table->json('card_rule_snapshot')
                ->nullable()
                ->after('expected_settlement_date');

            $table->index(['company_id', 'expected_settlement_date'], 'account_receivables_company_settlement_idx');
            $table->index(['company_id', 'card_payment_profile_id'], 'account_receivables_company_card_profile_idx');
        });

        DB::table('account_receivables')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $due = round((float) $row->due_amount, 4);

                    DB::table('account_receivables')
                        ->where('id', $row->id)
                        ->update([
                            'gross_amount' => $due,
                            'card_fee_amount' => 0,
                            'net_amount' => $due,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('account_receivables', function (Blueprint $table) {
            $table->dropIndex('account_receivables_company_card_profile_idx');
            $table->dropIndex('account_receivables_company_settlement_idx');
            $table->dropConstrainedForeignId('card_payment_profile_id');
            $table->dropColumn([
                'gross_amount',
                'card_fee_percent_snapshot',
                'card_fee_fixed_snapshot',
                'card_fee_amount',
                'net_amount',
                'payment_date',
                'settlement_days_snapshot',
                'expected_settlement_date',
                'card_rule_snapshot',
            ]);
        });
    }
};
