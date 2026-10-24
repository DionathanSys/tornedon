<?php

namespace App\Filament\Clusters\Financial\Resources\Components;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Tables\Enums\ColumnManagerLayout;
use Filament\Tables\Table;

final class FinancialTableColumns
{
    public static function configure(Table $table): Table
    {
        foreach ($table->getColumns() as $column) {
            if (! $column->isToggleable()) {
                $column->toggleable();
            }
        }

        return $table->columnManagerLayout(ColumnManagerLayout::Modal)
            ->columnManagerColumns(['default' => 1, 'sm' => 2, 'lg' => 4])
            ->columnManagerWidth(Width::FiveExtraLarge)
            ->columnManagerTriggerAction(fn (Action $action) => $action->slideOver(false));
    }
}
