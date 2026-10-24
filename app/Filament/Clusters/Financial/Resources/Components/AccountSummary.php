<?php

namespace App\Filament\Clusters\Financial\Resources\Components;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class AccountSummary
{
    public static function query(Builder $query): Builder
    {
        return $query->withSum(['installments as considered_balance' => fn (Builder $q) => $q->open()], 'balance_amount')
            ->withMin(['installments as next_open_due_date' => fn (Builder $q) => $q->open()], 'due_date')
            ->withCount(['installments as ignored_count' => fn (Builder $q) => $q->whereNotNull('ignored_at')]);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conta')->columns(3)->columnSpanFull()->schema([
                TextEntry::make('counterparty_label')
                    ->label('Parceiro')
                    ->helperText(function (AccountPayable|AccountReceivable $record): ?string {
                        $partner = $record instanceof AccountPayable ? $record->supplier : $record->customer;

                        return filled($partner?->document_number)
                            ? ($partner->document_type ?: 'CPF/CNPJ').': '.$partner->document_number
                            : null;
                    }),
                TextEntry::make('document_number')->label('Documento')->placeholder('-'),
                TextEntry::make('status')->label('Situação financeira')->formatStateUsing(fn ($state) => $state?->description())->badge(),
                TextEntry::make('consideration')->label('Consideração no módulo')->state(fn ($record) => $record->installments()->whereNotNull('ignored_at')->exists()
                    ? ($record->installments()->open()->exists() ? 'Parcialmente ignorada' : 'Ignorada') : 'Considerada')->badge(),
                TextEntry::make('description')->label('Descrição')->placeholder('-')->columnSpanFull(),
                TextEntry::make('due_amount')->label('Valor atualizado')->money('BRL'),
                TextEntry::make('paid_amount')->label('Liquidado')->money('BRL'),
                TextEntry::make('open_balance')->label('Saldo considerado')->state(fn ($record) => $record->installments()->considered()->where('status', '!=', 'cancelled')->get()->sum('balance_amount'))->money('BRL'),
                TextEntry::make('next_due')->label('Próximo vencimento em aberto')->state(fn ($record) => $record->installments()->open()->min('due_date'))->date('d/m/Y')->placeholder('-'),
            ]),
        ]);
    }
}
