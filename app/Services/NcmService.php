<?php

namespace App\Services;

use App\Models\NcmCode;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NcmService
{
    use HandlesServiceResponse;

    /**
     * Verifica se a tabela ncm_codes possui registros.
     * Usa cache de 1 semana para evitar queries repetidas.
     */
    public function hasData(): bool
    {
        return Cache::remember('ncm_codes_has_data', 604800, function () {
            $exists = NcmCode::exists();
            Log::debug('Verificando existência de dados na tabela ncm_codes', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'exists' => $exists,
            ]);
            return $exists;
        });
    }

    /**
     * Garante que a tabela ncm_codes possui dados.
     * Se estiver vazia, dispara a importação e retorna se conseguiu carregar.
     */
    public function ensureDataLoaded(): bool
    {
        if ($this->hasData()) {
            return true;
        }

        Log::warning('Tabela ncm_codes vazia. Disparando importação automática.', [
            'metodo' => __METHOD__ . '@' . __LINE__,
        ]);

        try {
            Artisan::call('ncm:import');
            Cache::forget('ncm_codes_has_data');

            return $this->hasData();
        } catch (\Exception $e) {
            Log::error('Falha ao importar NCM automaticamente.', [
                'metodo'    => __METHOD__ . '@' . __LINE__,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
    /**
     * Verifica se um código NCM existe na tabela.
     */
    public function exists(string $code): bool
    {
        return NcmCode::where('code', self::normalize($code))->exists();
    }

    /**
     * Busca a descrição de um código NCM.
     * Retorna null se não encontrado.
     */
    public function getDescription(string $code): ?string
    {
        return NcmCode::where('code', self::normalize($code))->value('description');
    }

    /**
     * Retorna os dados completos de um código NCM.
     * Inclui código, descrição, data início e fim.
     */
    public function find(string $code): ?NcmCode
    {
        return NcmCode::where('code', self::normalize($code))->first();
    }

    /**
     * Verifica se um código NCM está vigente na data informada (padrão: hoje).
     */
    public function isValid(string $code, ?Carbon $date = null): bool
    {
        $date = $date ?? Carbon::today();

        return NcmCode::where('code', self::normalize($code))
            ->where('start_date', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $date);
            })
            ->exists();
    }

    /**
     * Retorna informações de vigência do código NCM.
     * Útil para exibir alertas no front-end.
     */
    public function getValidityInfo(string $code): ?array
    {
        $ncm = $this->find($code);

        if (!$ncm) {
            return null;
        }

        $today = Carbon::today();
        $isExpired = $ncm->end_date && $ncm->end_date->lt($today);
        $isNotYetValid = $ncm->start_date->gt($today);

        return [
            'code' => $ncm->code,
            'description' => $ncm->description,
            'start_date' => $ncm->start_date->format('d/m/Y'),
            'end_date' => $ncm->end_date?->format('d/m/Y'),
            'is_valid' => !$isExpired && !$isNotYetValid,
            'is_expired' => $isExpired,
            'is_not_yet_valid' => $isNotYetValid,
        ];
    }

    /**
     * Remove pontos e espaços do código (ex: 0204.22.00 → 02042200).
     */
    private static function normalize(string $code): string
    {
        return str_replace('.', '', trim($code));
    }
}
