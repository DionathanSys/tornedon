<?php

namespace App\Filament\Operation;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\CashMovement;
use App\Models\ProductionRequest;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\CashMovementService;
use App\Services\ProductionRequest\ProductionRequestService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

class OperationResourceSupport
{
    public static function definition(string $module): array
    {
        return match ($module) {
            'production_requests' => ['model' => ProductionRequest::class, 'service' => ProductionRequestService::class, 'date' => 'order_date', 'title' => 'Pedidos de produção', 'create' => 'Novo pedido', 'relation' => 'customer', 'pending' => ['open'], 'settled' => 'delivered', 'tabs' => ['Abertos', 'Entregues']],
            'account_receivables' => ['model' => AccountReceivable::class, 'service' => AccountReceivableService::class, 'date' => 'due_date', 'title' => 'Contas a receber', 'create' => 'Nova conta a receber', 'relation' => 'customer', 'pending' => ['pending', 'overdue', 'partially_received'], 'settled' => 'received', 'tabs' => ['Pendentes', 'Recebidas']],
            'account_payables' => ['model' => AccountPayable::class, 'service' => AccountPayableService::class, 'date' => 'due_date', 'title' => 'Contas a pagar', 'create' => 'Nova conta a pagar', 'relation' => 'supplier', 'pending' => ['pending', 'overdue', 'partially_paid'], 'settled' => 'paid', 'tabs' => ['Pendentes', 'Pagas']],
            'cash_movements' => ['model' => CashMovement::class, 'service' => CashMovementService::class, 'date' => 'transaction_date', 'title' => 'Caixa', 'create' => 'Novo movimento', 'relation' => 'financialAccount', 'pending' => ['inflow'], 'settled' => 'outflow', 'tabs' => ['Entradas', 'Saídas']],
            default => abort(403),
        };
    }

    public static function query(string $module): Builder
    {
        return self::definition($module)['model']::query()->where('company_id', Filament::getTenant()->getKey());
    }

    public static function resource(string $module): string
    {
        return OperationModules::definitions()[$module]['page'];
    }

    public static function money(mixed $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    public static function decimal(mixed $value): float
    {
        $value = trim((string) $value);

        return (float) (str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value);
    }

    public static function normalizeNumber(mixed $value): mixed
    {
        return preg_match('/^-?(?:\d+(?:\.\d+)?|\d+(?:\.\d{3})*(?:,\d+)?)$/', trim((string) $value))
            ? self::decimal($value) : $value;
    }
}
