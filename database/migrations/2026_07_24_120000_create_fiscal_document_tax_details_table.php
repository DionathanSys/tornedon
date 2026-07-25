<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_document_tax_details')) {
            Schema::create('fiscal_document_tax_details', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id');
                $table->foreignId('fiscal_document_id');
                $table->json('freight_data')->nullable();
                $table->json('payment_data')->nullable();
                $table->json('tax_data')->nullable();
                $table->timestamps();

                $table->unique('fiscal_document_id', 'fd_tax_details_document_unique');
                $table->index('company_id', 'fd_tax_details_company_idx');
                $table->foreign('company_id', 'fd_tax_details_company_fk')
                    ->references('id')
                    ->on('companies')
                    ->cascadeOnDelete();
                $table->foreign('fiscal_document_id', 'fd_tax_details_document_fk')
                    ->references('id')
                    ->on('fiscal_documents')
                    ->cascadeOnDelete();
            });
        }

        DB::table('fiscal_documents')
            ->select(['id', 'company_id', 'freight_data', 'payment_data', 'tax_data', 'created_at', 'updated_at'])
            ->where(function ($query): void {
                $query->whereNotNull('freight_data')
                    ->orWhereNotNull('payment_data')
                    ->orWhereNotNull('tax_data');
            })
            ->orderBy('id')
            ->chunkById(500, function ($documents): void {
                $now = now();

                foreach ($documents as $document) {
                    DB::table('fiscal_document_tax_details')->updateOrInsert(
                        ['fiscal_document_id' => $document->id],
                        [
                            'company_id' => $document->company_id,
                            'freight_data' => $document->freight_data,
                            'payment_data' => $document->payment_data,
                            'tax_data' => $document->tax_data,
                            'created_at' => $document->created_at ?? $now,
                            'updated_at' => $document->updated_at ?? $now,
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive: do not drop tables or remove migrated data.
    }
};
