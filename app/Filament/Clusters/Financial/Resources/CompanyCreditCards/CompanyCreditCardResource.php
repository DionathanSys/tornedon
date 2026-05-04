<?php

namespace App\Filament\Clusters\Financial\Resources\CompanyCreditCards;

use App\Filament\Clusters\Financial\Resources\CompanyCreditCards\Pages\CreateCompanyCreditCard;
use App\Filament\Clusters\Financial\Resources\CompanyCreditCards\Pages\EditCompanyCreditCard;
use App\Filament\Clusters\Financial\Resources\CompanyCreditCards\Pages\ListCompanyCreditCards;
use App\Filament\Clusters\Financial\Resources\CompanyCreditCards\Schemas\CompanyCreditCardForm;
use App\Filament\Clusters\Financial\Resources\CompanyCreditCards\Tables\CompanyCreditCardsTable;
use App\Models\CompanyCreditCard;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CompanyCreditCardResource extends Resource
{
    protected static ?string $model = CompanyCreditCard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Cartão Corporativo';

    protected static ?string $pluralModelLabel = 'Cartões Corporativos';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with(['issuerPartner', 'defaultFinancialAccount']);
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyCreditCardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompanyCreditCardsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyCreditCards::route('/'),
            'create' => CreateCompanyCreditCard::route('/create'),
            'edit' => EditCompanyCreditCard::route('/{record}/edit'),
        ];
    }
}
