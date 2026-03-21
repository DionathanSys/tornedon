<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Forms\Components\SignaturePad;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class SignServiceOrderAction
{
    public static function make(): Action
    {
        return Action::make('signServiceOrder')
            ->label('Assinar')
            ->icon(Heroicon::PencilSquare)
            ->color('gray')
            ->modal()
            ->modalHeading('Assinatura do Cliente')
            ->modalDescription('Colete a assinatura no modal e salve para registrar imediatamente a nova assinatura e o horário.')
            ->modalSubmitActionLabel('Salvar')
            ->modalCancelActionLabel('Cancelar')
            ->modalWidth('4xl')
            ->fillForm(function (ServiceOrder $record): array {
                return [
                    'customer_signature' => $record->customer_signature,
                    'customer_signed_at' => $record->customer_signed_at,
                ];
            })
            ->schema(function (Schema $schema): Schema {
                return $schema
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->components([
                        SignaturePad::make('customer_signature')
                            ->label('Assinatura')
                            ->columnSpanFull(),
                        DateTimePicker::make('customer_signed_at')
                            ->label('Última assinatura')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->seconds(false)
                            ->displayFormat('d/m/Y H:i')
                            ->readOnly()
                            ->dehydrated(false),
                        TextInput::make('signature_help')
                            ->label('')
                            ->columnSpanFull()
                            // ->content('Use "Salvar" para gravar a assinatura agora. "Limpar" remove o desenho do canvas, mas só persiste ao salvar o modal.'),
                    ]);
            })
            // ->visible(fn (ServiceOrder $record): bool => (bool) $record->state()?->canEdit())
            ->action(function (Action $action, ServiceOrder $record, array $data): void {
                Log::debug('SignServiceOrderAction (Filament): Salvando assinatura da OS', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $record->id,
                    'user_id' => Auth::id(),
                    'has_signature' => filled($data['customer_signature'] ?? null),
                ]);

                $payload = [
                    'customer_signature' => filled($data['customer_signature'] ?? null) ? $data['customer_signature'] : null,
                    'customer_signed_at' => filled($data['customer_signature'] ?? null) ? now() : null,
                ];

                $service = app(ServiceOrderService::class);
                $updated = $service->update($record, $payload, Auth::id());

                if ($service->hasError() || $updated === null) {
                    Log::error('SignServiceOrderAction (Filament): Erro ao salvar assinatura da OS', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'service_order_id' => $record->id,
                        'error_code' => $service->getErrorCode(),
                        'message' => $service->getMessage(),
                        'errors' => $service->getErrors(),
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );

                    $action->halt();

                    return;
                }

                notify::success(message: filled($payload['customer_signature']) ? 'Assinatura salva com sucesso.' : 'Assinatura removida com sucesso.');
            })
            ->after(function (Action $action): void {
                $record = $action->getRecord();

                if ($record instanceof ServiceOrder) {
                    $record->refresh();
                }

                $livewire = $action->getLivewire();

                if ($livewire && method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['customer_signature', 'customer_signed_at']);
                }
            });
    }
}
