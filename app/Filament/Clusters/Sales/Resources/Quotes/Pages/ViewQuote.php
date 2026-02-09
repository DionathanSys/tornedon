<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages;

use App\Filament\Clusters\Sales\Resources\Quotes\QuoteResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
