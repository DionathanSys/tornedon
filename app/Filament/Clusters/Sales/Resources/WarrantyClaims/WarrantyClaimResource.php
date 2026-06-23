<?php

namespace App\Filament\Clusters\Sales\Resources\WarrantyClaims;

use App\Filament\Clusters\Sales\Resources\WarrantyClaims\Pages\CreateWarrantyClaim;
use App\Filament\Clusters\Sales\Resources\WarrantyClaims\Pages\EditWarrantyClaim;
use App\Filament\Clusters\Sales\Resources\WarrantyClaims\Pages\ListWarrantyClaims;
use App\Filament\Clusters\Sales\Resources\WarrantyClaims\Schemas\WarrantyClaimForm;
use App\Filament\Clusters\Sales\Resources\WarrantyClaims\Tables\WarrantyClaimsTable;
use App\Models\WarrantyClaim;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class WarrantyClaimResource extends Resource
{
    protected static ?string $model = WarrantyClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Vendas';

    protected static ?string $modelLabel = 'Garantia';

    protected static ?string $pluralModelLabel = 'Garantias';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return WarrantyClaimForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WarrantyClaimsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarrantyClaims::route('/'),
            'create' => CreateWarrantyClaim::route('/create'),
            'edit' => EditWarrantyClaim::route('/{record}/edit'),
        ];
    }
}
