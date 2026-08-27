<?php

namespace App\Services\FiscalDocument;

use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Domain\DTO\Fiscal\FiscalEmissionPreflightResult;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\Tax\TaxRegime;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Services\Fiscal\NfeConfigService;
use App\Services\Fiscal\NfseConfigService;
use App\Services\FiscalDocument\Resolvers\FiscalEmissionScenarioResolver;
use App\Services\FiscalDocument\Resolvers\NfseEmissionCityResolver;
use App\Services\FiscalDocument\Resolvers\NfsePayloadBuilderResolver;
use App\Services\FiscalDocument\Validators\FiscalProfileValidator;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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
            'customer.contacts',
            'items.product',
            'items.service',
            'fiscalProfile',
        ]);

        $scenario = app(FiscalEmissionScenarioResolver::class)->resolve($document);

        if ($scenario === null) {
            $this->setError('Não foi possível resolver o cenário fiscal do documento.');

            return null;
        }

        if (! $this->canProceedInCurrentStatus($document, $allowQueuedStatus)) {
            $this->setError('Documento fiscal não pode seguir para emissão no estado atual.');

            return null;
        }

        $companyId = (int) $document->company_id;
        $environment = $this->resolveEnvironment($document);
        $series = trim($scenario->resolveSeries($document));
        $operationNature = $scenario->resolveOperationNature($document);
        $errors = [];

        if ($companyId < 1) {
            $errors['company_id'][] = 'Empresa emitente não definida.';
        }

        if ($series === '') {
            $errors[$document->isNfe() ? 'document_series' : 'rps_series'][] = 'A série do documento fiscal não pôde ser resolvida.';
        }

        if ($document->isNfe()) {
            $this->validateNfeDocument($document, $errors, $operationNature);
        } elseif ($document->isNfse()) {
            $this->validateNfseDocument($document, $errors);
        } else {
            $errors['document_type'][] = 'Tipo de documento fiscal não suportado no preflight.';
        }

        $scenario->validate($document, $errors);

        if ($errors !== []) {
            Log::debug('Documento fiscal inválido para emissão.', ['errors' => $errors]);
            $this->setError('Documento fiscal inválido para emissão.', $errors);

            return null;
        }

        $payloadBuilderKey = $scenario->payloadBuilderKey($document);

        if ($document->isNfse()) {
            $payloadBuilderKey = app(NfsePayloadBuilderResolver::class)->resolveKey($document);
        }

        $result = new FiscalEmissionPreflightResult(
            passed: true,
            companyId: $companyId,
            documentModel: $scenario->documentModel(),
            operationNature: $operationNature,
            series: $series,
            environment: $environment,
            queueGroupKey: $scenario->buildQueueGroupKey($document, $series, $environment),
            scenarioCode: $scenario->code(),
            channelCode: $scenario->channelCode($document),
            payloadBuilderKey: $payloadBuilderKey,
            candidateNumber: $scenario->resolveCandidateNumber($document, $series),
            scenarioContext: $scenario->resolveContext($document),
        );

        $this->setSuccess('Documento fiscal apto para emissão.', $result->toArray());

        return $result;
    }

    private function canProceedInCurrentStatus(FiscalDocument $document, bool $allowQueuedStatus): bool
    {
        if ($document->isNfe()) {
            return ! $document->isNfeInProcessing()
                && ! $document->isNfeAuthorized()
                && ! $document->isNfeCanceled()
                && ($allowQueuedStatus || ! $document->isNfeQueued());
        }

        if ($document->isNfse()) {
            return ! $document->isNfseInProcessing()
                && ! $document->isNfseAuthorized()
                && ! $document->isNfsePendingReconciliation()
                && ! $document->isNfseCanceled()
                && ($allowQueuedStatus || ! $document->isNfseQueued());
        }

        return false;
    }

    private function resolveEnvironment(FiscalDocument $document): int
    {
        if ($document->isNfse()) {
            return app(NfseConfigService::class)->resolveAmbiente((int) $document->company_id);
        }

        return app(NfeConfigService::class)->resolveAmbiente((int) $document->company_id);
    }

    /**
     * @param  array<int|string,mixed>  $errors
     */
    private function validateNfeDocument(FiscalDocument $document, array &$errors, ?string $operationNature): void
    {
        if (! is_string($operationNature) || trim($operationNature) === '') {
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

        $this->validateCustomer($document, $errors, true);

        try {
            FiscalProfileValidator::validateProfileExists((int) $document->company_id);

            if (is_string($operationNature) && trim($operationNature) !== '') {
                FiscalProfileValidator::validateOperationNatureConfigured((int) $document->company_id, $operationNature);
            }
        } catch (ValidationException $e) {
            $errors = array_merge_recursive($errors, $e->errors());
        }

        $normalizedItems = $this->normalizeNfeItemsForEmission($document, $errors);

        if ($normalizedItems !== [] && $document->company !== null && $document->customer !== null) {
            try {
                FiscalProfileValidator::validateItemsTaxCompatibility((int) $document->company_id, $normalizedItems);

                $issuerUf = (string) Arr::get($document->company->address ?? [], 'state', '');
                $recipientUf = (string) ($document->customer->resolveAddressForCompany($document->company_id)?->state ?? '');

                if ($issuerUf !== '' && $recipientUf !== '') {
                    FiscalProfileValidator::validateCfopCompatibility($normalizedItems, $issuerUf, $recipientUf);
                }

                FiscalProfileValidator::validateCfopCstCoherence($normalizedItems);
            } catch (ValidationException $e) {
                $errors = array_merge_recursive($errors, $e->errors());
            }
        }
    }

    /**
     * @param  array<int|string,mixed>  $errors
     */
    private function validateNfseDocument(FiscalDocument $document, array &$errors): void
    {
        $nfseModel = $document->nfse_model instanceof NfseModel
            ? $document->nfse_model->value
            : trim((string) ($document->nfse_model ?? ''));

        if ($nfseModel === '') {
            $errors['nfse_model'][] = 'O modelo da NFS-e é obrigatório para emissão.';
        }

        $this->validateCustomer($document, $errors, true);

        $profile = $document->fiscalProfile ?? $document->company?->fiscalProfile;
        $effectiveCity = app(NfseEmissionCityResolver::class)->resolve($document);

        if ($effectiveCity === null && ! is_string(config('nfse_builders.municipal:default'))) {
            $errors['service_city_code'][] = 'A cidade efetiva de emissão da NFS-e não pôde ser resolvida.';
        }

        $items = $document->items;

        if ($items->isEmpty()) {
            $errors['items'][] = 'A NFS-e deve conter pelo menos um item de serviço.';

            return;
        }

        if ($items->count() > 1) {
            $errors['items'][] = 'A NFS-e permite apenas um item de serviço por documento.';
        }

        $item = $items->first();

        if ($item === null) {
            return;
        }

        if (blank($item->description)) {
            $errors['items.0.description'][] = 'A discriminação do serviço é obrigatória.';
        }

        if ((float) $item->quantity <= 0) {
            $errors['items.0.quantity'][] = 'A quantidade deve ser maior que zero.';
        }

        if ((float) $item->unit_price < 0) {
            $errors['items.0.unit_price'][] = 'O preço unitário não pode ser negativo.';
        }

        if ((float) $item->total_price <= 0) {
            $errors['items.0.total_price'][] = 'O valor do serviço deve ser maior que zero.';
        }

        $serviceCode = $this->normalizeNfseServiceCode(
            $item->municipal_tax_code
                ?? $item->service?->municipal_tax_code
                ?? $profile?->default_municipal_tax_code
        );

        if ($serviceCode === '') {
            $errors['items.0.municipal_tax_code'][] = 'A NFS-e exige código de serviço válido.';
        }

        $nbsCode = $this->normalizeDigits($item->nbs_code ?? $item->service?->nbs_code ?? $profile?->default_nbs_code);

        if ($nbsCode === '') {
            $errors['items.0.nbs_code'][] = 'A NFS-e exige código NBS.';
        }

        if ($nfseModel === 'municipal') {
            $isPinhalzinhoIpm = $effectiveCity === NfseConfigService::PINHALZINHO_SC_IBGE_CODE;

            if (! $isPinhalzinhoIpm && blank($item->iss_exigibility)) {
                $errors['items.0.iss_exigibility'][] = 'A exigibilidade do ISS é obrigatória para NFS-e municipal.';
            }

            if ($isPinhalzinhoIpm) {
                $this->validatePinhalzinhoIpmConfiguration($document, $errors);
            }
        }

        if ($nfseModel === 'nacional') {
            if ($serviceCode === '') {
                $errors['items.0.municipal_tax_code'][] = 'A NFS-e nacional exige código LC 116/2003 no formato esperado.';
            }

            if (strlen($nbsCode) !== 9) {
                $errors['items.0.nbs_code'][] = 'A NFS-e nacional exige código NBS com 9 dígitos.';
            }

            // Block export (UF = EX) in V1
            $customerAddress = $document->customer?->resolveAddressForCompany($document->company_id);
            $customerUf = strtoupper(trim((string) ($customerAddress?->state ?? '')));
            if ($customerUf === 'EX') {
                $errors['customer.address.state'][] = 'A NFS-e Nacional V1 não suporta emissão para exterior (UF = EX).';
            }

            $taxRegime = $profile?->tax_regime instanceof TaxRegime
                ? $profile->tax_regime
                : TaxRegime::tryFrom((string) $profile?->tax_regime);

            $specialTaxRegime = trim((string) ($profile?->nfse_special_tax_regime ?? ''));
            $nationalAssessmentRegime = trim((string) ($profile?->nfse_nacional_regime_apuracao ?? ''));

            if ($taxRegime === TaxRegime::SIMPLES_NACIONAL && $specialTaxRegime === '6' && $nationalAssessmentRegime === '') {
                $errors['fiscal_profile.nfse_nacional_regime_apuracao'][] = 'A NFS-e nacional exige o regime de apuração para emitente Simples Nacional ME/EPP.';
            }
        }
    }

    /**
     * @param  array<int|string,mixed>  $errors
     */
    private function validatePinhalzinhoIpmConfiguration(FiscalDocument $document, array &$errors): void
    {
        $config = app(NfseConfigService::class);
        $companyId = (int) $document->company_id;

        if ($this->resolveEnvironment($document) === NfeConfigService::AMBIENTE_HOMOLOGACAO) {
            $errors['integranotas.ambiente'][] = 'Pinhalzinho/SC (IPM) não disponibiliza ambiente de homologação na IntegraNotas. Configure produção somente após validação com a prefeitura.';
        }

        if ($config->resolvePinhalzinhoIpmTaxRegime($companyId) === null) {
            $errors['integranotas.nfse_ipm_regime_tributacao'][] = 'A situação tributária IPM é obrigatória para NFS-e de Pinhalzinho/SC.';
        }
    }

    /**
     * @param  array<int|string,mixed>  $errors
     */
    private function validateCustomer(FiscalDocument $document, array &$errors, bool $requireAddress): void
    {
        if ($document->customer === null) {
            $errors['customer_id'][] = $document->isNfse() ? 'Tomador do serviço não encontrado.' : 'Destinatário não encontrado.';

            return;
        }

        $documentNumber = preg_replace('/\D/', '', (string) ($document->customer->document_number ?? ''));

        if (! in_array(strlen($documentNumber), [11, 14], true)) {
            $errors['customer.document_number'][] = $document->isNfse()
                ? 'Tomador deve possuir CPF ou CNPJ válido para emissão.'
                : 'Destinatário deve possuir CPF ou CNPJ válido para emissão.';
        }

        if (! $requireAddress) {
            return;
        }

        $address = $document->customer->resolveAddressForCompany($document->company_id);

        if ($address === null) {
            $errors['customer.address'][] = $document->isNfse()
                ? 'Tomador deve possuir endereço cadastrado.'
                : 'Destinatário deve possuir endereço cadastrado.';

            return;
        }

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
                $errors["customer.address.{$field}"][] = "Endereço sem {$label}.";
            }
        }
    }

    /**
     * @param  array<int|string,mixed>  $errors
     * @return array<int,array<string,mixed>>
     */
    private function normalizeNfeItemsForEmission(FiscalDocument $document, array &$errors): array
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

            $ibsCbs = data_get($taxData, 'imposto.ibs_cbs');
            if ($this->isEmptyIbsCbs($ibsCbs)) {
                data_forget($taxData, 'imposto.ibs_cbs');
                $ibsCbs = null;
            }

            if (is_array($ibsCbs) && $ibsCbs !== []) {
                foreach ($this->requiredIbsCbsFields() as $field => $label) {
                    if (blank(data_get($ibsCbs, $field))) {
                        $itemErrors["{$prefix}.tax_data.imposto.ibs_cbs.{$field}"][] = "IBS/CBS incompleto: {$label}.";
                    }
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

    private function isEmptyIbsCbs(mixed $ibsCbs): bool
    {
        if (! is_array($ibsCbs) || $ibsCbs === []) {
            return false;
        }

        foreach (Arr::dot($ibsCbs) as $value) {
            if (is_array($value)) {
                continue;
            }

            if ($this->isZeroLike($value)) {
                continue;
            }

            if (blank($value)) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function isZeroLike(mixed $value): bool
    {
        if (is_numeric($value)) {
            return (float) $value === 0.0;
        }

        return is_string($value) && preg_match('/^0+([,.]0+)?$/', trim($value)) === 1;
    }

    /**
     * @return array<string,string>
     */
    private function requiredIbsCbsFields(): array
    {
        return [
            'situacao_tributaria' => 'CST obrigatório',
            'classificacao_tributaria' => 'classificação tributária obrigatória',
            'grupo_ibs_cbs.valor_base_calculo' => 'base de cálculo obrigatória',
            'grupo_ibs_cbs.ibs_estadual.aliquota' => 'alíquota IBS estadual obrigatória',
            'grupo_ibs_cbs.ibs_estadual.valor' => 'valor IBS estadual obrigatório',
            'grupo_ibs_cbs.ibs_municipal.aliquota' => 'alíquota IBS municipal obrigatória',
            'grupo_ibs_cbs.ibs_municipal.valor' => 'valor IBS municipal obrigatório',
            'grupo_ibs_cbs.cbs.aliquota' => 'alíquota CBS obrigatória',
            'grupo_ibs_cbs.cbs.valor' => 'valor CBS obrigatório',
        ];
    }

    private function normalizeNfseServiceCode(?string $code): string
    {
        $digits = $this->normalizeDigits($code);

        if ($digits === '') {
            return '';
        }

        return str_pad(substr($digits, 0, 4), 4, '0', STR_PAD_LEFT);
    }

    private function normalizeDigits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) ($value ?? ''));
    }
}
