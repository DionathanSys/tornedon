<?php

namespace App\Filament\Clusters\Financial\Resources\PurchaseClosings\Schemas;

use App\Enum\FiscalDocument\OperationType;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\FiscalDocument;
use App\Models\PurchaseClosing;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Leandrocfe\FilamentPtbrFormFields\Money;

class PurchaseClosingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 4,
                'lg' => 12,
            ])
            ->components([
                Section::make('Dados do Fechamento')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        SelectPartner::make('supplier_id', 'all')
                            ->label('Fornecedor')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('documents', []))
                            ->columnSpan(['md' => 2, 'lg' => 5]),
                        TextInput::make('reference')
                            ->label('Referência')
                            ->maxLength(100)
                            ->placeholder('Ex: FECH-05/2026')
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextInput::make('status')
                            ->label('Status')
                            ->formatStateUsing(fn ($state): string => $state?->description() ?? ($state ?: 'Rascunho'))
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit')
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                        DatePicker::make('start_date')
                            ->label('Período Inicial')
                            ->required()
                            ->displayFormat('d/m/Y')
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('documents', []))
                            ->columnStart(1)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        DatePicker::make('end_date')
                            ->label('Período Final')
                            ->required()
                            ->displayFormat('d/m/Y')
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('documents', []))
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Notas do Fechamento')
                    ->description('Selecione as notas confirmadas do fornecedor dentro do período e informe o desconto aplicado em cada uma.')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('documents')
                            ->label('Notas fiscais')
                            ->columnSpanFull()
                            ->default([])
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => static::selectedDocumentLabel($state['fiscal_document_id'] ?? null))
                            ->addActionLabel('Adicionar nota fiscal')
                            ->schema([
                                Select::make('fiscal_document_id')
                                    ->label('Nota Fiscal')
                                    ->required()
                                    ->preload()
                                    ->searchable()
                                    ->native(false)
                                    ->live()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->options(fn (Get $get, ?PurchaseClosing $record): array => static::eligibleDocumentOptions($get, $record))
                                    ->helperText(fn (Get $get, ?PurchaseClosing $record): string => static::documentsHelperText($get, $record))
                                    ->columnSpan(['md' => 2, 'lg' => 6]),
                                Placeholder::make('issued_at_preview')
                                    ->label('Emissão')
                                    ->content(fn (Get $get): string => static::documentIssuedAt($get('fiscal_document_id')))
                                    ->columnSpan(['md' => 1, 'lg' => 2]),
                                Placeholder::make('document_amount_preview')
                                    ->label('Valor da Nota')
                                    ->columnStart(1)
                                    ->content(fn (Get $get): string => static::documentAmountFormatted($get('fiscal_document_id')))
                                    ->columnSpan(['md' => 1, 'lg' => 2]),
                                Money::make('discount_amount')
                                    ->label('Desconto')
                                    ->default(0)
                                    ->required()
                                    ->live()
                                    ->columnSpan(['md' => 1, 'lg' => 2]),
                                Placeholder::make('net_amount_preview')
                                    ->label('Líquido')
                                    ->content(fn (Get $get): string => static::documentNetAmountFormatted($get('fiscal_document_id'), $get('discount_amount')))
                                    ->columnSpan(['md' => 1, 'lg' => 2]),
                            ])
                            ->columns([
                                'sm' => 1,
                                'md' => 4,
                                'lg' => 12,
                            ]),
                    ]),
                Section::make('Totais')
                    ->columns([
                        'sm' => 1,
                        'md' => 3,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('gross_amount_preview')
                            ->label('Valor Bruto')
                            ->content(fn (Get $get): string => static::formatMoney(static::totals($get)['gross'])),
                        Placeholder::make('discount_amount_preview_total')
                            ->label('Descontos')
                            ->content(fn (Get $get): string => static::formatMoney(static::totals($get)['discount'])),
                        Placeholder::make('net_amount_preview_total')
                            ->label('Valor Líquido')
                            ->content(fn (Get $get): string => static::formatMoney(static::totals($get)['net'])),
                    ]),
                Hidden::make('company_id'),
            ]);
    }

    private static function eligibleDocumentOptions(Get $get, ?PurchaseClosing $record): array
    {
        $supplierId = $get('../../supplier_id') ?: $get('supplier_id');
        $startDate = $get('../../start_date') ?: $get('start_date');
        $endDate = $get('../../end_date') ?: $get('end_date');

        if (blank($supplierId) || blank($startDate) || blank($endDate)) {
            return [];
        }

        return static::eligibleDocumentsQuery((int) $supplierId, (string) $startDate, (string) $endDate, $record)
            ->with('items')
            ->get(['id', 'document_number', 'issued_at'])
            ->mapWithKeys(function (FiscalDocument $document): array {
                $amount = $document->items->sum(fn ($item): float => (float) $item->total_price);

                return [
                    $document->id => sprintf(
                        '%s | %s | %s',
                        $document->document_number ?: 'Sem número',
                        $document->issued_at?->format('d/m/Y') ?: 'Sem emissão',
                        static::formatMoney($amount),
                    ),
                ];
            })
            ->all();
    }

    private static function documentsHelperText(Get $get, ?PurchaseClosing $record): string
    {
        $supplierId = $get('../../supplier_id') ?: $get('supplier_id');
        $startDate = $get('../../start_date') ?: $get('start_date');
        $endDate = $get('../../end_date') ?: $get('end_date');

        if (blank($supplierId) || blank($startDate) || blank($endDate)) {
            return 'Selecione fornecedor e período para listar as notas elegíveis.';
        }

        $count = static::eligibleDocumentsQuery((int) $supplierId, (string) $startDate, (string) $endDate, $record)->count();

        if ($count === 0) {
            return 'Nenhuma nota elegível encontrada para este fechamento.';
        }

        return $count === 1
            ? '1 nota elegível encontrada.'
            : "{$count} notas elegíveis encontradas.";
    }

    private static function eligibleDocumentsQuery(int $supplierId, string $startDate, string $endDate, ?PurchaseClosing $record): Builder
    {
        return FiscalDocument::query()
            ->where('company_id', Filament::getTenant()->id)
            ->where('customer_id', $supplierId)
            ->where('confirmed', true)
            ->where('operation_type', OperationType::ENTRADA->value)
            ->whereBetween('issued_at', [$startDate, $endDate])
            ->whereDoesntHave('purchaseClosingLinks', function (Builder $query) use ($record): void {
                if ($record) {
                    $query->where('purchase_closing_id', '!=', $record->id);
                    return;
                }

                $query->whereNotNull('purchase_closing_id');
            })
            ->orderBy('issued_at')
            ->orderBy('document_number');
    }

    private static function selectedDocumentLabel(mixed $documentId): ?string
    {
        if (blank($documentId)) {
            return 'Nova nota fiscal';
        }

        $document = static::findDocument((int) $documentId);

        return $document?->document_number ?: 'Nota selecionada';
    }

    private static function documentIssuedAt(mixed $documentId): string
    {
        if (blank($documentId)) {
            return '-';
        }

        return static::findDocument((int) $documentId)?->issued_at?->format('d/m/Y') ?: '-';
    }

    private static function documentAmountFormatted(mixed $documentId): string
    {
        if (blank($documentId)) {
            return 'R$ 0,00';
        }

        return static::formatMoney(static::documentAmount((int) $documentId));
    }

    private static function documentNetAmountFormatted(mixed $documentId, mixed $discountAmount): string
    {
        if (blank($documentId)) {
            return 'R$ 0,00';
        }

        $gross = static::documentAmount((int) $documentId);
        $discount = round((float) ($discountAmount ?? 0), 2);

        return static::formatMoney(max($gross - $discount, 0));
    }

    /**
     * @return array{gross: float, discount: float, net: float}
     */
    private static function totals(Get $get): array
    {
        $gross = 0;
        $discount = 0;

        foreach (($get('documents') ?? []) as $document) {
            $documentId = (int) ($document['fiscal_document_id'] ?? 0);

            if ($documentId <= 0) {
                continue;
            }

            $gross += static::documentAmount($documentId);
            $discount += round((float) ($document['discount_amount'] ?? 0), 2);
        }

        return [
            'gross' => round($gross, 2),
            'discount' => round($discount, 2),
            'net' => round(max($gross - $discount, 0), 2),
        ];
    }

    private static function documentAmount(int $documentId): float
    {
        $document = static::findDocument($documentId);

        if (! $document) {
            return 0;
        }

        return round((float) $document->items->sum(fn ($item): float => (float) $item->total_price), 2);
    }

    private static function findDocument(int $documentId): ?FiscalDocument
    {
        return FiscalDocument::query()
            ->with('items')
            ->where('company_id', Filament::getTenant()->id)
            ->find($documentId);
    }

    private static function formatMoney(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}
