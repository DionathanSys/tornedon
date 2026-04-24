<?php

namespace App\Filament\Clusters\Settings\Resources\AuditEntries;

use App\Filament\Clusters\Settings\Resources\AuditEntries\Pages\ListAuditEntries;
use App\Filament\Clusters\Settings\Resources\AuditEntries\Tables\AuditEntriesTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\AuditEntry;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AuditEntryResource extends Resource
{
    protected static ?string $model = AuditEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $modelLabel = 'Evento de Auditoria';

    protected static ?string $pluralModelLabel = 'Auditoria';

    protected static ?int $navigationSort = 30;

    public static function table(Table $table): Table
    {
        return AuditEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEntries::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->select(AuditEntry::listColumns())
            ->with(['actorUser:id,name']);
        $companyId = Filament::getTenant()?->id;

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->canViewAuditLogs() ?? false;
    }
}
