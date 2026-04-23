<?php

namespace App\Services\FiscalDocument;

use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Domain\DTO\Fiscal\FiscalEmissionPreflightResult;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\NfeSequence;
use App\Services\Fiscal\NfeConfigService;
use App\Services\FiscalDocument\Validators\FiscalProfileValidator;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class FiscalEmissionPreflightService
{
    use HandlesServiceResponse;

    public function validateForQueue(FiscalDocument $document): ?FiscalEmissionPreflightResult
    {
        return $this->validate($document, false);
    }

    public function validateForSend(FiscalDocument $document): ?FiscalEmissionPreflightResult
    {
        return $this->validate($document, true);
    }

    private function validate(FiscalDocument $document, bool $allowQueuedStatus): ?FiscalEmissionPreflightResult
    {
        $this->resetResponse();

        $document->loadMissing([
            'company.fiscalProfile',
            'customer.address',
            'items.product',
        ]);

        if (! $document->isNfe()) {
            $this->setError('O preflight atual suporta apenas emissão de NF-e.');
            return null;
        }

        if (
            $document->isNfeInProcessing()
            || $document->isNfeAuthorized()
            || $document->isNfeCanceled()
            || (! $allowQueuedStatus && $document->isNfeQueued())
        ) {
            $this->setError('Documento fiscal não pode seguir para emissão no estado atual.');
            return null;
        }

        $configService = app(NfeConfigService::class);
        $companyId = (int) $document->company_id;
        $environment = $configService->resolveAmbiente($companyId);
        $series = trim((string) ($document->document_series ?: $configService->resolveSerie($companyId)));
        $operationNature = $document->operation_nature instanceof OperationNature
            ? $document->operation_nature->value
            : trim((string) ($document->operation_nature ?? ''));

        $errors = [];

        if ($companyId < 1) {
            $errors['company_id'][] = 'Empresa emitente não definida.';
        }

        if ($series === '') {
            $errors['document_series'][] = 'A série da NF-e não pôde ser resolvida.';
        }

        if ($operationNature === '') {
            $errors['operation_nature'][] = 'A natureza da operação é obrigatória para emissão.';
        }

        if (! $document->operation_type) {
            $errors['operation_type'][] = 'O tipo de operação é obrigatório para emissão.';
        }

        if (! $document->issue_purpose) {
            $errors['issue_purpose'][] = 'A finalidade de emissão é obrigatória para emissão.';
        }

        if (! is_array($document->freight_data) || blank($document->freight_data['modalidade_frete'] ?? null)) {
            $errors['freight_data.modalidade_frete'][] = 'A modalidade de frete é obrigatória para emissão.';
        }

        if ($document->customer === null) {
            $errors['customer_id'][] = 'Destinatário não encontrado.';
        } else {
            $documentNumber = preg_replace('/\D/', '', (string) ($document->customer->document_number ?? ''));
            if (! in_array(strlen($documentNumber), [11, 14], true)) {
                $errors['customer.document_number'][] = 'Destinatário deve possuir CPF ou CNPJ válido para emissão.';
            }

            $address = $document->customer->address?->first();
            if ($address === null) {
                $errors['customer.address'][] = 'Destinatário deve possuir endereço cadastrado.';
            } else {
                foreach ([
                    'street' => 'logradouro',
                    'number' => 'número',
                    'neighborhood' => 'bairro',
                    'city' => 'município',
                    'state' => 'UF',
                    'postal_code' => 'CEP',
                    'city_code' => 'código do município',
                ] as $field => $label) {
                    if (blank($address->{$field} ?? null)) {
                        $errors["customer.address.{$field}"][] = "Endereço do destinatário sem {$label}.";
                    }
                }
            }
        }

        try {
            FiscalProfileValidator::validateProfileExists($companyId);

            if ($operationNature !== '') {
                FiscalProfileValidator::validateOperationNatureConfigured($companyId, $operationNature);
            }
        } catch (ValidationException $e) {
            $errors = array_merge_recursive($errors, $e->errors());
        }

        $normalizedItems = $this->normalizeItemsForEmission($document, $errors);

        if ($normalizedItems !== [] && $document->company !== null && $document->customer !== null) {
            try {
                FiscalProfileValidator::validateItemsTaxCompatibility($companyId, $normalizedItems);

                $issuerUf = (string) Arr::get($document->company->address ?? [], 'state', '');
                $recipientUf = (string) ($document->customer->address?->first()?->state ?? '');

                if ($issuerUf !== '' && $recipientUf !== '') {
                    FiscalProfileValidator::validateCfopCompatibility($normalizedItems, $issuerUf, $recipientUf);
                }
            } catch (ValidationException $e) {
                $errors = array_merge_recursive($errors, $e->errors());
            }
        }

        $scenarioCode = $this->resolveScenarioCode($operationNature);

        if (
            $scenarioCode === 'purchase_return'
            || $document->issue_purpose === IssuePurpose::DEVOLUCAO
        ) {
            $originKey = data_get($document->tax_data, 'purchase_return_origin.document_key');

            if (! is_string($originKey) || trim($originKey) === '') {
                $errors['tax_data.purchase_return_origin.document_key'][] = 'Nota de devolução exige chave da NF-e de origem.';
            }
        }

        if ($errors !== []) {
            $this->setError('Documento fiscal inválido para emissão.', $errors);
            return null;
        }

        $queueGroupKey = $this->buildQueueGroupKey(
            companyId: $companyId,
            documentModel: DocumentModel::NFE->value,
            series: $series,
            environment: $environment,
        );

        $candidateNumber = NfeSequence::peekNextNumber($companyId, $series, $operationNature);

        $result = new FiscalEmissionPreflightResult(
            passed: true,
            companyId: $companyId,
            documentModel: DocumentModel::NFE->value,
            operationNature: $operationNature,
            series: $series,
            environment: $environment,
            queueGroupKey: $queueGroupKey,
            scenarioCode: $scenarioCode,
            candidateNumber: $candidateNumber,
        );

        $this->setSuccess('Documento fiscal apto para emissão.', $result->toArray());

        return $result;
    }

    /**
     * @param  array<int|string,mixed>  $errors
     * @return array<int,array<string,mixed>>
     */
    private function normalizeItemsForEmission(FiscalDocument $document, array &$errors): array
    {
        $items = $document->items;

        if ($items->isEmpty()) {
            $errors['items'][] = 'A NF-e deve conter pelo menos um item.';
            return [];
        }

        return $items->values()->map(function (FiscalDocumentItem $item, int $index) use (&$errors): array {
            $itemErrors = [];
            $taxData = is_array($item->tax_data) ? $item->tax_data : [];
            $fiscalSnapshot = is_array($item->fiscal_snapshot) ? $item->fiscal_snapshot : [];

            if (($taxData['imposto'] ?? null) === null && $fiscalSnapshot !== []) {
                try {
                    $taxData = array_replace_recursive(
                        $taxData,
                        FiscalDecisionDTO::fromArray($fiscalSnapshot)->toTaxData((float) $item->total_price)
                    );
                } catch (\Throwable) {
                    // Permite que a validação de obrigatoriedade relate a inconsistência ao usuário.
                }
            }

            $cfop = $item->cfop_code ?: ($fiscalSnapshot['cfop'] ?? null);
            $prefix = "items.{$index}";

            if (! $item->product_id) {
                $itemErrors["{$prefix}.product_id"][] = 'Produto obrigatório.';
            }

            if (blank($item->product_code)) {
                $itemErrors["{$prefix}.product_code"][] = 'Código do produto obrigatório.';
            }

            if (blank($item->ncm_code)) {
                $itemErrors["{$prefix}.ncm_code"][] = 'NCM obrigatório.';
            }

            if (blank($cfop)) {
                $itemErrors["{$prefix}.cfop_code"][] = 'CFOP obrigatório.';
            }

            if (blank($item->unit_of_measure)) {
                $itemErrors["{$prefix}.unit_of_measure"][] = 'Unidade de medida obrigatória.';
            }

            if ((float) $item->quantity <= 0) {
                $itemErrors["{$prefix}.quantity"][] = 'Quantidade deve ser maior que zero.';
            }

            if ((float) $item->unit_price < 0) {
                $itemErrors["{$prefix}.unit_price"][] = 'Preço unitário não pode ser negativo.';
            }

            if ((float) $item->total_price < 0) {
                $itemErrors["{$prefix}.total_price"][] = 'Preço total não pode ser negativo.';
            }

            if (blank($item->product_origin)) {
                $itemErrors["{$prefix}.product_origin"][] = 'Origem do produto obrigatória.';
            }

            foreach ([
                'icms' => 'ICMS',
                'pis' => 'PIS',
                'cofins' => 'COFINS',
            ] as $path => $label) {
                if (blank(data_get($taxData, "imposto.{$path}.situacao_tributaria"))) {
                    $itemErrors["{$prefix}.tax_data.imposto.{$path}.situacao_tributaria"][] = "{$label} obrigatório para emissão.";
                }
            }

            if ($itemErrors !== []) {
                $errors = array_merge_recursive($errors, $itemErrors);
            }

            return [
                'product_id' => $item->product_id,
                'product_code' => $item->product_code,
                'ncm_code' => $item->ncm_code,
                'cfop_code' => $cfop,
                'product_origin' => $item->product_origin,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
                'unit_of_measure' => $item->unit_of_measure,
                'tax_data' => $taxData,
            ];
        })->all();
    }

    private function resolveScenarioCode(string $operationNature): string
    {
        return match (OperationNature::tryFrom($operationNature)) {
            OperationNature::DEVOLUCAO_COMPRA => 'purchase_return',
            OperationNature::REMESSA_CONSERTO => 'repair_remittance',
            OperationNature::RETORNO_CONSERTO => 'repair_return',
            OperationNature::REMESSA_DEMONSTRACAO => 'demonstration_remittance',
            OperationNature::RETORNO_DEMONSTRACAO => 'demonstration_return',
            OperationNature::TRANSFERENCIA => 'transfer',
            OperationNature::BONIFICACAO => 'bonus',
            OperationNature::SIMPLES_REMESSA => 'simple_remittance',
            default => 'sale',
        };
    }

    private function buildQueueGroupKey(int $companyId, string $documentModel, string $series, int $environment): string
    {
        return implode(':', [
            $documentModel,
            $companyId,
            $series,
            $environment,
        ]);
    }
}
