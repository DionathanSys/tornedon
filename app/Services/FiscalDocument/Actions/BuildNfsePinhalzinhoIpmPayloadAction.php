<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Services\Fiscal\NfseConfigService;

class BuildNfsePinhalzinhoIpmPayloadAction extends BuildNfseMunicipalPayloadAction
{
    public function identifier(): string
    {
        return 'municipal:'.NfseConfigService::PINHALZINHO_SC_IBGE_CODE;
    }

    public function build(FiscalDocument $fiscalDocument): ?array
    {
        $companyId = (int) $fiscalDocument->company_id;
        $config = app(NfseConfigService::class);
        $taxRegime = $config->resolvePinhalzinhoIpmTaxRegime($companyId);

        if ($taxRegime === null) {
            $this->setError('NFS-e de Pinhalzinho/SC requer a situação tributária IPM configurada.');

            return null;
        }

        $payload = parent::build($fiscalDocument);

        if ($payload === null) {
            return null;
        }

        // A API v1 da IntegraNotas para Pinhalzinho valida os campos nacionais
        // no wrapper de serviço, embora mantenha os itens no layout municipal.
        $payload['servico']['codigo'] = preg_replace('/\D/', '', $payload['servico']['codigo']);
        $payload['servico']['endereco_local_prestacao'] = [
            'codigo_municipio_prestacao' => NfseConfigService::PINHALZINHO_SC_IBGE_CODE,
        ];
        $payload['servico']['tributos_municipais'] = [
            'tipo_operacao' => '1',
        ];

        foreach ($payload['servico']['itens'] as &$item) {
            unset($item['codigo_cnae'], $item['exigibilidade_iss'], $item['valor_iss']);

            $item['codigo'] = preg_replace('/\D/', '', $item['codigo']);
            $item['regime_tributacao'] = $taxRegime;

            // O IPM exige a tag mesmo quando a alíquota aplicável é zero.
            $item['valor_aliquota'] = $item['valor_aliquota'] ?? 0;
        }
        unset($item);

        $payload['servico']['codigo_municipio'] = NfseConfigService::PINHALZINHO_SC_IBGE_CODE;
        $payload['regime_tributacao'] = $taxRegime;

        return $payload;
    }
}
