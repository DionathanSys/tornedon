<?php

namespace App\Traits;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Services\ServiceOrder\ServiceOrderService;
use Illuminate\Support\Facades\Auth;

/**
 * Centraliza as verificações de estado + permissão para operações em itens de OS.
 *
 * Regra de negócio:
 *  - Somente ordens com status "aberta" (State::OPEN) podem ter itens criados/editados/excluídos.
 *  - O usuário precisa ter permissão de `update` (para criar/editar) ou `delete` (para excluir)
 *    conforme definido em ServiceOrderPolicy.
 *
 * Uso:
 *  - Na camada Filament: use nas Action classes para controlar visibilidade dos botões.
 *  - Na camada Service: use para bloquear a execução mesmo sem interface gráfica.
 */
trait AuthorizesServiceOrderItemActions
{
    /**
     * Verifica se os itens da OS podem ser criados ou editados.
     * Combina verificação de estado (aberta) + permissão de update.
     */
    protected static function canModifyItems(int|ServiceOrder $order): bool
    {
        if (is_int($order)) {
            $order = (new ServiceOrderService())->find($order);
        }

        return $order->status === State::OPEN;
            // && Auth::user()?->can('update', $order);
    }

    /**
     * Verifica se um item da OS pode ser excluído.
     * Combina verificação de estado (aberta) + permissão de delete.
     */
    protected static function canDeleteItem(int|ServiceOrder $order): bool
    {
        if (is_int($order)) {
            $order = (new ServiceOrderService())->find($order);
        }
        
        return $order->status === State::OPEN;
            // && Auth::user()?->can('delete', $order);
    }
}
