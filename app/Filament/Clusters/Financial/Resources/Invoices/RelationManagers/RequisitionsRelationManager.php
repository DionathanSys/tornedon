<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\Requisition\Status;
use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RequisitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'requisitions';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('number')
                    ->required(),
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->required(),
                Select::make('company_id')
                    ->relationship('company', 'name')
                    ->required(),
                Select::make('service_order_id')
                    ->relationship('serviceOrder', 'id'),
                Select::make('quote_id')
                    ->relationship('quote', 'id'),
                DatePicker::make('sale_date')
                    ->required(),
                Select::make('status')
                    ->options(Status::class)
                    ->required(),
                Select::make('payment_method')
                    ->options(Method::class),
                Select::make('payment_condition')
                    ->options(Condition::class),
                Textarea::make('observations')
                    ->columnSpanFull(),
                TextInput::make('delivery_address'),
                DatePicker::make('delivery_date'),
                Select::make('salesperson_id')
                    ->relationship('salesperson', 'name'),
                DateTimePicker::make('invoiced_at'),
                Select::make('equipment_id')
                    ->relationship('equipment', 'name'),
                Toggle::make('stock_consumed')
                    ->required(),
                Toggle::make('stock_reserved'),
                TextInput::make('additional_info'),
                TextInput::make('created_by')
                    ->numeric(),
                TextInput::make('updated_by')
                    ->numeric(),
                Select::make('production_order_id')
                    ->relationship('productionOrder', 'id'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('number')
                    ->searchable(),
                TextColumn::make('customer.name')
                    ->searchable(),
                TextColumn::make('company.name')
                    ->searchable(),
                TextColumn::make('serviceOrder.id')
                    ->searchable(),
                TextColumn::make('quote.id')
                    ->searchable(),
                TextColumn::make('sale_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('payment_method')
                    ->badge()
                    ->searchable(),
                TextColumn::make('payment_condition')
                    ->badge()
                    ->searchable(),
                TextColumn::make('delivery_address')
                    ->searchable(),
                TextColumn::make('delivery_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('salesperson.name')
                    ->searchable(),
                TextColumn::make('invoiced_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('equipment.name')
                    ->searchable(),
                IconColumn::make('stock_consumed')
                    ->boolean(),
                IconColumn::make('stock_reserved')
                    ->boolean(),
                TextColumn::make('created_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('productionOrder.id')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // CreateAction::make(),
                // AssociateAction::make(),
            ])
            ->recordActions([
                // EditAction::make(),
                // DissociateAction::make(),
                // DeleteAction::make(),
            ])
            ->toolbarActions([
                // BulkActionGroup::make([
                    // DissociateBulkAction::make(),
                    // DeleteBulkAction::make(),
                // ]),
            ]);
    }
}
