<?php

namespace App\Policies;

use App\Models\ServiceOrder;
use App\Models\User;

class ServiceOrderPolicy
{
    /**
     * Determina se o usuário pode visualizar ordens de serviço.
     */
    public function viewAny(User $user): bool
    {
        return true;
        // Implemente sua lógica de permissão
        // Exemplo: verificar se tem a permissão ou role
        return $user->can('view_service_orders');
    }

    /**
     * Determina se o usuário pode visualizar uma ordem específica.
     */
    public function view(User $user, ServiceOrder $serviceOrder): bool
    {
        return true;
        // Verifica se pode ver ordens de serviço E se a ordem pertence à empresa do usuário
        return $user->can('view_service_orders') 
            && $user->belongsToCompany($serviceOrder->company_id);
    }

    /**
     * Determina se o usuário pode criar ordens de serviço.
     */
    public function create(User $user): bool
    {
        return true;
        return $user->can('create_service_orders');
    }

    /**
     * Determina se o usuário pode atualizar a ordem de serviço.
     */
    public function update(User $user, ServiceOrder $serviceOrder): bool
    {
        // Acesso à página é sempre permitido; campos e itens são bloqueados
        // individualmente pelo form (->disabled) e pela AuthorizesServiceOrderItemActions.
        return true;
        // Para ativar verificação de permissão e empresa:
        // if (in_array($serviceOrder->status->value, ['faturada', 'cancelada'])) {
        //     return false;
        // }
        // return $user->can('update_service_orders')
        //     && $user->belongsToCompany($serviceOrder->company_id);
    }

    /**
     * Determina se o usuário pode excluir a ordem de serviço.
     */
    public function delete(User $user, ServiceOrder $serviceOrder): bool
    {
        // Apenas ordens abertas podem ser excluídas
        if ($serviceOrder->status->value !== 'aberta') {
            return false;
        }

        return true;
        // Para ativar verificação de permissão e empresa:
        // return $user->can('delete_service_orders')
        //     && $user->belongsToCompany($serviceOrder->company_id);
    }

    /**
     * Determina se o usuário pode restaurar a ordem de serviço.
     */
    public function restore(User $user, ServiceOrder $serviceOrder): bool
    {
        return true;
        return $user->can('restore_service_orders');
    }

    /**
     * Determina se o usuário pode excluir permanentemente a ordem.
     */
    public function forceDelete(User $user, ServiceOrder $serviceOrder): bool
    {
        return true;
        return $user->can('force_delete_service_orders');
    }

    /**
     * Determina se o usuário pode modificar preços/descontos.
     */
    public function modifyPricing(User $user, ServiceOrder $serviceOrder): bool
    {
        return true;
        // Apenas usuários com permissão especial podem alterar preços
        // E apenas em ordens abertas
        if ($serviceOrder->status->value !== 'aberta') {
            return false;
        }

        return $user->can('modify_service_order_pricing') 
            && $user->belongsToCompany($serviceOrder->company_id);
    }

    /**
     * Determina se o usuário pode aprovar a ordem.
     */
    public function approve(User $user, ServiceOrder $serviceOrder): bool
    {
        return true;
        return $user->can('approve_service_orders')
            && $user->belongsToCompany($serviceOrder->company_id)
            && $serviceOrder->requires_approval
            && !$serviceOrder->approved_by_customer;
    }

    /**
     * Determina se o usuário pode fechar a ordem.
     */
    public function close(User $user, ServiceOrder $serviceOrder): bool
    {
        return true;
        return $user->can('close_service_orders')
            && $user->belongsToCompany($serviceOrder->company_id)
            && $serviceOrder->status->value === 'aberta';
    }

    /**
     * Determina se o usuário pode cancelar a ordem.
     */
    public function cancel(User $user, ServiceOrder $serviceOrder): bool
    {
        return true;
        return $user->can('cancel_service_orders')
            && $user->belongsToCompany($serviceOrder->company_id)
            && in_array($serviceOrder->status->value, ['aberta', 'encerrada']);
    }

    /**
     * Determina se o usuário pode faturar a ordem.
     */
    public function invoice(User $user, ServiceOrder $serviceOrder): bool
    {
        return true;
        return $user->can('invoice_service_orders')
            && $user->belongsToCompany($serviceOrder->company_id)
            && $serviceOrder->status->value === 'encerrada';
    }
}
