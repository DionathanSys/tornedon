<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Enum\Product\Unit;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\ItemsRelationManager;
use App\Models\FiscalDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Support\Icons\Heroicon;

class FiscalDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make('FiscalDocumentTabs')
                    ->columnSpanFull()
                    ->persistTab(true)
                    ->tabs([
                        Tab::make('Principal')
                            ->columns(['md' => 6, 'lg' => 12])
                            ->columnSpanFull()
                            ->schema([
                                Section::make('Identificação')
                                    ->columnSpanFull()
                                    ->columns(['md' => 6, 'lg' => 12])
                                    ->schema([
                                        SelectPartner::make('customer_id')
                                            ->label('Cliente / Tomador')
                                            ->columnSpan(['md' => 4, 'lg' => 6])
                                            ->disabledOn('edit'),
                                        Select::make('document_type')
                                            ->label('Tipo de Documento')
                                            ->options(DocumentModel::toSelectArray())
                                            ->default(DocumentModel::NFE->value)
                                            ->required()
                                            ->native(false)
                                            ->live()
                                            ->disabledOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 3]),
                                        Select::make('nfse_model')
                                            ->label('Modelo NFS-e')
                                            ->options(NfseModel::toSelectArray())
                                            ->disabledOn('edit')
                                            ->native(false)
                                            ->columnSpan(['md' => 1, 'lg' => 3]),
                                        TextEntry::make('emission_requested_at')
                                            ->label('Solicitação de Emissão')
                                            ->dateTime('d/m/Y H:i:s')
                                            ->visibleOn('edit')
                                            ->placeholder(' - ')
                                            ->columnStart(1)
                                            ->columnSpan(['md' => 1, 'lg' => 3]),
                                        TextEntry::make('emission_attempted_at')
                                            ->label('Última Tentativa')
                                            ->dateTime('d/m/Y H:i:s')
                                            ->placeholder(' - ')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 3]),
                                        TextEntry::make('confirmed_at')
                                            ->label('Dt. Confirmação')
                                            ->dateTime('d/m/Y H:i:s')
                                            ->placeholder(' - ')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 3]),
                                        TextEntry::make('updated_at')
                                            ->label('Últ. Alteração')
                                            ->dateTime('d/m/Y H:i:s')
                                            ->placeholder(' - ')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 3]),
                                    ])
                                    ->columns(['md' => 2])
                                    ->collapsible(),

                                Section::make('Dados da NFS-e')
                                    ->columnSpanFull()
                                    ->columns(['md' => 6, 'lg' => 12])
                                    ->schema([
                                        TextEntry::make('status')
                                            ->label('Status')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->formatStateUsing(fn (Status $state): ?string => $state->description())
                                            ->badge()
                                            ->color(fn (Status $state) => $state->color()),
                                        TextEntry::make('nfse_status')
                                            ->label('Status NFS-e')
                                            ->visibleOn('edit')
                                            ->visible(fn ($record, $operation): bool => $record->isNfse() && $operation === 'edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn ($record): string => $record->nfse_status ? $record->nfse_status->description() : 'N/D')
                                            ->badge()
                                            ->color(fn ($record): string => $record->nfse_status ? $record->nfse_status->color() : 'gray'),
                                        TextEntry::make('document_number')
                                            ->label('Nº Documento')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn ($record): string => $record->document_number ? $record->document_number : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('rps_number')
                                            ->label('Nº RPS')
                                            ->visibleOn('edit')
                                            ->visible(fn ($record): bool => $record->isNfse())
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn ($record): string => $record->rps_number ? $record->rps_number : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('document_series')
                                            ->label('Série')
                                            ->visibleOn('edit')
                                            ->visible(fn ($record): bool => $record->isNfe())
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn ($record): string => $record->document_series ? $record->document_series : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('rps_series')
                                            ->label('Série')
                                            ->visibleOn('edit')
                                            ->visible(fn ($record): bool => $record->isNfse())
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn ($record): string => $record->rps_series ? $record->rps_series : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('document_key')
                                            ->label('Chave Doc.')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->state(fn ($record): string => $record->document_key ? $record->document_key : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('invoice_id')
                                            ->label('Fatura Vinculada')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->visible(fn ($state): bool => $state !== null)
                                            ->formatStateUsing(fn ($record, $state): ?string => $state ? $record->invoice->invoice_number : 'Sem fatura vinculada')
                                            ->url(fn ($record): ?string => $record->invoice ? InvoiceResource::getUrl('edit', ['record' => $record->invoice]) : null, true),
                                    ])
                                    ->columns(['md' => 2])
                                    ->collapsible()
                                    ->visible(fn (Get $get): bool => $get('document_type') === DocumentModel::NFSE->value),

                                Section::make('Dados da NF-e')
                                    ->columnSpanFull()
                                    ->columns(['md' => 6, 'lg' => 12])
                                    ->schema([
                                        TextEntry::make('status')
                                            ->label('Status')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->formatStateUsing(fn (Status $state): ?string => $state->description())
                                            ->badge()
                                            ->color(fn (Status $state) => $state->color()),
                                        TextEntry::make('nfe_status')
                                            ->label('Status NF-e')
                                            ->visibleOn('edit')
                                            ->visible(fn ($record, $operation): bool => $record->isNfe() && $operation === 'edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn ($record): string => $record->nfe_status ? $record->nfe_status->description() : 'N/D')
                                            ->badge()
                                            ->color(fn ($record): string => $record->nfe_status ? $record->nfe_status->color() : 'gray'),
                                        TextEntry::make('document_number')
                                            ->label('Nº Documento')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn ($record): string => $record->document_number ? $record->document_number : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('document_series')
                                            ->label('Série')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn ($record): string => $record->document_series ? $record->document_series : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('document_key')
                                            ->label('Chave Doc.')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->state(fn ($record): string => $record->document_key ? $record->document_key : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('invoice_id')
                                            ->label('Fatura Vinculada')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->visible(fn ($state): bool => $state !== null)
                                            ->formatStateUsing(fn ($record, $state): ?string => $state ? $record->invoice->invoice_number : 'Sem fatura vinculada')
                                            ->url(fn ($record): ?string => $record->invoice ? InvoiceResource::getUrl('edit', ['record' => $record->invoice]) : null, true),
                                        TextEntry::make('operation_nature')
                                            ->label('Natureza da Operação')
                                            ->columnSpan(['md' => 2, 'lg' => 2]),

                                        TextEntry::make('issued_at')
                                            ->label('Data de Emissão')
                                            ->visibleOn('edit')
                                            ->formatStateUsing(fn ($state): ?string => $state ? $state->format('d/m/Y') : 'N/D')
                                            ->default(now())
                                            ->columnSpan(['md' => 2, 'lg' => 2]),

                                        TextEntry::make('movement_at')
                                            ->label('Data Entrada/Saída')
                                            ->visible(false)
                                            ->formatStateUsing(fn ($state): ?string => $state ? $state->format('d/m/Y') : 'N/D')
                                            ->default(now())
                                            ->columnSpan(['md' => 2, 'lg' => 2]),

                                        TextEntry::make('operation_type')
                                            ->label('Tipo de Operação')
                                            ->formatStateUsing(fn ($state): ?string => $state ? $state->description() : 'N/D')
                                            ->default(OperationType::SAIDA->value)
                                            ->columnSpan(['md' => 2, 'lg' => 2]),

                                        Select::make('issue_purpose')
                                            ->label('Finalidade de Emissão')
                                            ->options(IssuePurpose::toSelectArray())
                                            ->default(IssuePurpose::NORMAL->value)
                                            ->required()
                                            ->native(false)
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->columnStart(1),

                                        Select::make('buyer_presence_indicator')
                                            ->label('Indicador de Presença')
                                            ->options(BuyerPresenceIndicator::toSelectArray())
                                            ->default(BuyerPresenceIndicator::OUTROS->value)
                                            ->native(false)
                                            ->columnSpan(['md' => 2, 'lg' => 2]),

                                        Toggle::make('is_final_consumer')
                                            ->label('Consumidor Final')
                                            ->inline(false)
                                            ->default(true)
                                            ->columnSpan(['md' => 2, 'lg' => 2]),
                                    ])
                                    ->columns(['md' => 2])
                                    ->collapsible()
                                    ->visible(fn (Get $get): bool => $get('document_type') !== DocumentModel::NFSE->value),

                                Livewire::make(ItemsRelationManager::class, fn (FiscalDocument $record) => [
                                    'ownerRecord' => $record,
                                    'pageClass' => EditFiscalDocument::class,
                                ])
                                    ->key('items-relation-manager')
                                    ->columnSpanFull()
                                    ->visibleOn([Operation::Edit]),

                                Section::make('Frete')
                                    ->columnSpanFull()
                                    ->collapsed()
                                    ->collapsible()
                                    ->columns(['md' => 6, 'lg' => 12])
                                    ->schema([
                                        Select::make('freight_data.modalidade_frete')
                                            ->label('Modalidade do Frete')
                                            ->options(FreightModality::toSelectArray())
                                            ->default(FreightModality::SEM_FRETE->value)
                                            ->native(false)
                                            ->columnSpan(['md' => 3]),

                                        Section::make('Transportador')
                                            ->columnSpanFull()
                                            ->columns(['md' => 6, 'lg' => 12])
                                            ->schema([
                                                SelectPartner::make('freight_data.transportador.id', 'supplier')
                                                    ->label('Transportador')
                                                    ->columnSpanFull()
                                                    ->required(false),
                                            ])
                                            ->collapsible(),

                                        Section::make('ICMS Retido')
                                            ->columnSpanFull()
                                            ->columns(['md' => 6, 'lg' => 12])
                                            ->schema([
                                                TextInput::make('freight_data.icms_retido.valor_servico')
                                                    ->label('Valor do Serviço')
                                                    ->numeric()
                                                    ->inputMode('decimal')
                                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                                TextInput::make('freight_data.icms_retido.base_calculo_retencao_icms')
                                                    ->label('Base de Cálculo')
                                                    ->numeric()
                                                    ->inputMode('decimal')
                                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                                TextInput::make('freight_data.icms_retido.aliquota_retencao')
                                                    ->label('Alíquota de Retenção')
                                                    ->numeric()
                                                    ->inputMode('decimal')
                                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                                TextInput::make('freight_data.icms_retido.valor_icms_retido')
                                                    ->label('Valor ICMS Retido')
                                                    ->numeric()
                                                    ->inputMode('decimal')
                                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                                TextInput::make('freight_data.icms_retido.cfop')
                                                    ->label('CFOP')
                                                    ->maxLength(4)
                                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                                TextInput::make('freight_data.icms_retido.codigo_municipio_ocorrencia_fato_gerador')
                                                    ->label('Cód. Município Fato Gerador')
                                                    ->maxLength(7)
                                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                            ])
                                            ->collapsed()
                                            ->collapsible(),

                                        Section::make('Veículo e Identificações')
                                            ->columnSpanFull()
                                            ->columns(['md' => 6, 'lg' => 12])
                                            ->schema([
                                                TextInput::make('freight_data.veiculo.placa')
                                                    ->label('Placa')
                                                    ->maxLength(8)
                                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                                TextInput::make('freight_data.veiculo.uf')
                                                    ->label('UF do Veículo')
                                                    ->maxLength(2)
                                                    ->columnSpan(['md' => 1, 'lg' => 1]),
                                                TextInput::make('freight_data.veiculo.rntc')
                                                    ->label('RNTC')
                                                    ->maxLength(20)
                                                    ->columnSpan(['md' => 3, 'lg' => 3]),
                                                TextInput::make('freight_data.identificacao_vagao')
                                                    ->label('Identificação do Vagão')
                                                    ->maxLength(20)
                                                    ->columnSpan(['md' => 3, 'lg' => 3]),
                                                TextInput::make('freight_data.identificacao_balsa')
                                                    ->label('Identificação da Balsa')
                                                    ->maxLength(20)
                                                    ->columnSpan(['md' => 3, 'lg' => 3]),
                                            ])
                                            ->collapsed()
                                            ->collapsible(),

                                        Repeater::make('freight_data.volumes')
                                            ->label('Volumes')
                                            ->columnSpanFull()
                                            ->columns(['md' => 6, 'lg' => 12])
                                            ->schema([
                                                TextInput::make('quantidade')
                                                    ->label('Quantidade')
                                                    ->numeric()
                                                    ->inputMode('numeric')
                                                    ->columnSpan(['md' => 1, 'lg' => 2]),
                                                Select::make('especie')
                                                    ->label('Espécie')
                                                    ->options(Unit::toSelectArray())
                                                    ->searchable()
                                                    ->native(false)
                                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                                TextInput::make('marca')
                                                    ->label('Marca')
                                                    ->maxLength(60)
                                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                                TextInput::make('numero')
                                                    ->label('Número')
                                                    ->maxLength(60)
                                                    ->columnSpan(['md' => 1, 'lg' => 2]),
                                                TextInput::make('peso_liquido')
                                                    ->label('Peso Líquido')
                                                    ->numeric()
                                                    ->inputMode('decimal')
                                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                                TextInput::make('peso_bruto')
                                                    ->label('Peso Bruto')
                                                    ->numeric()
                                                    ->inputMode('decimal')
                                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                                Repeater::make('lacres')
                                                    ->label('Lacres')
                                                    ->columnSpanFull()
                                                    ->schema([
                                                        TextInput::make('numero')
                                                            ->label('Número do Lacre')
                                                            ->maxLength(60),
                                                    ])
                                                    ->defaultItems(0)
                                                    ->addActionLabel('Adicionar lacre')
                                                    ->collapsible(),
                                            ])
                                            ->defaultItems(0)
                                            ->addActionLabel('Adicionar volume')
                                            ->collapsible(),
                                    ])
                                    ->columns(['md' => 2])
                                    ->collapsible()
                                    ->visible(fn (Get $get): bool => $get('document_type') !== DocumentModel::NFSE->value),

                                Section::make('Informações Adicionais')
                                    ->columnSpanFull()
                                    ->columns(['md' => 6, 'lg' => 12])
                                    ->visible(fn (Get $get, $operation): bool => $get('document_type') !== DocumentModel::NFSE->value && $operation === 'edit')
                                    ->schema([
                                        Textarea::make('additional_taxpayer_information')
                                            ->label('Informações ao Contribuinte')
                                            ->rows(3)
                                            ->maxLength(2000)
                                            ->columnSpan(['md' => 3, 'lg' => 6]),

                                        Textarea::make('additional_tax_information')
                                            ->label('Informações ao Fisco')
                                            ->rows(3)
                                            ->maxLength(2000)
                                            ->columnSpan(['md' => 3, 'lg' => 6]),
                                    ])
                                    ->collapsible()
                                    ->collapsed(),
                                Section::make('Informações adicionais de compra')
                                    ->visible(fn (Get $get, $operation): bool => $get('document_type') !== DocumentModel::NFSE->value && $operation === 'edit')
                                    ->schema([
                                        TextInput::make('additional_purchase_information_nota_empenho')
                                            ->label('Nota de Empenho')
                                            ->maxLength(60),

                                        TextInput::make('additional_purchase_information_pedido')
                                            ->label('Pedido')
                                            ->maxLength(60),

                                        TextInput::make('additional_purchase_information_contrato')
                                            ->label('Contrato')
                                            ->maxLength(60),
                                    ])
                                    ->columns(['md' => 3])
                                    ->columnSpanFull()
                                    ->collapsible()
                                    ->collapsed(),
                            ]),

                        Tab::make('Erros')
                            ->visibleOn([Operation::Edit])
                            ->columnSpanFull()
                            ->columns(['md' => 6, 'lg' => 12])
                            ->schema([
                                Section::make('Histórico de Erros de Emissão')
                                    ->columnSpanFull()
                                    ->columns(['md' => 6, 'lg' => 12])
                                    ->schema([
                                        Section::make('Conciliação de RPS')
                                            ->columnSpanFull()
                                            ->columns(['md' => 6, 'lg' => 12])
                                            ->visible(fn (?FiscalDocument $record): bool => self::latestRpsReconciliationEntry($record) !== null)
                                            ->schema([
                                                TextEntry::make('rps_reconciliation_reason')
                                                    ->label('Justificativa registrada')
                                                    ->state(fn (?FiscalDocument $record): string => (string) (self::latestRpsReconciliationEntry($record)['mensagem'] ?? 'N/D'))
                                                    ->columnSpanFull(),
                                                TextEntry::make('rps_gap_justified')
                                                    ->label('Lacuna justificada')
                                                    ->state(fn (?FiscalDocument $record): string => self::rpsReconciliationFlag($record, 'gap_justification_required') ? 'Sim' : 'Não')
                                                    ->badge()
                                                    ->color(fn (?FiscalDocument $record): string => self::rpsReconciliationFlag($record, 'gap_justification_required') ? 'warning' : 'gray')
                                                    ->columnSpan(['md' => 2, 'lg' => 3]),
                                                TextEntry::make('rps_sequence_rewound')
                                                    ->label('Sequência rebobinada')
                                                    ->state(fn (?FiscalDocument $record): string => self::rpsReconciliationFlag($record, 'released_document_number') ? 'Sim' : 'Não')
                                                    ->badge()
                                                    ->color(fn (?FiscalDocument $record): string => self::rpsReconciliationFlag($record, 'released_document_number') ? 'success' : 'gray')
                                                    ->columnSpan(['md' => 2, 'lg' => 3]),
                                                TextEntry::make('rps_document_ready_for_retry')
                                                    ->label('Documento pronto para novo envio')
                                                    ->state(fn (?FiscalDocument $record): string => self::rpsReconciliationFlag($record, 'document_cleared_for_new_rps') || self::rpsReconciliationFlag($record, 'released_document_number') ? 'Sim' : 'Não')
                                                    ->badge()
                                                    ->color(fn (?FiscalDocument $record): string => self::rpsReconciliationFlag($record, 'document_cleared_for_new_rps') || self::rpsReconciliationFlag($record, 'released_document_number') ? 'success' : 'warning')
                                                    ->columnSpan(['md' => 2, 'lg' => 3]),
                                                TextEntry::make('rps_previous_number')
                                                    ->label('RPS conciliado')
                                                    ->state(fn (?FiscalDocument $record): string => self::formatRpsReconciliationNumber($record))
                                                    ->columnSpan(['md' => 2, 'lg' => 3]),
                                            ]),
                                        Repeater::make('errors_messages')
                                            ->label('Erros')
                                            ->columnSpanFull()
                                            ->compact()
                                            ->columns(['md' => 6, 'lg' => 12])
                                            ->table([
                                                TableColumn::make('At')
                                                    ->width('80px'),
                                                TableColumn::make('Código')
                                                    ->width('80px'),
                                                TableColumn::make('Origem')
                                                    ->width('80px'),
                                                TableColumn::make('Mensagem')
                                                    ->width('350px'),
                                            ])
                                            ->schema([
                                                TextInput::make('at')
                                                    ->label('Data/Hora')
                                                    ->columnSpan(['md' => 2, 'lg' => 4])
                                                    ->disabled(),
                                                TextInput::make('codigo')
                                                    ->label('Código')
                                                    ->columnSpan(['md' => 2, 'lg' => 4])
                                                    ->disabled(),
                                                TextInput::make('job')
                                                    ->label('Origem')
                                                    ->columnSpan(['md' => 2, 'lg' => 4])
                                                    ->formatStateUsing(fn ($state, Get $get): ?string => $state ?? $get('origem'))
                                                    ->disabled(),
                                                Textarea::make('mensagem')
                                                    ->label('Mensagem')
                                                    ->rows(3)
                                                    ->columnSpanFull()
                                                    ->disabled(),
                                            ])
                                            ->addable(false)
                                            ->deletable(false)
                                            ->reorderable(false)
                                            ->collapsed(false)
                                            ->dehydrated(false)
                                            ->default([]),
                                    ]),
                            ]),

                        Tab::make('Cartas de Correção')
                            ->visible(fn (?FiscalDocument $record, $operation): bool => $operation === 'edit'
                                && $record?->isNfe()
                                && is_array(data_get($record?->nfe_payload, 'correcoes'))
                                && data_get($record?->nfe_payload, 'correcoes') !== [])
                            ->columnSpanFull()
                            ->columns(['md' => 6, 'lg' => 12])
                            ->schema([
                                Section::make('Histórico de Cartas de Correção')
                                    ->columnSpanFull()
                                    ->columns(['md' => 6, 'lg' => 12])
                                    ->schema([
                                        Repeater::make('nfe_payload.correcoes')
                                            ->label('Cartas emitidas')
                                            ->columnSpanFull()
                                            ->compact()
                                            ->columns(['md' => 6, 'lg' => 12])
                                            ->table([
                                                TableColumn::make('Nº')
                                                    ->width('80px'),
                                                TableColumn::make('Protocolo')
                                                    ->width('180px'),
                                                TableColumn::make('Data/Hora')
                                                    ->width('180px'),
                                                TableColumn::make('Downloads')
                                                    ->width('160px'),
                                            ])
                                            ->schema([
                                                TextInput::make('sequencial')
                                                    ->label('Número da Carta')
                                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                                    ->disabled(),
                                                TextInput::make('protocolo')
                                                    ->label('Protocolo')
                                                    ->columnSpan(['md' => 2, 'lg' => 3])
                                                    ->disabled(),
                                                TextInput::make('data_hora_evento')
                                                    ->label('Data/Hora do Evento')
                                                    ->columnSpan(['md' => 2, 'lg' => 3])
                                                    ->formatStateUsing(fn ($state): ?string => self::formatCorrectionEventDate($state))
                                                    ->disabled(),
                                                TextInput::make('pdf_status')
                                                    ->label('PDF')
                                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                                    ->formatStateUsing(fn ($state, Get $get): string => filled($get('pdf_base64')) ? 'Disponível' : 'Indisponível')
                                                    ->suffixAction(
                                                        Action::make('download_correction_pdf')
                                                            ->icon(Heroicon::DocumentArrowDown)
                                                            ->url(fn (Get $get, ?FiscalDocument $record): ?string => self::buildCorrectionDownloadUrl($record, $get, 'pdf'))
                                                            ->openUrlInNewTab()
                                                            ->hidden(fn (Get $get): bool => ! filled($get('pdf_base64')))
                                                    )
                                                    ->disabled(),
                                                TextInput::make('xml_status')
                                                    ->label('XML')
                                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                                    ->formatStateUsing(fn ($state, Get $get): string => filled($get('xml_base64')) ? 'Disponível' : 'Indisponível')
                                                    ->suffixAction(
                                                        Action::make('download_correction_xml')
                                                            ->icon(Heroicon::DocumentArrowDown)
                                                            ->url(fn (Get $get, ?FiscalDocument $record): ?string => self::buildCorrectionDownloadUrl($record, $get, 'xml'))
                                                            ->openUrlInNewTab()
                                                            ->hidden(fn (Get $get): bool => ! filled($get('xml_base64')))
                                                    )
                                                    ->disabled(),
                                                Textarea::make('justificativa')
                                                    ->label('Texto da Correção')
                                                    ->rows(4)
                                                    ->columnSpanFull()
                                                    ->disabled(),
                                            ])
                                            ->addable(false)
                                            ->deletable(false)
                                            ->reorderable(false)
                                            ->collapsed(false)
                                            ->dehydrated(false)
                                            ->default([]),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    private static function formatCorrectionEventDate(mixed $state): ?string
    {
        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($state)->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            return $state;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function latestRpsReconciliationEntry(?FiscalDocument $record): ?array
    {
        if ($record === null || ! is_array($record->errors_messages)) {
            return null;
        }

        $entries = array_values(array_filter(
            $record->errors_messages,
            fn ($entry): bool => is_array($entry) && (($entry['codigo'] ?? null) === 'rps_reconciliation')
        ));

        if ($entries === []) {
            return null;
        }

        return end($entries) ?: null;
    }

    private static function rpsReconciliationFlag(?FiscalDocument $record, string $key): bool
    {
        return (bool) data_get(self::latestRpsReconciliationEntry($record), 'contexto.'.$key, false);
    }

    private static function formatRpsReconciliationNumber(?FiscalDocument $record): string
    {
        $entry = self::latestRpsReconciliationEntry($record);
        $serie = data_get($entry, 'contexto.rps_series');
        $number = data_get($entry, 'contexto.rps_number');

        if (! is_scalar($serie) || ! is_scalar($number)) {
            return 'N/D';
        }

        return (string) $serie.'/'.(string) $number;
    }

    private static function buildCorrectionDownloadUrl(?FiscalDocument $record, Get $get, string $type): ?string
    {
        if (! $record instanceof FiscalDocument) {
            return null;
        }

        $sequencial = $get('sequencial');

        if (! filled($sequencial)) {
            return null;
        }

        return route('fiscal-documents.correction-letters.download', [
            'fiscalDocument' => $record,
            'sequencial' => (int) $sequencial,
            'type' => $type,
        ]);
    }
}
