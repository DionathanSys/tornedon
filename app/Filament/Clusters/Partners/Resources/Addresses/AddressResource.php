<?php

namespace App\Filament\Clusters\Partners\Resources\Addresses;

use App\Filament\Clusters\Partners\PartnersCluster;
use App\Filament\Clusters\Partners\Resources\Addresses\Pages\ManageAddresses;
use App\Models\Address;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AddressResource extends Resource
{
    protected static ?string $model = Address::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $cluster = PartnersCluster::class;

     protected static ?string $modelLabel = 'Endereço';

    protected static ?string $pluralModelLabel = 'Endereços';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('partner_id')
                    ->label('Parceiro')
                    ->required()
                    ->relationship('partner', 'name'),
                TextInput::make('street')
                    ->label('Logradouro')
                    ->required()
                    ->maxLength(255),
                TextInput::make('number')
                    ->label('Número')
                    ->required()
                    ->maxLength(50),
                TextInput::make('complement')
                    ->label('Complemento'),
                TextInput::make('neighborhood')
                    ->label('Bairro'),
                TextInput::make('city')
                    ->label('Cidade'),
                TextInput::make('state')
                    ->label('Estado'),
                TextInput::make('country')
                    ->required()
                    ->default('BRASIL'),
                TextInput::make('postal_code')
                    ->label('CEP'),
                TextInput::make('city_code')
                    ->label('Código do IBGE da Cidade'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('partner.name')
                    ->label('Parceiro')
                    ->placeholder('-'),
                TextEntry::make('street')
                    ->label('Logradouro')
                    ->placeholder('-'),
                TextEntry::make('number')
                    ->label('Número')
                    ->placeholder('-'),
                TextEntry::make('complement')
                    ->label('Complemento')
                    ->placeholder('-'),
                TextEntry::make('neighborhood')
                    ->label('Bairro')
                    ->placeholder('-'),
                TextEntry::make('city')
                    ->label('Cidade')
                    ->placeholder('-'),
                TextEntry::make('state')
                    ->label('Estado')
                    ->placeholder('-'),
                TextEntry::make('country')
                    ->label('País')
                    ->placeholder('-'),
                TextEntry::make('postal_code')
                    ->label('CEP')
                    ->placeholder('-'),
                TextEntry::make('city_code')
                    ->label('Código do IBGE da Cidade')
                    ->placeholder('-'),
                TextEntry::make('createdBy.name')
                    ->label('Criado por')
                    ->placeholder('-'),
                TextEntry::make('updatedBy.name')
                    ->label('Atualizado por')
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('partner_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('street')
                    ->searchable(),
                TextColumn::make('number')
                    ->searchable(),
                TextColumn::make('complement')
                    ->searchable(),
                TextColumn::make('neighborhood')
                    ->searchable(),
                TextColumn::make('city')
                    ->searchable(),
                TextColumn::make('state')
                    ->searchable(),
                TextColumn::make('country')
                    ->searchable(),
                TextColumn::make('postal_code')
                    ->searchable(),
                TextColumn::make('city_code')
                    ->searchable(),
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
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAddresses::route('/'),
        ];
    }
}
