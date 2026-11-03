<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions;

use App\Models\BankStatementImport;
use App\Services\Financial\BankStatement\BankReconciliationQualityReport;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

final class BankReconciliationQualityAction
{
    public static function make(?BankStatementImport $import = null): Action
    {
        $authorize = fn (): bool => $import ? Gate::allows('view', $import) : Gate::allows('viewAny', BankStatementImport::class);

        return Action::make('reconciliation_quality')
            ->label('Qualidade das sugestões')
            ->icon('heroicon-o-chart-bar')
            ->authorize($authorize)
            ->modalHeading('Qualidade das sugestões de conciliação')
            ->modalDescription($import ? 'Importação: '.$import->file_name : 'Todas as importações da empresa atual')
            ->modalWidth('6xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn () => view('filament.actions.bank-reconciliation-quality', [
                'metrics' => app(BankReconciliationQualityReport::class)->summary(Filament::getTenant()->id, $import?->id),
            ]))
            ->extraModalFooterActions([
                Action::make('export_reconciliation_reviews')->label('Exportar base revisada (JSON)')->authorize($authorize)
                    ->action(function () use ($import) {
                        $query = app(BankReconciliationQualityReport::class)->decisions(Filament::getTenant()->id, $import?->id)->with('evaluation');

                        return response()->streamDownload(function () use ($query): void {
                            echo '[';
                            $separator = '';
                            foreach ($query->lazyById(100) as $decision) {
                                echo $separator.json_encode($decision->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                                $separator = ',';
                            }
                            echo ']';
                        }, 'conciliacoes-revisadas.json', ['Content-Type' => 'application/json; charset=UTF-8']);
                    }),
            ]);
    }
}
