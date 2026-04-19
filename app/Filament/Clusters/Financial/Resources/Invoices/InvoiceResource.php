<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices;

use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\AccountReceivablesRelationManager;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\FiscalDocumentsRelationManager;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\InstallmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\PaymentsRelationManager;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\RequisitionsRelationManager;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\ServiceOrdersRelationManager;
use App\Filament\Clusters\Financial\Resources\Invoices\Schemas\InvoiceForm;
use App\Filament\Clusters\Financial\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static ?string $cluster = FinancialCluster::class;

    protected static ?string $modelLabel = 'Fatura';

    protected static ?string $pluralModelLabel = 'Faturas';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return InvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationGroup::make('Contas', [
                AccountReceivablesRelationManager::class,
                InstallmentsRelationManager::class,
                PaymentsRelationManager::class,
            ]),
            RelationGroup::make('Itens', [
                RequisitionsRelationManager::class,
                ServiceOrdersRelationManager::class,
            ]),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'edit' => EditInvoice::route('/{record}/edit'),
        ];
    }

}
