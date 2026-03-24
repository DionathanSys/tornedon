<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Schemas;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FiscalDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 6,
                'lg' => 12,
            ])
            ->components([
                Section::make('Dados da Nota de Entrada')
                    ->columns([
                        'sm' => 1,
                        'md' => 6,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        SelectPartner::make('customer_id')
                            ->label('Fornecedor'),
                        Select::make('document_type')
                            ->label('Tipo Documento')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(DocumentModel::toSelectArray())
                            ->default(DocumentModel::NFE->value)
                            ->native(false)
                            ->required(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->visibleOn('edit')
                            ->badge(),
                    ]),
                Section::make('Identificação')
                    ->columns(['sm' => 1,'md' => 6,'lg' => 12,])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('document_number')
                            ->label('Número da NF')
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
                        Select::make('operation_nature')
                            ->label('Natureza da Operação')
                            ->columnSpan(['md' => 2, 'lg' => 5])
                            ->options(OperationNature::toSelectArray())
                            ->default(OperationNature::VENDA_DENTRO_ESTADO->value)
                            ->searchable()
                            ->required(),
                        Select::make('issue_purpose')
                            ->label('Finalidade de Emissão')
                            ->columnSpan(['md' => 1, 'lg' => 4])
                            ->options(IssuePurpose::toSelectArray())
                            ->default(IssuePurpose::NORMAL->value)
                            ->native(false)
                            ->required(),
                    ]),
                Section::make('Datas')
                    ->columns(['sm' => 1,'md' => 6,'lg' => 12,])
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('issued_at')
                            ->label('Data de Emissão')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->displayFormat('d/m/Y')
                            ->default(now()),
                        DatePicker::make('movement_at')
                            ->label('Data de Entrada')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->displayFormat('d/m/Y')
                            ->default(now()),
                    ]),
                Hidden::make('company_id'),
                Hidden::make('operation_type')->default(OperationType::ENTRADA->value),
            ]);
    }
}
