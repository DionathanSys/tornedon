<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Models\Invoice;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\InvoiceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class ConfirmInvoiceAction
{
    public static function make(): Action
    {
        return Action::make('confirmInvoice')
            ->label('Confirmar')
            ->icon(Heroicon::Check)
            ->color('success')
            ->modalHeading('Confirmar Fatura')
            ->modalDescription('Ao confirmar, o sistema irá gerar automaticamente os documentos fiscais necessários e as contas a receber.')
            ->visible(fn (Invoice $record): bool => ! $record->confirmed && ! $record->canceled)
            ->schema([
                Placeholder::make('document_types')
                    ->label('Documentos que serão gerados')
                    ->content(fn (Invoice $record): string => self::resolveDocumentTypesDescription($record)),

                Select::make('payment_method')
                    ->label('Forma de Pagamento')
                    ->options(Method::toSelectArray())
                    ->default(fn (Invoice $record): ?string => $record->payment_method?->value)
                    ->native(false)
                    ->required(),

                Select::make('payment_condition')
                    ->label('Condição de Pagamento')
                    ->options(Condition::toGroupedSelectArray())
                    ->default(fn (Invoice $record): ?string => $record->payment_condition?->value)
                    ->native(false)
                    ->required(),
            ])
            ->action(function (Action $action, Invoice $record, array $data): void {
                $service = app(InvoiceService::class);
                $result = $service->confirm($record, $data, Auth::id());

                if ($service->hasError() || $result === null) {
                    Log::error('ConfirmInvoiceAction UI: erro ao confirmar fatura', [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'invoice_id' => $record->id,
                        'message'    => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                        'errors'     => $service->getErrors(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    $action->halt();
                    return;
                }

                $types = collect($result['documents_types'] ?? [])
                    ->map(static fn (string $type): string => strtoupper($type))
                    ->implode(', ');

                notify::success(
                    "Fatura confirmada com sucesso. {$result['documents_count']} documento(s) fiscal(is) ({$types}) e {$result['account_receivables_count']} conta(s) a receber geradas."
                );
            });
    }

    private static function resolveDocumentTypesDescription(Invoice $record): string
    {
        $record->loadMissing(['requisitions.items', 'serviceOrders.items']);

        $types = [];

        $hasProducts = $record->requisitions
            ->contains(fn ($requisition): bool => $requisition->items->isNotEmpty());

        $hasServices = $record->serviceOrders
            ->contains(fn ($serviceOrder): bool => $serviceOrder->items->isNotEmpty());

        if ($hasProducts) {
            $types[] = 'NF-e para itens de produto';
        }

        if ($hasServices) {
            $types[] = 'NFS-e para itens de serviço';
        }

        if ($types === []) {
            return 'Nenhum item elegível encontrado nesta fatura.';
        }

        return implode(' e ', $types) . '.';
    }
}
