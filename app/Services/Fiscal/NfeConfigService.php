<?php

namespace App\Services\Fiscal;

use App\Models\CompanyPreference;
use Illuminate\Support\Facades\Log;

/**
 * Responsável exclusivamente por resolver as configurações da API IntegraNotas
 * por empresa (token, ambiente) via CompanyPreference.
 *
 * Chaves de CompanyPreference utilizadas:
 *   integranotas.token_producao     — JWT de produção
 *   integranotas.token_homologacao  — JWT de homologação
 *   integranotas.ambiente           — 1 (produção) | 2 (homologação)
 *   integranotas.webhook_secret     — assinatura para validar retorno do webhook
 *   integranotas.serie_padrao       — série padrão da NF-e (ex: "1")
 */
class NfeConfigService
{
    public const AMBIENTE_PRODUCAO    = 1;
    public const AMBIENTE_HOMOLOGACAO = 2;

    /**
     * Retorna o ambiente configurado da empresa (1 ou 2).
     */
    public function resolveAmbiente(int $companyId): int
    {
        $ambiente = CompanyPreference::get('integranotas.ambiente', $companyId, self::AMBIENTE_HOMOLOGACAO);

        return (int) ($ambiente['value'] ?? $ambiente ?? self::AMBIENTE_HOMOLOGACAO);
    }

    /**
     * Retorna o token correto de acordo com o ambiente ativo.
     */
    public function resolveToken(int $companyId): string
    {
        $ambiente = $this->resolveAmbiente($companyId);

        $key = $ambiente === self::AMBIENTE_PRODUCAO
            ? 'integranotas.token_producao'
            : 'integranotas.token_homologacao';

        $pref = CompanyPreference::get($key, $companyId);
        $token = is_array($pref) ? ($pref['value'] ?? '') : ($pref ?? '');

        if (empty($token)) {
            Log::warning('NfeConfigService: token IntegraNotas não configurado', [
                'company_id' => $companyId,
                'ambiente'   => $ambiente,
                'key'        => $key,
            ]);
        }

        return (string) $token;
    }

    /**
     * Retorna a série padrão configurada da empresa.
     */
    public function resolveSerie(int $companyId): string
    {
        $pref = CompanyPreference::get('integranotas.serie_padrao', $companyId, '1');

        return (string) (is_array($pref) ? ($pref['value'] ?? '1') : ($pref ?? '1'));
    }

    /**
     * Monta o array de configuração para instanciar o SDK CloudDfe\SdkPHP\Nfe.
     *
     * @return array{token: string, ambiente: int, options: array}
     */
    public function buildSdkParams(int $companyId): array
    {
        return [
            'token'   => $this->resolveToken($companyId),
            'ambiente' => $this->resolveAmbiente($companyId),
            'options' => [
                'debug'        => false,
                'timeout'      => 60,
                'port'         => 443,
                'http_version' => CURL_HTTP_VERSION_NONE,
            ],
        ];
    }

    /**
     * Retorna o segredo para validação de assinatura do webhook.
     */
    public function resolveWebhookSecret(int $companyId): ?string
    {
        $pref = CompanyPreference::get('integranotas.webhook_secret', $companyId);

        return is_array($pref) ? ($pref['value'] ?? null) : ($pref ?: null);
    }
}
