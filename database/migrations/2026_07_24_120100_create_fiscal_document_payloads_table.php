<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_document_payloads')) {
            Schema::create('fiscal_document_payloads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id');
                $table->foreignId('fiscal_document_id');
                $table->json('nfe_payload')
                    ->nullable();
                $table->json('nfse_payload')
                    ->nullable();
                $table->timestamps();

                $table->unique('fiscal_document_id', 'fd_payloads_document_unique');
                $table->index('company_id', 'fd_payloads_company_idx');
                $table->foreign('company_id', 'fd_payloads_company_fk')
                    ->references('id')
                    ->on('companies')
                    ->cascadeOnDelete();
                $table->foreign('fiscal_document_id', 'fd_payloads_document_fk')
                    ->references('id')
                    ->on('fiscal_documents')
                    ->cascadeOnDelete();
            });
        }

        DB::table('fiscal_documents')
            ->select(['id', 'company_id', 'nfe_payload', 'nfse_payload', 'created_at', 'updated_at'])
            ->where(function ($query): void {
                $query->whereNotNull('nfe_payload')
                    ->orWhereNotNull('nfse_payload');
            })
            ->orderBy('id')
            ->chunkById(500, function ($documents): void {
                $now = now();

                foreach ($documents as $document) {
                    DB::table('fiscal_document_payloads')->updateOrInsert(
                        ['fiscal_document_id' => $document->id],
                        [
                            'company_id' => $document->company_id,
                            'nfe_payload' => $document->nfe_payload,
                            'nfse_payload' => $document->nfse_payload,
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
