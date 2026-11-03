<?php

namespace App\Filament\Bpo\Resources\BankStatementImports\Pages;

use App\Enum\Financial\BankStatementLineStatus;
use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Resources\BankStatementImportResource;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions\BankReconciliationQualityAction;
use App\Models\BankStatementImport;
use App\Services\Audit\AuditRecorder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ViewImport extends ViewRecord
{
    use HasWorkbenchHeader;

    public function booted(): void
    {
        Gate::authorize('view', $this->getRecord());
    }

    protected string $view = 'filament.bpo.pages.bank-statement-import';

    public function workbenchEyebrow(): string
    {
        return 'CONCILIAÇÃO · IMPORTAÇÃO #'.$this->getRecord()->getKey();
    }

    public function workbenchDescription(): string
    {
        return 'Dados do extrato e movimentos bancários reunidos para conferir e conciliar os lançamentos.';
    }

    public function getTitle(): string
    {
        return 'Extrato bancário';
    }

    protected static string $resource = BankStatementImportResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('Voltar às importações')->url(static::getResource()::getUrl()),
            BankReconciliationQualityAction::make($this->getRecord()),
            Action::make('delete_import')->label('Excluir importação')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
                ->authorize(fn (BankStatementImport $record) => Gate::allows('delete', $record))
                ->modalHeading('Excluir extrato importado')->modalDescription('Disponível apenas quando todas as linhas continuam pendentes e sem vínculos financeiros. Nenhum movimento de caixa será excluído por esta ação.')
                ->modalSubmitActionLabel('Excluir importação')->modalCancelActionLabel('Manter extrato')
                ->action(function (BankStatementImport $record): void {
                    $deleted = DB::transaction(function () use ($record): bool {
                        $import = static::getResource()::getEloquentQuery()->lockForUpdate()->findOrFail($record->id);
                        Gate::authorize('delete', $import);
                        $lines = $import->lines()->select(['id', 'reconciliation_status', 'cash_movement_id', 'bank_reconciliation_group_id'])->lockForUpdate()->get();
                        if ($lines->contains(fn ($line) => $line->reconciliation_status !== BankStatementLineStatus::PENDING || $line->cash_movement_id || $line->bank_reconciliation_group_id)) {
                            return false;
                        }
                        $audit = app(AuditRecorder::class);
                        $before = $audit->snapshot($import);
                        $import->delete();
                        $audit->recordModelEvent($import, 'bank_statement_import.deleted', 'Importação pendente excluída', $before, null, auth()->id());

                        return true;
                    });
                    if (! $deleted) {
                        Notification::make()->title('Importação preservada')->body('Há linhas já tratadas ou com vínculo financeiro. Reabra a conciliação antes de excluir.')->danger()->send();

                        return;
                    }
                    Notification::make()->title('Importação excluída')->success()->send();
                    $this->redirect(static::getResource()::getUrl());
                }),
        ];
    }
}
