<?php

namespace App\Filament\Mobile\Resources\Services;

use App\Filament\Mobile\Resources\Services\Pages\CreateService;
use App\Filament\Mobile\Resources\Services\Pages\EditService;
use App\Filament\Mobile\Resources\Services\Pages\ListServices;
use App\Filament\Mobile\Resources\Services\Schemas\ServiceForm;
use App\Filament\Mobile\Resources\Services\Tables\ServicesTable;
use App\Models\Service;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ServiceResource extends Resource
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
        return ServicesTable::configure($table);
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
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
