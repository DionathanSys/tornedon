<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Tables;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions\SefazDistributionDocumentRecordActions;
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
                    ->tooltip(fn(SefazDistributionDocument $record): string => $record->document_key)
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Chave copiada')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(Status $state): string => $state->description())
                    ->color(fn(Status $state): string => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('import_status')
                    ->label('Importação')
                    ->badge()
                    ->formatStateUsing(fn(ImportStatus $state): string => $state->description())
                    ->color(fn(ImportStatus $state): string => $state->color())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('manifestation_status')
                    ->label('Manifestação')
                    ->badge()
                    ->formatStateUsing(fn(ManifestationStatus $state): string => $state->description())
                    ->color(fn(ManifestationStatus $state): string => $state->color())
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
                TextColumn::make('partner_id')
                    ->label('Fornecedor')
                    ->badge()
                    ->formatStateUsing(fn($state, SefazDistributionDocument $record): string => $record->partner?->name ? 'Vinculado' : 'Pendente')
                    ->color(fn($state, SefazDistributionDocument $record): string => $record->partner?->name ? 'success' : 'warning')
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
                    ->options(collect(Status::cases())->mapWithKeys(fn(Status $status) => [
                        $status->value => $status->description(),
                    ])->all()),
                SelectFilter::make('import_status')
                    ->options(collect(ImportStatus::cases())->mapWithKeys(fn(ImportStatus $status) => [
                        $status->value => $status->description(),
                    ])->all()),
                SelectFilter::make('manifestation_status')
                    ->options(collect(ManifestationStatus::cases())->mapWithKeys(fn(ManifestationStatus $status) => [
                        $status->value => $status->description(),
                    ])->all()),
                Filter::make('ready_to_import')
                    ->label('Prontos para importar')
                    ->query(fn($query) => $query->where('import_status', ImportStatus::READY_TO_IMPORT->value)),
                Filter::make('with_errors')
                    ->label('Com erro')
                    ->query(fn($query) => $query->where(function ($subQuery) {
                        $subQuery
                            ->where('status', Status::ERROR->value)
                            ->orWhere('import_status', ImportStatus::IMPORT_ERROR->value);
                    })),
                Filter::make('ignored')
                    ->label('Ignorados')
                    ->query(fn($query) => $query->where('import_status', ImportStatus::IGNORED->value)),
                Filter::make('without_partner')
                    ->label('Sem fornecedor vinculado')
                    ->query(fn($query) => $query->whereNull('partner_id')),
                Filter::make('without_products')
                    ->label('Sem produtos vinculados')
                    ->query(fn($query) => $query->where(function ($subQuery) {
                        $subQuery
                            ->whereNull('items_json')
                            ->orWhereJsonContains('items_json', [['product_id' => null]]);
                    })),
                Filter::make('imported')
                    ->label('Já importados')
                    ->query(fn($query) => $query->where('import_status', ImportStatus::IMPORTED->value)),
            ])
            ->recordActions([
                ActionGroup::make(SefazDistributionDocumentRecordActions::make())
                    ->icon(Heroicon::Bars3)
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
