<?php

namespace App\Traits;

use App\Enum\Requisition\Status;
use App\Models\Requisition;
use App\Services\Requisition\RequisitionService;
use Illuminate\Support\Facades\Auth;

/**
 * Centraliza as verificações de estado + permissão para operações em itens de Requisição.
 *
 * Regra de negócio:
 *  - Somente requisições com status "aberta" (Status::OPEN) podem ter itens criados/editados/excluídos.
 *  - O usuário precisa ter permissão de `update` (para criar/editar) ou `delete` (para excluir)
 *    conforme definido em RequisitionPolicy.
 *
 * Uso:
 *  - Na camada Filament: use nas Action classes para controlar visibilidade dos botões.
 *  - Na camada Service: use para bloquear a execução mesmo sem interface gráfica.
 */
trait AuthorizesRequisitionItemActions
{
    /**
     * Verifica se os itens da Requisição podem ser criados ou editados.
     * Combina verificação de estado (aberta) + permissão de update.
     */
    protected static function canModifyItems(int|Requisition $requisition): bool
    {
        if (is_int($requisition)) {
            $requisition = (new RequisitionService())->find($requisition);
        }

        return $requisition->status === Status::OPEN;
            // && Auth::user()?->can('update', $requisition);
    }

    /**
     * Verifica se um item da Requisição pode ser excluído.
     * Combina verificação de estado (aberta) + permissão de delete.
     */
    protected static function canDeleteItem(int|Requisition $requisition): bool
    {
        if (is_int($requisition)) {
            $requisition = (new RequisitionService())->find($requisition);
        }

        return $requisition->status === Status::OPEN;
            // && Auth::user()?->can('delete', $requisition);
    }
}
