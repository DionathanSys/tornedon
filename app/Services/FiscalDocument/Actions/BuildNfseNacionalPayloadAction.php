<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Monta o payload NFS-e no formato nacional (DPS/NFS-e Nacional).
 *
 * Estrutura: regime_apuracao, regime_tributacao, data_emissao, numero, serie,
 * tomador, servico { codigo, discriminacao, codigo_nbs, valor_servicos,
 * tributos_municipais, tributos_nacionais, tributos_totais }.
 */
class BuildNfseNacionalPayloadAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): ?array
    {
        try {
            Log::debug('BuildNfseNacionalPayloadAction: iniciando montagem de payload', [
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
            $address  = $customer?->address?->first();
            $profile  = $fiscalDocument->fiscalProfile ?? $company->fiscalProfile;

            $issuedAt = $fiscalDocument->issued_at->format('Y-m-d') . 'T00:00:00-03:00';

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

            if ($address) {
                $tomador['endereco'] = [
                    'logradouro'       => $address->street ?? '',
                    'numero'           => $address->number ?? 'S/N',
                    'complemento'      => $address->complement ?? '',
                    'bairro'           => $address->neighborhood ?? '',
                    'uf'               => $address->state ?? '',
                    'codigo_municipio' => $address->city_code ?? '',
                    'cep'              => preg_replace('/\D/', '', $address->postal_code ?? ''),
                ];
            }

            // ------------------------------------------------------------------
            // Consolidar serviço (modelo nacional = 1 bloco de serviço, não array de itens)
            // ------------------------------------------------------------------
            $items = $fiscalDocument->items;

            if ($items->isEmpty()) {
                $msgErro = 'NFS-e Nacional requer ao menos um item de serviço.';
                $this->setError($msgErro);
                Log::warning('BuildNfseNacionalPayloadAction: validação fallou - sem itens', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'erro'               => $msgErro,
                ]);
                return null;
            }

            // Primeiro item como referência de código/discriminação
            $firstItem  = $items->first();
            $taxData    = $firstItem->tax_data ?? [];

            $valorServicosTotal = $items->sum(fn ($i) => round((float) $i->total_price, 2));
            $discriminacoes     = $items->map(fn ($i) => collect(array_filter([
                $i->description,
                $i->additional_information,
            ]))->implode('; '))->filter()->implode("\n");

            $serviceCode = $this->normalizeServiceCode(
                $firstItem->service_code
                ?? $firstItem->service?->service_code
                ?? $profile?->default_service_code
            );

            $nbsCode = $this->normalizeNbsCode(
                $firstItem->nbs_code
                ?? $firstItem->service?->nbs_code
                ?? $profile?->default_nbs_code
            );

            $discriminacao = trim((string) ($discriminacoes ?: ($firstItem->description ?? '')));
            if ($discriminacao === '') {
                $discriminacao = 'Servicos prestados conforme documento fiscal.';
            }

            if ($serviceCode === '') {
                $msgErro = 'NFS-e requer o codigo do servico (LC 116/2003) no formato XX.XX (ex: 01.01).';
                $this->setError($msgErro);
                Log::warning('BuildNfseNacionalPayloadAction: código de serviço vazio', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'erro'               => $msgErro,
                ]);
                return null;
            }

            if ($nbsCode === '') {
                $msgErro = 'NFS-e Nacional requer o codigo NBS (cNBS) com 9 digitos.';
                $this->setError($msgErro);
                Log::warning('BuildNfseNacionalPayloadAction: código NBS vazio', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'erro'               => $msgErro,
                ]);
                return null;
            }

            if (round($valorServicosTotal, 2) <= 0) {
                $msgErro = 'NFS-e Nacional requer valor de servicos maior que zero.';
                $this->setError($msgErro);
                Log::warning('BuildNfseNacionalPayloadAction: valor total zerado', [
                    'fiscal_document_id'   => $fiscalDocument->id,
                    'erro'                 => $msgErro,
                    'valor_total'          => $valorServicosTotal,
                    'items_count'          => $items->count(),
                ]);
                return null;
            }

            $servico = [
                'codigo'        => $serviceCode,
                'discriminacao' => substr($discriminacao, 0, 2000),
                'codigo_nbs'    => $nbsCode,
                'valor_servicos'=> round($valorServicosTotal, 2),
            ];

            $ctm = $firstItem->service?->municipal_tax_code ?? $profile?->default_service_code ?? null;
            if ($ctm) {
                $servico['codigo_tributacao_municipio'] = $ctm;
            }

            // Município de prestação
            $companyAddress = $company->address ?? [];
            $municipioPrestador = $companyAddress['city_code'] ?? null;
            $municipioPrestacao = $address?->city_code ?? $municipioPrestador;
            if ($municipioPrestacao) {
                $servico['codigo_municipio_prestacao'] = $municipioPrestacao;
            }

            // Recebido
            $servico['valor_recebido'] = round($valorServicosTotal, 2);

            // ------------------------------------------------------------------
            // Tributos municipais (ISS)
            // ------------------------------------------------------------------
            $issRetido = (bool) ($firstItem->iss_withheld ?? $profile?->iss_withheld_default ?? false);
            $aliquota  = (float) ($firstItem->iss_rate ?? $profile?->iss_rate_default ?? 0);

            $tributosMunicipais = [
                'tipo_operacao' => '1', // Operação tributável
                'iss_retido'    => $issRetido,
            ];

            if ($aliquota > 0) {
                $tributosMunicipais['valor_aliquota'] = round($aliquota, 2);
            }

            $servico['tributos_municipais'] = $tributosMunicipais;

            // ------------------------------------------------------------------
            // Tributos nacionais (PIS/COFINS/INSS/IR/CSLL)
            // ------------------------------------------------------------------
            $tributosNacionais = [];
            $tributosNacionais['cst'] = $taxData['cst'] ?? '00';

            foreach (['valor_pis', 'aliquota_pis', 'valor_cofins', 'aliquota_cofins', 'valor_inss', 'valor_ir', 'valor_csll'] as $field) {
                if (isset($taxData[$field]) && (float) $taxData[$field] > 0) {
                    $tributosNacionais[$field] = round((float) $taxData[$field], 2);
                }
            }

            if (isset($taxData['valor_base_calculo']) && (float) $taxData['valor_base_calculo'] > 0) {
                $tributosNacionais['valor_base_calculo'] = round((float) $taxData['valor_base_calculo'], 2);
            }

            if (isset($taxData['retido'])) {
                $tributosNacionais['retido'] = (bool) $taxData['retido'];
            }

            $servico['tributos_nacionais'] = $tributosNacionais;

            // ------------------------------------------------------------------
            // Tributos totais
            // ------------------------------------------------------------------
            $tributosTotais = [];

            // ISS como tributo municipal
            if ($aliquota > 0) {
                $tributosTotais['percentual_tributos_municipais'] = round($aliquota, 2);
                $tributosTotais['valor_tributos_municipais'] = round($valorServicosTotal * $aliquota / 100, 2);
            }

            if (! empty($tributosTotais)) {
                $servico['tributos_totais'] = $tributosTotais;
            }

            // ------------------------------------------------------------------
            // Payload raiz
            // ------------------------------------------------------------------
            $serie = $this->normalizeSerie($fiscalDocument->rps_series ?? null);

            $payload = [
                'numero'       => (string) $fiscalDocument->rps_number,
                'serie'        => $serie,
                'data_emissao' => $issuedAt,
                'tomador'      => $tomador,
                'servico'      => $servico,
            ];

            // Data de competência
            if ($fiscalDocument->movement_at && ! $fiscalDocument->movement_at->isSameDay($fiscalDocument->issued_at)) {
                $payload['data_competencia'] = $fiscalDocument->movement_at->format('Y-m-d') . 'T00:00:00-03:00';
            }

            // Regime
            $regime = $profile?->nfse_special_tax_regime ?? null;
            if ($regime) {
                $payload['regime_tributacao'] = $regime;
            }

            // Informações complementares
            $infoComplementar = $fiscalDocument->additional_taxpayer_information
                ?? $profile?->default_nfse_additional_information
                ?? null;
            if ($infoComplementar) {
                $payload['informacoes_complementares'] = $infoComplementar;
            }

            Log::info('BuildNfseNacionalPayloadAction: payload montado com sucesso', [
                'fiscal_document_id' => $fiscalDocument->id,
                'rps_number'         => $payload['numero'] ?? null,
                'items_count'        => $items->count(),
                'valor_total'        => $payload['servico']['valor_servicos'] ?? 0,
                'codigo_servico'     => $serviceCode,
                'codigo_nbs'         => $nbsCode,
            ]);

            $this->setSuccess();
            return $payload;

        } catch (\Exception $e) {
            $msgErro = 'Erro ao montar payload NFS-e nacional: ' . $e->getMessage();
            $this->setError($msgErro);

            Log::error('BuildNfseNacionalPayloadAction: exceção capturada', [
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

    private function normalizeSerie(?string $serie): string
    {
        $digits = preg_replace('/\D/', '', (string) $serie);

        if ($digits === '') {
            return '1';
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
