<?php

namespace App\Filament\Clusters\Financial\Resources\CardInstitutions;

use App\Filament\Clusters\Financial\Resources\CardInstitutions\Pages\CreateCardInstitution;
use App\Filament\Clusters\Financial\Resources\CardInstitutions\Pages\EditCardInstitution;
use App\Filament\Clusters\Financial\Resources\CardInstitutions\Pages\ListCardInstitutions;
use App\Filament\Clusters\Financial\Resources\CardInstitutions\Schemas\CardInstitutionForm;
use App\Filament\Clusters\Financial\Resources\CardInstitutions\Tables\CardInstitutionsTable;
use App\Models\CardInstitution;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CardInstitutionResource extends Resource
{
    protected static ?string $model = CardInstitution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Instituição de cartão';

    protected static ?string $pluralModelLabel = 'Instituições de cartão';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return CardInstitutionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CardInstitutionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCardInstitutions::route('/'),
            'create' => CreateCardInstitution::route('/create'),
            'edit' => EditCardInstitution::route('/{record}/edit'),
        ];
    }
}
