<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Schemas;

use App\Enum\Invoice\Status;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
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
                                    ->columns(['sm' => 1, 'md' => 4, 'lg' => 12,])
                                    ->columnSpanFull()
                                    ->schema([
                                        TextInput::make('invoice_number')
                                            ->label('Número da Fatura')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->required()
                                            ->maxLength(50),
                                        Select::make('status')
                                            ->label('Status')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->options(Status::toSelectArray())
                                            ->native(false)
                                            ->default(Status::PENDING->value)
                                            ->visibleOn('edit')
                                            ->disabled(),
                                        DatePicker::make('invoice_date')
                                            ->label('Data da Fatura')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->default(now())
                                            ->required()
                                            ->displayFormat('d/m/Y'),
                                        Select::make('customer_id')
                                            ->label('Cliente')
                                            ->columnSpan(['md' => 2, 'lg' => 5])
                                            ->relationship('customer', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->required(),
                                    ]),
                            ]),
                        // Tab::make('Produtos')
                        //     ->schema([
                        //         Livewire::make(RequisitionsRelationManager::class, fn(Invoice $record) => [
                        //             'ownerRecord' => $record,
                        //             'pageClass' => EditInvoice::class,
                        //         ])
                        //             ->columnSpanFull(),
                        //     ]),
                        // Tab::make('Serviços')
                        //     ->schema([
                        //         Livewire::make(ServiceOrdersRelationManager::class, fn(Invoice $record) => [
                        //             'ownerRecord' => $record,
                        //             'pageClass' => EditInvoice::class,
                        //         ])
                        //             ->columnSpanFull(),
                        //     ]),
                    ]),

                Hidden::make('company_id'),
                Hidden::make('created_by'),
                Hidden::make('updated_by'),
            ]);
    }
}
