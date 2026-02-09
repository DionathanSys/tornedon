<?php

namespace App\Services\Requisition;

use App\Enum\Requisition\Status;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\RequisitionSequence;
use Illuminate\Support\Facades\DB;

class RequisitionService
{
    /**
     * Cria uma requisição com seus itens.
     *
     * @param  array  $data       Dados da requisição (sem itens)
     * @param  array  $items      Array de itens [{product_id, quantity, unit_price, ...}, ...]
     * @param  int    $userId     ID do usuário que está criando
     * @param  int    $companyId  ID da empresa
     */
    public function create(array $data, array $items, int $userId, int $companyId): Requisition
    {
        return DB::transaction(function () use ($data, $items, $userId, $companyId) {
            $data['number'] = $this->generateNumber($companyId);
            $data['company_id'] = $companyId;
            $data['status'] = $data['status'] ?? Status::OPEN->value;
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;

            $requisition = Requisition::create($data);

            foreach ($items as $item) {
                $item['created_by'] = $userId;
                $item['updated_by'] = $userId;
                $requisition->items()->create($item);
            }

            return $requisition->load('items');
        });
    }

    /**
     * Atualiza uma requisição e seus itens.
     *
     * @param  Requisition  $requisition
     * @param  array        $data   Dados da requisição
     * @param  array|null   $items  Itens atualizados (se null, não altera itens)
     * @param  int          $userId
     */
    public function update(Requisition $requisition, array $data, ?array $items, int $userId): Requisition
    {
        return DB::transaction(function () use ($requisition, $data, $items, $userId) {
            $data['updated_by'] = $userId;

            // Não permite alterar number e company_id
            unset($data['number'], $data['company_id']);

            $requisition->update($data);

            if ($items !== null) {
                $this->syncItems($requisition, $items, $userId);
            }

            return $requisition->load('items');
        });
    }

    /**
     * Exclui uma requisição (soft delete).
     */
    public function delete(Requisition $requisition): bool
    {
        return DB::transaction(function () use ($requisition) {
            $requisition->items()->delete();
            return $requisition->delete();
        });
    }

    /**
     * Sincroniza os itens da requisição.
     * Remove itens que não estão no array, atualiza existentes e cria novos.
     */
    private function syncItems(Requisition $requisition, array $items, int $userId): void
    {
        $existingIds = $requisition->items()->pluck('id')->toArray();
        $incomingIds = collect($items)->pluck('id')->filter()->toArray();

        // Remove itens que foram excluídos
        $toDelete = array_diff($existingIds, $incomingIds);
        if (!empty($toDelete)) {
            RequisitionItem::whereIn('id', $toDelete)->delete();
        }

        foreach ($items as $item) {
            $item['updated_by'] = $userId;

            if (!empty($item['id']) && in_array($item['id'], $existingIds)) {
                // Atualiza item existente
                RequisitionItem::where('id', $item['id'])->update($item);
            } else {
                // Cria novo item
                $item['created_by'] = $userId;
                unset($item['id']);
                $requisition->items()->create($item);
            }
        }
    }

    /**
     * Gera o próximo número de requisição para a empresa.
     * Usa lock pessimista para evitar duplicidade.
     */
    private function generateNumber(int $companyId): string
    {
        $sequence = RequisitionSequence::lockForUpdate()
            ->firstOrCreate(
                ['company_id' => $companyId],
                ['last_number' => 0]
            );

        $sequence->increment('last_number');

        return str_pad($sequence->last_number, 5, '0', STR_PAD_LEFT);
    }
}
