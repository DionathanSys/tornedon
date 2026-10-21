<?php

namespace App\Filament\Clusters\Financial\Actions;

use App\Services\Financial\ChangeInstallmentStatusService;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

final class ChangeInstallmentStatusBulkAction
{
    public static function make(string $model): BulkAction
    {
        return BulkAction::make('change_status_without_movement')
            ->label('Alterar status sem movimentação')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (): bool => Filament::auth()->user() && Filament::getTenant()
                && app(ChangeInstallmentStatusService::class)->allows(
                    Filament::auth()->user(), Filament::getTenant(), $model, Filament::getCurrentPanel()?->getId(),
                ))
            ->requiresConfirmation()
            ->modalHeading('Alterar status sem movimentação')
            ->modalDescription('Altera somente o status das parcelas selecionadas. Não registra pagamentos, recebimentos ou movimentações de caixa. Valores, saldos e datas serão preservados.')
            ->schema([
                Select::make('status')
                    ->label('Novo status')
                    ->options(ChangeInstallmentStatusService::options($model))
                    ->required()
                    ->native(false),
            ])
            ->action(function (Collection $records, array $data) use ($model): void {
                abort_unless(Filament::auth()->user() && Filament::getTenant(), 403);
                $updated = app(ChangeInstallmentStatusService::class)->change(
                    $records, $model, $data['status'], Filament::auth()->user(), Filament::getTenant(), Filament::getCurrentPanel()?->getId(),
                );

                Notification::make()
                    ->title("{$updated} parcela(s) atualizada(s) sem movimentação.")
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
