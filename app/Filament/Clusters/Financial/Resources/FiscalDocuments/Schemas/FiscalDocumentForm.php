<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Schemas;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\RelationManagers\ItemsRelationManager;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\FiscalDocument;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FiscalDocumentForm
{
    private static function importedReadOnly(?FiscalDocument $record): bool
    {
        return $record?->isImportedFromDfe() ?? false;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['sm' => 1, 'md' => 6, 'lg' => 12,])
            ->components([
                Section::make('Dados da Nota de Entrada')
                    ->columns(['sm' => 1, 'md' => 6, 'lg' => 12,])
                    ->columnSpanFull()
                    ->collapsible()
                    ->compact()
                    ->schema([
                        SelectPartner::make('customer_id', 'all')
                            ->label('Fornecedor')
                            ->disabled(fn (?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->columnSpan(['md' => 3, 'lg' => 6]),
                        Select::make('document_type')
                            ->label('Tipo Documento')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->options(DocumentModel::toSelectArray())
                            ->default(DocumentModel::NFE->value)
                            ->native(false)
                            ->disabled(fn (?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->required(),
                        TextEntry::make('confirmed_at')
                            ->label('Confirmada em')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->visibleOn('edit')
                            ->formatStateUsing(fn(?Carbon $state) => $state?->format('d/m/Y H:i:s'))
                            ->placeholder('Não confirmada')
                            ->badge(),
                    ]),
                Section::make('Identificação')
                    ->columns(['sm' => 1, 'md' => 6, 'lg' => 12,])
                    ->columnSpanFull()
                    ->compact()
                    ->schema([
                        TextInput::make('document_number')
                            ->label('Número da NF')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->maxLength(20)
                            ->disabled(fn (?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->autocomplete(false),
                        TextInput::make('document_series')
                            ->label('Série')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->maxLength(5)
                            ->disabled(fn (?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->autocomplete(false),
                        TextInput::make('document_key')
                            ->label('Chave de Acesso')
                            ->columnSpan(['md' => 2, 'lg' => 7])
                            ->maxLength(50)
                            ->disabled(fn (?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->autocomplete(false),
                    ]),
                Section::make('Operação')
                    ->columns(['sm' => 1, 'md' => 4, 'lg' => 12,])
                    ->columnSpanFull()
                    ->compact()
                    ->schema([
                        Select::make('operation_nature')
                            ->label('Natureza da Operação')
                            ->columnSpan(['md' => 2, 'lg' => 5])
                            ->options(OperationNature::toSelectArray())
                            ->default(OperationNature::VENDA_DENTRO_ESTADO->value)
                            ->searchable()
                            ->disabled(fn (?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->required(),
                        Select::make('issue_purpose')
                            ->label('Finalidade de Emissão')
                            ->columnSpan(['md' => 1, 'lg' => 4])
                            ->options(IssuePurpose::toSelectArray())
                            ->default(IssuePurpose::NORMAL->value)
                            ->native(false)
                            ->disabled(fn (?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->required(),
                    ]),
                Livewire::make(ItemsRelationManager::class, fn(FiscalDocument $record) => [
                    'ownerRecord' => $record,
                    'pageClass' => EditFiscalDocument::class,
                ])
                    ->visibleOn('edit') 
                    ->key('items-relation-manager')
                    ->columnSpanFull(),
                Hidden::make('company_id'),
                Hidden::make('operation_type')->default(OperationType::ENTRADA->value),
            ]);
    }
}
