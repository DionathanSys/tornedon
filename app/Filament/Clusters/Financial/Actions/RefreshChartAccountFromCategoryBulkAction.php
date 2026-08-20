<?php

namespace App\Filament\Clusters\Financial\Actions;

use App\Services\Financial\RefreshChartAccountFromCategoryService;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

final class RefreshChartAccountFromCategoryBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('refresh_chart_account_from_category')
            ->label('Atualizar plano de contas')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Atualizar classificação dos registros selecionados')
            ->modalDescription('A conta do plano será atualizada conforme o vínculo atual da categoria financeira. Outros dados não serão alterados.')
            ->action(function (Collection $records): void {
                $result = app(RefreshChartAccountFromCategoryService::class)->refresh($records, auth()->id());
                $message = "{$result['updated']} registro(s) atualizado(s).";

                if ($result['skipped'] > 0) {
                    $message .= " {$result['skipped']} ignorado(s) por não possuírem categoria financeira válida.";
                }

                Notification::make()
                    ->title($message)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
