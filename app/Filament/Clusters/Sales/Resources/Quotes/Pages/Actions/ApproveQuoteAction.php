<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Services\Quote\QuoteService;

class ApproveQuoteAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approve';
    }

    public function handle()
    {
        $record = $this->getRecord();
        $service = app(QuoteService::class);
        $ok = $service->approve($record, auth()->id());

        if ($ok) {
            Notification::make()->success()->title('Orçamento aprovado')->send();
        } else {
            Notification::make()->danger()->title('Erro ao aprovar orçamento')->body($service->getMessage())->send();
        }
    }
}
