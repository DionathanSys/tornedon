<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners;

use App\Filament\Clusters\Partners\PartnersCluster;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\Pages\CreateCompanyPartner;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\Pages\EditCompanyPartner;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\Pages\ListCompanyPartners;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\Pages\ViewCompanyPartner;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\Schemas\CompanyPartnerForm;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\Schemas\CompanyPartnerInfolist;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\Tables\CompanyPartnersTable;
use App\Models\CompanyPartner;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CompanyPartnerResource extends Resource
{
    protected static ?string $model = CompanyPartner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::UserGroup;

    protected static ?string $cluster = PartnersCluster::class;

    protected static ?string $modelLabel = 'Parceiro';

    protected static ?string $pluralModelLabel = 'Parceiros';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return CompanyPartnerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CompanyPartnerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompanyPartnersTable::configure($table);
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
            'index' => ListCompanyPartners::route('/'),
            'create' => CreateCompanyPartner::route('/create'),
            'view' => ViewCompanyPartner::route('/{record}'),
            'edit' => EditCompanyPartner::route('/{record}/edit'),
        ];
    }
}
