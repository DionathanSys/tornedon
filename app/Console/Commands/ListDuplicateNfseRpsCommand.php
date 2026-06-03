<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListDuplicateNfseRpsCommand extends Command
{
    protected $signature = 'nfse:rps-duplicates
        {--company= : Filtra por company_id}
        {--series= : Filtra por rps_series}
        {--rps= : Filtra por rps_number}
        {--limit=100 : Limite de grupos duplicados exibidos}';

    protected $description = 'Lista documentos fiscais NFS-e com RPS duplicado por empresa e série';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $query = DB::table('fiscal_documents')
            ->select([
                'company_id',
                'document_type',
                'rps_series',
                'rps_number',
                DB::raw('COUNT(*) as duplicates_count'),
                DB::raw('GROUP_CONCAT(id ORDER BY id SEPARATOR ",") as document_ids'),
                DB::raw('GROUP_CONCAT(COALESCE(nfse_status, "null") ORDER BY id SEPARATOR ",") as nfse_statuses'),
            ])
            ->where('document_type', 'nfse')
            ->whereNotNull('rps_series')
            ->whereNotNull('rps_number')
            ->groupBy('company_id', 'document_type', 'rps_series', 'rps_number')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('company_id')
            ->orderBy('rps_series')
            ->orderByRaw('CAST(rps_number AS UNSIGNED)')
            ->orderBy('rps_number');

        if ($this->option('company') !== null) {
            $query->where('company_id', (int) $this->option('company'));
        }

        if ($this->option('series') !== null) {
            $query->where('rps_series', (string) $this->option('series'));
        }

        if ($this->option('rps') !== null) {
            $query->where('rps_number', (string) $this->option('rps'));
        }

        $duplicates = $query
            ->limit($limit)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('Nenhuma duplicidade de RPS encontrada para os filtros informados.');

            return self::SUCCESS;
        }

        $this->warn('Duplicidades de RPS encontradas:');
        $this->newLine();

        $this->table(
            ['company_id', 'serie', 'rps_number', 'quantidade', 'document_ids', 'nfse_statuses'],
            $duplicates->map(fn (object $duplicate): array => [
                'company_id' => (int) $duplicate->company_id,
                'serie' => (string) $duplicate->rps_series,
                'rps_number' => (string) $duplicate->rps_number,
                'quantidade' => (int) $duplicate->duplicates_count,
                'document_ids' => (string) $duplicate->document_ids,
                'nfse_statuses' => (string) $duplicate->nfse_statuses,
            ])->all()
        );

        $totalGroups = $duplicates->count();

        $this->newLine();
        $this->line("Grupos exibidos: {$totalGroups}");

        if ($totalGroups === $limit) {
            $this->line('O resultado pode ter sido truncado pelo limite informado.');
        }

        return self::FAILURE;
    }
}
