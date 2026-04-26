<?php

namespace App\Services\FiscalDocument\Resolvers;

use App\Models\FiscalDocument;
use Illuminate\Support\Arr;

class NfseEmissionCityResolver
{
    public function resolve(FiscalDocument $document): ?string
    {
        $override = $this->normalizeCityCode(data_get($document->tax_data, 'nfse_city_override'));

        if ($override !== null) {
            return $override;
        }

        $profile = $document->fiscalProfile ?? $document->company?->fiscalProfile;
        $profileCity = $this->normalizeCityCode($profile?->default_service_city_code);

        if ($profileCity !== null) {
            return $profileCity;
        }

        return $this->normalizeCityCode(Arr::get($document->company?->address ?? [], 'city_code'));
    }

    private function normalizeCityCode(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? ''));

        return $digits !== '' ? $digits : null;
    }
}
