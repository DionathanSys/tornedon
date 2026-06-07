<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Contracts\NfsePayloadBuilder;
use App\Support\Fiscal\FiscalItemAmounts;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Monta o payload NFS-e no formato municipal (Chapecó-SC / padrão ABRASF).
 *
 * Estrutura: numero, serie, tipo, data_emissao, status, data_competencia,
 * regime_tributacao, incentivo_fiscal, tomador, servico { iss_retido, itens[] }.
 */
class BuildNfseMunicipalPayloadAction
    implements NfsePayloadBuilder
{
    use HandlesActionResponse;

    public function supports(FiscalDocument $fiscalDocument): bool
    {
        return $fiscalDocument->isNfse();
    }

    public function identifier(): string
    {
        return 'municipal:default';
    }

    public function build(FiscalDocument $fiscalDocument): ?array
    {
        try {
            Log::debug('BuildNfseMunicipalPayloadAction: iniciando montagem de payload', [
                'fiscal_document_id' => $fiscalDocument->id,
                'company_id'         => $fiscalDocument->company_id,
                'customer_id'        => $fiscalDocument->customer_id,
                'items_count'        => $fiscalDocument->items->count(),
                'rps_number'         => $fiscalDocument->rps_number,
                'rps_series'         => $fiscalDocument->rps_series,
            ]);

            $fiscalDocument->loadMissing([
                'company',
                'customer.address',
                'items.service',
                'fiscalProfile',
            ]);

            $company  = $fiscalDocument->company;
            $customer = $fiscalDocument->customer;
            $address  = $customer?->resolveAddressForCompany($fiscalDocument->company_id);
            $profile  = $fiscalDocument->fiscalProfile ?? $company->fiscalProfile;

            $issuedAt = now()->format('Y-m-d\TH:i:sP');

            // ------------------------------------------------------------------
            // Tomador
            // ------------------------------------------------------------------
            $tomador = [
                'razao_social' => $customer->name,
            ];

            $docNumber = preg_replace('/\D/', '', $customer->document_number ?? '');
            if (strlen($docNumber) === 14) {
                $tomador['cnpj'] = $docNumber;
            } elseif (strlen($docNumber) === 11) {
                $tomador['cpf'] = $docNumber;
            }

            // IM - Inscrição Municipal (Aceita null)
            if ($customer->municipal_tax_id) {
                $tomador['im'] = $customer->municipal_tax_id;
            }

            $phone = $customer->contacts?->first()?->phone ?? null;
            if ($phone) {
                $tomador['telefone'] = preg_replace('/\D/', '', $phone);
            }

            $email = $customer->contacts?->first()?->email ?? $customer->email ?? null;
            if ($email) {
                $tomador['email'] = $email;
            }

            if ($address === null) {
                $msgErro = 'NFS-e municipal requer endereço completo do tomador.';
                $this->setError($msgErro);
                Log::warning('BuildNfseMunicipalPayloadAction: endereço do tomador ausente', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'customer_id' => $fiscalDocument->customer_id,
                ]);
                return null;
            }

            $logradouro = trim((string) ($address->street ?? ''));
            $numero = trim((string) ($address->number ?? ''));
            $bairro = trim((string) ($address->neighborhood ?? ''));
            $codigoMunicipio = preg_replace('/\D/', '', (string) ($address->city_code ?? ''));
            $uf = trim((string) ($address->state ?? ''));
            $cep = preg_replace('/\D/', '', (string) ($address->postal_code ?? ''));

            $requiredAddressFields = [
                'logradouro' => $logradouro,
                'numero' => $numero,
                'bairro' => $bairro,
                'codigo_municipio' => $codigoMunicipio,
                'uf' => $uf,
                'cep' => $cep,
            ];

            foreach ($requiredAddressFields as $field => $value) {
                if ($value === '') {
                    $msgErro = "NFS-e municipal requer o campo {$field} no endereço do tomador.";
                    $this->setError($msgErro);
                    Log::warning('BuildNfseMunicipalPayloadAction: endereço do tomador incompleto', [
                        'fiscal_document_id' => $fiscalDocument->id,
                        'customer_id' => $fiscalDocument->customer_id,
                        'field' => $field,
                    ]);
                    return null;
                }
            }

            $tomador['endereco'] = [
                'logradouro' => $logradouro,
                'numero' => $numero,
                'complemento' => trim((string) ($address->complement ?? '')),
                'bairro' => $bairro,
                'codigo_municipio' => $codigoMunicipio,
                'uf' => $uf,
                'cep' => $cep,
            ];

            // ------------------------------------------------------------------
            // Itens de serviço
            // ------------------------------------------------------------------
            $itens = [];
            $valorServicosTotal = 0.0;
            $valorDescontoIncondicionadoTotal = 0.0;
            $valorBaseCalculoTotal = 0.0;
            $discriminacoes = [];
            $serviceCodeCandidates = [];
            $nbsCodeCandidates = [];

            foreach ($fiscalDocument->items as $item) {
                $taxData = $item->tax_data ?? [];

                $codigoServico = $this->normalizeServiceCode(
                    $item->municipal_tax_code
                    ?? $item->service?->municipal_tax_code
                    ?? $profile?->default_municipal_tax_code
                );

                $codigoNbs = $this->normalizeNbsCode(
                    $item->nbs_code
                    ?? $item->service?->nbs_code
                    ?? $profile?->default_nbs_code
                );

                $discriminacao = trim((string) $item->description);
                if ($discriminacao === '') {
                    $discriminacao = 'Serviços prestados conforme documento fiscal.';
                }

                $valorServicosItem = round((float) $item->total_price, 2);
                $valorDescontoIncondicionadoItem = round((float) ($item->discount_amount ?? 0), 2);
                $valorBaseCalculoItem = FiscalItemAmounts::taxableBase(
                    $valorServicosItem,
                    $valorDescontoIncondicionadoItem
                );
                $valorServicosTotal += $valorServicosItem;
                $valorDescontoIncondicionadoTotal += $valorDescontoIncondicionadoItem;
                $valorBaseCalculoTotal += $valorBaseCalculoItem;
                $discriminacoes[] = $discriminacao;

                if ($codigoServico !== '') {
                    $serviceCodeCandidates[] = $codigoServico;
                }

                if ($codigoNbs !== '') {
                    $nbsCodeCandidates[] = $codigoNbs;
                }

                $itemPayload = [
                    'codigo'        => $codigoServico,
                    'discriminacao' => substr($discriminacao, 0, 2000),
                    'valor_servicos'=> $valorServicosItem,
                    'valor_base_calculo' => $valorBaseCalculoItem,
                ];

                if ($valorDescontoIncondicionadoItem > 0) {
                    $itemPayload['valor_desconto_incondicionado'] = $valorDescontoIncondicionadoItem;
                }

                Log::debug('Atualizando item NFS-e via RelationManager', [
                    'metodo'  => __METHOD__ . '@' . __LINE__,
                    'item_id' => $item->id,
                    'data'    => $item->additional_information,
                ]);
                
                if (! empty($item->additional_information)) {
                    $itemPayload['informacoes_complementares'] = $item->additional_information;
                }

                if ($item->cnae_code || $profile?->service_cnae_code) {
                    $itemPayload['codigo_cnae'] = $item->cnae_code ?? $profile?->service_cnae_code;
                }

                if ($codigoNbs !== '') {
                    $itemPayload['codigo_nbs'] = $codigoNbs;
                }

                $ctm = $item->service?->municipal_tax_code
                    ?? $item->municipal_tax_code
                    ?? $profile?->default_municipal_tax_code
                    ?? null;
                if ($ctm) {
                    $itemPayload['codigo_tributacao_municipio'] = $ctm;
                }

                // Exigibilidade ISS
                $exigibilidade = $item->iss_exigibility ?? null;
                $itemPayload['exigibilidade_iss'] = $exigibilidade;

                // Alíquota e ISS
                $aliquota = (float) ($item->iss_rate ?? $profile?->iss_rate_default ?? 0);
                if ($aliquota > 0) {
                    $itemPayload['valor_aliquota'] = round($aliquota, 2);
                    $itemPayload['valor_iss'] = round($valorBaseCalculoItem * $aliquota / 100, 2);
                }

                // Retenções declaratórias (do tax_data do item)
                foreach (['valor_pis', 'valor_cofins', 'valor_inss', 'valor_ir', 'valor_csll', 'valor_outras'] as $ret) {
                    if (isset($taxData[$ret]) && (float) $taxData[$ret] > 0) {
                        $itemPayload[$ret] = round((float) $taxData[$ret], 2);
                    }
                }

                // Valor deduções
                if (isset($taxData['valor_deducoes']) && (float) $taxData['valor_deducoes'] > 0) {
                    $itemPayload['valor_deducoes'] = round((float) $taxData['valor_deducoes'], 2);
                }

                $itens[] = $itemPayload;
            }

            if (empty($itens)) {
                $msgErro = 'NFS-e Municipal requer ao menos um item de serviço.';
                $this->setError($msgErro);
                Log::warning('BuildNfseMunicipalPayloadAction: validação falhou', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'erro'               => $msgErro,
                    'items_count'        => $fiscalDocument->items->count(),
                ]);
                return null;
            }

            $codigoServico = $serviceCodeCandidates[0] ?? '';
            $codigoNbs = $nbsCodeCandidates[0] ?? '';
            $discriminacaoGeral = trim(implode("\n", array_filter($discriminacoes)));
            $informacoesComplementaresItens = $fiscalDocument->items
                ->pluck('additional_information')
                ->filter(fn ($info) => filled($info))
                ->map(fn ($info) => trim((string) $info))
                ->unique()
                ->implode("\n");

            if ($discriminacaoGeral === '') {
                $discriminacaoGeral = 'Serviços prestados conforme documento fiscal.';
            }

            if ($codigoServico === '') {
                $msgErro = 'NFS-e requer o codigo do servico (LC 116/2003) no formato XX.XX (ex: 01.01).';
                $this->setError($msgErro);
                Log::warning('BuildNfseMunicipalPayloadAction: código de serviço vazio', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'erro'               => $msgErro,
                    'municipal_tax_code_attempts' => array_merge(
                        $fiscalDocument->items->pluck('municipal_tax_code')->filter()->values()->toArray(),
                        $fiscalDocument->items->pluck('service.municipal_tax_code')->filter()->values()->toArray(),
                    ),
                ]);
                return null;
            }

            if ($codigoNbs === '') {
                $msgErro = 'NFS-e requer o codigo NBS (cNBS) com 9 digitos.';
                $this->setError($msgErro);
                Log::warning('BuildNfseMunicipalPayloadAction: código NBS vazio', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'erro'               => $msgErro,
                ]);
                return null;
            }

            if (round($valorServicosTotal, 2) <= 0) {
                $msgErro = 'NFS-e requer valor de servicos maior que zero.';
                $this->setError($msgErro);
                Log::warning('BuildNfseMunicipalPayloadAction: valor total zerado', [
                    'fiscal_document_id'   => $fiscalDocument->id,
                    'erro'                 => $msgErro,
                    'valor_total'          => $valorServicosTotal,
                    'items_count'          => count($itens),
                ]);
                return null;
            }

            // ------------------------------------------------------------------
            // Serviço (wrapper)
            // ------------------------------------------------------------------
            $servico = [
                'iss_retido' => (bool) ($fiscalDocument->items->first()?->iss_withheld
                    ?? $profile?->iss_withheld_default
                    ?? false),
                // Compatibilidade com validadores que exigem o formato nacional no bloco servico.
                'codigo' => $codigoServico,
                'discriminacao' => substr($discriminacaoGeral, 0, 2000),
                'codigo_nbs' => $codigoNbs,
                'valor_servicos' => round($valorServicosTotal, 2),
                'valor_base_calculo' => round($valorBaseCalculoTotal, 2),
                'itens' => $itens,
            ];

            if (round($valorDescontoIncondicionadoTotal, 2) > 0) {
                $servico['valor_desconto_incondicionado'] = round($valorDescontoIncondicionadoTotal, 2);
            }

            // Município de incidência e prestação
            $companyAddress = $company->address ?? [];
            $municipioPrestador = $companyAddress['city_code'] ?? null;
            if ($municipioPrestador) {
                $servico['codigo_municipio'] = $municipioPrestador;
            }

            $municipioPrestacao = preg_replace('/\D/', '', (string) ($profile?->default_service_city_code ?? ''));
            if ($municipioPrestacao === '') {
                $municipioPrestacao = $municipioPrestador;
            }

            if ($municipioPrestacao) {
                $servico['codigo_municipio_prestacao'] = $municipioPrestacao;
            }

            // ------------------------------------------------------------------
            // Payload raiz
            // ------------------------------------------------------------------
            $serie = $this->normalizeSerie($fiscalDocument->rps_series ?? null);

            if ($serie === '') {
                $msgErro = 'NFS-e municipal requer série RPS válida para emissão.';
                $this->setError($msgErro);
                Log::warning('BuildNfseMunicipalPayloadAction: série RPS inválida', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'rps_series' => $fiscalDocument->rps_series,
                ]);
                return null;
            }

            $payload = [
                'numero'       => (string) $fiscalDocument->rps_number,
                'serie'        => $serie,
                'tipo'         => $fiscalDocument->rps_type ?? '1', // 1 é Recibo provisório de serviços
                'data_emissao' => $issuedAt,
                'status'       => '1',
                'tomador'      => $tomador,
                'servico'      => $servico,
            ];

            // Data de competência (se diferente de emissão)
            if ($fiscalDocument->movement_at && ! $fiscalDocument->movement_at->isSameDay($fiscalDocument->issued_at)) {
                Log::debug('BuildNfseMunicipalPayloadAction: data de competência diferente da data de emissão, incluindo no payload', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'issued_at'          => $fiscalDocument->issued_at->toDateTimeString(),
                    'movement_at'        => $fiscalDocument->movement_at->toDateTimeString(),
                ]);
                $payload['data_competencia'] = $fiscalDocument->movement_at->format('Y-m-d') . 'T00:00:00-03:00';
            }

            // Regime especial de tributação
            $regime = $profile?->nfse_special_tax_regime ?? null;
            if ($regime) {
                $payload['regime_tributacao'] = $regime;
            }

            // Informações complementares (campo específico, sem mesclar em discriminação)
            $infoComplementar = $fiscalDocument->additional_taxpayer_information
                ?? ($informacoesComplementaresItens !== '' ? $informacoesComplementaresItens : null)
                ?? $profile?->default_nfse_additional_information
                ?? null;
            if ($infoComplementar) {
                $payload['informacoes_complementares'] = $infoComplementar;
            }

            Log::info('BuildNfseMunicipalPayloadAction: payload montado com sucesso', [
                'fiscal_document_id' => $fiscalDocument->id,
                'rps_number'         => $payload['numero'] ?? null,
                'items_count'        => count($itens),
                'valor_total'        => $payload['servico']['valor_servicos'] ?? 0,
                'codigo_servico'     => $codigoServico,
            ]);

            $this->setSuccess();
            return $payload;

        } catch (\Exception $e) {
            $msgErro = 'Erro ao montar payload NFS-e municipal: ' . $e->getMessage();
            $this->setError($msgErro);

            Log::error('BuildNfseMunicipalPayloadAction: exceção capturada', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'company_id'         => $fiscalDocument->company_id,
                'customer_id'        => $fiscalDocument->customer_id,
                'exception'          => $e->getMessage(),
                'erro_classe'        => get_class($e),
                'trace'              => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    public function execute(FiscalDocument $fiscalDocument): ?array
    {
        return $this->build($fiscalDocument);
    }

    private function normalizeSerie(?string $serie): string
    {
        $digits = preg_replace('/\D/', '', (string) $serie);

        if ($digits === '') {
            return '';
        }

        return substr($digits, 0, 5);
    }

    private function normalizeServiceCode(?string $code): string
    {
        $digits = preg_replace('/\D/', '', (string) $code);

        if ($digits === '') {
            return '';
        }

        // LC 116/2003: formato deve ser XX.XX (2 dígitos.2 dígitos)
        $padded = str_pad(substr($digits, 0, 4), 4, '0', STR_PAD_LEFT);
        return substr($padded, 0, 2) . '.' . substr($padded, 2, 2);
    }

    private function normalizeNbsCode(?string $code): string
    {
        $digits = preg_replace('/\D/', '', (string) $code);

        if ($digits === '') {
            return '';
        }

        return str_pad(substr($digits, 0, 9), 9, '0', STR_PAD_LEFT);
    }
}
