<?php

namespace App\Filament\Clusters\Settings\Resources\EmailDispatches;

use App\Filament\Clusters\Settings\Resources\EmailDispatches\Pages\ListEmailDispatches;
use App\Filament\Clusters\Settings\Resources\EmailDispatches\Tables\EmailDispatchesTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\EmailDispatch;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmailDispatchResource extends Resource
{
    protected static ?string $model = EmailDispatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $modelLabel = 'Envio de E-mail';

    protected static ?string $pluralModelLabel = 'Envios de E-mail';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return EmailDispatchesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailDispatches::route('/'),
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
}
