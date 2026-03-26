<?php

namespace App\Services\Equipment;

use App\Models\Company;
use App\Models\Equipment;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class EquipmentService
{
    use HandlesServiceResponse;

    /* ==============================
     |  Escrita
     |==============================*/

    /**
     * Cria um novo equipamento
     */
    public function create(array $data, bool $bypassTenantAssociation = false): ?Equipment
    {
        try {
            $equipment = $bypassTenantAssociation
                ? Equipment::withoutEvents(
                    fn() => Equipment::query()->withoutGlobalScopes()->create($data)
                )
                : Equipment::create($data);

            $this->setSuccess('Equipamento criado com sucesso');
            return $equipment;
        } catch (\Exception $e) {
            $this->setError('Erro ao criar equipamento', [$e->getMessage()]);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro ao criar equipamento',
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);
            return null;
        }
    }
    /**
     * Cria um novo equipamento para uma empresa
     */
    public function createForCompany(Company $company, array $data): ?Equipment
    {
        try {
            $equipment = $company->equipments()->create($data);
            $this->setSuccess('Equipamento criado com sucesso');
            return $equipment;
        } catch (\Exception $e) {
            $this->setError('Erro ao criar equipamento', [$e->getMessage()]);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro ao criar equipamento',
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);
            return null;
        }
    }

    /**
     * Atualiza um equipamento existente
     */
    public function update(Equipment $equipment, array $data): ?Equipment
    {
        try {
            $equipment->update($data);
            $this->setSuccess('Equipamento atualizado com sucesso');
            return $equipment;
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar equipamento', [$e->getMessage()]);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro ao atualizar equipamento',
                'equipment_id' => $equipment->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);
            return null;
        }
    }

    /**
     * Deleta um equipamento
     */
    public function delete(Equipment $equipment): bool
    {
        try {
            $equipment->delete();
            $this->setSuccess('Equipamento deletado com sucesso');
            return true;
        } catch (\Exception $e) {
            $this->setError('Erro ao deletar equipamento', [$e->getMessage()]);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro ao deletar equipamento',
                'equipment_id' => $equipment->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /* ==============================
     |  Consultas
     |==============================*/

    /**
     * Busca um equipamento pelo ID.
     */
    public function find(int $id): ?Equipment
    {
        return Equipment::with(['owner', 'company'])->find($id);
    }

    /**
     * Lista equipamentos de uma empresa, com filtros opcionais.
     */
    public function list(int $companyId, array $filters = []): Collection
    {
        Log::debug('Listando equipamentos', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'company_id' => $companyId,
            'filters'    => $filters,
        ]);

        $query = Equipment::where('company_id', $companyId);

        if (isset($filters['search'])) {
            $query->searchByIdentifier($filters['search']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['owner_id'])) {
            $query->where('owner_id', $filters['owner_id']);
        }

        if (isset($filters['partner_id'])) {
            // Aceitar também como partner_id para compatibilidade
            $query->where('owner_id', $filters['partner_id']);
        }

        return $query->with(['owner'])->get();
    }

    /**
     * Lista equipamentos de um partner em uma empresa específica
     * Note: Equipment usa owner_id para referenciar o Partner
     */
    public function listByCompanyAndPartner(int $companyId, int $partnerId): Collection
    {
        return Equipment::where('company_id', $companyId)
            ->where('owner_id', $partnerId)
            ->with(['owner'])
            ->get();
    }

    /**
     * Busca equipamentos por placa ou número de série para uso em selects do Filament.
     * Retorna um array [id => 'Nome — Identificador (Proprietário)'].
     *
     * @param string $search  Termo de busca
     * @param int    $companyId  Restringe à empresa atual
     * @param int    $limit   Máximo de resultados (padrão 20)
     */
    public function searchForSelect(string $search, int $companyId, ?int $owner_id = null, int $limit = 20): array
    {
        Log::debug('Buscando equipamentos para select', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'search'     => $search,
            'company_id' => $companyId,
            'owner_id'   => $owner_id,
        ]);

        $query = Equipment::where('company_id', $companyId)
            ->searchByIdentifier($search)
            ->with('owner')
            ->limit($limit);

        if ($owner_id) {
            $query->where('owner_id', $owner_id);
        }

        return $query->get()
            ->mapWithKeys(fn(Equipment $equipment) => [
                $equipment->id => self::formatSelectLabel($equipment),
            ])
            ->toArray();
    }

    /**
     * Retorna o label formatado de um equipamento para exibição em um select.
     */
    public function getLabelForSelect(int $id): ?string
    {
        $equipment = $this->find($id);

        if (! $equipment) {
            return null;
        }

        return self::formatSelectLabel($equipment);
    }

    /* ==============================
     |  Helpers
     |==============================*/

    /**
     * Formata o label do equipamento: "Nome — Identificador (Proprietário)"
     */
    private static function formatSelectLabel(Equipment $equipment): string
    {
        $label = "{$equipment->name} — {$equipment->identifier}";

        if ($equipment->owner) {
            $label .= " ({$equipment->owner->name})";
        }

        return $label;
    }
}
