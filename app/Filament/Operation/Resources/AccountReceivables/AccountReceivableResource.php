<?php

namespace App\Filament\Operation\Resources\AccountReceivables;

use App\Filament\Operation\Concerns\AuthorizesOperationResource;
use App\Filament\Operation\Resources\AccountReceivables\Pages\CreateAccountReceivable;
use App\Filament\Operation\Resources\AccountReceivables\Pages\EditAccountReceivable;
use App\Filament\Operation\Resources\AccountReceivables\Pages\ListAccountReceivables;
use App\Filament\Operation\Resources\AccountReceivables\RelationManagers\InstallmentsRelationManager;
use App\Filament\Operation\Resources\AccountReceivables\RelationManagers\PaymentsRelationManager;
use App\Filament\Operation\Resources\AccountReceivables\Schemas\AccountReceivableForm;
use App\Filament\Operation\Resources\AccountReceivables\Tables\AccountReceivablesTable;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Models\AccountReceivable;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AccountReceivableResource extends Resource
{
    use AuthorizesOperationResource;

    protected static ?string $model = AccountReceivable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowTrendingUp;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Conta à Receber';

    protected static ?string $pluralModelLabel = 'Contas à Receber';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return AccountReceivableForm::configure($schema, useSections: false);
    }

    public static function table(Table $table): Table
    {
        return AccountReceivablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
            PaymentsRelationManager::class,
            AttachmentsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountReceivables::route('/'),
            'create' => CreateAccountReceivable::route('/create'),
            'view' => Pages\ViewAccountReceivable::route('/{record}'),
            'edit' => EditAccountReceivable::route('/{record}/edit'),
        ];
    }
}
