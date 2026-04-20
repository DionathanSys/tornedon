<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Tables;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Support\Actions\FiscalDocumentRecordActions;
use App\Models\FiscalDocument;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

class FiscalDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn(?DocumentModel $state) => $state?->description() ?? '—')
                    ->color(fn(?DocumentModel $state) => match ($state) {
                        DocumentModel::NFSE => 'info',
                        default => 'gray',
                    })
                    ->sortable()
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('document_number')
                    ->label('Número')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('document_series')
                    ->label('Série')
                    ->sortable()
                    ->placeholder('-')
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('rps_series')
                    ->label('RPS Série')
                    ->sortable()
                    ->placeholder('-')
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->lineClamp(2)
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('operation_nature')
                    ->label('Natureza')
                    ->formatStateUsing(fn(OperationNature $state) => $state->description())
                    ->limit(25)
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Emissão')
                    ->date('d/m/Y')
                    ->sortable()
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('fiscal_status')
                    ->label('Status Fiscal')
                    ->badge()
                    ->state(
                        fn(FiscalDocument $record): ?NfeStatus => $record->isNfse()
                            ? $record->nfse_status
                            : $record->nfe_status
                    )
                    ->formatStateUsing(fn(?NfeStatus $state) => $state?->description() ?? 'Não enviado')
                    ->color(fn(?NfeStatus $state) => $state?->color() ?? 'gray')
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(?Status $state) => $state?->description() ?? '-')
                    ->color(fn(?Status $state) => $state?->color() ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('nfe_protocolo')
                    ->label('Protocolo')
                    ->searchable()
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('document_key')
                    ->label('Chave de Acesso')
                    ->limit(20)
                    ->searchable()
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label('Confirmado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('pending')
                    ->label('Pendente')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('document_type')
                    ->label('Tipo de Documento')
                    ->options(DocumentModel::toSelectArray()),

                Tables\Filters\SelectFilter::make('nfe_status')
                    ->label('Status NF-e')
                    ->options(array_merge(
                        ['null' => 'Não enviado'],
                        NfeStatus::toSelectArray()
                    )),

                Tables\Filters\SelectFilter::make('nfse_status')
                    ->label('Status NFS-e')
                    ->options(array_merge(
                        ['null' => 'Não enviada'],
                        NfeStatus::toSelectArray()
                    )),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray()),
            ])
            ->reorderableColumns()
            ->recordActions([
                ActionGroup::make(
                    FiscalDocumentRecordActions::make(includeEditAction: true)
                )->size(Size::ExtraSmall)->icon(Heroicon::Bars3),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Documento Fiscal')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
