<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\Requisition\Status;
use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
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
                    ->label('Nº')
                    ->searchable()
                    ->recordUrl(fn($record) => RequisitionResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('serviceOrder.id')
                    ->label('ID OS')
                    ->searchable()
                    ->placeholder('Sem OS')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('quote.id')
                    ->label('ID Orç.')
                    ->placeholder('Sem Orçamento')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('sale_date')
                    ->label('Dt. da Venda')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(Status $state) => $state->description())
                    ->color(fn(Status $state) => $state->color())
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('payment_method')
                    ->label('Forma de Pagto')
                    ->formatStateUsing(fn(Method $state) => $state->description())
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('payment_condition')
                    ->label('Condição de Pagamento')
                    ->formatStateUsing(fn(Condition $state) => $state->description())
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('delivery_address')
                    ->label('Endereço de Entrega')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('delivery_date')
                    ->label('Dt. Entrega')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('salesperson.name')
                    ->label('Vendedor')
                    ->placeholder('Sem Vendedor')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('invoiced_at')
                    ->label('Faturado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('equipment.name')
                    ->label('Equipamento')
                    ->placeholder('Sem Equipamento')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                IconColumn::make('stock_consumed')
                    ->label('Estoque Consumido')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('stock_reserved')
                    ->label('Reservado')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy.name')
                    ->label('Atualizado por')
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
