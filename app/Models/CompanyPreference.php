<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class CompanyPreference extends Model
{
    protected $fillable = [
        'company_id',
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Busca uma preferência específica da empresa
     *
     * @param string $key Chave da preferência
     * @param int|null $companyId ID da empresa (null usa a empresa do tenant atual)
     * @param mixed $default Valor padrão caso não encontre
     * @return mixed
     */
    public static function get(string $key, ?int $companyId = null, mixed $default = null): mixed
    {
        $companyId = $companyId ?? self::getCurrentCompanyId();

        if (!$companyId) {
            return $default;
        }

        $cacheKey = "company_preference_{$companyId}_{$key}";

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($key, $companyId, $default) {
            $preference = self::where('company_id', $companyId)
                ->where('key', $key)
                ->first();

            return $preference ? $preference->value : $default;
        });
    }

    /**
     * Define uma preferência da empresa
     *
     * @param string $key Chave da preferência
     * @param mixed $value Valor da preferência
     * @param int|null $companyId ID da empresa (null usa a empresa do tenant atual)
     * @return CompanyPreference
     */
    public static function set(string $key, mixed $value, ?int $companyId = null): CompanyPreference
    {
        $companyId = $companyId ?? self::getCurrentCompanyId();

        if (!$companyId) {
            throw new \RuntimeException('Company ID não pode ser nulo');
        }

        // Limpar cache
        Cache::forget("company_preference_{$companyId}_{$key}");

        return self::updateOrCreate(
            [
                'company_id' => $companyId,
                'key' => $key,
            ],
            [
                'value' => $value,
            ]
        );
    }

    /**
     * Remove uma preferência da empresa
     *
     * @param string $key Chave da preferência
     * @param int|null $companyId ID da empresa (null usa a empresa do tenant atual)
     * @return bool
     */
    public static function remove(string $key, ?int $companyId = null): bool
    {
        $companyId = $companyId ?? self::getCurrentCompanyId();

        if (!$companyId) {
            return false;
        }

        // Limpar cache
        Cache::forget("company_preference_{$companyId}_{$key}");

        return self::where('company_id', $companyId)
            ->where('key', $key)
            ->delete() > 0;
    }

    /**
     * Busca múltiplas preferências de uma vez
     *
     * @param array $keys Array de chaves
     * @param int|null $companyId ID da empresa (null usa a empresa do tenant atual)
     * @param array $defaults Valores padrão para cada chave
     * @return array Array associativo [chave => valor]
     */
    public static function getMultiple(array $keys, ?int $companyId = null, array $defaults = []): array
    {
        $companyId = $companyId ?? self::getCurrentCompanyId();

        if (!$companyId) {
            return $defaults;
        }

        $preferences = self::where('company_id', $companyId)
            ->whereIn('key', $keys)
            ->get()
            ->pluck('value', 'key')
            ->toArray();

        // Preencher com defaults para chaves não encontradas
        foreach ($keys as $key) {
            if (!isset($preferences[$key])) {
                $preferences[$key] = $defaults[$key] ?? null;
            }
        }

        return $preferences;
    }

    /**
     * Busca todas as preferências de uma empresa
     *
     * @param int|null $companyId ID da empresa (null usa a empresa do tenant atual)
     * @return array Array associativo [chave => valor]
     */
    public static function getAll(?int $companyId = null): array
    {
        $companyId = $companyId ?? self::getCurrentCompanyId();

        if (!$companyId) {
            return [];
        }

        return self::where('company_id', $companyId)
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Limpa o cache de preferências de uma empresa
     *
     * @param int|null $companyId ID da empresa (null usa a empresa do tenant atual)
     * @return void
     */
    public static function clearCache(?int $companyId = null): void
    {
        $companyId = $companyId ?? self::getCurrentCompanyId();

        if (!$companyId) {
            return;
        }

        // Buscar todas as chaves dessa empresa e limpar o cache
        $keys = self::where('company_id', $companyId)
            ->pluck('key');

        foreach ($keys as $key) {
            Cache::forget("company_preference_{$companyId}_{$key}");
        }
    }

    /**
     * Obtém o ID da empresa atual (do contexto Filament/Tenant)
     *
     * @return int|null
     */
    protected static function getCurrentCompanyId(): ?int
    {
        // Tentar obter da tenant do Filament
        if (class_exists('\Filament\Facades\Filament')) {
            $tenant = \Filament\Facades\Filament::getTenant();
            if ($tenant) {
                return $tenant->id;
            }
        }

        // Tentar obter do usuário autenticado
        $user = Auth::user();
        if ($user && method_exists($user, 'getCurrentCompanyId')) {
            return $user->getCurrentCompanyId();
        }

        // Pode adicionar outras formas de obter o company_id aqui
        return null;
    }

    // ========== Métodos de conveniência para preferências comuns ==========

    /**
     * Obtém o método de pagamento padrão da empresa
     *
     * @param int|null $companyId
     * @return string|null
     */
    public static function getDefaultPaymentMethod(?int $companyId = null): ?string
    {
        return self::get('default_payment_method', $companyId);
    }

    /**
     * Define o método de pagamento padrão da empresa
     *
     * @param string $method
     * @param int|null $companyId
     * @return CompanyPreference
     */
    public static function setDefaultPaymentMethod(string $method, ?int $companyId = null): CompanyPreference
    {
        return self::set('default_payment_method', $method, $companyId);
    }

    /**
     * Obtém a condição de pagamento padrão da empresa
     *
     * @param int|null $companyId
     * @return string|null
     */
    public static function getDefaultPaymentCondition(?int $companyId = null): ?string
    {
        return self::get('default_payment_condition', $companyId);
    }

    /**
     * Define a condição de pagamento padrão da empresa
     *
     * @param string $condition
     * @param int|null $companyId
     * @return CompanyPreference
     */
    public static function setDefaultPaymentCondition(string $condition, ?int $companyId = null): CompanyPreference
    {
        return self::set('default_payment_condition', $condition, $companyId);
    }

    /**
     * Obtém o prazo de validade padrão de orçamentos (em dias)
     *
     * @param int|null $companyId
     * @return int|null
     */
    public static function getDefaultQuoteValidityDays(?int $companyId = null): ?int
    {
        return self::get('default_quote_validity_days', $companyId, 30);
    }

    /**
     * Define o prazo de validade padrão de orçamentos
     *
     * @param int $days
     * @param int|null $companyId
     * @return CompanyPreference
     */
    public static function setDefaultQuoteValidityDays(int $days, ?int $companyId = null): CompanyPreference
    {
        return self::set('default_quote_validity_days', $days, $companyId);
    }

    /**
     * Obtém a margem de lucro padrão (em percentual)
     *
     * @param int|null $companyId
     * @return float|null
     */
    public static function getDefaultProfitMargin(?int $companyId = null): ?float
    {
        return self::get('default_profit_margin', $companyId);
    }

    /**
     * Define a margem de lucro padrão
     *
     * @param float $margin
     * @param int|null $companyId
     * @return CompanyPreference
     */
    public static function setDefaultProfitMargin(float $margin, ?int $companyId = null): CompanyPreference
    {
        return self::set('default_profit_margin', $margin, $companyId);
    }

    /**
     * Obtém as configurações de e-mail
     *
     * @param int|null $companyId
     * @return array|null
     */
    public static function getEmailSettings(?int $companyId = null): ?array
    {
        return self::get('email_settings', $companyId);
    }

    /**
     * Define as configurações de e-mail
     *
     * @param array $settings
     * @param int|null $companyId
     * @return CompanyPreference
     */
    public static function setEmailSettings(array $settings, ?int $companyId = null): CompanyPreference
    {
        return self::set('email_settings', $settings, $companyId);
    }

    /**
     * Obtém configurações de notificação
     *
     * @param int|null $companyId
     * @return array|null
     */
    public static function getNotificationSettings(?int $companyId = null): ?array
    {
        return self::get('notification_settings', $companyId);
    }

    /**
     * Define configurações de notificação
     *
     * @param array $settings
     * @param int|null $companyId
     * @return CompanyPreference
     */
    public static function setNotificationSettings(array $settings, ?int $companyId = null): CompanyPreference
    {
        return self::set('notification_settings', $settings, $companyId);
    }
}
