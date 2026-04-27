<?php

namespace App\Filament\Clusters\Settings\Resources\FiscalRules;

use App\Filament\Clusters\Settings\Resources\FiscalRules\Pages\CreateFiscalRule;
use App\Filament\Clusters\Settings\Resources\FiscalRules\Pages\EditFiscalRule;
use App\Filament\Clusters\Settings\Resources\FiscalRules\Pages\ListFiscalRules;
use App\Filament\Clusters\Settings\Resources\FiscalRules\Schemas\FiscalRuleForm;
use App\Filament\Clusters\Settings\Resources\FiscalRules\Tables\FiscalRulesTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\FiscalRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FiscalRuleResource extends Resource
{
    protected static ?string $model = FiscalRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return FiscalRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FiscalRulesTable::configure($table);
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
            'index' => ListFiscalRules::route('/'),
            'create' => CreateFiscalRule::route('/create'),
            'edit' => EditFiscalRule::route('/{record}/edit'),
        ];
    }
}
