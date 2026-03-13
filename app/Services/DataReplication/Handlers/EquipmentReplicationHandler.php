<?php

namespace App\Services\DataReplication\Handlers;

use App\Models\Equipment;
use App\Models\Partner;
use App\Services\Equipment\EquipmentService;

class EquipmentReplicationHandler
{
    /**
     * Handler para replicação de Equipments
     *
     * Replica:
     * 1. Equipment completo
     * 2. Valida que o owner (Partner) existe na empresa alvo
     */
    public function handle(Equipment $equipment, array $targetCompanyIds): array
    {
        $result = [
            'successful' => [],
            'failed' => [],
        ];

        foreach ($targetCompanyIds as $companyId) {
            try {
                $this->replicateToCompany($equipment, $companyId);
                $result['successful'][] = [
                    'company_id' => $companyId,
                    'equipment_id' => $equipment->id,
                ];
            } catch (\Exception $e) {
                $result['failed'][] = [
                    'company_id' => $companyId,
                    'equipment_id' => $equipment->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Replica para uma empresa específica
     */
    private function replicateToCompany(Equipment $equipment, int $companyId): void
    {
        // Validar que o equipamento ainda não existe (mesma placa/serial na empresa alvo)
        $exists = Equipment::where('company_id', $companyId)
            ->where(function ($query) use ($equipment) {
                $query->where('placa', $equipment->placa)
                    ->orWhere('serial_number', $equipment->serial_number);
            })
            ->exists();

        if ($exists) {
            throw new \DomainException(
                "Um equipamento com a mesma placa/serial já existe nesta empresa."
            );
        }

        // Se existe um owner_id, validar que o Partner existe na empresa alvo
        if ($equipment->owner_id) {
            $partnerExists = Partner::find($equipment->owner_id)
                ->companies()
                ->where('companies.id', $companyId)
                ->exists();

            if (!$partnerExists) {
                throw new \DomainException(
                    "O Partner dono do equipamento (ID: {$equipment->owner_id}) não está vinculado a esta empresa."
                );
            }
        }

        // Usar EquipmentService para criar novo Equipment
        $equipmentService = app(EquipmentService::class);
        $equipmentData = [
            'name' => $equipment->name,
            'owner_id' => $equipment->owner_id,
            'company_id' => $companyId,
            'type' => $equipment->type,
            'placa' => $equipment->placa,
            'mark' => $equipment->mark,
            'model' => $equipment->model,
            'serial_number' => $equipment->serial_number,
            'created_by' => $equipment->created_by,
        ];

        $createdEquipment = $equipmentService->create($equipmentData);

        if (!$createdEquipment) {
            throw new \DomainException(
                "Falha ao criar Equipment: " . $equipmentService->getMessageUser()
            );
        }
    }
}
