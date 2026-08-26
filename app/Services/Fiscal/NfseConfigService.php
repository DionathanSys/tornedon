<?php

namespace App\Services\Fiscal;

use App\Enum\FiscalDocument\NfseModel;
use App\Models\CompanyPreference;

/**
 * Resolve as configurações da API IntegraNotas para NFS-e por empresa.
 *
 * Reutiliza os mesmos tokens e ambiente da NF-e (mesma API IntegraNotas).
 * A diferença é a série RPS (chave integranotas.nfse_serie_padrao).
 */
class NfseConfigService
{
    public const PINHALZINHO_SC_IBGE_CODE = '4212908';

    public const API_VERSION_V1 = 1;

    public const API_VERSION_V2 = 2;

    public const OPERATION_CREATE = 'create';

    public const OPERATION_PREVIEW = 'preview';

    public const OPERATION_SUBSTITUTE = 'substitute';

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
        $pref = CompanyPreference::get('integranotas.nfse_serie_padrao', $companyId, '1');

        $serie = (string) (is_array($pref) ? ($pref['value'] ?? '1') : ($pref ?? '1'));
        $digits = preg_replace('/\D/', '', $serie);

        return $digits !== '' ? substr($digits, 0, 5) : '1';
    }

    /**
     * Monta o array de configuração para instanciar o SDK CloudDfe\SdkPHP\Nfse.
     */
    public function buildSdkParams(int $companyId, ?string $operation = null): array
    {
        return [
            ...$this->nfeConfig->buildSdkParams($companyId),
            'version' => $this->resolveApiVersionForOperation($operation),
        ];
    }

    public function resolveWebhookSecret(int $companyId): ?string
    {
        return $this->nfeConfig->resolveWebhookSecret($companyId);
    }

    public function resolvePinhalzinhoIpmTaxRegime(int $companyId): ?string
    {
        $value = $this->resolvePreferenceString('integranotas.nfse_ipm_regime_tributacao', $companyId);

        return in_array($value, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '15'], true)
            ? $value
            : null;
    }

    private function resolvePreferenceString(string $key, int $companyId): string
    {
        $value = CompanyPreference::get($key, $companyId, '');

        return trim((string) (is_array($value) ? ($value['value'] ?? '') : $value));
    }

    /**
     * Retorna o modelo padrão da NFS-e (Municipal ou Nacional).
     */
    public function resolveNfseModeloPadrao(int $companyId): NfseModel
    {
        $pref = CompanyPreference::get('integranotas.nfse_modelo_padrao', $companyId);
        $value = is_array($pref) ? ($pref['value'] ?? null) : $pref;

        return NfseModel::tryFrom((string) ($value ?? '')) ?? NfseModel::MUNICIPAL;
    }

    public function resolveApiVersionForOperation(?string $operation = null): int
    {
        return match ($operation) {
            self::OPERATION_CREATE,
            self::OPERATION_PREVIEW,
            self::OPERATION_SUBSTITUTE => self::API_VERSION_V2,
            default => self::API_VERSION_V1,
        };
    }
}
