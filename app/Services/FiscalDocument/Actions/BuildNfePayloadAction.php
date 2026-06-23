<?php

namespace App\Services\FiscalDocument\Actions;

use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\Tax\TaxRegime;
use App\Models\CompanyPartner;
use App\Models\FiscalDocument;
use App\Models\Partner;
use App\Support\Fiscal\FiscalItemAmounts;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Monta o payload completo para envio à API IntegraNotas.
 *
 * Lê dados do FiscalDocument (com relacionamentos carregados):
 *   - company         → dados do emitente
 *   - customer        → destinatário (com address e contacts)
 *   - items.product   → itens da NF-e
 *   - freight_data    → frete (já armazenado como array)
 *   - payment_data    → formas de pagamento
 *   - tax_data        → totais tributários
 *
 * Os dados tributários de cada item (ICMS, PIS, COFINS) vêm de
 * FiscalDocumentItem::tax_data — preenchidos no momento da criação do documento.
 */
class BuildNfePayloadAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument, ?\App\Domain\DTO\Fiscal\ScenarioContext $scenarioContext = null): ?array
    {
        try {
            Log::debug('BuildNfePayloadAction: iniciando montagem de payload', [
                'fiscal_document_id' => $fiscalDocument->id,
                'company_id' => $fiscalDocument->company_id,
                'customer_id' => $fiscalDocument->customer_id,
                'items_count' => $fiscalDocument->items->count(),
                'numero' => $fiscalDocument->document_number,
                'serie' => $fiscalDocument->document_series,
                'natureza' => $fiscalDocument->operation_nature,
            ]);

            $company = $fiscalDocument->company()->first();
            $customer = $fiscalDocument->customer()->with('address')->first();
            $items = $fiscalDocument->items()->with('product')->get();

            $fiscalDocument->setRelation('company', $company);
            $fiscalDocument->setRelation('customer', $customer);
            $fiscalDocument->setRelation('items', $items);

            $company = $fiscalDocument->company;
            $customer = $fiscalDocument->customer;
            $address = $customer?->resolveAddressForCompany($fiscalDocument->company_id);
            $taxRegime = $company?->fiscalProfile()->first()?->tax_regime;
            $operationType = $fiscalDocument->operation_type instanceof OperationType
                ? $fiscalDocument->operation_type->value
                : (string) ($fiscalDocument->operation_type ?? OperationType::SAIDA->value);

            $issuedAt = $this->resolveNfeTimestamp($fiscalDocument->issued_at ?? now())->format('Y-m-d\TH:i:sP');
            $movementAt = $this->resolveMovementTimestamp(
                $fiscalDocument->movement_at ?? $fiscalDocument->issued_at ?? now(),
                Carbon::parse($issuedAt),
                $operationType,
            )->format('Y-m-d\TH:i:sP');

            // ------------------------------------------------------------------
            // Monta destinatário
            // ------------------------------------------------------------------
            $destinatario = [
                'nome' => $customer->name,
                'indicador_inscricao_estadual' => $customer->state_tax_indicator?->value ?? '9',
                'inscricao_estadual' => $customer->state_tax_id,
                'inscricao_municipal' => $customer->municipal_tax_id,
            ];

            // CPF ou CNPJ
            $docNumber = preg_replace('/\D/', '', $customer->document_number ?? '');
            if (strlen($docNumber) === 14) {
                $destinatario['cnpj'] = $docNumber;
            } else {
                $destinatario['cpf'] = $docNumber;
            }

            if ($address) {
                $destinatario['endereco'] = [
                    'logradouro' => $address->street ?? '',
                    'numero' => $address->number ?? 'S/N',
                    'complemento' => $address->complement ?? '',
                    'bairro' => $address->neighborhood ?? '',
                    'codigo_municipio' => $address->city_code ?? '',
                    'nome_municipio' => $address->city ?? '',
                    'uf' => $address->state ?? '',
                    'cep' => preg_replace('/\D/', '', $address->postal_code ?? ''),
                    'codigo_pais' => '1058',
                    'nome_pais' => 'BRASIL',
                    'telefone' => preg_replace('/\D/', '', $address->phone ?? ''),
                ];
            }

            // ------------------------------------------------------------------
            // Monta itens
            // ------------------------------------------------------------------
            Log::debug('BuildNfePayloadAction: processando itens do documento', [
                'fiscal_document_id' => $fiscalDocument->id,
                'items_count' => $fiscalDocument->items->count(),
            ]);

            $itens = [];
            $payloadTotals = [
                'valor_produtos' => 0.0,
                'valor_nota' => 0.0,
                'valor_desconto' => 0.0,
                'valor_frete' => 0.0,
                'valor_seguro' => 0.0,
                'valor_outras_despesas' => 0.0,
                'base_calculo_icms' => 0.0,
                'valor_icms' => 0.0,
                'base_calculo_icms_st' => 0.0,
                'valor_icms_st' => 0.0,
                'valor_ipi' => 0.0,
                'valor_pis' => 0.0,
                'valor_cofins' => 0.0,
            ];

            foreach ($fiscalDocument->items as $index => $item) {
                $taxData = is_array($item->tax_data) ? $item->tax_data : [];
                $fiscalSnapshot = is_array($item->fiscal_snapshot) ? $item->fiscal_snapshot : [];
                $taxableBase = FiscalItemAmounts::taxableBase($item->total_price, $item->discount_amount);

                if (($taxData['imposto'] ?? null) === null && $fiscalSnapshot !== []) {
                    $taxData = array_replace_recursive(
                        $taxData,
                        FiscalDecisionDTO::fromArray($fiscalSnapshot)->toTaxData($taxableBase)
                    );
                }

                $taxData = $this->normalizeItemTaxDataForPayload($taxData);

                $quantityCommercial = (float) number_format((float) $item->quantity, 4, '.', '');
                $baseUnitPriceCommercial = (float) $item->unit_price;
                $grossValueFloat = round($quantityCommercial * $baseUnitPriceCommercial, 2);

                $quantityTaxable = (float) number_format((float) ($item->taxable_quantity ?? $item->quantity), 4, '.', '');
                $baseUnitPriceTaxable = (float) ($item->taxable_unit_price ?? $item->unit_price);

                $unitPriceCommercial = $quantityCommercial > 0
                    ? ($grossValueFloat / $quantityCommercial)
                    : $baseUnitPriceCommercial;

                $unitPriceTaxable = $quantityTaxable > 0
                    ? ($grossValueFloat / $quantityTaxable)
                    : $baseUnitPriceTaxable;

                $grossValue = number_format($grossValueFloat, 2, '.', '');

                $itemPayload = [
                    'numero_item' => $index + 1,
                    'codigo_produto' => $item->product_code,
                    'descricao' => $item->description ?? $item->product?->name ?? '',
                    'codigo_ncm' => $item->ncm_code,
                    'cfop' => $item->cfop_code ?: ($fiscalSnapshot['cfop'] ?? null),
                    'unidade_comercial' => $item->unit_of_measure,
                    'quantidade_comercial' => $quantityCommercial,
                    'valor_unitario_comercial' => number_format($unitPriceCommercial, 10, '.', ''),
                    'valor_bruto' => $grossValue,
                    'unidade_tributavel' => $item->taxable_unit ?? $item->unit_of_measure,
                    'quantidade_tributavel' => number_format($quantityTaxable, 4, '.', ''),
                    'valor_unitario_tributavel' => number_format($unitPriceTaxable, 10, '.', ''),
                    'origem' => $item->product_origin,
                    'inclui_no_total' => '1',
                    'imposto' => $taxData['imposto'] ?? [],
                    'valor_desconto' => number_format((float) ($item->discount_amount ?? 0), 2, '.', ''),
                    'valor_frete' => number_format((float) ($item->freight_amount ?? 0), 2, '.', ''),
                    'valor_seguro' => number_format((float) ($item->insurance_amount ?? 0), 2, '.', ''),
                    'valor_outras_despesas' => number_format((float) ($item->other_expenses_amount ?? 0), 2, '.', ''),
                ];

                if (! empty($item->additional_information)) {
                    $itemPayload['informacoes_adicionais'] = $item->additional_information;
                } elseif (! empty($taxData['informacoes_adicionais'])) {
                    $itemPayload['informacoes_adicionais'] = $taxData['informacoes_adicionais'];
                }

                $itens[] = $itemPayload;

                $payloadTotals['valor_produtos'] += $grossValueFloat;
                $payloadTotals['valor_desconto'] += (float) ($item->discount_amount ?? 0);
                $payloadTotals['valor_frete'] += (float) ($item->freight_amount ?? 0);
                $payloadTotals['valor_seguro'] += (float) ($item->insurance_amount ?? 0);
                $payloadTotals['valor_outras_despesas'] += (float) ($item->other_expenses_amount ?? 0);
                $payloadTotals['base_calculo_icms'] += (float) data_get($taxData, 'imposto.icms.valor_base_calculo', 0);
                $payloadTotals['valor_icms'] += (float) data_get($taxData, 'imposto.icms.valor', 0);
                $payloadTotals['base_calculo_icms_st'] += (float) data_get($taxData, 'imposto.icms.valor_base_calculo_st', 0);
                $payloadTotals['valor_icms_st'] += (float) data_get($taxData, 'imposto.icms.valor_st', 0);
                $payloadTotals['valor_ipi'] += (float) data_get($taxData, 'imposto.ipi.valor', 0);
                $payloadTotals['valor_pis'] += (float) data_get($taxData, 'imposto.pis.valor', 0);
                $payloadTotals['valor_cofins'] += (float) data_get($taxData, 'imposto.cofins.valor', 0);
            }

            $payloadTotals['valor_nota'] = round(
                $payloadTotals['valor_produtos']
                - $payloadTotals['valor_desconto']
                + $payloadTotals['valor_frete']
                + $payloadTotals['valor_seguro']
                + $payloadTotals['valor_outras_despesas'],
                2
            );

            if ($taxRegime !== null) {
                $regime = $taxRegime instanceof TaxRegime
                    ? $taxRegime
                    : TaxRegime::tryFrom((string) $taxRegime);

                if ($regime !== null) {
                    $compatibilityError = $this->validateIcmsTaxRegimeCompatibility($itens, $regime);

                    if ($compatibilityError !== null) {
                        $this->setError($compatibilityError);
                        Log::warning('BuildNfePayloadAction: incompatibilidade CST/CSOSN com regime tributario', [
                            'fiscal_document_id' => $fiscalDocument->id,
                            'company_id' => $fiscalDocument->company_id,
                            'tax_regime' => $regime->value,
                            'erro' => $compatibilityError,
                        ]);

                        return null;
                    }
                }
            }

            // ------------------------------------------------------------------
            // Monta payload raiz
            // ------------------------------------------------------------------
            if (! $fiscalDocument->operation_nature) {
                $msgErro = 'Natureza da operação não definida no documento fiscal.';
                $this->setError($msgErro);
                Log::warning('BuildNfePayloadAction: falta natureza da operação', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'erro' => $msgErro,
                ]);

                return null;
            }

            $payload = [
                'natureza_operacao' => $fiscalDocument->operation_nature instanceof \App\Enum\FiscalDocument\OperationNature
                                                ? $fiscalDocument->operation_nature->value
                                                : (string) $fiscalDocument->operation_nature,
                'serie' => $fiscalDocument->document_series ?? '1',
                'numero' => (int) $fiscalDocument->document_number,
                'data_emissao' => $issuedAt,
                'data_entrada_saida' => $movementAt,
                'tipo_operacao' => $operationType,
                'finalidade_emissao' => $fiscalDocument->issue_purpose instanceof \App\Enum\FiscalDocument\IssuePurpose
                                                ? $fiscalDocument->issue_purpose->value
                                                : ($fiscalDocument->issue_purpose ?? '1'),
                'consumidor_final' => $fiscalDocument->is_final_consumer ? '1' : '0',
                'presenca_comprador' => $fiscalDocument->buyer_presence_indicator instanceof \App\Enum\FiscalDocument\BuyerPresenceIndicator
                                                ? $fiscalDocument->buyer_presence_indicator->value
                                                : ($fiscalDocument->buyer_presence_indicator ? '1' : '9'),
                'destinatario' => $destinatario,
                'itens' => $itens,
                'frete' => $this->normalizeFreightData($fiscalDocument->freight_data, $fiscalDocument->company_id),
                'pagamento' => $fiscalDocument->payment_data ?? ['formas_pagamento' => [['meio_pagamento' => '99', 'valor' => '0.00']]],
            ];

            $intermediario = $this->resolveIntermediaryData($fiscalDocument);

            if ($intermediario !== null) {
                $payload['intermediario'] = $intermediario;
            }

            // Totais e cobrança (tax_data)
            if (! empty($fiscalDocument->tax_data)) {
                if ($scenarioContext?->hasReference()) {
                    $payload['notas_referenciadas'] = [[
                        'nfe' => [
                            'chave' => $scenarioContext->referenceDocumentKey,
                        ],
                    ]];
                }

                if (! isset($payload['notas_referenciadas'])) {
                    $originDocumentKey = data_get($fiscalDocument->tax_data, 'purchase_return_origin.document_key');

                    if (is_string($originDocumentKey) && trim($originDocumentKey) !== '') {
                        $payload['notas_referenciadas'] = [[
                            'nfe' => [
                                'chave' => trim($originDocumentKey),
                            ],
                        ]];
                        Log::warning('BuildNfePayloadAction: usando fallback tax_data para notas_referenciadas', [
                            'fiscal_document_id' => $fiscalDocument->id,
                        ]);
                    }
                }

                if (! isset($payload['notas_referenciadas'])) {
                    $genericReferenceKey = data_get($fiscalDocument->tax_data, 'reference.document_key');

                    if (is_string($genericReferenceKey) && trim($genericReferenceKey) !== '') {
                        $payload['notas_referenciadas'] = [[
                            'nfe' => [
                                'chave' => trim($genericReferenceKey),
                            ],
                        ]];

                        Log::warning('BuildNfePayloadAction: usando fallback generico tax_data.reference para notas_referenciadas', [
                            'fiscal_document_id' => $fiscalDocument->id,
                        ]);
                    }
                }

                $payload['totais'] = $this->formatTotalsForPayload($payloadTotals);
                if (! empty($fiscalDocument->tax_data['cobranca'])) {
                    $payload['cobranca'] = $fiscalDocument->tax_data['cobranca'];
                }
            }

            if ($fiscalDocument->additional_tax_information) {
                $payload['informacoes_adicionais_fisco'] = $fiscalDocument->additional_tax_information;
            }

            if ($fiscalDocument->additional_taxpayer_information) {
                $payload['informacoes_adicionais_contribuinte'] = $fiscalDocument->additional_taxpayer_information;
            }

            if (! empty($fiscalDocument->additional_purchase_information)) {
                $purchaseInfo = json_decode($fiscalDocument->additional_purchase_information, true);
                if (is_array($purchaseInfo)) {
                    $payload['informacoes_adicionais_compra'] = $purchaseInfo;
                }
            }

            if (! empty($fiscalDocument->taxpayer_observations)) {
                $obsContrib = json_decode($fiscalDocument->taxpayer_observations, true);
                if (is_array($obsContrib)) {
                    $payload['observacoes_contribuinte'] = $this->normalizeObservations($obsContrib);
                }
            }

            if (! empty($fiscalDocument->tax_observations)) {
                $obsFisco = json_decode($fiscalDocument->tax_observations, true);
                if (is_array($obsFisco)) {
                    $payload['observacoes_fisco'] = $this->normalizeObservations($obsFisco);
                }
            }

            Log::info('BuildNfePayloadAction: payload montado com sucesso', [
                'fiscal_document_id' => $fiscalDocument->id,
                'numero' => $payload['numero'] ?? null,
                'serie' => $payload['serie'] ?? null,
                'items_count' => count($itens),
                'natureza' => $payload['natureza_operacao'] ?? null,
            ]);

            $this->setSuccess();

            return $payload;

        } catch (\Exception $e) {
            $msgErro = 'Erro ao montar payload da NF-e: '.$e->getMessage();
            $this->setError($msgErro);

            Log::error('BuildNfePayloadAction: exceção capturada', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'company_id' => $fiscalDocument->company_id,
                'customer_id' => $fiscalDocument->customer_id,
                'exception' => $e->getMessage(),
                'erro_classe' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Normaliza observacoes para o formato esperado no payload:
     * [ ['campo' => 'x', 'texto' => 'y'], ... ]
     */
    private function normalizeObservations(array $observations): array
    {
        // Formato legado/esperado: lista de objetos
        if (array_is_list($observations)) {
            return collect($observations)
                ->filter(fn ($item) => is_array($item))
                ->map(fn (array $item) => [
                    'campo' => trim((string) ($item['campo'] ?? '')),
                    'texto' => trim((string) ($item['texto'] ?? '')),
                ])
                ->filter(fn (array $item) => $item['campo'] !== '' || $item['texto'] !== '')
                ->values()
                ->toArray();
        }

        // Formato key-value: { campo: texto }
        return collect($observations)
            ->map(fn ($texto, $campo) => [
                'campo' => trim((string) $campo),
                'texto' => trim((string) $texto),
            ])
            ->filter(fn (array $item) => $item['campo'] !== '' || $item['texto'] !== '')
            ->values()
            ->toArray();
    }

    private function resolveNfeTimestamp(Carbon $date, ?Carbon $minimum = null): Carbon
    {
        $timestamp = $date->copy();

        if ($timestamp->isStartOfDay()) {
            $now = now();
            $timestamp->setTime($now->hour, $now->minute, $now->second);
        }

        if ($minimum !== null && $timestamp->lt($minimum)) {
            return $minimum->copy();
        }

        return $timestamp;
    }

    private function resolveMovementTimestamp(Carbon $date, Carbon $issuedAt, string $operationType): Carbon
    {
        if ($operationType === OperationType::SAIDA->value && $date->isStartOfDay()) {
            return $issuedAt->copy();
        }

        return $this->resolveNfeTimestamp($date, $issuedAt);
    }

    private function normalizeFreightData(mixed $freightData, int|string|null $companyId = null): array
    {
        if (! is_array($freightData)) {
            return ['modalidade_frete' => '9'];
        }

        $normalized = [
            'modalidade_frete' => (string) ($freightData['modalidade_frete'] ?? '9'),
        ];

        $transportador = $this->normalizeCarrierData($freightData['transportador'] ?? null, $companyId);
        if ($transportador !== []) {
            $normalized['transportador'] = $transportador;
        }

        $icmsRetido = $this->filterEmptyRecursive([
            'valor_servico' => data_get($freightData, 'icms_retido.valor_servico'),
            'base_calculo_retencao_icms' => data_get($freightData, 'icms_retido.base_calculo_retencao_icms'),
            'aliquota_retencao' => data_get($freightData, 'icms_retido.aliquota_retencao'),
            'valor_icms_retido' => data_get($freightData, 'icms_retido.valor_icms_retido'),
            'cfop' => data_get($freightData, 'icms_retido.cfop'),
            'codigo_municipio_ocorrencia_fato_gerador' => data_get($freightData, 'icms_retido.codigo_municipio_ocorrencia_fato_gerador'),
        ]);

        if ($icmsRetido !== []) {
            $normalized['icms_retido'] = $icmsRetido;
        }

        $veiculo = $this->filterEmptyRecursive([
            'placa' => data_get($freightData, 'veiculo.placa'),
            'uf' => data_get($freightData, 'veiculo.uf'),
            'rntc' => data_get($freightData, 'veiculo.rntc'),
        ]);

        if ($veiculo !== []) {
            $normalized['veiculo'] = $veiculo;
        }

        $identificacaoVagao = $this->normalizeScalar($freightData['identificacao_vagao'] ?? null);
        if ($identificacaoVagao !== null) {
            $normalized['identificacao_vagao'] = $identificacaoVagao;
        }

        $identificacaoBalsa = $this->normalizeScalar($freightData['identificacao_balsa'] ?? null);
        if ($identificacaoBalsa !== null) {
            $normalized['identificacao_balsa'] = $identificacaoBalsa;
        }

        $volumes = collect($freightData['volumes'] ?? [])
            ->filter(fn (mixed $volume): bool => is_array($volume))
            ->map(function (array $volume): array {
                $normalizedVolume = $this->filterEmptyRecursive([
                    'quantidade' => $volume['quantidade'] ?? null,
                    'especie' => $volume['especie'] ?? null,
                    'marca' => $volume['marca'] ?? null,
                    'numero' => $volume['numero'] ?? null,
                    'peso_liquido' => $volume['peso_liquido'] ?? null,
                    'peso_bruto' => $volume['peso_bruto'] ?? null,
                    'lacres' => collect($volume['lacres'] ?? [])
                        ->map(function (mixed $lacre): array {
                            if (is_array($lacre)) {
                                return $this->filterEmptyRecursive([
                                    'numero' => $lacre['numero'] ?? null,
                                ]);
                            }

                            $numero = $this->normalizeScalar($lacre);

                            return $numero === null ? [] : ['numero' => $numero];
                        })
                        ->filter(fn (array $lacre): bool => $lacre !== [])
                        ->values()
                        ->all(),
                ]);

                return $normalizedVolume;
            })
            ->filter(fn (array $volume): bool => $volume !== [])
            ->values()
            ->all();

        if ($volumes !== []) {
            $normalized['volumes'] = $volumes;
        }

        return $normalized;
    }

    private function resolveIntermediaryData(FiscalDocument $fiscalDocument): ?array
    {
        $buyerPresence = $fiscalDocument->buyer_presence_indicator instanceof \App\Enum\FiscalDocument\BuyerPresenceIndicator
            ? $fiscalDocument->buyer_presence_indicator->value
            : (string) ($fiscalDocument->buyer_presence_indicator ?? '');

        if (! in_array($buyerPresence, ['2', '3', '4', '9'], true)) {
            return null;
        }

        $indicator = (string) data_get($fiscalDocument->tax_data, 'intermediario.indicador', '0');

        if (! in_array($indicator, ['0', '1'], true)) {
            $indicator = '0';
        }

        $intermediario = [
            'indicador' => $indicator,
        ];

        if ($indicator === '1') {
            $cnpj = preg_replace('/\D/', '', (string) data_get($fiscalDocument->tax_data, 'intermediario.cnpj', ''));
            $identificacao = trim((string) data_get($fiscalDocument->tax_data, 'intermediario.identificacao', ''));

            if ($cnpj !== '') {
                $intermediario['cnpj'] = $cnpj;
            }

            if ($identificacao !== '') {
                $intermediario['identificacao'] = $identificacao;
            }
        }

        return $this->filterEmptyRecursive($intermediario);
    }

    private function buildTotalsFromItems(FiscalDocument $fiscalDocument): array
    {
        $totals = [
            'valor_produtos' => 0.0,
            'valor_nota' => 0.0,
            'valor_desconto' => 0.0,
            'valor_frete' => 0.0,
            'valor_seguro' => 0.0,
            'valor_outras_despesas' => 0.0,
            'base_calculo_icms' => 0.0,
            'valor_icms' => 0.0,
            'base_calculo_icms_st' => 0.0,
            'valor_icms_st' => 0.0,
            'valor_ipi' => 0.0,
            'valor_pis' => 0.0,
            'valor_cofins' => 0.0,
        ];

        foreach ($fiscalDocument->items as $item) {
            $taxData = is_array($item->tax_data) ? $item->tax_data : [];

            $totals['valor_produtos'] += (float) ($item->total_price ?? 0);
            $totals['valor_desconto'] += (float) ($item->discount_amount ?? 0);
            $totals['valor_frete'] += (float) ($item->freight_amount ?? 0);
            $totals['valor_seguro'] += (float) ($item->insurance_amount ?? 0);
            $totals['valor_outras_despesas'] += (float) ($item->other_expenses_amount ?? 0);
            $totals['base_calculo_icms'] += (float) data_get($taxData, 'imposto.icms.valor_base_calculo', 0);
            $totals['valor_icms'] += (float) data_get($taxData, 'imposto.icms.valor', 0);
            $totals['base_calculo_icms_st'] += (float) data_get($taxData, 'imposto.icms.valor_base_calculo_st', 0);
            $totals['valor_icms_st'] += (float) data_get($taxData, 'imposto.icms.valor_st', 0);
            $totals['valor_ipi'] += (float) data_get($taxData, 'imposto.ipi.valor', 0);
            $totals['valor_pis'] += (float) data_get($taxData, 'imposto.pis.valor', 0);
            $totals['valor_cofins'] += (float) data_get($taxData, 'imposto.cofins.valor', 0);
        }

        $totals['valor_nota'] = round(
            $totals['valor_produtos']
            - $totals['valor_desconto']
            + $totals['valor_frete']
            + $totals['valor_seguro']
            + $totals['valor_outras_despesas'],
            2
        );

        return collect($totals)
            ->map(fn (float $value): string => number_format(round($value, 2), 2, '.', ''))
            ->all();
    }

    private function formatTotalsForPayload(array $totals): array
    {
        return collect($totals)
            ->map(fn (float $value): string => number_format(round($value, 2), 2, '.', ''))
            ->all();
    }

    private function normalizeItemTaxDataForPayload(array $taxData): array
    {
        $icmsStatus = (string) data_get($taxData, 'imposto.icms.situacao_tributaria', '');

        if ($icmsStatus === '') {
            return $taxData;
        }

        $hasOwnIcmsHighlight = (float) data_get($taxData, 'imposto.icms.valor_base_calculo', 0) > 0
            || (float) data_get($taxData, 'imposto.icms.aliquota', 0) > 0
            || (float) data_get($taxData, 'imposto.icms.valor', 0) > 0;

        // Para Simples, se o usuário destacou ICMS manualmente em um CSOSN sem destaque,
        // reclassificamos para 900 no payload para manter a tributação informada válida.
        if (in_array($icmsStatus, ['102', '103', '300', '400'], true) && $hasOwnIcmsHighlight) {
            data_set($taxData, 'imposto.icms.situacao_tributaria', '900');

            return $taxData;
        }

        // CSOSNs do Simples sem destaque de ICMS não devem enviar BC/aliquota/valor.
        if (in_array($icmsStatus, ['102', '103', '300', '400'], true)) {
            data_set($taxData, 'imposto.icms', [
                'situacao_tributaria' => $icmsStatus,
            ]);

            return $taxData;
        }

        // CSOSN 500 não deve destacar ICMS próprio, apenas contexto de ST quando existir.
        if ($icmsStatus === '500') {
            $normalized = [
                'situacao_tributaria' => $icmsStatus,
            ];

            foreach ([
                'valor_base_calculo_st',
                'aliquota_st',
                'valor_st',
                'modalidade_base_calculo_st',
                'aliquota_margem_valor_adicionado_st',
            ] as $field) {
                $value = data_get($taxData, 'imposto.icms.'.$field);

                if ($value !== null && $value !== '') {
                    $normalized[$field] = $value;
                }
            }

            data_set($taxData, 'imposto.icms', $normalized);
        }

        return $taxData;
    }

    private function normalizeCarrierData(mixed $carrierData, int|string|null $companyId = null): array
    {
        if (! is_array($carrierData)) {
            return [];
        }

        $partnerId = isset($carrierData['id']) ? (int) $carrierData['id'] : null;

        if ($partnerId !== null && $partnerId > 0) {
            $partnerCarrierData = $this->resolveCarrierDataFromPartner($partnerId, $companyId);

            if ($partnerCarrierData !== []) {
                return $partnerCarrierData;
            }
        }

        $document = preg_replace('/\D/', '', (string) ($carrierData['cnpj'] ?? ''));

        if ($document === '') {
            $document = preg_replace('/\D/', '', (string) ($carrierData['cpf'] ?? ''));
        }

        $normalized = [
            'nome' => $carrierData['nome'] ?? null,
            'inscricao_estadual' => $carrierData['inscricao_estadual'] ?? null,
            'endereco' => $carrierData['endereco'] ?? null,
            'nome_municipio' => $carrierData['nome_municipio'] ?? null,
            'uf' => $carrierData['uf'] ?? null,
        ];

        if (strlen($document) === 14) {
            $normalized['cnpj'] = $document;
        } elseif (strlen($document) === 11) {
            $normalized['cpf'] = $document;
        }

        return $this->filterEmptyRecursive($normalized);
    }

    private function resolveCarrierDataFromPartner(int $partnerId, int|string|null $companyId = null): array
    {
        $partner = Partner::query()->find($partnerId);

        if (! $partner) {
            return [];
        }

        $companyPartner = null;

        if ($companyId !== null) {
            $companyPartner = CompanyPartner::query()
                ->with('addresses')
                ->where('partner_id', $partnerId)
                ->where('company_id', (int) $companyId)
                ->first();
        }

        $address = $companyPartner?->addresses->sortBy('id')->first() ?? $partner->address()->orderBy('id')->first();
        $document = preg_replace('/\D/', '', (string) $partner->document_number);

        $carrierData = [
            'nome' => $partner->name,
            'inscricao_estadual' => $partner->state_tax_id,
            'endereco' => $address?->street,
            'nome_municipio' => $address?->city,
            'uf' => $address?->state,
        ];

        if (strlen($document) === 14) {
            $carrierData['cnpj'] = $document;
        } elseif (strlen($document) === 11) {
            $carrierData['cpf'] = $document;
        }

        return $this->filterEmptyRecursive($carrierData);
    }

    private function filterEmptyRecursive(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->filterEmptyRecursive($value);

                if ($value === []) {
                    continue;
                }
            } elseif (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    continue;
                }
            } elseif ($value === null) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    private function normalizeScalar(mixed $value): string|int|float|null
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return null;
    }

    private function validateIcmsTaxRegimeCompatibility(array $items, TaxRegime $regime): ?string
    {
        $usesCsosn = $regime->usaCsosn();

        foreach ($items as $index => $item) {
            $taxSituation = data_get($item, 'imposto.icms.situacao_tributaria');

            if (! is_string($taxSituation) || trim($taxSituation) === '') {
                continue;
            }

            $taxSituationInt = (int) $taxSituation;
            $isCsosn = $taxSituationInt >= 100;
            $itemNumber = $index + 1;

            if ($usesCsosn && ! $isCsosn) {
                return "Item {$itemNumber}: a empresa está no {$regime->description()} e deve informar CSOSN (100-900) no ICMS, não CST '{$taxSituation}'.";
            }

            if (! $usesCsosn && $isCsosn) {
                return "Item {$itemNumber}: a empresa está no {$regime->description()} e deve informar CST ICMS (00-90), não CSOSN '{$taxSituation}'.";
            }
        }

        return null;
    }
}
