<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $this->assertNoDuplicateRpsNumbers();

        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'document_type', 'rps_series', 'rps_number'],
                'fd_company_doc_type_rps_unique'
            );
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropUnique('fd_company_doc_type_rps_unique');
        });
    }

    private function assertNoDuplicateRpsNumbers(): void
    {
        $duplicates = DB::table('fiscal_documents')
            ->select([
                'company_id',
                'document_type',
                'rps_series',
                'rps_number',
                DB::raw('COUNT(*) as duplicates_count'),
                DB::raw('GROUP_CONCAT(id ORDER BY id SEPARATOR ",") as document_ids'),
            ])
            ->whereNotNull('rps_series')
            ->whereNotNull('rps_number')
            ->groupBy('company_id', 'document_type', 'rps_series', 'rps_number')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('company_id')
            ->orderBy('document_type')
            ->orderBy('rps_series')
            ->orderBy('rps_number')
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $details = $duplicates
            ->take(10)
            ->map(fn (object $duplicate): string => sprintf(
                'company_id=%d document_type=%s rps=%s/%s ids=[%s] quantidade=%d',
                (int) $duplicate->company_id,
                (string) $duplicate->document_type,
                (string) $duplicate->rps_series,
                (string) $duplicate->rps_number,
                (string) $duplicate->document_ids,
                (int) $duplicate->duplicates_count,
            ))
            ->implode('; ');

        throw new RuntimeException(
            'Não foi possível criar o índice único de RPS porque existem documentos fiscais com RPS duplicado. '
            .'Concilie os registros legados antes de rodar esta migration. '
            .'Duplicidades encontradas: '.$details
        );
    }
};
