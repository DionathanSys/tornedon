<?php

namespace App\Support;

class ServiceOrderTravelData
{
    public static function normalize(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $normalized = preg_replace('/[^\d,\.\-]/', '', trim((string) $value));

        if ($normalized === null || $normalized === '' || $normalized === '-') {
            return 0.0;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return round((float) $normalized, 2);
    }

    public static function calculate(mixed $valueKm, mixed $distanceKm): float
    {
        return round(
            self::normalize($valueKm) * self::normalize($distanceKm),
            2
        );
    }

    public static function format(mixed $value): string
    {
        return number_format(self::normalize($value), 2, ',', '.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $data): array
    {
        $hasValueKm = array_key_exists('value_km', $data);
        $hasDistanceKm = array_key_exists('distance_km', $data);
        $travelValue = self::normalize($data['travel_value'] ?? 0);

        if ($hasValueKm) {
            $data['value_km'] = self::normalize($data['value_km']);
        }

        if ($hasDistanceKm) {
            $data['distance_km'] = self::normalize($data['distance_km']);
        }

        if ($hasValueKm || $hasDistanceKm) {
            if ($travelValue > 0 && (float) ($data['value_km'] ?? 0) === 0.0 && (float) ($data['distance_km'] ?? 0) === 0.0) {
                $data['value_km'] = $travelValue;
                $data['distance_km'] = 1.0;
                $data['travel_value'] = $travelValue;
            } else {
                $data['travel_value'] = self::calculate(
                    $data['value_km'] ?? 0,
                    $data['distance_km'] ?? 0
                );
            }
        } elseif (array_key_exists('travel_value', $data)) {
            $data['travel_value'] = $travelValue;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function hydrate(array $data, mixed $defaultValueKm = 0): array
    {
        $travelValue = self::normalize($data['travel_value'] ?? 0);
        $fallbackValueKm = self::normalize($defaultValueKm);

        $hasValueKm = array_key_exists('value_km', $data) && filled($data['value_km']);
        $hasDistanceKm = array_key_exists('distance_km', $data) && filled($data['distance_km']);

        $valueKm = $hasValueKm ? self::normalize($data['value_km']) : 0.0;
        $distanceKm = $hasDistanceKm ? self::normalize($data['distance_km']) : 0.0;

        if ((! $hasValueKm && ! $hasDistanceKm) || ($valueKm === 0.0 && $distanceKm === 0.0 && $travelValue > 0)) {
            if ($fallbackValueKm > 0) {
                $valueKm = $fallbackValueKm;
                $distanceKm = $travelValue > 0 ? round($travelValue / $fallbackValueKm, 2) : 0.0;
            } elseif ($travelValue > 0) {
                $valueKm = $travelValue;
                $distanceKm = 1.0;
            }
        } elseif (! $hasValueKm && $distanceKm > 0) {
            $valueKm = $travelValue > 0 ? round($travelValue / $distanceKm, 2) : 0.0;
        } elseif (! $hasDistanceKm && $valueKm > 0) {
            $distanceKm = $travelValue > 0 ? round($travelValue / $valueKm, 2) : 0.0;
        }

        $data['value_km'] = $valueKm;
        $data['distance_km'] = $distanceKm;
        $data['travel_value'] = $travelValue > 0
            ? $travelValue
            : self::calculate($valueKm, $distanceKm);

        return $data;
    }
}
