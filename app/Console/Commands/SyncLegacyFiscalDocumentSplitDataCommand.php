<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncLegacyFiscalDocumentSplitDataCommand extends Command
{
    protected $signature = 'fiscal-documents:sync-legacy-split-data {--chunk=500} {--dry-run}';

    protected $description = 'Sincroniza JSONs legados de documentos fiscais para as tabelas auxiliares sem remover dados antigos.';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($this->option('dry-run')) {
            $taxCandidates = $this->legacyTaxDocumentsQuery()->count();
            $payloadCandidates = $this->legacyPayloadDocumentsQuery()->count();

            $this->info("Dry-run: {$taxCandidates} documentos têm dados fiscais legados para avaliar.");
            $this->info("Dry-run: {$payloadCandidates} documentos têm payloads legados para avaliar.");

            return self::SUCCESS;
        }

        $stats = [
            'tax_documents_processed' => 0,
            'tax_details_created' => 0,
            'tax_details_updated' => 0,
            'payload_documents_processed' => 0,
            'payloads_created' => 0,
            'payloads_updated' => 0,
        ];

        $this->legacyTaxDocumentsQuery()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($documents) use (&$stats): void {
                foreach ($documents as $document) {
                    $stats['tax_documents_processed']++;

                    $result = $this->syncTaxDetail($document);

                    if ($result !== null) {
                        $stats[$result]++;
                    }
                }
            });

        $this->legacyPayloadDocumentsQuery()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($documents) use (&$stats): void {
                foreach ($documents as $document) {
                    $stats['payload_documents_processed']++;

                    $result = $this->syncPayload($document);

                    if ($result !== null) {
                        $stats[$result]++;
                    }
                }
            });

        $this->info("Documentos fiscais avaliados para dados fiscais: {$stats['tax_documents_processed']}.");
        $this->info("Detalhes fiscais criados: {$stats['tax_details_created']}.");
        $this->info("Detalhes fiscais atualizados: {$stats['tax_details_updated']}.");
        $this->info("Documentos fiscais avaliados para payloads: {$stats['payload_documents_processed']}.");
        $this->info("Payloads criados: {$stats['payloads_created']}.");
        $this->info("Payloads atualizados: {$stats['payloads_updated']}.");

        return self::SUCCESS;
    }

    private function legacyTaxDocumentsQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('fiscal_documents')
            ->select(['id', 'company_id', 'freight_data', 'payment_data', 'tax_data', 'created_at', 'updated_at'])
            ->where(function ($query): void {
                $query->whereNotNull('freight_data')
                    ->orWhereNotNull('payment_data')
                    ->orWhereNotNull('tax_data');
            });
    }

    private function legacyPayloadDocumentsQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('fiscal_documents')
            ->select(['id', 'company_id', 'nfe_payload', 'nfse_payload', 'created_at', 'updated_at'])
            ->where(function ($query): void {
                $query->whereNotNull('nfe_payload')
                    ->orWhereNotNull('nfse_payload');
            });
    }

    private function syncTaxDetail(object $document): ?string
    {
        $existing = DB::table('fiscal_document_tax_details')
            ->where('fiscal_document_id', $document->id)
            ->first();

        $values = $this->missingValues($existing, [
            'freight_data' => $document->freight_data,
            'payment_data' => $document->payment_data,
            'tax_data' => $document->tax_data,
        ]);

        [$taxTotals, $fiscalMetadata] = $this->splitTaxData($document->tax_data);

        $values += $this->missingValues($existing, [
            'tax_totals' => $taxTotals,
            'fiscal_metadata' => $fiscalMetadata,
        ]);

        if ($values === []) {
            return null;
        }

        $now = now();

        if ($existing === null) {
            DB::table('fiscal_document_tax_details')->insert([
                'company_id' => $document->company_id,
                'fiscal_document_id' => $document->id,
                ...$values,
                'created_at' => $document->created_at ?? $now,
                'updated_at' => $document->updated_at ?? $now,
            ]);

            return 'tax_details_created';
        }

        DB::table('fiscal_document_tax_details')
            ->where('id', $existing->id)
            ->update([
                ...$values,
                'updated_at' => $now,
            ]);

        return 'tax_details_updated';
    }

    private function syncPayload(object $document): ?string
    {
        $existing = DB::table('fiscal_document_payloads')
            ->where('fiscal_document_id', $document->id)
            ->first();

        $values = $this->missingValues($existing, [
            'nfe_payload' => $document->nfe_payload,
            'nfse_payload' => $document->nfse_payload,
        ]);

        if ($values === []) {
            return null;
        }

        $now = now();

        if ($existing === null) {
            DB::table('fiscal_document_payloads')->insert([
                'company_id' => $document->company_id,
                'fiscal_document_id' => $document->id,
                ...$values,
                'created_at' => $document->created_at ?? $now,
                'updated_at' => $document->updated_at ?? $now,
            ]);

            return 'payloads_created';
        }

        DB::table('fiscal_document_payloads')
            ->where('id', $existing->id)
            ->update([
                ...$values,
                'updated_at' => $now,
            ]);

        return 'payloads_updated';
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function missingValues(?object $existing, array $values): array
    {
        $missing = [];

        foreach ($values as $key => $value) {
            if ($value === null || ($existing !== null && $existing->{$key} !== null)) {
                continue;
            }

            $missing[$key] = $this->jsonValue($value);
        }

        return $missing;
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private function splitTaxData(mixed $value): array
    {
        $taxData = $this->jsonArray($value);

        if ($taxData === null) {
            return [null, null];
        }

        $totals = $taxData['totais'] ?? null;
        unset($taxData['totais']);

        return [
            is_array($totals) ? $totals : null,
            $taxData !== [] ? $taxData : null,
        ];
    }

    private function jsonArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function jsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }
}
