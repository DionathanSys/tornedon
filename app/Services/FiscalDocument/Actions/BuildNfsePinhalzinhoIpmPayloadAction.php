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

        // O IPM não utiliza NBS, exigibilidade ISS ou os wrappers nacionais.
        unset(
            $payload['servico']['codigo_nbs'],
            $payload['servico']['discriminacao'],
            $payload['servico']['valor_servicos'],
            $payload['servico']['valor_base_calculo'],
        );

        foreach ($payload['servico']['itens'] as &$item) {
            unset($item['codigo_nbs'], $item['codigo_cnae'], $item['exigibilidade_iss'], $item['valor_iss']);
        }
        unset($item);

        $payload['servico']['codigo_municipio'] = NfseConfigService::PINHALZINHO_SC_IBGE_CODE;
        $payload['regime_tributacao'] = $taxRegime;

        return $payload;
    }

    protected function requiresNbs(): bool
    {
        return false;
    }
}
