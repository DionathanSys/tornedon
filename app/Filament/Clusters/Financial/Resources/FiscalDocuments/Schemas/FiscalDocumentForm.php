<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Schemas;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\RelationManagers\ItemsRelationManager;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource as SalesFiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\FiscalDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
                            ->disabled(fn(?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('document_type')
                            ->label('Tipo Documento')
                            ->columnSpan(['md' => 1])
                            ->options(DocumentModel::toSelectArray())
                            ->default(DocumentModel::NFE->value)
                            ->native(false)
                            ->disabled(fn(?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->required(),
                        Select::make('issue_purpose')
                            ->label('Finalidade')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(IssuePurpose::toSelectArray())
                            ->default(IssuePurpose::NORMAL->value)
                            ->native(false)
                            ->disabled(fn(?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->required(),
                        DatePicker::make('issued_at')
                            ->label('Data Emissão')
                            ->columnSpan(['md' => 2])
                            ->disabled(fn(?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->displayFormat('DD/MM/YYYY')
                            ->required()
                            ->default(now()),
                        Select::make('operation_nature')
                            ->label('Natureza da Operação')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->options(OperationNature::toSelectArray())
                            ->default(OperationNature::VENDA_DENTRO_ESTADO->value)
                            ->searchable()
                            ->disabled(fn(?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->required(),
                        TextInput::make('document_number')
                            ->label('Número da NF')
                            ->columnSpan(['md' => 1, 'lg' => 1])
                            ->columnStart(1)
                            ->maxLength(20)
                            ->disabled(fn(?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->autocomplete(false),
                        TextInput::make('document_series')
                            ->label('Série')
                            ->columnSpan(['md' => 1])
                            ->maxLength(5)
                            ->disabled(fn(?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->autocomplete(false),
                        TextInput::make('document_key')
                            ->label('Chave de Acesso')
                            ->columnSpan(['md' => 2])
                            ->maxLength(50)
                            ->disabled(fn(?FiscalDocument $record): bool => self::importedReadOnly($record))
                            ->autocomplete(false)
                            ->copyable(copyMessage: 'Copiado!', copyMessageDuration: 1500),
                    ]),
                Section::make('Log')
                    ->columns(['sm' => 1, 'md' => 6, 'lg' => 12])
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed()
                    ->compact()
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('linked_return_fiscal_document_id')
                            ->label('NF de devolução vinculada')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->state(fn(FiscalDocument $record): ?string => $record->linkedReturnFiscalDocument()?->document_number)
                            ->placeholder('-')
                            ->url(fn(FiscalDocument $record): ?string => ($linkedRecord = $record->linkedReturnFiscalDocument())
                                ? SalesFiscalDocumentResource::getUrl('edit', ['record' => $linkedRecord])
                                : null, true),
                        TextEntry::make('linked_origin_fiscal_document_id')
                            ->label('NF de origem vinculada')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->state(fn(FiscalDocument $record): ?string => $record->linkedOriginFiscalDocument()?->document_number)
                            ->placeholder('-')
                            ->url(fn(FiscalDocument $record): ?string => ($linkedRecord = $record->linkedOriginFiscalDocument())
                                ? FiscalDocumentResource::getUrl('edit', ['record' => $linkedRecord])
                                : null, true),
                        TextEntry::make('confirmed_at')
                            ->label('Confirmada em')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('Não confirmada'),
                        TextEntry::make('canceled_at')
                            ->label('Cancelada em')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('Não cancelada'),
                        TextEntry::make('emission_requested_at')
                            ->label('Solicitada em')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('emission_attempted_at')
                            ->label('Ultima tentativa')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('return_financial_processed_at')
                            ->label('Proc. financeiro em')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('Não processada'),
                        TextEntry::make('return_stock_processed_at')
                            ->label('Proc. estoque em')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('created_at')
                            ->label('Criada em')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('createdBy.name')
                            ->label('Criada por')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->placeholder('-'),
                        TextEntry::make('updated_at')
                            ->label('Atualizada em')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('updatedBy.name')
                            ->label('Atualizada por')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->placeholder('-'),
                        TextEntry::make('confirmedBy.name')
                            ->label('Confirmada por')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->placeholder('-'),
                        TextEntry::make('canceledBy.name')
                            ->label('Cancelada por')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->placeholder('-'),
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
