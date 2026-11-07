<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Schemas;

use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Models\AccountPayable;
use App\Models\FiscalDocument;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
                    ->state(fn (FiscalDocument $record) => $record->accountPayables()
                        ->where('company_id', Filament::getTenant()->id)
                        ->with(['installments' => fn (HasMany $query) => $query
                            ->where('company_id', Filament::getTenant()->id)
                            ->orderBy('sequence_number')])
                        ->orderBy('id')
                        ->get()
                        ->filter(fn (AccountPayable $account): bool => AccountPayableResource::canView($account))
                        ->values())
                    ->placeholder('Nenhuma conta a pagar vinculada a esta nota.')
                    ->columns(['sm' => 2, 'lg' => 4])
                    ->schema([
                        TextEntry::make('id')->label('Conta a pagar'),
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
                                TableColumn::make('Valor atualizado'),
                                TableColumn::make('Valor pago'),
                                TableColumn::make('Saldo'),
                                TableColumn::make('Situação'),
                                TableColumn::make('Data do pagamento'),
                                TableColumn::make('Consideração no módulo'),
                            ])
                            ->schema([
                                TextEntry::make('sequence_number'),
                                TextEntry::make('due_date')->date('d/m/Y'),
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
                            ]),
                    ]),
            ]);
    }
}
