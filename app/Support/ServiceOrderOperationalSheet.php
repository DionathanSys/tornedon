<?php

namespace App\Support;

use App\Models\CompanyEntitlement;
use App\Models\Equipment;
use Illuminate\Validation\ValidationException;

class ServiceOrderOperationalSheet
{
    public const FEATURE = 'service_order_operational_sheet';

    public static function enabled(?int $companyId): bool
    {
        return $companyId && CompanyEntitlement::enabledFor($companyId, self::FEATURE);
    }

    public static function isVehicle(?int $companyId, mixed $equipmentId, mixed $customerId): bool
    {
        return Equipment::query()->where('company_id', $companyId)->where('owner_id', $customerId)
            ->find($equipmentId)?->isVehicle() ?? false;
    }

    public static function validatePayload(array $data, int $companyId, mixed $equipmentId, mixed $customerId): void
    {
        if (! array_intersect(['defects', 'entry_mileage'], array_keys($data))) {
            return;
        }

        if (! self::enabled($companyId)) {
            throw ValidationException::withMessages(['defects' => 'A ficha operacional não está habilitada para esta empresa.']);
        }

        if (isset($data['entry_mileage']) && ! self::isVehicle($companyId, $equipmentId, $customerId)) {
            throw ValidationException::withMessages(['entry_mileage' => 'A quilometragem só pode ser informada para Carro ou Caminhão.']);
        }
    }
}
