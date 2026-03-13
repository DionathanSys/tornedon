<?php

namespace App\Services\Equipment;

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
    public function create(array $data): ?Equipment
    {
        try {
            $equipment = Equipment::create($data);
            $this->setSuccess('Equipamento criado com sucesso');
            return $equipment;
        } catch (\Exception $e) {
            $this->setError('Erro ao criar equipamento', [$e->getMessage()]);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro ao criar equipamento',
                'exception' => $e->getMessage(),
                'data' => $data,
            ]);
            return null;
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

        return $query->with(['owner'])->get();
    }

    /**
     * Busca equipamentos por placa ou número de série para uso em selects do Filament.
     * Retorna um array [id => 'Nome — Identificador (Proprietário)'].
     *
     * @param string $search  Termo de busca
     * @param int    $companyId  Restringe à empresa atual
     * @param int    $limit   Máximo de resultados (padrão 20)
     */
    public function searchForSelect(string $search, int $companyId, int $limit = 20): array
    {
        Log::debug('Buscando equipamentos para select', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'search'     => $search,
            'company_id' => $companyId,
        ]);

        return Equipment::where('company_id', $companyId)
            ->searchByIdentifier($search)
            ->with('owner')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Equipment $equipment) => [
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
