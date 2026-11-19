<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Tables;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions\SefazDistributionDocumentRecordActions;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\PayableSummary;
use App\Models\SefazDistributionDocument;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\ColumnManagerLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class SefazDistributionDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_number')
                    ->label('Número NF')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('document_series')
                    ->label('Série')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('issuer_name')
                    ->label('Emitente')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('document_key')
                    ->label('Chave')
                    ->tooltip(fn (SefazDistributionDocument $record): string => $record->document_key)
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Chave copiada')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (Status $state): string => $state->description())
                    ->color(fn (Status $state): string => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('import_status')
                    ->label('Importação')
                    ->badge()
                    ->formatStateUsing(fn (ImportStatus $state): string => $state->description())
                    ->color(fn (ImportStatus $state): string => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('manifestation_status')
                    ->label('Manifestação')
                    ->badge()
                    ->formatStateUsing(fn (ManifestationStatus $state): string => $state->description())
                    ->color(fn (ManifestationStatus $state): string => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                IconColumn::make('full_xml_available')
                    ->label('XML completo')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('total_amount')
                    ->label('Valor total')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('issued_at')
                    ->label('Emissão')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('import_ready_at')
                    ->label('Pronto p/ importar')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('fiscalDocument.id')
                    ->label('Nota entrada')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('accountPayable.id')
                    ->label('Conta pagar')
                    ->badge()
                    ->placeholder('Não gerada')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('financial_status')->label('Situação financeira')->badge()
                    ->state(fn (SefazDistributionDocument $record): string => PayableSummary::status($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Quitado' => 'success', 'Em aberto', 'Parcialmente pago' => 'warning', default => 'gray'
                    }),
                TextColumn::make('financial_balance')->label('Saldo financeiro')->money('BRL')
                    ->state(fn (SefazDistributionDocument $record): float => PayableSummary::balance($record)),
                TextColumn::make('next_financial_due_date')->label('Próximo vencimento')->date('d/m/Y')->placeholder('-')
                    ->state(fn (SefazDistributionDocument $record) => PayableSummary::nextDueDate($record)),
                TextColumn::make('partner_id')
                    ->label('Fornecedor')
                    ->badge()
                    ->formatStateUsing(fn ($state, SefazDistributionDocument $record): string => $record->partner?->name ? 'Vinculado' : 'Pendente')
                    ->color(fn ($state, SefazDistributionDocument $record): string => $record->partner?->name ? 'success' : 'warning')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('last_seen_at')
                    ->label('Última detecção')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('last_action')
                    ->label('Última ação')
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(Status::cases())->mapWithKeys(fn (Status $status) => [
                        $status->value => $status->description(),
                    ])->all()),
                SelectFilter::make('import_status')
                    ->options(collect(ImportStatus::cases())->mapWithKeys(fn (ImportStatus $status) => [
                        $status->value => $status->description(),
                    ])->all()),
                SelectFilter::make('manifestation_status')
                    ->options(collect(ManifestationStatus::cases())->mapWithKeys(fn (ManifestationStatus $status) => [
                        $status->value => $status->description(),
                    ])->all()),
                DateRangeFilter::make('issued_at')
                    ->label('Data de Emissão')
                    ->autoApply()
                    ->firstDayOfWeek(0)
                    ->icon('heroicon-o-x-mark')
                    ->alwaysShowCalendar(),
                Filter::make('ready_to_import')
                    ->label('Prontos para importar')
                    ->query(fn ($query) => $query->where('import_status', ImportStatus::READY_TO_IMPORT->value)),
                Filter::make('without_financial')->label('Sem financeiro')
                    ->query(fn ($query) => $query->whereNull('account_payable_id')->whereDoesntHave('fiscalDocument.accountPayables')),
                Filter::make('pending_financial_links')->label('Com vínculo financeiro pendente')
                    ->query(fn ($query) => $query->where(function ($query) {
                        $query->where(function ($query) {
                            $query->whereNull('account_payable_id')->whereHas('fiscalDocument.accountPayables');
                        })->orWhere(function ($query) {
                            $query->whereNotNull('fiscal_document_id')->whereHas('accountPayable', fn ($query) => $query->whereNull('fiscal_document_id'));
                        })->orWhere(function ($query) {
                            $query->whereNull('fiscal_document_id')->whereNotNull('account_payable_id')->whereExists(function ($query) {
                                $query->selectRaw('1')->from('fiscal_documents')
                                    ->whereColumn('fiscal_documents.company_id', 'sefaz_distribution_documents.company_id')
                                    ->whereColumn('fiscal_documents.document_key', 'sefaz_distribution_documents.document_key');
                            });
                        });
                    })),
                Filter::make('with_errors')
                    ->label('Com erro')
                    ->query(fn ($query) => $query->where(function ($subQuery) {
                        $subQuery
                            ->where('status', Status::ERROR->value)
                            ->orWhere('import_status', ImportStatus::IMPORT_ERROR->value);
                    })),
                Filter::make('ignored')
                    ->label('Ignorados')
                    ->query(fn ($query) => $query->where('import_status', ImportStatus::IGNORED->value)),
                Filter::make('without_partner')
                    ->label('Sem fornecedor vinculado')
                    ->query(fn ($query) => $query->whereNull('partner_id')),
                Filter::make('without_products')
                    ->label('Sem produtos vinculados')
                    ->query(fn ($query) => $query->where(function ($subQuery) {
                        $subQuery
                            ->whereNull('items_json')
                            ->orWhereJsonContains('items_json', [['product_id' => null]]);
                    })),
                Filter::make('imported')
                    ->label('Já importados')
                    ->query(fn ($query) => $query->where('import_status', ImportStatus::IMPORTED->value)),
            ])
            ->recordActions([
                ActionGroup::make(SefazDistributionDocumentRecordActions::make())
                    ->icon(Heroicon::Bars3),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label('Excluir notas'),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->reorderableColumns()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->columnManagerLayout(ColumnManagerLayout::Modal)
            ->columnManagerColumns(2);
    }
}
