<?php

namespace App\Filament\Clusters\Financial\Actions;

use App\Models\BankSlip;
use App\Models\PixCharge;
use App\Services\Financial\Banking\BankChargeLifecycleService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class BankChargeLifecycleActions
{
    public static function make(): array
    {
        return [
            self::action('cancel_charge', 'Cancelar no provedor', Heroicon::XCircle)
                ->visible(fn (BankSlip|PixCharge $record): bool => ! $record->trashed()
                    && in_array($record->status->value, ['registered', 'expired', 'cancel_pending'], true))
                ->modalDescription('Solicita o cancelamento no provedor. O registro continuará no sistema; acompanhe a confirmação pela consulta ou webhook.')
                ->action(function (BankSlip|PixCharge $record, array $data): void {
                    Gate::authorize('update', $record);
                    self::run(fn () => app(BankChargeLifecycleService::class)->cancel($record, Filament::getTenant()->id, Filament::auth()->user(), $data['reason']));
                    Notification::make()->title('Cancelamento enfileirado.')->success()->send();
                }),
            self::action('remove_charge', 'Remover', Heroicon::Trash)
                ->visible(fn (BankSlip|PixCharge $record): bool => ! $record->trashed())
                ->modalDescription('Move o registro para a lixeira, preservando seu histórico. Cobranças registradas precisam de cancelamento confirmado. Emissões não enviadas ou recusadas por validação/autenticação podem ser removidas sem cancelamento.')
                ->action(function (BankSlip|PixCharge $record, array $data): void {
                    Gate::authorize('delete', $record);
                    self::run(fn () => app(BankChargeLifecycleService::class)->remove($record, Filament::getTenant()->id, Filament::auth()->user(), $data['reason']));
                    Notification::make()->title('Cobrança movida para a lixeira.')->success()->send();
                }),
            self::action('force_remove_charge', 'Excluir definitivamente', Heroicon::Trash)
                ->visible(fn (BankSlip|PixCharge $record): bool => $record->trashed())
                ->modalDescription('Exclui definitivamente o registro da cobrança. Esta ação é irreversível. O histórico de eventos e auditoria será preservado.')
                ->action(function (BankSlip|PixCharge $record, array $data): void {
                    Gate::authorize('delete', $record);
                    self::run(fn () => app(BankChargeLifecycleService::class)->remove($record, Filament::getTenant()->id, Filament::auth()->user(), $data['reason'], permanent: true));
                    Notification::make()->title('Cobrança excluída definitivamente.')->success()->send();
                }),
        ];
    }

    private static function action(string $name, string $label, Heroicon $icon): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color('danger')
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->label('Justificativa')->required()->minLength(10)->maxLength(2000)]);
    }

    private static function run(\Closure $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            // Service errors refer to domain fields (charge, operation), which are
            // not inputs in this modal and would otherwise remain invisible.
            Notification::make()
                ->title('Não foi possível concluir a ação.')
                ->body(collect($exception->errors())->flatten()->unique()->implode("\n"))
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }
}
