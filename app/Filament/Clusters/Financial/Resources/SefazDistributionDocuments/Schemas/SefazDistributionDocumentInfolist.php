<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Schemas;

use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use App\Models\SefazDistributionDocument;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class SefazDistributionDocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Documento detectado')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('issuer_name')
                            ->label('Emitente')
                            ->beforeContent(Action::make('open_partner')
                                ->hiddenLabel()
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->tooltip('Acessar cadastro do parceiro')
                                ->visible(fn (SefazDistributionDocument $record): bool => $record->companyPartner !== null)
                                ->url(fn (SefazDistributionDocument $record): ?string => $record->companyPartner
                                    ? CompanyPartnerResource::getUrl('edit', ['record' => $record->companyPartner->id])
                                    : null
                                )
                                ->openUrlInNewTab()
                            )
                            ->columnSpan(2),
                        TextEntry::make('document_key')
                            ->label('Chave')
                            ->columnSpan(2),
                        TextEntry::make('document_number')
                            ->label('Número NF'),
                        TextEntry::make('document_series')
                            ->label('Série'),
                        TextEntry::make('status')
                            ->label('Fluxo DF-e')
                            ->badge()
                            ->formatStateUsing(fn (Status $state): string => $state->description())
                            ->color(fn (Status $state): string => $state->color()),
                        TextEntry::make('import_status')
                            ->label('Importação')
                            ->badge()
                            ->formatStateUsing(fn (ImportStatus $state): string => $state->description())
                            ->color(fn (ImportStatus $state): string => $state->color()),
                        TextEntry::make('manifestation_status')
                            ->label('Manifestação')
                            ->badge()
                            ->formatStateUsing(fn (ManifestationStatus $state): string => $state->description())
                            ->color(fn (ManifestationStatus $state): string => $state->color()),
                        TextEntry::make('partner.name')
                            ->label('Fornecedor vinculado')
                            ->placeholder('Não vinculado'),
                        TextEntry::make('fiscalDocument.id')
                            ->label('Documento fiscal')
                            ->placeholder('Ainda não importado'),
                        TextEntry::make('accountPayable.id')
                            ->label('Conta a pagar')
                            ->placeholder('Ainda não gerada'),
                        TextEntry::make('issued_at')
                            ->label('Emissão')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('total_amount')
                            ->label('Valor total')
                            ->money('BRL', locale: 'pt_BR'),
                        TextEntry::make('last_action')
                            ->label('Última ação')
                            ->placeholder('-'),
                        TextEntry::make('last_action_at')
                            ->label('Última ação em')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('last_error_message')
                            ->label('Último erro')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(4),
                Section::make('Timeline operacional')
                    ->columnSpanFull()
                    ->persistCollapsed()
                    ->collapsed()
                    ->schema([
                        RepeatableEntry::make('timeline')
                            ->label('')
                            ->columnSpanFull()
                            ->state(function ($record): array {
                                $baseTimeline = collect([
                                    [
                                        'when' => $record->created_at,
                                        'title' => 'Detectado',
                                        'description' => 'Documento encontrado na distribuição DF-e.',
                                        'source' => 'Sistema',
                                    ],
                                    $record->import_ready_at ? [
                                        'when' => $record->import_ready_at,
                                        'title' => 'Pronto para importar',
                                        'description' => 'XML completo disponível para importação.',
                                        'source' => 'Sistema',
                                    ] : null,
                                    $record->imported_at ? [
                                        'when' => $record->imported_at,
                                        'title' => 'Importado',
                                        'description' => 'Documento importado para Nota de Entrada.',
                                        'source' => 'Sistema',
                                    ] : null,
                                    $record->ignored_at ? [
                                        'when' => $record->ignored_at,
                                        'title' => 'Ignorado',
                                        'description' => (string) ($record->ignore_reason ?: 'Documento ignorado manualmente.'),
                                        'source' => optional($record->ignoredBy)->name ?: 'Sistema',
                                    ] : null,
                                ])->filter();

                                $auditTimeline = $record->auditEntries
                                    ->sortByDesc('occurred_at')
                                    ->map(fn ($entry): array => [
                                        'when' => $entry->occurred_at,
                                        'title' => $entry->summary,
                                        'description' => $entry->event,
                                        'source' => $entry->actor_name ?: $entry->source_label,
                                    ]);

                                return $baseTimeline
                                    ->concat($auditTimeline)
                                    ->sortByDesc(fn (array $item) => optional($item['when'])->timestamp ?? 0)
                                    ->values()
                                    ->all();
                            })
                            ->table([
                                TableColumn::make('Quando'),
                                TableColumn::make('Evento'),
                                TableColumn::make('Descrição'),
                                TableColumn::make('Origem'),
                            ])
                            ->schema([
                                TextEntry::make('when')
                                    ->dateTime('d/m/Y H:i:s')
                                    ->placeholder('-'),
                                TextEntry::make('title')
                                    ->weight(FontWeight::SemiBold),
                                TextEntry::make('description')
                                    ->wrap(),
                                TextEntry::make('source')
                                    ->placeholder('Sistema'),
                            ]),
                    ]),
                Section::make('Itens detectados')
                    ->columnSpanFull()
                    ->schema([
                        Livewire::make(\App\Livewire\SefazDistributionDocumentItemsTable::class, fn (SefazDistributionDocument $record): array => [
                            'documentId' => $record->id,
                        ])
                            ->key('sefaz-distribution-document-items-table')
                            ->columnSpanFull(),
                    ])

                    ->collapsed(fn ($record): bool => empty($record->items_json)),
            ]);
    }
}
