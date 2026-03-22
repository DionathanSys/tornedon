<?php

namespace App\Filament\Mobile\Resources\MobileServices;

use App\Filament\Clusters\Sales\Resources\Services\Schemas\ServiceForm;
use App\Filament\Mobile\Resources\MobileServices\Pages\CreateMobileService;
use App\Filament\Mobile\Resources\MobileServices\Pages\EditMobileService;
use App\Filament\Mobile\Resources\MobileServices\Pages\ListMobileServices;
use App\Filament\Mobile\Resources\MobileServices\Tables\MobileServicesTable;
use App\Models\Service;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MobileServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static ?string $slug = 'services';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Wrench;

    protected static ?string $modelLabel = "Servi\u{00E7}o";

    protected static ?string $pluralModelLabel = "Servi\u{00E7}os";

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return ServiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MobileServicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMobileServices::route('/'),
            'create' => CreateMobileService::route('/create'),
            'edit' => EditMobileService::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }
}
