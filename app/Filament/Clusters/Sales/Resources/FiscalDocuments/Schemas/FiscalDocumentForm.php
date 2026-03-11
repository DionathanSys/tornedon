<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\ItemsRelationManager;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Partner;
use Filament\Forms;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Operation;

class FiscalDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identificação')
                    ->schema([
                        Forms\Components\Select::make('company_id')
                            ->label('Empresa Emitente')
                            ->options(Company::pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('customer_id')
                            ->label('Cliente / Tomador')
                            ->options(Partner::pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('document_type')
                            ->label('Tipo de Documento')
                            ->options(DocumentModel::toSelectArray())
                            ->default(DocumentModel::NFE->value)
                            ->required()
                            ->native(false)
                            ->live()
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('invoice_id')
                            ->label('Fatura Vinculada')
                            ->options(Invoice::pluck('invoice_number', 'id'))
                            ->searchable()
                            ->nullable()
                            ->native(false)
                            ->helperText('Opcional — associa o documento a uma fatura existente.')
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible(),

                // === NFS-e: Modelo ===
                Section::make('Dados da NFS-e')
                    ->schema([
                        Forms\Components\Select::make('nfse_model')
                            ->label('Modelo NFS-e')
                            ->options(NfseModel::toSelectArray())
                            ->required()
                            ->native(false)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\DatePicker::make('issued_at')
                            ->label('Data de Emissão')
                            ->required()
                            ->native(false)
                            ->default(now())
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible()
                    ->visible(fn (Get $get): bool => $get('document_type') === DocumentModel::NFSE->value),

                // === NF-e: Dados ===
                Section::make('Dados da NF-e')
                    ->schema([
                        Forms\Components\Select::make('operation_nature')
                            ->label('Natureza da Operação')
                            ->options(OperationNature::toSelectArray())
                            ->default(OperationNature::VENDA_DENTRO_ESTADO->value)
                            ->searchable()
                            ->required()
                            ->columnSpan(['md' => 2]),

                        Forms\Components\DatePicker::make('issued_at')
                            ->label('Data de Emissão')
                            ->required()
                            ->native(false)
                            ->default(now())
                            ->columnSpan(['md' => 1]),

                        Forms\Components\DatePicker::make('movement_at')
                            ->label('Data Entrada/Saída')
                            ->required()
                            ->native(false)
                            ->default(now())
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('operation_type')
                            ->label('Tipo de Operação')
                            ->options(OperationType::toSelectArray())
                            ->default(OperationType::SAIDA->value)
                            ->required()
                            ->native(false)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('issue_purpose')
                            ->label('Finalidade de Emissão')
                            ->options(IssuePurpose::toSelectArray())
                            ->default(IssuePurpose::NORMAL->value)
                            ->required()
                            ->native(false)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Toggle::make('is_final_consumer')
                            ->label('Consumidor Final')
                            ->default(true)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('buyer_presence_indicator')
                            ->label('Indicador de Presença')
                            ->options(BuyerPresenceIndicator::toSelectArray())
                            ->default(BuyerPresenceIndicator::OUTROS->value)
                            ->native(false)
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible()
                    ->visible(fn (Get $get): bool => $get('document_type') !== DocumentModel::NFSE->value),

                Livewire::make(ItemsRelationManager::class, fn (FiscalDocument $record) => [
                    'ownerRecord' => $record,
                    'pageClass'   => EditFiscalDocument::class,
                ])
                    ->key('items-relation-manager')
                    ->columnSpanFull()
                    ->visibleOn([Operation::Edit]),

                // === NF-e: Frete ===
                Section::make('Frete')
                    ->schema([
                        Forms\Components\Select::make('freight_data.modalidade_frete')
                            ->label('Modalidade do Frete')
                            ->options([
                                '0' => '0 Por conta do emitente (CIF)',
                                '1' => '1 Por conta do destinatário (FOB)',
                                '2' => '2 Por conta de terceiros',
                                '9' => '9 Sem frete',
                            ])
                            ->default('9')
                            ->native(false)
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible()
                    ->visible(fn (Get $get): bool => $get('document_type') !== DocumentModel::NFSE->value),

                Section::make('Informações Adicionais')
                    ->schema([
                        Forms\Components\Textarea::make('additional_taxpayer_information')
                            ->label('Informações ao Contribuinte')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpan(['md' => 2]),

                        Forms\Components\Textarea::make('additional_tax_information')
                            ->label('Informações ao Fisco')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpan(['md' => 2]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible(),
            ]);
    }
}
