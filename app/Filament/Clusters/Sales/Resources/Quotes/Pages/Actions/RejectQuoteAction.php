<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Services\Quote\QuoteService;
use Illuminate\Support\Facades\Auth;

class RejectQuoteAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'reject';
    }

    public function handle(array $arguments)
    {
        $record = $this->getRecord();
        $reason = $arguments['reason'] ?? '';
        $service = app(QuoteService::class);
        $ok = $service->reject($record, $reason, Auth::id());

        if ($ok) {
            Notification::make()->success()->title('Orçamento rejeitado')->send();
        } else {
            Notification::make()->danger()->title('Erro ao rejeitar orçamento')->body($service->getMessage())->send();
        }
    }
}
