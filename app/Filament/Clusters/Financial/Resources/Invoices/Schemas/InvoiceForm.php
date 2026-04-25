<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Schemas;

use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\FiscalDocumentsRelationManager;
use App\Models\Invoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['sm' => 1, 'md' => 4, 'lg' => 12,])
            ->components([
                Section::make('Dados da Fatura')
                    ->columns(['sm' => 1, 'md' => 6, 'lg' => 12,])
                    ->columnSpanFull()
                    ->schema([
                        Hidden::make('company_id'),
                        Hidden::make('created_by'),
                        Hidden::make('updated_by'),
                        TextEntry::make('customer.name')
                            ->label('Cliente')
                            // ->state(fn(Invoice $record): string => $record->customer->name)
                            // ->disabled()
                            ->columnSpan(['md' => 3, 'lg' => 6])
                            // ->relationship('customer', 'name')
                            // ->searchable()
                            // ->preload()
                            // ->required(),
                            ,
                        TextInput::make('invoice_number')
                            ->label('Número da Fatura')
                            ->disabled()
                            ->columnStart(1)
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
                        Money::make('services_amount')
                            ->label('Valor de Serviços')
                            ->disabled()
                            ->readOnly()
                            ->dehydrated(false)
                            ->formatStateUsing(fn(?Invoice $record): string => number_format((float) ($record?->services_amount ?? 0), 2, ',', '.'))
                            ->columnStart(1)
                            ->columnSpan(['md' => 2, 'lg' => 2]),
                        Money::make('products_amount')
                            ->label('Valor de Produtos')
                            ->disabled()
                            ->readOnly()
                            ->dehydrated(false)
                            ->formatStateUsing(fn(?Invoice $record): string => number_format((float) ($record?->products_amount ?? 0), 2, ',', '.'))
                            ->columnSpan(['md' => 2, 'lg' => 2]),
                        Money::make('total_amount')
                            ->label('Valor Total')
                            ->disabled()
                            ->readOnly()
                            ->dehydrated(false)
                            ->formatStateUsing(fn(?Invoice $record): string => number_format((float) ($record?->total_amount ?? 0), 2, ',', '.'))
                            ->columnSpan(['md' => 2, 'lg' => 2]),
                        Money::make('discount_amount')
                            ->label('Desconto')
                            ->disabled()
                            ->readOnly()
                            ->dehydrated(false)
                            ->formatStateUsing(fn(?Invoice $record): string => number_format((float) ($record?->discount_amount ?? 0), 2, ',', '.'))
                            ->columnSpan(['md' => 2, 'lg' => 2]),
                        Money::make('net_value')
                            ->label('Valor Líquido')
                            ->disabled()
                            ->readOnly()
                            ->dehydrated(false)
                            ->formatStateUsing(fn(?Invoice $record): string => number_format((float) ($record?->net_value ?? 0), 2, ',', '.'))
                            ->columnSpan(['md' => 2, 'lg' => 2]),
                    ]),
                Section::make('')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->columns(1)
                    ->visible(fn (?Invoice $record): bool => $record->fiscalDocuments()->exists())
                    ->contained(false)
                    ->schema([
                        Livewire::make(FiscalDocumentsRelationManager::class, fn(Invoice $record) => [
                            'ownerRecord' => $record,
                            'pageClass' => EditInvoice::class,
                        ])

                            ->columnSpanFull(),
                    ]),

            ]);
    }
}
