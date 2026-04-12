<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\ItemsRelationManager;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Partner;
use Filament\Forms;
use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Operation;
use Filament\Forms\Components\Repeater\TableColumn;

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
                                            ->required()
                                            ->native(false)
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
                                            ->formatStateUsing(fn(Status $state): ?string => $state->description())
                                            ->badge()
                                            ->color(fn(Status $state) => $state->color()),
                                        TextEntry::make('nfse_status')
                                            ->label('Status NFS-e')
                                            ->visibleOn('edit')
                                            ->visible(fn($record, $operation): bool => $record->isNfse() && $operation === Operation::Edit)
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn($record): string => $record->nfse_status ? $record->nfse_status->description() : 'N/D'),
                                        TextEntry::make('document_number')
                                            ->label('Nº Documento')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn($record): string => $record->document_number ? $record->document_number : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('rps_number')
                                            ->label('Nº RPS')
                                            ->visibleOn('edit')
                                            ->visible(fn($record): bool => $record->isNfse())
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn($record): string => $record->rps_number ? $record->rps_number : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('document_series')
                                            ->label('Série')
                                            ->visibleOn('edit')
                                            ->visible(fn($record): bool => $record->isNfe())
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn($record): string => $record->document_series ? $record->document_series : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('rps_series')
                                            ->label('Série')
                                            ->visibleOn('edit')
                                            ->visible(fn($record): bool => $record->isNfse())
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn($record): string => $record->rps_series ? $record->rps_series : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('document_key')
                                            ->label('Chave Doc.')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->state(fn($record): string => $record->document_key ? $record->document_key : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('invoice_id')
                                            ->label('Fatura Vinculada')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->visible(fn($state): bool => $state !== null)
                                            ->formatStateUsing(fn($record, $state): ?string => $state ? $record->invoice->invoice_number : 'Sem fatura vinculada')
                                            ->url(fn($record): ?string => $record->invoice ? InvoiceResource::getUrl('edit', ['record' => $record->invoice]) : null, true),
                                    ])
                                    ->columns(['md' => 2])
                                    ->collapsible()
                                    ->visible(fn(Get $get): bool => $get('document_type') === DocumentModel::NFSE->value),

                                Section::make('Dados da NF-e')
                                    ->columnSpanFull()
                                    ->columns(['md' => 6, 'lg' => 12])
                                    ->schema([
                                        TextEntry::make('status')
                                            ->label('Status')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->formatStateUsing(fn(Status $state): ?string => $state->description())
                                            ->badge()
                                            ->color(fn(Status $state) => $state->color()),
                                        TextEntry::make('nfe_status')
                                            ->label('Status NF-e')
                                            ->visibleOn('edit')
                                            ->visible(fn($record, $operation): bool => $record->isNfe() && $operation === Operation::Edit)
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn($record): string => ! $record->nfse_status ? $record->nfse_status->description() : 'N/D'),
                                        TextEntry::make('document_number')
                                            ->label('Nº Documento')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->state(fn($record): string => $record->document_number ? $record->document_number : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('document_key')
                                            ->label('Chave Doc.')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->state(fn($record): string => $record->document_key ? $record->document_key : 'N/D')
                                            ->placeholder('N/D'),
                                        TextEntry::make('invoice_id')
                                            ->label('Fatura Vinculada')
                                            ->visibleOn('edit')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->visible(fn($state): bool => $state !== null)
                                            ->formatStateUsing(fn($record, $state): ?string => $state ? $record->invoice->invoice_number : 'Sem fatura vinculada')
                                            ->url(fn($record): ?string => $record->invoice ? InvoiceResource::getUrl('edit', ['record' => $record->invoice]) : null, true),
                                        Select::make('operation_nature')
                                            ->label('Natureza da Operação')
                                            ->options(OperationNature::toSelectArray())
                                            ->default(OperationNature::VENDA_DENTRO_ESTADO->value)
                                            ->searchable()
                                            ->required()
                                            ->columnSpan(['md' => 2, 'lg' => 4]),

                                        DatePicker::make('issued_at')
                                            ->label('Data de Emissão')
                                            ->visibleOn('edit')
                                            ->readOnly()
                                            ->displayFormat('d/m/Y')
                                            ->default(now())
                                            ->columnSpan(['md' => 2, 'lg' => 2]),

                                        DatePicker::make('movement_at')
                                            ->label('Data Entrada/Saída')
                                            ->visible(false)
                                            ->readOnly()
                                            ->displayFormat('d/m/Y')
                                            ->default(now())
                                            ->columnSpan(['md' => 2, 'lg' => 2]),

                                        Select::make('operation_type')
                                            ->label('Tipo de Operação')
                                            ->options(OperationType::toSelectArray())
                                            ->default(OperationType::SAIDA->value)
                                            ->required()
                                            ->native(false)
                                            ->columnSpan(['md' => 2, 'lg' => 2]),

                                        Select::make('issue_purpose')
                                            ->label('Finalidade de Emissão')
                                            ->options(IssuePurpose::toSelectArray())
                                            ->default(IssuePurpose::NORMAL->value)
                                            ->required()
                                            ->native(false)
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->columnStart(1),

                                        Select::make('buyer_presence_indicator')
                                            ->label('Indicador de Presença')
                                            ->options(BuyerPresenceIndicator::toSelectArray())
                                            ->default(BuyerPresenceIndicator::OUTROS->value)
                                            ->native(false)
                                            ->columnSpan(['md' => 2, 'lg' => 4]),

                                        Toggle::make('is_final_consumer')
                                            ->label('Consumidor Final')
                                            ->inline(false)
                                            ->default(true)
                                            ->columnSpan(['md' => 2, 'lg' => 2]),
                                    ])
                                    ->columns(['md' => 2])
                                    ->collapsible()
                                    ->visible(fn(Get $get): bool => $get('document_type') !== DocumentModel::NFSE->value),

                                Livewire::make(ItemsRelationManager::class, fn(FiscalDocument $record) => [
                                    'ownerRecord' => $record,
                                    'pageClass'   => EditFiscalDocument::class,
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
                                            ->options([
                                                '0' => '0 Por conta do emitente (CIF)',
                                                '1' => '1 Por conta do destinatário (FOB)',
                                                '2' => '2 Por conta de terceiros',
                                                '9' => '9 Sem frete',
                                            ])
                                            ->default('9')
                                            ->native(false)
                                            ->columnSpan(['md' => 3]),
                                    ])
                                    ->columns(['md' => 2])
                                    ->collapsible()
                                    ->visible(fn(Get $get): bool => $get('document_type') !== DocumentModel::NFSE->value),

                                Section::make('Informações Adicionais')
                                    ->columnSpanFull()
                                    ->columns(['md' => 6, 'lg' => 12])
                                    ->visible(fn(Get $get, $operation): bool => $get('document_type') !== DocumentModel::NFSE->value && $operation === 'edit')
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
                                                    ->formatStateUsing(fn($state, Get $get): ?string => $state ?? $get('origem'))
                                                    ->disabled(),
                                                Textarea::make('mensagem')
                                                    ->label('Mensagem')
                                                    // ->rows(3)
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
}
