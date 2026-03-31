<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
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
            ->recordUrl(fn($record) => ServiceOrderResource::getUrl('edit', ['record' => $record]), true)
            ->columns([
                TextColumn::make('number')
                    ->label('Nº')
                    ->searchable(),
                TextColumn::make('customer.name')   
                    ->label('Cliente')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quote.id')
                    ->label('ID Orçamento')
                    ->searchable()
                    ->placeholder('Sem Orçamento')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('total_amount')
                    ->label('Valor Total')
                    ->money('BRL', 100)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('discount_amount')
                    ->label('Valor do Desc. (R$)')
                    ->money('BRL', 100)
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('order_date')
                    ->label('Dt. Ordem')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('scheduled_date')
                    ->label('Dt. Agendada')
                    ->date('d/m/Y')
                    ->placeholder('Sem data')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('limit_date')
                    ->label('Dt. Limite')
                    ->date('d/m/Y')
                    ->placeholder('Sem data')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completion_date')
                    ->label('Dt. Finalização')
                    ->date('d/m/Y')
                    ->placeholder('Sem data')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn(State $state) => $state->description())
                    ->color(fn(State $state) => $state->color())
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->formatStateUsing(fn(Priority $state) => $state->description())
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn(Type $state) => $state->description())
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('equipment.name')
                    ->label('Equipamento')
                    ->searchable()
                    ->placeholder('Sem equipamento')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('location')
                    ->label('Localização')
                    ->placeholder('Sem localização')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('estimated_hours')
                    ->numeric()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('actual_hours')
                    ->numeric()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('travel_value')
                    ->numeric()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_method')
                    ->label('Forma de Pagto')
                    ->formatStateUsing(fn(Method $state) => $state->description())
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('payment_condition')
                    ->label('Condição de Pagamento')
                    ->formatStateUsing(fn(Condition $state) => $state->description())
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('technician.name')
                    ->label('Técnico')
                    ->searchable()
                    ->placeholder('Sem técnico')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('supervisor.name')
                    ->label('Supervisor')
                    ->searchable()
                    ->placeholder('Sem supervisor')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('salesperson.name')
                    ->label('Vendedor')
                    ->searchable()
                    ->placeholder('Sem vendedor')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('warranty_expires_at')
                    ->date('d/m/Y')
                    ->label('Garantia Válida Até')
                    ->placeholder('Sem garantia')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('requires_approval')
                    ->label('Requer Aprovação')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('approved_by_customer')
                    ->label('Aprovado pelo Cliente')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approved_at')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy.name')
                    ->label('Atualizado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
            ])
            ->headerActions([
            ])
            ->recordActions([
            ])
            ->toolbarActions([
            ]);
    }
}
