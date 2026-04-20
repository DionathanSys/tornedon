<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Tables;

use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Jobs\ManifestSefazDistributionDocumentJob;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
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
                    ->sortable(),
                TextColumn::make('document_series')
                    ->label('Série')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('issuer_name')
                    ->label('Emitente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('document_key')
                    ->label('Chave')
                    ->tooltip(fn(SefazDistributionDocument $record): string => $record->document_key)
                    ->searchable()
                    ->limit(20)
                    ->copyable()
                    ->copyMessage('Chave copiada')
                    ->copyMessageDuration(1500)
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(Status $state): string => $state->description())
                    ->color(fn(Status $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('manifestation_status')
                    ->label('Manifestação')
                    ->badge()
                    ->formatStateUsing(fn(ManifestationStatus $state): string => $state->description())
                    ->color(fn(ManifestationStatus $state): string => $state->color())
                    ->sortable(),
                IconColumn::make('full_xml_available')
                    ->label('XML completo')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Valor total')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('issued_at')
                    ->label('Emissão')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('import_ready_at')
                    ->label('Pronto p/ importar')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_seen_at')
                    ->label('Última detecção')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(Status::cases())->mapWithKeys(fn(Status $status) => [
                        $status->value => $status->description(),
                    ])->all()),
                SelectFilter::make('manifestation_status')
                    ->options(collect(ManifestationStatus::cases())->mapWithKeys(fn(ManifestationStatus $status) => [
                        $status->value => $status->description(),
                    ])->all()),
            ])
            ->recordActions([
                Action::make('retryManifestation')
                    ->label('Tentar manifestação novamente')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn(SefazDistributionDocument $record): bool => ! $record->full_xml_available
                        && in_array($record->manifestation_status, [
                            ManifestationStatus::FAILED,
                            ManifestationStatus::REJECTED,
                        ], true))
                    ->action(function (SefazDistributionDocument $record): void {
                        app(SefazDistributionDocumentService::class)->prepareManualManifestationRetry($record);
                        ManifestSefazDistributionDocumentJob::dispatch($record->id, 1);

                        Notification::make()
                            ->title('Manifestação reenfileirada')
                            ->body('A tentativa manual de manifestação foi enviada para a fila.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }
}
