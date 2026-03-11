<?php

namespace App\Services\Fiscal;

use App\Models\CompanyPreference;
use Illuminate\Support\Facades\Log;

/**
 * Resolve as configurações da API IntegraNotas para NFS-e por empresa.
 *
 * Reutiliza os mesmos tokens e ambiente da NF-e (mesma API IntegraNotas).
 * A diferença é a série RPS (chave integranotas.nfse_serie_padrao).
 */
class NfseConfigService
{
    public function __construct(
        private NfeConfigService $nfeConfig,
    ) {}

    public function resolveAmbiente(int $companyId): int
    {
        return $this->nfeConfig->resolveAmbiente($companyId);
    }

    public function resolveToken(int $companyId): string
    {
        return $this->nfeConfig->resolveToken($companyId);
    }

    /**
     * Retorna a série padrão do RPS configurada para NFS-e.
     */
    public function resolveSerie(int $companyId): string
    {
        $pref = CompanyPreference::get('integranotas.nfse_serie_padrao', $companyId, 'RPS');

        return (string) (is_array($pref) ? ($pref['value'] ?? 'RPS') : ($pref ?? 'RPS'));
    }

    /**
     * Monta o array de configuração para instanciar o SDK CloudDfe\SdkPHP\Nfse.
     */
    public function buildSdkParams(int $companyId): array
    {
        return $this->nfeConfig->buildSdkParams($companyId);
    }

    public function resolveWebhookSecret(int $companyId): ?string
    {
        return $this->nfeConfig->resolveWebhookSecret($companyId);
    }
}
