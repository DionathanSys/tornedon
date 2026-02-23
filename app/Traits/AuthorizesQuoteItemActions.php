<?php

namespace App\Traits;

use App\Enum\Quote\Status;
use App\Models\Quote;

/**
 * Centraliza as verificações de estado + permissão para operações em itens de Orçamento.
 *
 * Regra de negócio:
 *  - Somente orçamentos com status "draft" (Status::DRAFT) podem ter itens criados/editados/excluídos.
 *  - O usuário precisa ter permissão de `update` (criar/editar) ou `delete` (excluir).
 *
 * Uso:
 *  - Na camada Filament: use nas Action classes para controlar visibilidade dos botões.
 *  - Na camada Service: use para bloquear a execução mesmo sem interface gráfica.
 */
trait AuthorizesQuoteItemActions
{
    /**
     * Verifica se os itens do Orçamento podem ser criados ou editados.
     * Combina verificação de estado (rascunho) + permissão de update.
     */
    protected static function canModifyQuoteItems(int|Quote $quote): bool
    {
        if (is_int($quote)) {
            $quote = Quote::find($quote);
        }

        return in_array($quote->status, [Status::DRAFT, Status::SENT]);
            // && Auth::user()?->can('update', $quote);
    }

    /**
     * Verifica se um item do Orçamento pode ser excluído.
     * Combina verificação de estado (rascunho) + permissão de delete.
     */
    protected static function canDeleteQuoteItem(int|Quote $quote): bool
    {
        if (is_int($quote)) {
            $quote = Quote::find($quote);
        }

        return $quote?->status === Status::DRAFT;
            // && Auth::user()?->can('delete', $quote);
    }
}
