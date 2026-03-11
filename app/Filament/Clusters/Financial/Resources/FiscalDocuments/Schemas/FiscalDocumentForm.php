<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Schemas;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FiscalDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 4,
                'lg' => 12,
            ])
            ->components([
                Section::make('Dados do Documento Fiscal')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('customer_id')
                            ->label('Cliente / Fornecedor')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('invoice_id')
                            ->label('Fatura')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->relationship('invoice', 'invoice_number')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('document_type')
                            ->label('Tipo Documento')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(DocumentModel::toSelectArray())
                            ->native(false)
                            ->required(),
                        Select::make('status')
                            ->label('Status')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->options(Status::toSelectArray())
                            ->native(false)
                            ->default(Status::PENDING->value)
                            ->visibleOn('edit')
                            ->disabled(),
                    ]),
                Section::make('Identificação')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('document_number')
                            ->label('Número')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->maxLength(20),
                        TextInput::make('document_series')
                            ->label('Série')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->maxLength(5),
                        TextInput::make('document_key')
                            ->label('Chave de Acesso')
                            ->columnSpan(['md' => 2, 'lg' => 7])
                            ->maxLength(50),
                    ]),
                Section::make('Operação')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('operation_type')
                            ->label('Tipo Operação')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(OperationType::toSelectArray())
                            ->native(false)
                            ->required(),
                        Select::make('operation_nature')
                            ->label('Natureza Operação')
                            ->columnSpan(['md' => 1, 'lg' => 4])
                            ->options(OperationNature::toSelectArray())
                            ->searchable()
                            ->required(),
                        Select::make('issue_purpose')
                            ->label('Finalidade Emissão')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->options(IssuePurpose::toSelectArray())
                            ->native(false)
                            ->required(),
                        DatePicker::make('issued_at')
                            ->label('Data Emissão')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->displayFormat('d/m/Y'),
                    ]),
                Section::make('Datas e Movimentação')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('movement_at')
                            ->label('Data Movimentação')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->displayFormat('d/m/Y'),
                        Toggle::make('is_final_consumer')
                            ->label('Consumidor Final')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->default(false),
                        Select::make('buyer_presence_indicator')
                            ->label('Presença do Comprador')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(BuyerPresenceIndicator::toSelectArray())
                            ->native(false)
                            ->default(BuyerPresenceIndicator::OUTROS->value),
                    ]),
                Section::make('Observações')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed()
                    ->persistCollapsed()
                    ->schema([
                        Textarea::make('tax_observations')
                            ->label('Observações Fiscais')
                            ->columnSpan(['md' => 2, 'lg' => 6])
                            ->rows(3),
                        Textarea::make('additional_tax_information')
                            ->label('Informações Fiscais Adicionais')
                            ->columnSpan(['md' => 2, 'lg' => 6])
                            ->rows(3),
                        Textarea::make('taxpayer_observations')
                            ->label('Observações do Contribuinte')
                            ->columnSpan(['md' => 2, 'lg' => 6])
                            ->rows(3),
                        Textarea::make('additional_taxpayer_information')
                            ->label('Informações Adicionais do Contribuinte')
                            ->columnSpan(['md' => 2, 'lg' => 6])
                            ->rows(3),
                        Textarea::make('additional_purchase_information')
                            ->label('Informações Adicionais de Compra')
                            ->columnSpanFull()
                            ->rows(3),
                    ]),
                Hidden::make('company_id'),
            ]);
    }
}
