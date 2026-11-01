<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\CashMovements\Actions\MovementActions;
use App\Filament\Bpo\Resources\CashMovements\Pages;
use App\Filament\Clusters\Financial\Resources\CashMovements\Tables\CashMovementsTable;
use Filament\Actions\ActionGroup;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CashMovementResource extends \App\Filament\Clusters\Financial\Resources\CashMovements\CashMovementResource
{
    public static function table(Table $table): Table
    {
        $table = CashMovementsTable::configure($table);

        return $table->recordActions([ActionGroup::make(MovementActions::records())->label('Ações')->icon('heroicon-o-ellipsis-vertical')], RecordActionsPosition::AfterColumns)
            ->toolbarActions(MovementActions::toolbar())
            ->defaultPaginationPageOption(15)->paginated([15, 30, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMovements::route('/'), 'create' => Pages\CreateMovement::route('/create'), 'edit' => Pages\EditMovement::route('/{record}/edit')];
    }

    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && $record->origin_type === 'manual'
            && blank($record->transfer_group_id) && $record->reversal_of_id === null && $record->reversed_at === null
            && ! $record->hasStatementLinks();
    }
}
