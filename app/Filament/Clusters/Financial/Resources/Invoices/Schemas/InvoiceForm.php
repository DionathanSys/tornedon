<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Schemas;

use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\FiscalDocumentsRelationManager;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\RequisitionsRelationManager;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\ServiceOrdersRelationManager;
use App\Models\Invoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['sm' => 1, 'md' => 4, 'lg' => 12,])
            ->components([
                Tabs::make()
                    ->columns(['sm' => 1, 'md' => 4, 'lg' => 12,])
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Geral')
                            ->schema([
                                Section::make('Dados da Fatura')
                                    ->columns(['sm' => 1, 'md' => 6, 'lg' => 12,])
                                    ->columnSpanFull()
                                    ->schema([
                                        TextInput::make('invoice_number')
                                            ->label('Número da Fatura')
                                            ->disabled()
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->required()
                                            ->maxLength(50),
                                        DatePicker::make('invoice_date')
                                            ->label('Data da Fatura')
                                            ->disabled()
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->default(now())
                                            ->required()
                                            ->displayFormat('d/m/Y'),
                                        Select::make('payment_method')
                                            ->label('Forma de Pagamento')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->options(PaymentMethod::toSelectArray())
                                            ->native(false)
                                            ->searchable(),
                                        Select::make('payment_condition')
                                            ->label('Condição de Pagamento')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->options(PaymentCondition::toGroupedSelectArray())
                                            ->native(false)
                                            ->searchable(),
                                        Select::make('customer_id')
                                            ->label('Cliente')
                                            ->disabled()
                                            ->columnSpan(['md' => 3, 'lg' => 6])
                                            ->relationship('customer', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->required(),
                                        TextInput::make('services_amount')
                                            ->label('Valor de Serviços')
                                            ->disabled()
                                            ->readOnly()
                                            ->dehydrated(false)
                                            ->formatStateUsing(fn(?Invoice $record): string => number_format((float) ($record?->services_amount ?? 0), 2, ',', '.'))
                                            ->prefix('R$')
                                            ->columnSpan(['md' => 2, 'lg' => 2]),
                                        TextInput::make('products_amount')
                                            ->label('Valor de Produtos')
                                            ->disabled()
                                            ->readOnly()
                                            ->dehydrated(false)
                                            ->formatStateUsing(fn(?Invoice $record): string => number_format((float) ($record?->products_amount ?? 0), 2, ',', '.'))
                                            ->prefix('R$')
                                            ->columnSpan(['md' => 2, 'lg' => 2]),
                                        TextInput::make('total_amount')
                                            ->label('Valor Total')
                                            ->disabled()
                                            ->readOnly()
                                            ->dehydrated(false)
                                            ->formatStateUsing(fn(?Invoice $record): string => number_format((float) ($record?->total_amount ?? 0), 2, ',', '.'))
                                            ->prefix('R$')
                                            ->columnSpan(['md' => 2, 'lg' => 2]),
                                    ]),
                                Section::make('Dados Fiscais')
                                    ->hiddenLabel()
                                    ->columnSpanFull()
                                    ->columns(1)
                                    ->contained(false)
                                    ->schema([
                                        Livewire::make(FiscalDocumentsRelationManager::class, fn(Invoice $record) => [
                                            'ownerRecord' => $record,
                                            'pageClass' => EditInvoice::class,
                                        ])

                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),

                Hidden::make('company_id'),
                Hidden::make('created_by'),
                Hidden::make('updated_by'),
            ]);
    }
}
