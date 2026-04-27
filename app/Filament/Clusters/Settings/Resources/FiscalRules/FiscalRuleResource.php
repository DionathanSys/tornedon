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
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FiscalRuleResource extends Resource
{
    protected static ?string $model = FiscalRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $modelLabel = 'Regra Fiscal';

    protected static ?string $pluralModelLabel = 'Regras Fiscais';

    protected static ?string $navigationLabel = 'Regras Fiscais';

    protected static ?int $navigationSort = 9;

    protected static ?string $recordTitleAttribute = 'cfop';

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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $companyId = Filament::getTenant()?->id;

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query;
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
