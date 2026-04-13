<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource as SalesFiscalDocumentResource;
use App\Filament\Support\Actions\FiscalDocumentRecordActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class FiscalDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'fiscalDocuments';

    protected static ?string $title = 'Documentos Fiscais';

    protected static ?string $modelLabel = 'Documento Fiscal';

    protected static ?string $pluralModelLabel = 'Documentos Fiscais';

    protected static string|BackedEnum|null $icon = Heroicon::DocumentText;

    #[On('invoice-confirmed')]
    public function refreshFiscalDocuments(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('document_number')
            ->columns([
                TextColumn::make('document_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (?DocumentModel $state) => $state?->description() ?? '—')
                    ->color(fn (?DocumentModel $state) => match ($state) {
                        DocumentModel::NFSE => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('document_number')
                    ->label('Número')
                    ->placeholder('-')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('document_series')
                    ->label('Série')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('issued_at')
                    ->label('Emissão')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('fiscal_status')
                    ->label('Status Fiscal')
                    ->badge()
                    ->state(fn ($record): ?NfeStatus => $record->isNfse() ? $record->nfse_status : $record->nfe_status)
                    ->formatStateUsing(fn (?NfeStatus $state) => $state?->description() ?? 'Não enviado')
                    ->color(fn (?NfeStatus $state) => $state?->color() ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?Status $state) => $state?->description() ?? '-')
                    ->color(fn (?Status $state) => $state?->color() ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('document_key')
                    ->label('Chave')
                    ->limit(20)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->poll('10s')
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('open')
                    ->label('Abrir')
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(
                        fn ($record): string => SalesFiscalDocumentResource::getUrl('edit', ['record' => $record]),
                        shouldOpenInNewTab: true
                    ),
                ActionGroup::make(FiscalDocumentRecordActions::make()),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhum documento fiscal vinculado')
            ->emptyStateDescription('Os documentos fiscais gerados para esta fatura aparecerao aqui.');
    }
}
