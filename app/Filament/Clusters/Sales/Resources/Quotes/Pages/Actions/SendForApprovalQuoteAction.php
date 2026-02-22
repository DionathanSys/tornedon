<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Services\Quote\QuoteService;
use Illuminate\Support\Facades\Auth;

class SendForApprovalQuoteAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'send_for_approval';
    }

    public function handle()
    {
        $record = $this->getRecord();
        $service = app(QuoteService::class);
        $ok = $service->sendForApproval($record, Auth::id());

        if ($ok) {
            Notification::make()->success()->title('Orçamento enviado para aprovação')->send();
        } else {
            Notification::make()->danger()->title('Erro ao enviar para aprovação')->body($service->getMessage())->send();
        }
    }
}
