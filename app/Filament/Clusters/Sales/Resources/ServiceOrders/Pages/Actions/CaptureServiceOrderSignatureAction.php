<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Forms\Components\SignaturePad;
use App\Models\ServiceOrder;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

final class CaptureServiceOrderSignatureAction
{
    public static function make(): Action
    {
        return Action::make('captureServiceOrderSignature')
            ->label('Coletar em tela cheia')
            ->icon(Heroicon::PencilSquare)
            ->color('primary')
            ->fillForm([
                'customer_signature' => null,
            ])
            ->schema([
                SignaturePad::make('customer_signature')
                    ->hiddenLabel()
                    ->minimal()
                    ->canvasHeight('calc(100dvh - 8rem)')
                    ->columnSpanFull(),
            ])
            ->modalWidth(Width::Screen)
            ->stickyModalFooter()
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->modalSubmitActionLabel('Salvar assinatura')
            ->modalCancelActionLabel('Cancelar')
            ->action(function (ServiceOrder $record, array $data, Action $action): void {
                $tenant = Filament::getTenant();

                if (! $tenant || $tenant->getKey() !== $record->company_id) {
                    Notification::make()
                        ->danger()
                        ->title('A ordem de serviço não pertence à empresa selecionada.')
                        ->send();

                    $action->halt();
                }

                $record->refresh();
                $signature = $data['customer_signature'] ?? null;

                $service = app(ServiceOrderService::class);
                $updated = $service->update($record, [
                    'customer_signature' => $signature,
                    'customer_signed_at' => blank($signature)
                        ? null
                        : ($signature !== $record->customer_signature ? now() : $record->customer_signed_at),
                ], Auth::id());

                if ($service->hasError() || $updated === null) {
                    Notification::make()
                        ->danger()
                        ->title($service->getMessageUser())
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->success()
                    ->title('Assinatura salva com sucesso.')
                    ->send();
            })
            ->after(function (Action $action): void {
                $livewire = $action->getLivewire();

                if (method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['customer_signature', 'customer_signed_at']);
                }
            });
    }
}
