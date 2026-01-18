<?php

namespace App\Filament\Clusters\Partners\Resources\Addresses;

use App\Filament\Clusters\Partners\PartnersCluster;
use App\Filament\Clusters\Partners\Resources\Addresses\Pages\ManageAddresses;
use App\Models\Address;
use App\Models\Partner;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AddressResource extends Resource
{
    protected static ?string $model = Address::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $cluster = PartnersCluster::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Endereço';

    protected static ?string $pluralModelLabel = 'Endereços';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 4,
                'lg' => 8,
            ])
            ->components([
                // Select::make('parceiro_id')
                //     ->options(fn() => Partner::all()->pluck('nome', 'id'))
                //     ->required()
                //     ->exists('company_partner', 'company_id', function ($query) {
                //         // Valida se o parceiro selecionado realmente pertence à empresa logada
                //         $query->where('company_id', Auth::user()->com);
                //     }),
                Select::make('partner_id')
                    ->label('Parceiro')
                    ->columnStart(1)
                    ->columnSpanFull()
                    ->required()
                    ->relationship(
                        'partner',
                        'name',
                        modifyQueryUsing: function (Builder $query) {
                            $tenant = Filament::getTenant();
                            return $query
                                ->whereHas('companies', function (Builder $subQuery) use ($tenant) {
                                    $subQuery->where('company_id', $tenant->id);
                                });
                        }
                    )
                    ->searchable()
                    ->preload(),
                TextInput::make('street')
                    ->label('Logradouro')
                    ->columnStart(1)
                    ->columnSpan([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 4,
                    ])
                    ->required()
                    ->maxLength(255),
                TextInput::make('number')
                    ->label('Número')
                    ->columnSpan([
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 2,
                    ])
                    ->required()
                    ->maxLength(50),
                TextInput::make('complement')
                    ->label('Complemento')
                    ->columnSpan([
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 2,
                    ]),
                TextInput::make('neighborhood')
                    ->label('Bairro')
                    ->columnSpan([
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 4,
                    ]),
                TextInput::make('city')
                    ->label('Cidade')
                    ->columnSpan([
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 4,
                    ]),
                TextInput::make('state')
                    ->label('Estado')
                    ->columnSpan([
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 4,
                    ]),
                TextInput::make('country')
                    ->label('País')
                    ->required()
                    ->columnSpan([
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 4,
                    ])
                    ->default('BRASIL'),
                TextInput::make('postal_code')
                    ->label('CEP')
                    ->columnSpan([
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 4,
                    ]),
                TextInput::make('city_code')
                    ->label('Código do IBGE da Cidade')
                    ->columnSpan([
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 4,
                    ]),
                Checkbox::make('open-record-after-creation')
                    ->label('Abrir o registro após a criação')
                    ->columnSpanFull()
                    ->default(true),
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
                TextColumn::make('partner.name')
                    ->label('Parceiro')
                    ->sortable(),
                TextColumn::make('street')
                    ->label('Rua')
                    ->searchable(),
                TextColumn::make('number')
                    ->label('Nro.')
                    ->searchable(),
                TextColumn::make('complement')
                    ->label('Complemento')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('neighborhood')
                    ->label('Bairro')
                    ->searchable(),
                TextColumn::make('city')
                    ->label('Cidade')
                    ->searchable(),
                TextColumn::make('state')
                    ->label('Estado')
                    ->searchable(),
                TextColumn::make('country')
                    ->label('País')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('postal_code')
                    ->label('CEP')
                    ->searchable(),
                TextColumn::make('city_code')
                    ->label('Cód. Cidade IBGE')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy.name')
                    ->label('Editado por')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Editado em')
                    ->dateTime('d/m/Y H:i')
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
