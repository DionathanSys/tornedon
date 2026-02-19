<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers;

use App\Enum\Product\Unit;
use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('service_id')
                    ->label('Serviço')
                    ->searchable()
                    ->relationship('service', 'name', function ($query) {
                        $query->where('services.company_id', Filament::getTenant()->id);
                    })
                    ->required()
                    ->columnSpanFull()
                    ->live()
                    ->afterStateUpdated(function (Set $set, callable $get, $state) {
                        $service = \App\Models\Service::find($state);
                        if ($service) {
                            $set('unit_price', number_format($service->price, 2, ',', ''));
                        } else {
                            $set('unit_price', null);
                        }
                        self::calculateValues($get, $set);
                    }),
                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('quantity')
                            ->label('Quantidade')
                            ->required()
                            ->numeric()
                            ->default(1)
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, Set $set, callable $get) => self::calculateValues($get, $set)),
                        Money::make('unit_price')
                            ->label('Preço Unitário')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, Set $set, callable $get) => self::calculateValues($get, $set)),
                        Money::make('subtotal')
                            ->label('Subtotal')
                            ->readOnly()
                            ->dehydrated(),
                    ]),
                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Money::make('discount_percentage')
                            ->label('Desconto (%)')
                            ->numeric()
                            ->default(0.0)
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set, callable $get) {
                                $subtotal = (float) ($get('subtotal') ?? 0);
                                $percentage = (float) ($state ?? 0);
                                $discountAmount = $subtotal * ($percentage / 100);
                                $set('discount_amount', number_format($discountAmount, 2, ',', ''));
                                self::calculateValues($get, $set);
                            }),
                        Money::make('discount_amount')
                            ->label('Desconto (R$)')
                            ->default(0.0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set, callable $get) {
                                $subtotal = (float) ($get('subtotal') ?? 0);
                                $discountAmount = (float) ($state ?? 0);
                                if ($subtotal > 0) {
                                    $percentage = ($discountAmount / $subtotal) * 100;
                                    $set('discount_percentage', number_format($percentage, 2, ',', ''));
                                }
                                self::calculateValues($get, $set);
                            }),
                        Money::make('total_amount')
                            ->label('Valor Total')
                            ->readOnly()
                            ->dehydrated(),
                    ]),
                Textarea::make('observations')
                    ->label('Observações')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('service_id')
            ->heading('Itens')
            ->columns([
                TextColumn::make('service.name')
                    ->label('Serviço')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->label('Qtde.')
                    ->numeric(2, ',', '.')
                    ->sortable(),
                TextColumn::make('unit_price')
                    ->label('Preço Unit.')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('unit_cost')
                    ->label('Custo Unit.')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_percentage')
                    ->label('Desc. (%)')
                    ->numeric(2, ',', '.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_amount')
                    ->label('Desc. (R$)')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('createdBy')
                    ->label('Criado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy')
                    ->label('Atualizado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Serviço')
                    ->icon(Heroicon::Plus)
                    ->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function calculateValues(callable $get, Set $set): void
    {
        $quantity = (float) ($get('quantity') ?? 0);
        $unitPrice = (float) ($get('unit_price') ?? 0);
        $discountAmount = (float) ($get('discount_amount') ?? 0);

        // Calcula o subtotal
        $subtotal = $quantity * $unitPrice;
        $set('subtotal', number_format($subtotal, 2, ',', ''));

        // Calcula o total
        $totalAmount = $subtotal - $discountAmount;
        $set('total_amount', number_format($totalAmount, 2, ',', ''));
    }
}
