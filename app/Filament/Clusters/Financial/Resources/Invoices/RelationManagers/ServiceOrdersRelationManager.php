<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
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

class ServiceOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceOrders';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('number')
                    ->label('Nº')
                    ->required(),
                Select::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'name')
                    ->required(),
                Select::make('company_id')
                    ->relationship('company', 'name')
                    ->required(),
                Select::make('quote_id')
                    ->relationship('quote', 'id'),
                DatePicker::make('order_date')
                    ->required(),
                DatePicker::make('scheduled_date'),
                DatePicker::make('limit_date'),
                DatePicker::make('completion_date'),
                Select::make('status')
                    ->options(State::class)
                    ->required(),
                Select::make('priority')
                    ->options(Priority::class)
                    ->required(),
                Select::make('type')
                    ->options(Type::class)
                    ->required(),
                Textarea::make('solution')
                    ->columnSpanFull(),
                Select::make('equipment_id')
                    ->relationship('equipment', 'name'),
                TextInput::make('location'),
                Textarea::make('customer_observations')
                    ->columnSpanFull(),
                Textarea::make('technician_observations')
                    ->columnSpanFull(),
                TextInput::make('estimated_hours')
                    ->numeric()
                    ->default(0.0),
                TextInput::make('actual_hours')
                    ->numeric()
                    ->default(0.0),
                TextInput::make('travel_value')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                Select::make('payment_method')
                    ->options(Method::class),
                Select::make('payment_condition')
                    ->options(Condition::class),
                Select::make('technician_id')
                    ->relationship('technician', 'name'),
                Select::make('supervisor_id')
                    ->relationship('supervisor', 'name'),
                Select::make('salesperson_id')
                    ->relationship('salesperson', 'name'),
                DatePicker::make('warranty_expires_at'),
                Toggle::make('requires_approval')
                    ->required(),
                Toggle::make('approved_by_customer')
                    ->required(),
                DateTimePicker::make('approved_at'),
                TextInput::make('customer_rating')
                    ->numeric(),
                Textarea::make('customer_feedback')
                    ->columnSpanFull(),
                TextInput::make('additional_info'),
                TextInput::make('created_by')
                    ->numeric(),
                TextInput::make('updated_by')
                    ->numeric(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('number')
                    ->label('Nº')
                    ->searchable(),
                TextColumn::make('customer.name')   
                    ->label('Cliente')
                    ->searchable(),
                TextColumn::make('quote.id')
                    ->label('ID Orçamento')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('order_date')
                    ->label('Dt. Ordem')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('scheduled_date')
                    ->label('Dt. Agendada')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('limit_date')
                    ->label('Dt. Limite')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('completion_date')
                    ->label('Dt. Finalização')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->badge()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('equipment.name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('location')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('estimated_hours')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('actual_hours')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('travel_value')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('payment_method')
                    ->badge()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('payment_condition')
                    ->badge()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('technician.name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('supervisor.name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('salesperson.name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('warranty_expires_at')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('requires_approval')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('approved_by_customer')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approved_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('customer_rating')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_by')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_by')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                //     DissociateBulkAction::make(),
                //     DeleteBulkAction::make(),
                // ]),
            ]);
    }
}
