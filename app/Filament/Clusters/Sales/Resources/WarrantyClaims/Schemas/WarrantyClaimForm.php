<?php

namespace App\Filament\Clusters\Sales\Resources\WarrantyClaims\Schemas;

use App\Enum\WarrantyClaim\CoverageType;
use App\Enum\WarrantyClaim\Responsibility;
use App\Enum\WarrantyClaim\Status;
use App\Enum\WarrantyClaim\SupplierDecision;
use App\Enum\WarrantyClaim\SupplierResolution;
use App\Enum\WarrantyClaim\Type;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\Equipment;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class WarrantyClaimForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['sm' => 1, 'md' => 4, 'lg' => 12])
            ->components([
                Hidden::make('company_id')
                    ->default(fn (): int => Filament::getTenant()->id),
                Section::make('Dados principais')
                    ->columns(['sm' => 1, 'md' => 4, 'lg' => 12])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('number')
                            ->label('Número')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit'),
                        Select::make('type')
                            ->label('Tipo')
                            ->options(Type::toSelectArray())
                            ->required()
                            ->native(false)
                            ->live()
                            ->default(Type::SERVICE_COMPANY->value)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        Select::make('status')
                            ->label('Status')
                            ->options(Status::toSelectArray())
                            ->required()
                            ->native(false)
                            ->default(Status::DRAFT->value)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        DatePicker::make('expires_at')
                            ->label('Garantia válida até')
                            ->displayFormat('d/m/Y')
                            ->columnSpan(['md' => 2, 'lg' => 2]),
                        TextInput::make('quantity')
                            ->label('Quantidade')
                            ->numeric()
                            ->step('0.0001')
                            ->required()
                            ->default(1)
                            ->columnSpan(['md' => 2, 'lg' => 2]),
                        SelectPartner::make('customer_id', 'customer')
                            ->label('Cliente')
                            ->columnSpan(['md' => 4, 'lg' => 6]),
                        SelectPartner::make('supplier_id', 'supplier')
                            ->label('Fornecedor')
                            ->required(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->visible(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->dehydrated(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->columnSpan(['md' => 4, 'lg' => 6]),
                        Select::make('product_id')
                            ->label('Produto')
                            ->relationship(
                                name: 'product',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id)
                            )
                            ->getOptionLabelFromRecordUsing(fn (Product $record): string => trim(($record->product_code ? $record->product_code.' - ' : '').$record->name))
                            ->searchable()
                            ->preload()
                            ->required(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->visible(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->dehydrated(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('equipment_id')
                            ->label('Equipamento')
                            ->relationship(
                                name: 'equipment',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id)
                            )
                            ->getOptionLabelFromRecordUsing(fn (Equipment $record): string => trim($record->name.' '.($record->identifier ? '- '.$record->identifier : '')))
                            ->searchable()
                            ->preload()
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextInput::make('serial_number')
                            ->label('Número de série')
                            ->maxLength(255)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextInput::make('lot_number')
                            ->label('Lote')
                            ->maxLength(255)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('coverage_type')
                            ->label('Cobertura')
                            ->options(CoverageType::toSelectArray())
                            ->required()
                            ->native(false)
                            ->default(CoverageType::LABOR_AND_PARTS->value)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        Select::make('responsibility')
                            ->label('Responsabilidade')
                            ->options(Responsibility::toSelectArray())
                            ->required()
                            ->native(false)
                            ->default(Responsibility::COMPANY->value)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        Toggle::make('advanced_replacement')
                            ->label('Troca antecipada')
                            ->visible(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->dehydrated(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->inline(false)
                            ->default(false)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        TextInput::make('supplier_protocol')
                            ->label('Protocolo no fornecedor')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->dehydrated(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                    ]),
                Section::make('Origem e vínculos')
                    ->columns(['sm' => 1, 'md' => 4, 'lg' => 12])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('service_order_id')
                            ->label('OS da garantia')
                            ->relationship(
                                name: 'serviceOrder',
                                titleAttribute: 'number',
                                modifyQueryUsing: fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id)
                            )
                            ->getOptionLabelFromRecordUsing(fn (ServiceOrder $record): string => '#'.$record->number)
                            ->searchable()
                            ->preload()
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        Select::make('origin_service_order_id')
                            ->label('OS de origem')
                            ->relationship(
                                name: 'originServiceOrder',
                                titleAttribute: 'number',
                                modifyQueryUsing: fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id)
                            )
                            ->getOptionLabelFromRecordUsing(fn (ServiceOrder $record): string => '#'.$record->number)
                            ->searchable()
                            ->preload()
                            ->required(fn (Get $get): bool => $get('type') === Type::SERVICE_COMPANY->value)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        Select::make('origin_requisition_id')
                            ->label('Requisição de origem')
                            ->relationship(
                                name: 'originRequisition',
                                titleAttribute: 'number',
                                modifyQueryUsing: fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id)
                            )
                            ->getOptionLabelFromRecordUsing(fn (Requisition $record): string => '#'.$record->number)
                            ->searchable()
                            ->preload()
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        Select::make('origin_invoice_id')
                            ->label('Fatura de origem')
                            ->relationship(
                                name: 'originInvoice',
                                titleAttribute: 'invoice_number',
                                modifyQueryUsing: fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id)
                            )
                            ->getOptionLabelFromRecordUsing(fn (Invoice $record): string => (string) ($record->invoice_number ?: $record->id))
                            ->searchable()
                            ->preload()
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        Select::make('origin_fiscal_document_id')
                            ->label('Documento fiscal de origem')
                            ->relationship(
                                name: 'originFiscalDocument',
                                titleAttribute: 'document_number',
                                modifyQueryUsing: fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id)
                            )
                            ->getOptionLabelFromRecordUsing(fn (FiscalDocument $record): string => trim(($record->document_number ?: 'Sem número').' '.($record->document_series ? '/ Série '.$record->document_series : '')))
                            ->searchable()
                            ->preload()
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                    ]),
                Section::make('Atendimento')
                    ->columns(['sm' => 1, 'md' => 4, 'lg' => 12])
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('customer_issue_description')
                            ->label('Problema informado pelo cliente')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                        Textarea::make('technical_diagnosis')
                            ->label('Diagnóstico técnico')
                            ->rows(4)
                            ->columnSpan(['md' => 2, 'lg' => 6]),
                        Textarea::make('resolution_notes')
                            ->label('Observações / resolução')
                            ->rows(4)
                            ->columnSpan(['md' => 2, 'lg' => 6]),
                        Select::make('supplier_decision')
                            ->label('Decisão do fornecedor')
                            ->options(SupplierDecision::toSelectArray())
                            ->required()
                            ->native(false)
                            ->default(SupplierDecision::PENDING->value)
                            ->visible(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->dehydrated(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        Select::make('supplier_resolution')
                            ->label('Resolução do fornecedor')
                            ->options(SupplierResolution::toSelectArray())
                            ->required()
                            ->native(false)
                            ->default(SupplierResolution::NONE->value)
                            ->visible(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->dehydrated(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        DateTimePicker::make('sent_to_supplier_at')
                            ->label('Enviado ao fornecedor em')
                            ->seconds(false)
                            ->visible(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->dehydrated(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        DateTimePicker::make('returned_from_supplier_at')
                            ->label('Retornado do fornecedor em')
                            ->seconds(false)
                            ->visible(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->dehydrated(fn (Get $get): bool => $get('type') === Type::PRODUCT_SUPPLIER->value)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        DateTimePicker::make('closed_at')
                            ->label('Encerrado em')
                            ->seconds(false)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                    ]),
            ]);
    }
}
