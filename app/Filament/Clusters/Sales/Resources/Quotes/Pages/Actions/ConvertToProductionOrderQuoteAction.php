<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Services\Quote\QuoteService;

class ConvertToProductionOrderQuoteAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'convert_to_production_order';
    }

    public function handle(array $arguments)
    {
        $record = $this->getRecord();
        $service = app(QuoteService::class);
        $result = $service->convertToProductionOrder($record, $arguments, auth()->id());

        if ($result) {
            Notification::make()->success()->title('Ordem de produção criada')->send();
        } else {
            Notification::make()->danger()->title('Erro ao converter orçamento')->body($service->getMessage())->send();
        }
    }
}
