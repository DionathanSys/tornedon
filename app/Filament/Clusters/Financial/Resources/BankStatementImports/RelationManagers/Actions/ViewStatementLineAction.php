<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Models\BankStatementLine;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

final class ViewStatementLineAction
{
    public static function make(): Action
    {
        return Action::make('view_statement_line')
            ->label('Detalhes do lançamento')
            ->authorize('view')
            ->modalHeading(fn (BankStatementLine $record): string => 'Detalhes do lançamento #'.$record->id)
            ->modalWidth('5xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->schema([
                Section::make('Dados bancários')->columns(3)->schema([
                    TextEntry::make('metadata.raw.name')->label('Parte informada pelo banco (NAME)')->placeholder('Não informado no OFX')->copyable()->columnSpanFull(),
                    TextEntry::make('metadata.raw.memo')->label('Histórico original (MEMO)')->placeholder('Não informado no OFX')->copyable()->columnSpanFull(),
                    TextEntry::make('description')->label('Descrição importada')->columnSpanFull(),
                    TextEntry::make('transaction_date')->label('Data da transação')->date('d/m/Y'),
                    TextEntry::make('amount')->label('Valor')->money('BRL'),
                    TextEntry::make('metadata.direction')->label('Tipo')->formatStateUsing(fn (?string $state): string => match ($state) {
                        'inflow' => 'Entrada', 'outflow' => 'Saída', default => 'Não informado',
                    }),
                    TextEntry::make('metadata.raw.trntype')->label('Tipo original (TRNTYPE)')->placeholder('Não informado no OFX'),
                    TextEntry::make('metadata.raw.dtposted')->label('Data original (DTPOSTED)')->placeholder('Não informado no OFX'),
                    TextEntry::make('metadata.raw.trnamt')->label('Valor original (TRNAMT)')->placeholder('Não informado no OFX'),
                    TextEntry::make('external_id')->label('Identificador da transação (FITID)')->copyable()->placeholder('Não informado')->columnSpan(2),
                    TextEntry::make('document_number')->label('Documento / referência')->copyable()->placeholder('Não informado'),
                ]),
                Section::make('Importação e conciliação')->columns(3)->schema([
                    TextEntry::make('import.file_name')->label('Arquivo OFX')->columnSpan(2),
                    TextEntry::make('financialAccount.name')->label('Conta financeira'),
                    TextEntry::make('reconciliation_status')->label('Situação')->badge()->formatStateUsing(fn ($state) => $state?->description() ?? 'Não informada'),
                    TextEntry::make('reconciled_at')->label('Conciliado em')->dateTime('d/m/Y H:i')->placeholder('Não conciliado'),
                    TextEntry::make('needs_review_at')->label('Revisão solicitada em')->dateTime('d/m/Y H:i')->placeholder('Sem revisão'),
                    TextEntry::make('review_reason')->label('Motivo da revisão')->placeholder('Sem revisão')->columnSpanFull(),
                    TextEntry::make('linked_movements')->label('Movimentos vinculados')->state(fn (BankStatementLine $record): string => $record->bank_reconciliation_group_id
                        ? $record->cashMovements->map(fn ($movement) => '#'.$movement->id.' — '.$movement->description)->implode('; ')
                        : ($record->cashMovement ? '#'.$record->cashMovement->id.' — '.$record->cashMovement->description : 'Nenhum'))->columnSpanFull(),
                ]),
                Section::make('Dados técnicos gravados')->collapsible()->collapsed()->columns(2)->schema([
                    TextEntry::make('id')->label('ID do registro'),
                    TextEntry::make('bank_statement_import_id')->label('ID da importação'),
                    TextEntry::make('created_at')->label('Criado em')->dateTime('d/m/Y H:i:s'),
                    TextEntry::make('updated_at')->label('Atualizado em')->dateTime('d/m/Y H:i:s'),
                    TextEntry::make('transaction_key')->label('Chave da transação')->copyable()->columnSpanFull(),
                    TextEntry::make('metadata_snapshot')->label('Metadados armazenados (JSON)')->state(fn (BankStatementLine $record): string => json_encode($record->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))
                        ->extraAttributes(['class' => 'whitespace-pre-wrap break-words font-mono text-xs'])->columnSpanFull(),
                ]),
            ]);
    }
}
