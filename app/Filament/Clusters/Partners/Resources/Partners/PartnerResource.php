<?php

namespace App\Filament\Clusters\Partners\Resources\Partners;

use App\Filament\Clusters\Partners\PartnersCluster;
use App\Filament\Clusters\Partners\Resources\Partners\Pages\CreatePartner;
use App\Filament\Clusters\Partners\Resources\Partners\Pages\EditPartner;
use App\Filament\Clusters\Partners\Resources\Partners\Pages\ListPartners;
use App\Filament\Clusters\Partners\Resources\Partners\Pages\ViewPartner;
use App\Filament\Clusters\Partners\Resources\Partners\Schemas\PartnerForm;
use App\Filament\Clusters\Partners\Resources\Partners\Schemas\PartnerInfolist;
use App\Filament\Clusters\Partners\Resources\Partners\Tables\PartnersTable;
use App\Models\Partner;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PartnerResource extends Resource
{
    protected static ?string $model = Partner::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::UserGroup;

    protected static ?string $cluster = PartnersCluster::class;

    protected static ?string $modelLabel = 'Parceiro';

    protected static ?string $pluralModelLabel = 'Parceiros';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PartnerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PartnerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartnersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartners::route('/'),
            'create' => CreatePartner::route('/create'),
            'view' => ViewPartner::route('/{record}'),
            'edit' => EditPartner::route('/{record}/edit'),
        ];
    }
}
