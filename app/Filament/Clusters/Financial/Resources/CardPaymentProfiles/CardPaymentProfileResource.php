<?php

namespace App\Filament\Clusters\Financial\Resources\CardPaymentProfiles;

use App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\Pages\CreateCardPaymentProfile;
use App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\Pages\EditCardPaymentProfile;
use App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\Pages\ListCardPaymentProfiles;
use App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\Schemas\CardPaymentProfileForm;
use App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\Tables\CardPaymentProfilesTable;
use App\Models\CardPaymentProfile;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CardPaymentProfileResource extends Resource
{
    protected static ?string $model = CardPaymentProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Perfil de Cartao';

    protected static ?string $pluralModelLabel = 'Perfis de Cartao';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return CardPaymentProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CardPaymentProfilesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCardPaymentProfiles::route('/'),
            // 'create' => CreateCardPaymentProfile::route('/create'),
            'edit' => EditCardPaymentProfile::route('/{record}/edit'),
        ];
    }
}
