<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions;

use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\CreateRequisition;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\EditRequisition;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Clusters\Sales\Resources\Requisitions\Schemas\RequisitionForm;
use App\Filament\Clusters\Sales\Resources\Requisitions\Tables\RequisitionsTable;
use App\Filament\Clusters\Sales\SalesCluster;
use App\Models\Requisition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RequisitionResource extends Resource
{
    protected static ?string $model = Requisition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentList;

    protected static ?string $cluster = SalesCluster::class;

    protected static ?string $modelLabel = 'Requisição';

    protected static ?string $pluralModelLabel = 'Requisições';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return RequisitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RequisitionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRequisitions::route('/'),
            // 'create' => CreateRequisition::route('/create'),
            'edit' => EditRequisition::route('/{record}/edit'),
        ];
    }
}
