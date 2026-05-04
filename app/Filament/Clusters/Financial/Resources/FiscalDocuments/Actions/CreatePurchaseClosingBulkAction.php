<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions;

use App\Filament\Clusters\Financial\Resources\PurchaseClosings\PurchaseClosingResource;
use App\Models\FiscalDocument;
use App\Notification\NotifyService as notify;
use App\Services\PurchaseClosing\PurchaseClosingService;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class CreatePurchaseClosingBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('createPurchaseClosing')
            ->label('Criar Fechamento')
            ->icon(Heroicon::DocumentDuplicate)
            ->color('warning')
            ->modalHeading('Criar fechamento de compras')
            ->modalDescription('Agrupa as notas selecionadas em um único fechamento. Apenas documentos válidos podem ser usados.')
            ->modalWidth('5xl')
            ->deselectRecordsAfterCompletion()
            ->fillForm(fn (Collection $records): array => self::buildFormData($records))
            ->schema([
                TextInput::make('supplier_name')
                    ->label('Fornecedor')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('period_label')
                    ->label('Período do Fechamento')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('selected_count')
                    ->label('Qtd. de Notas Selecionadas')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('reference')
                    ->label('Referência')
                    ->maxLength(100)
                    ->placeholder('Ex: FECH-05/2026'),
                Textarea::make('notes')
                    ->label('Observações')
                    ->rows(3)
                    ->columnSpanFull(),
                TextEntry::make('selection_hint')
                    ->label('Validação')
                    ->state('As notas devem estar confirmadas, pertencer ao mesmo fornecedor, à mesma empresa e não podem já estar vinculadas a outro fechamento.')
                    ->columnSpanFull(),
                Repeater::make('documents')
                    ->label('Notas selecionadas')
                    ->columnSpanFull()
                    ->deletable(false)
                    ->addable(false)
                    ->reorderable(false)
                    ->schema([
                        Hidden::make('fiscal_document_id'),
                        TextInput::make('document_number')
                            ->label('Nota Fiscal')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('issued_at_label')
                            ->label('Emissão')
                            ->disabled()
                            ->dehydrated(false),
                        Money::make('document_amount')
                            ->label('Valor da Nota')
                            ->disabled()
                            ->dehydrated(false),
                        Money::make('discount_amount')
                            ->label('Desconto')
                            ->default(0)
                            ->required()
                            ->live(),
                        TextEntry::make('net_amount_preview')
                            ->label('Líquido')
                            ->state(fn (Get $get): string => self::formatMoney(max(
                                round((float) ($get('document_amount') ?? 0), 2) - round((float) ($get('discount_amount') ?? 0), 2),
                                0
                            ))),
                    ])
                    ->columns(5),
            ])
            ->action(function (Collection $records, array $data): void {
                $validationError = self::validateSelection($records);

                if ($validationError !== null) {
                    notify::error(message: $validationError);
                    return;
                }

                /** @var FiscalDocument $first */
                $first = $records->first();
                $issuedDates = $records
                    ->pluck('issued_at')
                    ->filter()
                    ->map(fn ($date) => $date->toDateString())
                    ->sort()
                    ->values();

                $service = app(PurchaseClosingService::class);
                $closing = $service->create([
                    'company_id'    => $first->company_id,
                    'supplier_id'   => $first->customer_id,
                    'start_date'    => $issuedDates->first(),
                    'end_date'      => $issuedDates->last(),
                    'reference'     => $data['reference'] ?? null,
                    'notes'         => $data['notes'] ?? null,
                    'documents' => collect($data['documents'] ?? [])
                        ->map(fn (array $document): array => [
                            'fiscal_document_id' => (int) $document['fiscal_document_id'],
                            'discount_amount' => round((float) ($document['discount_amount'] ?? 0), 2),
                        ])
                        ->all(),
                ], (int) Auth::id());

                if ($service->hasError() || $closing === null) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return;
                }

                notify::success('Fechamento de compra criado com sucesso.');

                redirect(PurchaseClosingResource::getUrl('edit', [
                    'record' => $closing,
                    'tenant' => Filament::getTenant(),
                ]));
            });
    }

    private static function buildFormData(Collection $records): array
    {
        $records->loadMissing(['customer', 'items']);

        $first = $records->first();
        $issuedDates = $records
            ->pluck('issued_at')
            ->filter()
            ->sort()
            ->values();

        return [
            'supplier_name' => $first?->customer?->name ?? 'Seleção inválida',
            'period_label' => $issuedDates->isEmpty()
                ? '-'
                : $issuedDates->first()->format('d/m/Y') . ' a ' . $issuedDates->last()->format('d/m/Y'),
            'selected_count' => (string) $records->count(),
            'documents' => $records
                ->sortBy(fn (FiscalDocument $record) => sprintf('%s|%s', $record->issued_at?->format('Y-m-d') ?? '', $record->document_number ?? ''))
                ->map(fn (FiscalDocument $record): array => [
                    'fiscal_document_id' => $record->id,
                    'document_number' => $record->document_number ?: 'Sem número',
                    'issued_at_label' => $record->issued_at?->format('d/m/Y') ?: '-',
                    'document_amount' => round((float) $record->items->sum(fn ($item): float => (float) $item->total_price), 2),
                    'discount_amount' => 0,
                ])
                ->values()
                ->all(),
        ];
    }

    private static function validateSelection(Collection $records): ?string
    {
        if ($records->isEmpty()) {
            return 'Selecione ao menos uma nota fiscal para criar o fechamento.';
        }

        $records->loadMissing(['customer', 'items', 'purchaseClosingLinks']);

        $companyIds = $records->pluck('company_id')->unique();
        if ($companyIds->count() > 1) {
            return 'Todas as notas selecionadas devem pertencer à mesma empresa.';
        }

        $supplierIds = $records->pluck('customer_id')->unique();
        if ($supplierIds->count() > 1 || blank($supplierIds->first())) {
            return 'Todas as notas selecionadas devem pertencer ao mesmo fornecedor.';
        }

        foreach ($records as $record) {
            if (! $record->confirmed) {
                return 'Apenas notas confirmadas podem ser usadas no fechamento.';
            }

            if ($record->purchaseClosingLinks->isNotEmpty()) {
                return "A nota #{$record->document_number} já está vinculada a outro fechamento.";
            }
        }

        return null;
    }

    private static function formatMoney(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}
