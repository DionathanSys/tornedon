<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Monta o payload NFS-e no formato municipal (Chapecó-SC / padrão ABRASF).
 *
 * Estrutura: numero, serie, tipo, data_emissao, status, data_competencia,
 * regime_tributacao, incentivo_fiscal, tomador, servico { iss_retido, itens[] }.
 */
class BuildNfseMunicipalPayloadAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): ?array
    {
        try {
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
                    'codigo_municipio' => $address->city_code ?? '',
                    'uf'               => $address->state ?? '',
                    'cep'              => preg_replace('/\D/', '', $address->postal_code ?? ''),
                ];
            }

            // ------------------------------------------------------------------
            // Itens de serviço
            // ------------------------------------------------------------------
            $itens = [];
            foreach ($fiscalDocument->items as $item) {
                $taxData = $item->tax_data ?? [];

                $itemPayload = [
                    'codigo'        => $item->service_code ?? $profile?->default_service_code ?? '',
                    'discriminacao' => $item->description ?? '',
                    'valor_servicos'=> round((float) $item->total_price, 2),
                ];

                if ($item->ncm_code || $profile?->service_cnae_code) {
                    $itemPayload['codigo_cnae'] = $item->cnae_code ?? $profile?->service_cnae_code;
                }

                $nbsCode = $item->nbs_code ?? $profile?->default_nbs_code ?? null;
                if ($nbsCode) {
                    $itemPayload['codigo_nbs'] = $nbsCode;
                }

                $ctm = $item->municipal_tax_code ?? $profile?->default_municipal_tax_code ?? null;
                if ($ctm) {
                    $itemPayload['codigo_tributacao_municipio'] = $ctm;
                }

                // Exigibilidade ISS
                $exigibilidade = $item->iss_exigibility ?? '1';
                $itemPayload['exigibilidade_iss'] = $exigibilidade;

                // Alíquota e ISS
                $aliquota = (float) ($item->iss_rate ?? $profile?->iss_rate_default ?? 0);
                if ($aliquota > 0) {
                    $itemPayload['valor_aliquota'] = round($aliquota, 2);
                    $valorServicos = (float) $itemPayload['valor_servicos'];
                    $itemPayload['valor_iss'] = round($valorServicos * $aliquota / 100, 2);
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
                $this->setError('NFS-e Municipal requer ao menos um item de serviço.');
                return null;
            }

            // ------------------------------------------------------------------
            // Serviço (wrapper)
            // ------------------------------------------------------------------
            $servico = [
                'iss_retido' => (bool) ($fiscalDocument->items->first()?->iss_withheld
                    ?? $profile?->iss_withheld_default
                    ?? false),
                'itens' => $itens,
            ];

            // Município de incidência e prestação
            $companyAddress = $company->address ?? [];
            $municipioPrestador = $companyAddress['city_code'] ?? null;
            if ($municipioPrestador) {
                $servico['codigo_municipio'] = $municipioPrestador;
            }

            $municipioPrestacao = $address?->city_code ?? $municipioPrestador;
            if ($municipioPrestacao && $municipioPrestacao !== $municipioPrestador) {
                $servico['codigo_municipio_prestacao'] = $municipioPrestacao;
            }

            // ------------------------------------------------------------------
            // Payload raiz
            // ------------------------------------------------------------------
            $payload = [
                'numero'       => (string) $fiscalDocument->rps_number,
                'serie'        => $fiscalDocument->rps_series ?? 'RPS',
                'tipo'         => $fiscalDocument->rps_type ?? '1',
                'data_emissao' => $issuedAt,
                'status'       => '1',
                'tomador'      => $tomador,
                'servico'      => $servico,
            ];

            // Data de competência (se diferente de emissão)
            if ($fiscalDocument->movement_at && ! $fiscalDocument->movement_at->isSameDay($fiscalDocument->issued_at)) {
                $payload['data_competencia'] = $fiscalDocument->movement_at->format('Y-m-d') . 'T00:00:00-03:00';
            }

            // Regime especial de tributação
            $regime = $profile?->nfse_special_tax_regime ?? null;
            if ($regime) {
                $payload['regime_tributacao'] = $regime;
            }

            $this->setSuccess();
            return $payload;

        } catch (\Exception $e) {
            $this->setError('Erro ao montar payload NFS-e municipal: ' . $e->getMessage());

            Log::error('BuildNfseMunicipalPayloadAction: erro', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
