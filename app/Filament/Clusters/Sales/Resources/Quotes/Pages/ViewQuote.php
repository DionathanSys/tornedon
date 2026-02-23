<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages;

use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\ApproveQuoteAction;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\ConvertToProductionOrderQuoteAction;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\RejectQuoteAction;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\ReopenQuoteAction;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions\SendForApprovalQuoteAction;
use App\Filament\Clusters\Sales\Resources\Quotes\QuoteResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                SendForApprovalQuoteAction::make(),
                ApproveQuoteAction::make(),
                RejectQuoteAction::make(),
                ReopenQuoteAction::make(),
                ConvertToProductionOrderQuoteAction::make(),
                EditAction::make(),
            ])->button(),
        ];
    }
}
