<?php

namespace App\Services\Fiscal\Sefaz;

use App\Jobs\ManifestSefazDistributionDocumentJob;
use App\Models\Company;
use App\Models\CompanyPreference;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SefazDfeSyncService
{
    public const LAST_NSU_KEY = 'sefaz.distribuicao_dfe.ultimo_nsu';
    public const LAST_RUN_AT_KEY = 'sefaz.distribuicao_dfe.last_run_at';
    public const LAST_SUCCESS_AT_KEY = 'sefaz.distribuicao_dfe.last_success_at';
    public const LAST_STATUS_KEY = 'sefaz.distribuicao_dfe.last_status';
    public const LAST_ERROR_KEY = 'sefaz.distribuicao_dfe.last_error';

    public function __construct(
        private readonly SefazDfeDistributionService $distributionService,
        private readonly SefazDistributionDocumentService $documentService,
        private readonly SefazDfeStorageService $storageService,
    ) {
    }

    /**
     * @return array{documents:int,status_code:string,status_message:string,ult_nsu:?string}
     */
    public function syncCompany(Company $company): array
    {
        CompanyPreference::set(self::LAST_RUN_AT_KEY, now()->toIso8601String(), $company->id);
        CompanyPreference::set(self::LAST_STATUS_KEY, 'running', $company->id);
        CompanyPreference::set(self::LAST_ERROR_KEY, null, $company->id);

        $lastNsuPref = CompanyPreference::get(self::LAST_NSU_KEY, $company->id, '0');
        $lastNsu = (string) (is_array($lastNsuPref) ? ($lastNsuPref['value'] ?? '0') : ($lastNsuPref ?? '0'));

        Log::info('SefazDfeSyncService: iniciando sincronização DF-e', [
            'company_id' => $company->id,
            'company_document' => $company->document_number,
            'last_nsu' => $lastNsu,
        ]);

        $result = $this->distributionService->distribute($company, 'ultimo_nsu', $lastNsu);
        if (! $result->success) {
            $message = trim("{$result->statusCode} - {$result->statusMessage}", ' -');
            throw new RuntimeException($message !== '' ? $message : 'A SEFAZ rejeitou a sincronização DF-e.');
        }

        $rawResponsePath = $this->storageService->storeRawResponse($company, $result->rawXml);

        $persistedCount = 0;

        foreach ($result->documents as $document) {
            $record = $this->documentService->persistFromDistribution($company, $document, $rawResponsePath);
            if ($record === null) {
                continue;
            }

            $persistedCount++;

            if (! $record->full_xml_available && $record->manifestation_status->value === 'pending') {
                ManifestSefazDistributionDocumentJob::dispatch($record->id);
            }
        }

        if ($result->ultNsu !== null) {
            CompanyPreference::set(self::LAST_NSU_KEY, $result->ultNsu, $company->id);
        }

        CompanyPreference::set(self::LAST_SUCCESS_AT_KEY, now()->toIso8601String(), $company->id);
        CompanyPreference::set(self::LAST_STATUS_KEY, 'success', $company->id);

        Log::info('SefazDfeSyncService: sincronização DF-e concluída', [
            'company_id' => $company->id,
            'status_code' => $result->statusCode,
            'status_message' => $result->statusMessage,
            'documents_found' => count($result->documents),
            'documents_persisted' => $persistedCount,
            'ult_nsu' => $result->ultNsu,
        ]);

        return [
            'documents' => $persistedCount,
            'status_code' => $result->statusCode,
            'status_message' => $result->statusMessage,
            'ult_nsu' => $result->ultNsu,
        ];
    }

    public function markFailure(Company $company, \Throwable $exception): void
    {
        CompanyPreference::set(self::LAST_STATUS_KEY, 'error', $company->id);
        CompanyPreference::set(self::LAST_ERROR_KEY, $exception->getMessage(), $company->id);

        Log::error('SefazDfeSyncService: sincronização DF-e falhou', [
            'company_id' => $company->id,
            'company_document' => $company->document_number,
            'error' => $exception->getMessage(),
        ]);
    }
}
