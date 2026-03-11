<?php

namespace App\Services\FiscalDocument\Validators;

use App\Enum\FiscalDocument\DocumentModel;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Validators\Items\NfeItemValidator;
use Illuminate\Validation\ValidationException;

/**
 * Resolve os validators corretos com base no document_type (modelo do documento).
 *
 * Centraliza a lógica de seleção para que CreateFiscalDocumentAction
 * e UpdateFiscalDocumentAction não precisem conhecer cada validator específico.
 */
class FiscalDocumentValidatorResolver
{
    /**
     * Executa validação completa para criação: regras comuns + específicas por modelo + itens.
     *
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        // 1. Regras comuns (cabeçalho)
        $validated = FiscalDocumentValidator::validateCreate($data);

        // 2. Regras específicas por modelo — merge para que campos
        //    como operation_nature, operation_type, etc. sejam persistidos.
        $documentType = $data['document_type'] ?? null;

        $specificValidated = match ($documentType) {
            DocumentModel::NFE->value => NfeDocumentValidator::validateCreate($data),
            default => [],
        };

        $validated = array_merge($validated, $specificValidated);

        // 3. Validação do perfil fiscal (NF-e)
        if ($documentType === DocumentModel::NFE->value) {
            $companyId = $data['company_id'] ?? null;

            if ($companyId) {
                FiscalProfileValidator::validateProfileExists($companyId);

                if (! empty($data['items'])) {
                    FiscalProfileValidator::validateItemsTaxCompatibility($companyId, $data['items']);
                }

                // 4. Valida que existe CFOP configurado para a operação selecionada
                if (! empty($data['operation_nature'])) {
                    FiscalProfileValidator::validateOperationNatureConfigured($companyId, $data['operation_nature']);
                }
            }
        }

        return $validated;
    }

    /**
     * Executa validação completa para atualização: regras comuns + específicas por modelo + itens.
     *
     * @throws ValidationException
     */
    public static function validateUpdate(array $data, int $id): array
    {
        // 1. Regras comuns (cabeçalho)
        $validated = FiscalDocumentValidator::validateUpdate($data, $id);

        // 2. Regras específicas por modelo — merge para que campos
        //    como operation_nature, operation_type, etc. sejam persistidos.
        $documentType = $data['document_type'] ?? null;

        if ($documentType) {
            $specificValidated = match ($documentType) {
                DocumentModel::NFE->value => NfeDocumentValidator::validateUpdate($data),
                default => [],
            };

            $validated = array_merge($validated, $specificValidated);
        }

        // 3. Regras de itens por modelo (apenas se itens foram enviados na atualização)
        if (! empty($data['items']) && $documentType) {
            match ($documentType) {
                DocumentModel::NFE->value => NfeItemValidator::validate($data),
                default => null,
            };
        }

        // 4. Validação do perfil fiscal (NF-e)
        if ($documentType === DocumentModel::NFE->value && ! empty($data['items'])) {
            $companyId = $data['company_id'] ?? null;

            if ($companyId) {
                FiscalProfileValidator::validateItemsTaxCompatibility($companyId, $data['items']);
            }
        }

        return $validated;
    }
}
