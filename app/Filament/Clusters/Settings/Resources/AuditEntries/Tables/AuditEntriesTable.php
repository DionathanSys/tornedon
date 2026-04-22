<?php

namespace App\Filament\Clusters\Settings\Resources\AuditEntries\Tables;

use App\Enum\Audit\AuditSource;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\AuditEntry;
use App\Models\BankStatementImport;
use App\Models\CashMovement;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Data/Hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('actor_name')
                    ->label('Ator')
                    ->placeholder('Sistema')
                    ->searchable(),
                TextColumn::make('source')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->description() ?? (string) $state)
                    ->color(fn ($state): string => $state?->color() ?? 'gray'),
                TextColumn::make('auditable_type')
                    ->label('Entidade')
                    ->formatStateUsing(fn (?string $state): string => AuditEntry::resolveAuditableTypeLabel($state))
                    ->toggleable(),
                TextColumn::make('action')
                    ->label('Ação')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->value())
                    ->sortable(),
                TextColumn::make('summary')
                    ->label('Resumo')
                    ->searchable()
                    ->wrap(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                Filter::make('occurred_between')
                    ->label('Período')
                    ->form([
                        DatePicker::make('from')
                            ->label('De'),
                        DatePicker::make('until')
                            ->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('occurred_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('occurred_at', '<=', $date));
                    }),
                SelectFilter::make('auditable_type')
                    ->label('Entidade')
                    ->options(self::auditableTypeOptions())
                    ->native(false)
                    ->multiple(),
                SelectFilter::make('action')
                    ->label('Ação')
                    ->options(self::actionOptions())
                    ->native(false)
                    ->multiple(),
                SelectFilter::make('source')
                    ->label('Origem')
                    ->options(AuditSource::toSelectArray())
                    ->native(false)
                    ->multiple(),
                SelectFilter::make('actor_user_id')
                    ->label('Usuário')
                    ->relationship('actorUser', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('record')
                    ->label('Documento/ID')
                    ->form([
                        TextInput::make('term')
                            ->label('Documento ou ID'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $term = trim((string) ($data['term'] ?? ''));

                        if ($term === '') {
                            return $query;
                        }

                        return $query->where(function (Builder $builder) use ($term): void {
                            $builder
                                ->where('summary', 'like', "%{$term}%")
                                ->orWhere('event', 'like', "%{$term}%");

                            if (is_numeric($term)) {
                                $builder->orWhere('auditable_id', (int) $term);
                            }
                        });
                    }),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Detalhes')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (AuditEntry $record): string => "Auditoria #{$record->id}")
                    ->modalSubmitAction(false)
                    ->infolist([
                        TextEntry::make('summary')
                            ->label('Resumo')
                            ->weight(FontWeight::Bold)
                            ->columnSpanFull(),
                        TextEntry::make('event')
                            ->label('Evento'),
                        TextEntry::make('action_label')
                            ->label('Ação'),
                        TextEntry::make('entity_label')
                            ->label('Entidade'),
                        TextEntry::make('auditable_id')
                            ->label('Registro'),
                        TextEntry::make('actor_name')
                            ->label('Ator')
                            ->placeholder('Sistema'),
                        TextEntry::make('source_label')
                            ->label('Origem'),
                        TextEntry::make('occurred_at')
                            ->label('Ocorrido em')
                            ->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('before')
                            ->label('Antes')
                            ->formatStateUsing(fn ($state): string => self::formatJson($state))
                            ->html()
                            ->columnSpanFull(),
                        TextEntry::make('after')
                            ->label('Depois')
                            ->formatStateUsing(fn ($state): string => self::formatJson($state))
                            ->html()
                            ->columnSpanFull(),
                        TextEntry::make('diff')
                            ->label('Diferenças')
                            ->formatStateUsing(fn ($state): string => self::formatJson($state))
                            ->html()
                            ->columnSpanFull(),
                        TextEntry::make('metadata')
                            ->label('Metadados')
                            ->formatStateUsing(fn ($state): string => self::formatJson($state))
                            ->html()
                            ->columnSpanFull(),
                    ]),
            ])
            ->toolbarActions([]);
    }

    private static function formatJson(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '-';
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<pre class="text-xs whitespace-pre-wrap">' . e($json ?: '-') . '</pre>';
    }

    /**
     * @return array<string, string>
     */
    private static function auditableTypeOptions(): array
    {
        return [
            'service_order' => 'Ordem de Serviço',
            'requisition' => 'Requisição',
            'production_order' => 'Ordem de Produção',
            Invoice::class => 'Fatura',
            FiscalDocument::class => 'Documento Fiscal',
            CashMovement::class => 'Movimento Financeiro',
            BankStatementImport::class => 'Importação de Extrato',
            AccountPayable::class => 'Conta a Pagar',
            AccountReceivable::class => 'Conta a Receber',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function actionOptions(): array
    {
        return [
            'created' => 'Criado',
            'updated' => 'Atualizado',
            'deleted' => 'Excluído',
            'closed' => 'Encerrado',
            'reopened' => 'Reaberto',
            'canceled' => 'Cancelado',
            'started' => 'Iniciado',
            'sent_to_qc' => 'Enviado para QC',
            'completed' => 'Concluído',
            'returned' => 'Retornado',
            'confirmed' => 'Confirmado',
            'nfe_sent' => 'NF-e enviada',
            'nfe_authorized' => 'NF-e autorizada',
            'nfe_rejected' => 'NF-e rejeitada',
            'nfe_canceled' => 'NF-e cancelada',
            'nfse_sent' => 'NFS-e enviada',
            'nfse_authorized' => 'NFS-e autorizada',
            'nfse_rejected' => 'NFS-e rejeitada',
            'nfse_canceled' => 'NFS-e cancelada',
            'imported' => 'Importado',
            'line_ignored' => 'Linha ignorada',
            'movement_reconciled' => 'Movimento conciliado',
            'manual_movement_created' => 'Movimento manual criado',
            'payment_registered' => 'Pagamento registrado',
            'installment_updated' => 'Parcela atualizada',
            'installment_deleted' => 'Parcela excluída',
            'transfer_created' => 'Transferência criada',
            'transfer_reversed' => 'Transferência estornada',
        ];
    }
}
