<?php

namespace App\Services;

use App\Models\NcmCode;
use Carbon\Carbon;

class NcmService
{
    /**
     * Verifica se um código NCM existe na tabela.
     */
    public function exists(string $code): bool
    {
        $code = $this->normalize($code);

        return NcmCode::where('code', $code)->exists();
    }

    /**
     * Busca a descrição de um código NCM.
     * Retorna null se não encontrado.
     */
    public function getDescription(string $code): ?string
    {
        $code = $this->normalize($code);

        return NcmCode::where('code', $code)->value('description');
    }

    /**
     * Retorna os dados completos de um código NCM.
     * Inclui código, descrição, data início e fim.
     */
    public function find(string $code): ?NcmCode
    {
        $code = $this->normalize($code);

        return NcmCode::where('code', $code)->first();
    }

    /**
     * Verifica se um código NCM está vigente na data informada (padrão: hoje).
     */
    public function isValid(string $code, ?Carbon $date = null): bool
    {
        $code = $this->normalize($code);
        $date = $date ?? Carbon::today();

        return NcmCode::where('code', $code)
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
     * Remove pontos do código para normalizar (ex: 0204.22.00 → 02042200).
     */
    private function normalize(string $code): string
    {
        return str_replace('.', '', trim($code));
    }
}
