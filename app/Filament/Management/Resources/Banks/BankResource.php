<?php

namespace App\Filament\Management\Resources\Banks;

use App\Filament\Management\Resources\Banks\Pages\CreateBank;
use App\Filament\Management\Resources\Banks\Pages\EditBank;
use App\Filament\Management\Resources\Banks\Pages\ListBanks;
use App\Models\Bank;
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

class BankResource extends Resource
{
    protected static ?string $model = Bank::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $modelLabel = 'Banco';

    protected static ?string $pluralModelLabel = 'Bancos';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->canManageProviders();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cadastro do banco')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('Codigo')
                        ->required()
                        ->maxLength(20),
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
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
                TextColumn::make('code')->label('Codigo')->searchable()->sortable(),
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()->label('Banco'),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBanks::route('/'),
            'create' => CreateBank::route('/create'),
            'edit' => EditBank::route('/{record}/edit'),
        ];
    }
}
