<?php

namespace App\Filament\Operation;

use App\Filament\Operation\Pages\CommercialDashboard;
use App\Filament\Operation\Pages\OperationDashboard;
use App\Filament\Operation\Pages\Requisitions\RequisitionList;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderQueue;
use App\Filament\Operation\Resources\AccountPayables\AccountPayableResource;
use App\Filament\Operation\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Operation\Resources\CashMovements\CashMovementResource;
use App\Filament\Operation\Resources\ProductionRequests\ProductionRequestResource;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Filament\Facades\Filament;

class OperationModules
{
    public static function definitions(): array
    {
        return [
            'home' => ['label' => 'Início', 'icon' => 'o-home', 'page' => OperationDashboard::class, 'permission' => null],
            'service_orders' => ['label' => 'Ordens', 'icon' => 'o-clipboard-document-list', 'page' => ServiceOrderQueue::class, 'permission' => 'service_orders.access'],
            'requisitions' => ['label' => 'Requisições', 'icon' => 'o-clipboard-document', 'page' => RequisitionList::class, 'permission' => 'requisitions.access'],
            'production_requests' => ['label' => 'Pedidos', 'icon' => 'o-shopping-cart', 'page' => ProductionRequestResource::class, 'permission' => 'production_requests.access'],
            'account_receivables' => ['label' => 'Receber', 'icon' => 'o-arrow-trending-up', 'page' => AccountReceivableResource::class, 'permission' => 'account_receivables.access'],
            'account_payables' => ['label' => 'Pagar', 'icon' => 'o-arrow-trending-down', 'page' => AccountPayableResource::class, 'permission' => 'account_payables.access'],
            'cash_movements' => ['label' => 'Caixa', 'icon' => 'o-arrows-right-left', 'page' => CashMovementResource::class, 'permission' => 'cash_movements.access'],
            'commercial_dashboard' => ['label' => 'Produção e financeiro', 'icon' => 'o-chart-bar', 'page' => CommercialDashboard::class, 'permission' => 'commercial_dashboard.access'],
            'team' => ['label' => 'Equipe e acessos', 'icon' => 'o-users', 'page' => \App\Filament\Operation\Pages\CompanyTeam::class, 'permission' => null],
        ];
    }

    public static function labels(): array
    {
        return array_map(fn (array $module): string => $module['label'], self::definitions());
    }

    public static function permissions(): array
    {
        return [
            ...self::defaultPermissions(),
            'account_payables.change_status_without_movement' => 'Pagar: ignorar/restaurar contas e parcelas',
            'account_receivables.change_status_without_movement' => 'Receber: ignorar/restaurar contas e parcelas',
        ];
    }

    public static function defaultPermissions(): array
    {
        $permissions = [];
        foreach (self::definitions() as $module) {
            if ($module['permission']) {
                $permissions[$module['permission']] = $module['label'];
            }
        }

        return $permissions;
    }

    public static function allows(string $module): bool
    {
        $definition = self::definitions()[$module] ?? null;
        /** @var User|null $user */
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();
        if (! $definition || ! $user || ! $tenant) {
            return false;
        }
        if ($module === 'team') {
            return \App\Filament\Operation\Pages\CompanyTeam::canAccess();
        }

        $access = app(UserAccessService::class);

        return $definition['permission']
            ? $access->allows($user, $tenant, $definition['permission'], 'operation')
            : $access->canAccessPanel($user, $tenant, 'operation');
    }

    public static function forPage(string $class): ?string
    {
        foreach ([
            '\\ServiceOrders\\' => 'service_orders',
            '\\Requisitions\\' => 'requisitions',
            '\\ProductionRequests\\' => 'production_requests',
            '\\AccountReceivables\\' => 'account_receivables',
            '\\AccountPayables\\' => 'account_payables',
            '\\CashMovements\\' => 'cash_movements',
        ] as $namespace => $module) {
            if (str_contains($class, $namespace)) {
                return $module;
            }
        }

        return $class === CommercialDashboard::class ? 'commercial_dashboard' : null;
    }

    public static function navigation(): array
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();
        if (! $user || ! $tenant) {
            return [];
        }
        $definitions = self::definitions();
        $settings = app(UserAccessService::class)->settings($user, $tenant);
        if (! app(UserAccessService::class)->canAccessPanel($user, $tenant, 'operation')) {
            return [];
        }
        $order = $settings?->navigation['operation'] ?? [];
        $keys = array_unique([...$order, ...array_keys($definitions)]);
        $items = [];
        foreach ($keys as $key) {
            if (! isset($definitions[$key])) {
                continue;
            }
            $module = $definitions[$key];
            if ($key === 'team' && ! \App\Filament\Operation\Pages\CompanyTeam::canAccess()) {
                continue;
            }
            if ($module['permission'] && ! app(UserAccessService::class)->allows($user, $tenant, $module['permission'], 'operation')) {
                continue;
            }
            $items[] = [...$module, 'key' => $key, 'url' => $module['page']::getUrl(panel: 'operation', tenant: $tenant)];
        }

        return $items;
    }
}
