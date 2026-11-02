<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\PurchaseClosings\Pages;
use Filament\Actions\EditAction;
use Filament\Tables\Table;

class PurchaseClosingResource extends \App\Filament\Clusters\Financial\Resources\PurchaseClosings\PurchaseClosingResource
{
    public static function getPages(): array
    {
        return ['index' => Pages\ListPurchaseClosings::route('/'), 'create' => Pages\CreatePurchaseClosing::route('/create'), 'edit' => Pages\EditPurchaseClosing::route('/{record}/edit')];
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)->recordActions([EditAction::make()->authorize(fn ($livewire, $record) => $livewire->canUseBpoAction('purchase_closings.update', $record))])->defaultPaginationPageOption(15)->paginated([15, 30, 50]);
    }
}
