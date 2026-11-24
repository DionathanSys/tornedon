<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Schemas;

use App\Filament\Bpo\Resources\AccountPayableResource as BpoAccountPayableResource;
use App\Filament\Clusters\Financial\Actions\IgnoreFinancialAction;
use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\DeleteInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\EditInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\RegisterInstallmentPaymentAction;
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\FiscalDocument;
use App\Services\Access\CompanyAuthorization;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;

final class LinkedAccountPayablesSection
{
    public static function make(): Section
    {
        return Section::make('Contas a pagar da nota')
            ->description('Resumo financeiro e parcelas vinculadas a esta nota de entrada.')
            ->columnSpanFull()
            ->visibleOn('edit')
            ->hidden(fn (): bool => ! AccountPayableResource::canViewAny())
            ->schema([
                RepeatableEntry::make('linked_account_payables')
                    ->hiddenLabel()
                    ->state(fn (?FiscalDocument $record) => $record?->accountPayables()
                        ->where('company_id', Filament::getTenant()->id)
                        ->with(['installments' => fn (HasMany $query) => $query
                            ->where('company_id', Filament::getTenant()->id)
                            ->orderBy('sequence_number')])
                        ->orderBy('id')
                        ->get()
                        ->filter(fn (AccountPayable $account): bool => AccountPayableResource::canView($account))
                        ->values() ?? collect())
                    ->placeholder('Nenhuma conta a pagar vinculada a esta nota.')
                    ->columns(['sm' => 2, 'lg' => 4])
                    ->schema([
                        TextEntry::make('id')->label('Conta a pagar'),
                        Actions::make([
                            Action::make('open_account_payable')
                                ->label('Abrir conta a pagar')
                                ->link()
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->url(fn (AccountPayable $record): string => (Filament::getCurrentPanel()?->getId() === 'bpo'
                                    ? BpoAccountPayableResource::class
                                    : AccountPayableResource::class)::getUrl('view', ['record' => $record])),
                        ])->key('linked_account_actions'),
                        TextEntry::make('description')->label('Descrição')->placeholder('-'),
                        TextEntry::make('status')
                            ->label('Situação financeira')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                            ->color(fn ($state) => $state?->color() ?? 'gray'),
                        TextEntry::make('payment_method')
                            ->label('Forma de pagamento')
                            ->formatStateUsing(fn ($state) => $state?->description() ?? '-'),
                        TextEntry::make('due_amount')->label('Valor atualizado')->money('BRL'),
                        TextEntry::make('paid_amount')->label('Valor pago')->money('BRL'),
                        TextEntry::make('open_balance')
                            ->label('Saldo considerado')
                            ->state(fn (AccountPayable $record) => $record->installments
                                ->whereNull('ignored_at')
                                ->reject(fn ($installment): bool => $installment->status?->value === 'cancelled')
                                ->sum('balance_amount'))
                            ->money('BRL'),
                        TextEntry::make('due_date')->label('Vencimento')->date('d/m/Y')->placeholder('-'),
                        RepeatableEntry::make('installments')
                            ->label('Parcelas')
                            ->columnSpanFull()
                            ->placeholder('Nenhuma parcela cadastrada.')
                            ->table([
                                TableColumn::make('Parcela'),
                                TableColumn::make('Vencimento'),
                                TableColumn::make('Código do boleto'),
                                TableColumn::make('Valor atualizado'),
                                TableColumn::make('Valor pago'),
                                TableColumn::make('Saldo'),
                                TableColumn::make('Situação'),
                                TableColumn::make('Data do pagamento'),
                                TableColumn::make('Consideração no módulo'),
                                TableColumn::make('Ações'),
                            ])
                            ->schema([
                                TextEntry::make('sequence_number'),
                                TextEntry::make('due_date')->date('d/m/Y'),
                                TextEntry::make('bank_slip_code')->placeholder('-')->copyable(),
                                TextEntry::make('due_amount')->money('BRL'),
                                TextEntry::make('paid_amount')->money('BRL'),
                                TextEntry::make('balance_amount')->money('BRL'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                                    ->color(fn ($state) => $state?->color() ?? 'gray'),
                                TextEntry::make('paid_date')->date('d/m/Y')->placeholder('-'),
                                TextEntry::make('consideration')
                                    ->state(fn ($record): string => $record->ignored_at ? 'Ignorada' : 'Considerada'),
                                Actions::make([
                                    ActionGroup::make([
                                        IgnoreFinancialAction::make()
                                            ->authorize(fn (AccountPayableInstallment $record): bool => self::allows($record, 'change_status_without_movement')),
                                        IgnoreFinancialAction::make(restore: true)
                                            ->authorize(fn (AccountPayableInstallment $record): bool => self::allows($record, 'change_status_without_movement')),
                                        RegisterInstallmentPaymentAction::make()
                                            ->authorize(fn (AccountPayableInstallment $record): bool => self::allows($record, 'settle')),
                                        EditInstallmentAction::make()
                                            ->authorize(fn (AccountPayableInstallment $record): bool => self::allows($record, 'update')),
                                        DeleteInstallmentAction::make()
                                            ->authorize(fn (AccountPayableInstallment $record): bool => self::allows($record, 'update')),
                                    ])->icon('heroicon-o-bars-3')->tooltip('Ações da parcela'),
                                ])->key('installment_payment_actions'),
                            ]),
                    ]),
            ]);
    }

    private static function allows(AccountPayableInstallment $record, string $operation): bool
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();
        if (! $tenant || ! $user || (int) $record->company_id !== (int) $tenant->id
            || (int) $record->accountPayable->company_id !== (int) $tenant->id
            || ! Gate::allows('view', $record->accountPayable)) {
            return false;
        }

        $access = app(CompanyAuthorization::class);

        return ! $access->requiresRoleAuthorization($user, $tenant, Filament::getCurrentPanel()?->getId())
            || $access->allowsRecord($user, $tenant, 'account_payables.'.$operation, $record->accountPayable);
    }
}
