<?php

namespace App\Filament\Management\Resources\BillingProviders;

use App\Filament\Management\Resources\BillingProviders\Pages\CreateBillingProvider;
use App\Filament\Management\Resources\BillingProviders\Pages\EditBillingProvider;
use App\Filament\Management\Resources\BillingProviders\Pages\ListBillingProviders;
use App\Models\BillingProvider;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class BillingProviderResource extends Resource
{
    protected static ?string $model = BillingProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $modelLabel = 'Provider financeiro';

    protected static ?string $pluralModelLabel = 'Providers financeiros';

    protected static ?int $navigationSort = 11;

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->canManageProviders();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Provider')
                ->columns(2)
                ->schema([
                    TextInput::make('key')
                        ->label('Chave')
                        ->required()
                        ->maxLength(100),
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('adapter_class')
                        ->label('Classe do adapter')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Toggle::make('is_active')
                        ->label('Ativo')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label('Chave')->searchable()->sortable(),
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                TextColumn::make('adapter_class')->label('Adapter')->toggleable(),
                IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()->label('Provider'),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingProviders::route('/'),
            'create' => CreateBillingProvider::route('/create'),
            'edit' => EditBillingProvider::route('/{record}/edit'),
        ];
    }
}
