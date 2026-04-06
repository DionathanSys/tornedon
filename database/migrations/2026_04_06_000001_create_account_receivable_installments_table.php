<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_receivable_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_receivable_id')
                ->constrained('account_receivables')
                ->cascadeOnDelete();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->string('sequence_number', 3);
            $table->string('status')
                ->index();
            $table->date('due_date');
            $table->date('received_date')
                ->nullable();
            $table->decimal('original_amount', 15, 4);
            $table->decimal('interest_amount', 15, 4)
                ->default(0);
            $table->decimal('fine_amount', 15, 4)
                ->default(0);
            $table->decimal('discount_amount', 15, 4)
                ->default(0);
            $table->decimal('due_amount', 15, 4);
            $table->decimal('received_amount', 15, 4)
                ->default(0);
            $table->decimal('balance_amount', 15, 4);
            $table->unsignedBigInteger('bank_account_id')
                ->nullable();
            $table->unsignedBigInteger('financial_category_id')
                ->nullable();
            $table->unsignedBigInteger('cost_center_id')
                ->nullable();
            $table->text('notes')
                ->nullable();
            $table->timestamps();

            $table->index(['account_receivable_id', 'sequence_number'], 'ari_account_seq_idx');
            $table->index(['company_id', 'due_date'], 'ari_company_due_idx');
        });

        $this->backfillExistingReceivableInstallments();
    }

    public function down(): void
    {
        Schema::dropIfExists('account_receivable_installments');
    }

    private function backfillExistingReceivableInstallments(): void
    {
        if (! Schema::hasTable('account_receivables') || ! Schema::hasTable('account_receivable_installments')) {
            return;
        }

        $now = now();

        DB::table('account_receivables')
            ->orderBy('id')
            ->chunkById(200, function ($receivables) use ($now): void {
                $rows = [];

                foreach ($receivables as $receivable) {
                    $dueAmount = (float) $receivable->due_amount;
                    $receivedAmount = (float) $receivable->paid_amount;

                    $rows[] = [
                        'account_receivable_id' => $receivable->id,
                        'company_id' => $receivable->company_id,
                        'sequence_number' => $receivable->sequence_number ?: '01',
                        'status' => $receivable->status,
                        'due_date' => $receivable->due_date,
                        'received_date' => $receivable->paid_date,
                        'original_amount' => $dueAmount,
                        'interest_amount' => 0,
                        'fine_amount' => 0,
                        'discount_amount' => 0,
                        'due_amount' => $dueAmount,
                        'received_amount' => $receivedAmount,
                        'balance_amount' => max(round($dueAmount - $receivedAmount, 4), 0),
                        'bank_account_id' => null,
                        'financial_category_id' => null,
                        'cost_center_id' => null,
                        'notes' => $receivable->description,
                        'created_at' => $receivable->created_at ?? $now,
                        'updated_at' => $receivable->updated_at ?? $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('account_receivable_installments')->insert($rows);
                }
            });
    }
};
