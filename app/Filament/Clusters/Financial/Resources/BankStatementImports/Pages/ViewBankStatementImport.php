<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Pages;

use App\Enum\Financial\BankStatementLineStatus;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\BankStatementImportResource;
use App\Models\BankStatementImport;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewBankStatementImport extends ViewRecord
{
    protected static string $resource = BankStatementImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('delete_import')
                ->label('Excluir importação')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('A importação só pode ser excluída se todas as suas linhas estiverem pendentes.')
                ->disabled(fn (BankStatementImport $record): bool => $record->lines()
                    ->where('reconciliation_status', '!=', BankStatementLineStatus::PENDING)
                    ->exists())
                ->tooltip(fn (BankStatementImport $record): ?string => $record->lines()
                    ->where('reconciliation_status', '!=', BankStatementLineStatus::PENDING)
                    ->exists()
                    ? 'Todas as linhas devem estar pendentes para excluir a importação.'
                    : null)
                ->action(function (BankStatementImport $record): void {
                    if ($record->lines()->where('reconciliation_status', '!=', BankStatementLineStatus::PENDING)->exists()) {
                        Notification::make()
                            ->title('Não é possível excluir uma importação com linhas já tratadas.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $record->delete();

                    $this->redirect(BankStatementImportResource::getUrl('index', [
                        'tenant' => Filament::getTenant(),
                    ]));
                }),
            Action::make('back_to_list')
                ->label('Voltar')
                ->url(BankStatementImportResource::getUrl('index', [
                    'tenant' => Filament::getTenant(),
                ])),
        ];
    }
}
