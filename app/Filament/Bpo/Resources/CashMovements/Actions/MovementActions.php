<?php

namespace App\Filament\Bpo\Resources\CashMovements\Actions;

use App\Filament\Bpo\Resources\CashMovements\Pages\ListMovements;
use App\Filament\Clusters\Financial\Actions\RefreshChartAccountFromCategoryBulkAction;
use App\Filament\Clusters\Financial\Resources\CashMovements\Actions\CreateTransferAction;
use App\Filament\Clusters\Financial\Resources\CashMovements\Actions\EditTransferAction;
use App\Filament\Clusters\Financial\Resources\CashMovements\Actions\ReverseTransferAction;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use App\Services\Access\CompanyAuthorization;
use App\Services\Financial\CashMovementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

final class MovementActions
{
    public static function records(): array
    {
        return [
            Action::make('details')->label('Detalhes')->icon('heroicon-o-eye')->modalHeading('Detalhes do movimento')
                ->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                ->authorize(fn (ListMovements $livewire, CashMovement $record) => $livewire->canOperateMovement('view', $record))
                ->beforeFormFilled(fn (CashMovement $record) => self::authorize($record, 'view'))
                ->schema([Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextEntry::make('description')->label('Descrição')->columnSpanFull(),
                    TextEntry::make('transaction_date')->label('Data')->date('d/m/Y'),
                    TextEntry::make('amount')->label('Valor')->money('BRL'),
                    TextEntry::make('financialAccount.name')->label('Conta'),
                    TextEntry::make('counterparty_label')->label('Parceiro'),
                    TextEntry::make('origin_label')->label('Origem'),
                    TextEntry::make('tracking_label')->label('De / Para'),
                    TextEntry::make('financialCategory.name')->label('Categoria')->placeholder('Sem categoria'),
                    TextEntry::make('reconciliation')->label('Conciliação')->state(fn (CashMovement $record) => $record->hasStatementLinks() ? 'Conciliado' : 'Não conciliado'),
                    TextEntry::make('reversed_at')->label('Estornado em')->dateTime('d/m/Y H:i')->placeholder('Não estornado'),
                    TextEntry::make('notes')->label('Observações')->placeholder('Sem observações')->columnSpanFull(),
                ])]),
            EditAction::make()->label('Editar movimento')
                ->authorize(fn (ListMovements $livewire, CashMovement $record) => $livewire->canOperateMovement('update', $record))
                ->visible(fn (CashMovement $record) => self::editable($record) && blank($record->transfer_group_id)),
            EditTransferAction::make()->label('Editar transferência')->modalHeading('Editar transferência entre contas')
                ->modalDescription('As alterações serão aplicadas aos dois lançamentos da transferência.')
                ->modalSubmitActionLabel('Salvar transferência')->modalCancelActionLabel('Voltar')->stickyModalFooter()
                ->authorize(fn (ListMovements $livewire, CashMovement $record) => $livewire->canOperateTransfer('update', $record))
                ->visible(fn (CashMovement $record) => self::editable($record) && $record->isTransfer()
                    && $record->transferGroupMovements()->every(fn (CashMovement $item) => self::editable($item)))
                ->beforeFormFilled(fn (CashMovement $record) => self::authorize($record, 'update', true))
                ->before(fn (CashMovement $record) => self::authorize($record, 'update', true)),
            ReverseTransferAction::make()->label('Estornar transferência')->modalHeading('Estornar transferência entre contas')
                ->modalDescription(fn (CashMovement $record) => 'Serão criados lançamentos inversos para os dois lados de “'.$record->description.'”, no valor de R$ '.number_format($record->amount, 2, ',', '.').'. O histórico e as conciliações existentes serão preservados.')
                ->modalSubmitActionLabel('Confirmar estorno')->modalCancelActionLabel('Manter transferência')->modalWidth('lg')
                ->authorize(fn (ListMovements $livewire, CashMovement $record) => $livewire->canOperateTransfer('reverse', $record)
                    && $livewire->canOperateMovement('create') && $livewire->canOperateTransfer('update', $record))
                ->visible(fn (CashMovement $record) => $record->reversal_of_id === null && $record->isTransfer() && $record->reversed_at === null)
                ->before(fn (CashMovement $record) => self::authorize($record, 'reverse', true)),
            Action::make('delete_safely')->label('Excluir / desfazer baixa')->icon('heroicon-o-trash')->color('danger')
                ->visible(fn (CashMovement $record) => blank($record->transfer_group_id) && $record->reversal_of_id === null && $record->reversed_at === null)
                ->authorize(fn (ListMovements $livewire, CashMovement $record) => $livewire->canDeleteMovement($record))
                ->requiresConfirmation()->modalWidth('lg')->modalHeading('Excluir movimento financeiro')
                ->modalDescription(fn (CashMovement $record) => 'Movimento: “'.$record->description.'” · R$ '.number_format($record->amount, 2, ',', '.').'. Se houver uma baixa vinculada, ela será desfeita e o saldo da conta a pagar/receber será recalculado. Baixas conciliadas preservam o histórico por estorno. Movimentos manuais conciliados exigem desfazer a conciliação antes de excluir.')
                ->modalSubmitActionLabel('Confirmar exclusão')->modalCancelActionLabel('Manter movimento')
                ->before(fn (CashMovement $record) => self::authorize($record, 'delete'))
                ->action(function (Action $action, CashMovement $record): void {
                    $service = app(CashMovementService::class);
                    if (! $service->deleteSafely($record, Filament::auth()->id())) {
                        Notification::make()->title('Não foi possível excluir o movimento')->body($service->getMessageUser())->danger()->send();
                        $action->halt();
                    }
                    Notification::make()->title($service->getMessage())->success()->send();
                }),
        ];
    }

    public static function toolbar(): array
    {
        return [
            CreateTransferAction::make()->label('Nova transferência')->modalHeading('Transferir entre contas')
                ->modalDescription('Selecione contas diferentes da empresa. Será registrada uma saída na origem e uma entrada de mesmo valor no destino.')
                ->modalSubmitActionLabel('Criar transferência')->modalCancelActionLabel('Voltar')->stickyModalFooter()
                ->authorize(fn (ListMovements $livewire) => $livewire->canOperateMovement('create'))
                ->before(fn () => Gate::authorize('create', CashMovement::class)),
            RefreshChartAccountFromCategoryBulkAction::make()->modalSubmitActionLabel('Atualizar selecionados')->modalCancelActionLabel('Voltar')
                ->authorize(fn (ListMovements $livewire) => $livewire->canOperateMovement('update'))
                ->before(function (Collection $records): void {
                    foreach ($records as $record) {
                        self::authorize($record, 'update');
                    }
                }),
        ];
    }

    private static function editable(CashMovement $record): bool
    {
        return $record->origin_type === 'manual' && $record->reversal_of_id === null && $record->reversed_at === null && ! $record->hasStatementLinks();
    }

    private static function authorize(CashMovement $record, string $ability, bool $transfer = false): void
    {
        $record->refresh();
        abort_unless((int) $record->company_id === (int) Filament::getTenant()->id, 403);
        Gate::authorize('view', $record);
        $access = app(CompanyAuthorization::class);
        if ($ability === 'delete' && in_array($record->origin_type, [AccountPayableInstallmentPayment::class, AccountReceivableInstallmentPayment::class], true) && $record->origin_id !== null) {
            $payable = $record->origin_type === AccountPayableInstallmentPayment::class;
            $title = $payable ? $record->payablePayment?->installment?->accountPayable : $record->receivablePayment?->installment?->accountReceivable;
            abort_unless($title && $access->allowsRecord(Filament::auth()->user(), Filament::getTenant(), ($payable ? 'account_payables' : 'account_receivables').'.reverse', $title), 403);
        }
        if ($transfer) {
            abort_unless($record->isTransfer(), 403);
        }
        foreach ($transfer ? $record->transferGroupMovements() : [$record] as $item) {
            abort_unless($access->allowsRecord(Filament::auth()->user(), Filament::getTenant(), 'cash_movements.'.$ability, $item), 403);
        }
    }
}
