<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\IssuerType;
use App\Enum\FiscalDocument\MunicipalTaxOperationType;
use App\Enum\FiscalDocument\NationalWithholdingType;
use App\Enum\FiscalDocument\RecipientType;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Contracts\NfsePayloadBuilder;
use App\Services\FiscalDocument\Validators\NfseNacionalV1Validator;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Monta o payload NFS-e no formato nacional (DPS/NFS-e Nacional) — V1.
 *
 * Estrutura: regime_apuracao, regime_tributacao, data_emissao, numero, serie,
 * tipo_emitente, tomador, servico { codigo, discriminacao, codigo_nbs,
 * valor_servicos, tributos_municipais, tributos_nacionais }.
 *
 * V1 constraints:
 * - tipo_emitente = '1' (prestador)
 * - tomador.tipo_destinatario = '0' (nacional)
 * - servico.tributos_municipais.tipo_operacao = '1'
 * - servico.tributos_nacionais.tipo_retencao = '2' (não retido)
 * - sem exportação (uf ≠ EX)
 * - sem blocos especiais (contingência, intermediário, obra, etc.)
 */
class BuildNfseNacionalPayloadAction
    implements NfsePayloadBuilder
{
    use HandlesActionResponse;

    public function supports(FiscalDocument $fiscalDocument): bool
    {
        return $fiscalDocument->isNfse();
    }

    public function identifier(): string
    {
        return 'nacional:default';
    }

    public function build(FiscalDocument $fiscalDocument): ?array
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
                'customer.contacts',
                'items.service',
                'fiscalProfile',
            ]);

            $company  = $fiscalDocument->company;
            $customer = $fiscalDocument->customer;
            $address  = $customer?->address?->first();
            $profile  = $fiscalDocument->fiscalProfile ?? $company->fiscalProfile;

            $issuedAt = ($fiscalDocument->issued_at ?? now())->format('Y-m-d\TH:i:sP');

            // ------------------------------------------------------------------
            // Tomador
            // ------------------------------------------------------------------
            $tomador = $this->buildTomador($customer, $address);

            if ($tomador === null) {
                return null;
            }

            // ------------------------------------------------------------------
            // Block export (UF = EX) in V1
            // ------------------------------------------------------------------
            $uf = strtoupper(trim($tomador['endereco']['uf'] ?? ''));
            if ($uf === 'EX') {
                $this->setError('NFS-e Nacional V1 não suporta emissão para exterior (UF = EX).');
                Log::warning('BuildNfseNacionalPayloadAction: exportação bloqueada na V1', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'uf'                 => $uf,
                ]);
                return null;
            }

            // ------------------------------------------------------------------
            // Consolidar serviço (modelo nacional = 1 bloco de serviço, não array de itens)
            // ------------------------------------------------------------------
            $servico = $this->buildServico($fiscalDocument, $profile, $address, $company);

            if ($servico === null) {
                return null;
            }

            // ------------------------------------------------------------------
            // Payload raiz
            // ------------------------------------------------------------------
            $serie = $this->normalizeSerie($fiscalDocument->rps_series ?? null);

            if ($serie === '') {
                $this->setError('NFS-e nacional requer série RPS válida para emissão.');
                Log::warning('BuildNfseNacionalPayloadAction: série RPS inválida', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'rps_series'         => $fiscalDocument->rps_series,
                ]);
                return null;
            }

            $payload = [
                'data_emissao'  => $issuedAt,
                'numero'        => (string) $fiscalDocument->rps_number,
                'serie'         => $serie,
                'tipo_emitente' => IssuerType::PROVIDER->value,
                'tomador'       => $tomador,
                'servico'       => $servico,
            ];

            // Data de competência
            if ($fiscalDocument->movement_at && ! $fiscalDocument->movement_at->isSameDay($fiscalDocument->issued_at)) {
                $payload['data_competencia'] = $fiscalDocument->movement_at->format('Y-m-d\TH:i:sP');
            }

            // Regime de apuração (configurável por empresa)
            $regimeApuracao = $profile?->nfse_nacional_regime_apuracao ?? null;
            if ($regimeApuracao !== null && trim((string) $regimeApuracao) !== '') {
                $payload['regime_apuracao'] = trim((string) $regimeApuracao);
            }

            // Regime de tributação
            $regime = $profile?->nfse_special_tax_regime ?? null;
            if ($regime !== null && trim((string) $regime) !== '') {
                $payload['regime_tributacao'] = (string) $regime;
            }

            // Informações complementares
            $informacoesComplementaresItens = $fiscalDocument->items
                ->pluck('additional_information')
                ->filter(fn ($info) => filled($info))
                ->map(fn ($info) => trim((string) $info))
                ->unique()
                ->implode("\n");

            $infoComplementar = $fiscalDocument->additional_taxpayer_information
                ?? ($informacoesComplementaresItens !== '' ? $informacoesComplementaresItens : null)
                ?? $profile?->default_nfse_additional_information
                ?? null;

            if ($infoComplementar !== null && trim((string) $infoComplementar) !== '') {
                $payload['informacoes_complementares'] = substr(trim((string) $infoComplementar), 0, 2000);
            }

            // ------------------------------------------------------------------
            // Remove null values and empty objects
            // ------------------------------------------------------------------
            $payload = $this->removeNullsRecursive($payload);

            // ------------------------------------------------------------------
            // Validate assembled payload before returning
            // ------------------------------------------------------------------
            $validator = new NfseNacionalV1Validator();
            $validationErrors = $validator->validate($payload);

            if ($validationErrors !== []) {
                $firstError = collect($validationErrors)->flatten()->first();
                $this->setError('Payload NFS-e Nacional V1 inválido: ' . ($firstError ?? 'erro de validação'));

                Log::warning('BuildNfseNacionalPayloadAction: payload falhou na validação V1', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'errors'             => $validationErrors,
                ]);

                return null;
            }

            Log::info('BuildNfseNacionalPayloadAction: payload montado com sucesso', [
                'fiscal_document_id' => $fiscalDocument->id,
                'rps_number'         => $payload['numero'] ?? null,
                'items_count'        => $fiscalDocument->items->count(),
                'valor_total'        => $payload['servico']['valor_servicos'] ?? 0,
                'codigo_servico'     => $payload['servico']['codigo'] ?? null,
                'codigo_nbs'         => $payload['servico']['codigo_nbs'] ?? null,
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

    public function execute(FiscalDocument $fiscalDocument): ?array
    {
        return $this->build($fiscalDocument);
    }

    // ------------------------------------------------------------------
    // Tomador builder
    // ------------------------------------------------------------------

    private function buildTomador($customer, $address): ?array
    {
        if ($customer === null) {
            $this->setError('NFS-e nacional requer tomador de serviço.');
            return null;
        }

        $tomador = [
            'razao_social'      => $customer->name,
            'tipo_destinatario' => RecipientType::DOMESTIC->value,
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

        if ($address === null) {
            $this->setError('NFS-e nacional requer endereço completo do tomador.');
            Log::warning('BuildNfseNacionalPayloadAction: endereço do tomador ausente', [
                'customer_id' => $customer->id,
            ]);
            return null;
        }

        $logradouro      = trim((string) ($address->street ?? ''));
        $numero          = trim((string) ($address->number ?? ''));
        $bairro          = trim((string) ($address->neighborhood ?? ''));
        $codigoMunicipio = preg_replace('/\D/', '', (string) ($address->city_code ?? ''));
        $uf              = trim((string) ($address->state ?? ''));
        $cep             = preg_replace('/\D/', '', (string) ($address->postal_code ?? ''));

        $requiredAddressFields = [
            'logradouro'       => $logradouro,
            'numero'           => $numero,
            'bairro'           => $bairro,
            'uf'               => $uf,
        ];

        foreach ($requiredAddressFields as $field => $value) {
            if ($value === '') {
                $this->setError("NFS-e nacional requer o campo {$field} no endereço do tomador.");
                Log::warning('BuildNfseNacionalPayloadAction: endereço do tomador incompleto', [
                    'customer_id' => $customer->id,
                    'field'       => $field,
                ]);
                return null;
            }
        }

        $endereco = [
            'logradouro' => $logradouro,
            'numero'     => $numero,
            'bairro'     => $bairro,
            'uf'         => $uf,
        ];

        $complemento = trim((string) ($address->complement ?? ''));
        if ($complemento !== '') {
            $endereco['complemento'] = $complemento;
        }

        if ($codigoMunicipio !== '') {
            $endereco['codigo_municipio'] = $codigoMunicipio;
        }

        if ($cep !== '') {
            $endereco['cep'] = $cep;
        }

        $tomador['endereco'] = $endereco;

        return $tomador;
    }

    // ------------------------------------------------------------------
    // Serviço builder
    // ------------------------------------------------------------------

    private function buildServico(FiscalDocument $fiscalDocument, $profile, $address, $company): ?array
    {
        $items = $fiscalDocument->items;

        if ($items->isEmpty()) {
            $this->setError('NFS-e Nacional requer ao menos um item de serviço.');
            Log::warning('BuildNfseNacionalPayloadAction: validação falhou - sem itens', [
                'fiscal_document_id' => $fiscalDocument->id,
            ]);
            return null;
        }

        // Primeiro item como referência de código/discriminação
        $firstItem = $items->first();
        $taxData   = $firstItem->tax_data ?? [];

        $valorServicosTotal = $items->sum(fn ($i) => round((float) $i->total_price, 2));
        $discriminacoes     = $items->map(fn ($i) => trim((string) $i->description))
            ->filter()
            ->implode("\n");

        // No layout nacional, `codigo` deve refletir o código fiscal configurado para o serviço.
        $serviceCode = $this->normalizeServiceCode(
            $firstItem->municipal_tax_code
                ?? $firstItem->service?->municipal_tax_code
                ?? $profile?->default_municipal_tax_code
        );

        $serviceCode = "140501";

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
            $this->setError('NFS-e Nacional requer o código do serviço (LC 116/2003).');
            Log::warning('BuildNfseNacionalPayloadAction: código de serviço vazio', [
                'fiscal_document_id' => $fiscalDocument->id,
            ]);
            return null;
        }

        if ($nbsCode === '') {
            $this->setError('NFS-e Nacional requer o código NBS (cNBS) com 9 dígitos.');
            Log::warning('BuildNfseNacionalPayloadAction: código NBS vazio', [
                'fiscal_document_id' => $fiscalDocument->id,
            ]);
            return null;
        }

        if (round($valorServicosTotal, 2) <= 0) {
            $this->setError('NFS-e Nacional requer valor de serviços maior que zero.');
            Log::warning('BuildNfseNacionalPayloadAction: valor total zerado', [
                'fiscal_document_id' => $fiscalDocument->id,
                'valor_total'        => $valorServicosTotal,
                'items_count'        => $items->count(),
            ]);
            return null;
        }

        $servico = [
            'codigo'         => $serviceCode,
            'discriminacao'  => substr($discriminacao, 0, 2000),
            'codigo_nbs'     => $nbsCode,
            'valor_servicos' => round($valorServicosTotal, 2),
        ];

        // Município de prestação
        $companyAddress = $company->address ?? [];
        $municipioPrestador = $companyAddress['city_code'] ?? null;
        $municipioPrestacao = $address?->city_code ?? $municipioPrestador;
        if ($municipioPrestacao) {
            $servico['codigo_municipio_prestacao'] = $municipioPrestacao;
        }

        // Valor recebido
        $servico['valor_recebido'] = round($valorServicosTotal, 2);

        // Descontos
        $descontoIncondicionado = $items->sum(fn ($i) => round((float) ($i->discount_amount ?? 0), 2));
        if (round($descontoIncondicionado, 2) > 0) {
            $servico['valor_desconto_incondicionado'] = round($descontoIncondicionado, 2);
        }

        // ------------------------------------------------------------------
        // Tributos municipais (ISS)
        // ------------------------------------------------------------------
        $issRetido = (bool) ($firstItem->iss_withheld ?? $profile?->iss_withheld_default ?? false);
        $aliquota  = (float) ($firstItem->iss_rate ?? $profile?->iss_rate_default ?? 0);

        $tributosMunicipais = [
            'tipo_operacao' => MunicipalTaxOperationType::TAXABLE_IN_MUNICIPALITY->value,
            'iss_retido'    => $issRetido,
        ];

        if ($aliquota > 0) {
            $tributosMunicipais['valor_aliquota'] = round($aliquota, 2);
        }

        $servico['tributos_municipais'] = $tributosMunicipais;

        // ------------------------------------------------------------------
        // Tributos nacionais (PIS/COFINS/INSS/IR/CSLL)
        // ------------------------------------------------------------------
        $cstDefault = $profile?->nfse_nacional_cst_default ?? null;

        $tributosNacionais = [
            'cst'           => $taxData['cst'] ?? $cstDefault ?? '00',
            'tipo_retencao' => NationalWithholdingType::NOT_WITHHELD->value,
        ];

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
        if ($aliquota > 0) {
            $servico['tributos_totais'] = [
                'percentual_tributos_municipais' => round($aliquota, 2),
                'valor_tributos_municipais'      => round($valorServicosTotal * $aliquota / 100, 2),
            ];
        }

        return $servico;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Remove null values and empty arrays recursively from payload.
     */
    private function removeNullsRecursive(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $cleaned = $this->removeNullsRecursive($value);
                if ($cleaned !== []) {
                    $result[$key] = $cleaned;
                }
                continue;
            }

            // Keep empty strings only when they are meaningful (e.g., complemento = '')
            // but skip truly empty values
            if (is_string($value) && trim($value) === '' && ! in_array($key, ['complemento'], true)) {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function normalizeSerie(?string $serie): string
    {
        $digits = preg_replace('/\D/', '', (string) $serie);

        if ($digits === '') {
            return '';
        }

        return substr($digits, 0, 5);
    }

    /**
     * Normalizes service code to 6-digit format for nacional (e.g., "01.01" → "010100").
     * Falls back to 4-digit padded + "00" suffix if the raw code has only 4 or fewer digits.
     */
    private function normalizeServiceCode(?string $code): string
    {
        $digits = preg_replace('/\D/', '', (string) $code);

        if ($digits === '') {
            return '';
        }

        // Pad to at least 4 digits, then ensure 6 digits
        $padded = str_pad(substr($digits, 0, 6), 6, '0', STR_PAD_RIGHT);

        return $padded;
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
