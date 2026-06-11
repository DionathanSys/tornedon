<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Schemas;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Enum\ProductionOrder\Status;
use App\Enum\Quote\Status as QuoteStatus;
use App\Models\Quote;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductionOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 6,
                'lg' => 6,
            ])
            ->components([
                Section::make('Dados da Ordem de Produção')
                    ->columns([
                        'sm' => 1,
                        'md' => 6,
                        'lg' => 6,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('quote_id')
                            ->label('Orçamento de origem')
                            ->columnSpan(['md' => 3, 'lg' => 3])
                            ->options(function (?\App\Models\ProductionOrder $record): array {
                                return Quote::query()
                                    ->where('company_id', Filament::getTenant()->id)
                                    ->where('status', QuoteStatus::APPROVED->value)
                                    ->where(function ($query) use ($record): void {
                                        $query->whereDoesntHave('productionOrder');

                                        if ($record?->quote_id) {
                                            $query->orWhere('id', $record->quote_id);
                                        }
                                    })
                                    ->orderByDesc('id')
                                    ->get()
                                    ->mapWithKeys(fn (Quote $quote): array => [
                                        $quote->id => sprintf(
                                            '#%s - %s',
                                            $quote->quote_number ?? $quote->id,
                                            $quote->customer?->name ?? 'Sem cliente'
                                        ),
                                    ])
                                    ->all();
                            })
                            ->searchable()
                            ->preload()
                            ->live()
                            ->helperText('Se informado, os itens do orçamento podem ser importados para a OP.')
                            ->afterStateUpdated(function ($state, callable $set): void {
                                if (! $state) {
                                    return;
                                }

                                $quote = Quote::query()->with('customer')->find($state);

                                if (! $quote) {
                                    return;
                                }

                                if ($quote->customer_id) {
                                    $set('customer_id', $quote->customer_id);
                                }

                                if (filled($quote->observations)) {
                                    $set('observations', $quote->observations);
                                }
                            }),
                        Select::make('customer_id')
                            ->label('Cliente')
                            ->columnSpan(['md' => 3, 'lg' => 3])
                            ->options(function () {
                                return \App\Models\Partner::whereHas('companies', function ($query) {
                                    $query->where('companies.id', Filament::getTenant()->id)
                                        ->whereJsonContains('company_partner.type', 'customer')
                                        ->where('company_partner.is_active', true);
                                })
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->helperText('Obrigatório para Uso Direto. Em produção para estoque, pode ser preenchido depois.')
                            ->required(fn (Get $get): bool => $get('destination_type') === DestinationType::DIRECT_DELIVERY->value),
                        TextInput::make('production_order_number')
                            ->label('Número')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->visibleOn('edit')
                            ->columnStart(1)
                            ->disabled(),
                        Select::make('status')
                            ->label('Status')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->options(Status::toSelectArray())
                            ->native(false)
                            ->default(Status::QUEUED->value)
                            ->visibleOn('edit')
                            ->disabled(),
                        Select::make('destination_type')
                            ->label('Destino')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->options(DestinationType::toSelectArray())
                            ->native(false)
                            ->default(DestinationType::STOCK->value)
                            ->live()
                            ->required()
                            ->helperText('Estoque: entra em estoque ao concluir. Uso Direto: entra em estoque e prepara a saída para venda.'),
                        Textarea::make('observations')
                            ->label('Observações')
                            ->columnSpanFull()
                            ->rows(3)
                            ->maxLength(1000),
                        Hidden::make('priority')
                            ->default(Priority::NORMAL->value),
                        Hidden::make('company_id')
                            ->default(fn() => Filament::getTenant()->id),
                    ]),
            ]);
    }
}
