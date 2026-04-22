<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Models\Invoice;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\Invoice\InvoiceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Callout;
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
            ->modalDescription('Ao confirmar, o sistema irá gerar automaticamente os documentos fiscais necessários e as contas a receber. Opcionalmente, você pode disparar a emissão dos documentos logo após a confirmação.')
            ->visible(fn (Invoice $record): bool => ! $record->confirmed && ! $record->canceled)
            ->schema([
                Callout::make('Documentos que serão gerados')
                    ->description(fn (Invoice $record): string => self::resolveDocumentTypesDescription($record))
                    ->info(),

                Checkbox::make('emit_fiscal_documents')
                    ->label('Disparar emissão dos documentos fiscais gerados')
                    ->helperText('Quando marcado, a NF-e e/ou NFS-e criada será enviada imediatamente para processamento.')
                    ->default(false),

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
            ->action(function (Action $action, Invoice $record, array $data, EditInvoice $livewire): void {

                Log::info('ConfirmInvoiceAction UI: confirmando fatura - Invoice ID: ' . $record->id, [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'invoice_id' => $record->id,
                    'data'       => $data,
                ]);

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

                if (($data['emit_fiscal_documents'] ?? false) === true) {
                    $emissionResult = self::emitGeneratedFiscalDocuments($record, Auth::id());

                    if ($emissionResult['failed'] === 0) {
                        notify::success(
                            "Emissão disparada com sucesso para {$emissionResult['sent']} documento(s) fiscal(is)."
                        );
                    } else {
                        $details = collect($emissionResult['errors'])
                            ->take(2)
                            ->implode(' | ');

                        notify::warning(
                            "A emissão foi disparada com pendências: {$emissionResult['sent']} documento(s) enviado(s) e {$emissionResult['failed']} com erro(s). {$details}"
                        );
                    }

                    $livewire->refreshInvoiceState();
                }

                $livewire->dispatch('invoice-confirmed');
            });
    }

    /**
     * @return array{sent:int, failed:int, errors:array<int, string>}
     */
    private static function emitGeneratedFiscalDocuments(Invoice $record, int $userId): array
    {
        $record->refresh()->loadMissing('fiscalDocuments');

        $sent = 0;
        $errors = [];

        foreach ($record->fiscalDocuments as $document) {
            $service = $document->isNfse()
                ? app(NfseDocumentService::class)
                : app(NfeDocumentService::class);

            $emitted = $service->emitir($document, $userId);

            if ($emitted) {
                $sent++;
                continue;
            }

            $message = $service->getMessageUser() ?: $service->getMessage() ?: 'Erro ao emitir documento fiscal.';

            $errors[] = sprintf(
                '%s #%d: %s',
                strtoupper((string) $document->document_type->value),
                $document->id,
                $message
            );

            Log::warning('ConfirmInvoiceAction UI: falha ao disparar emissão do documento fiscal', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'invoice_id'         => $record->id,
                'fiscal_document_id' => $document->id,
                'document_type'      => $document->document_type->value,
                'message'            => $message,
                'user_id'            => $userId,
            ]);
        }

        return [
            'sent' => $sent,
            'failed' => count($errors),
            'errors' => $errors,
        ];
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
