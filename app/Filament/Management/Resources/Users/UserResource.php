<?php

namespace App\Filament\Management\Resources\Users;

use App\Enum\User\ManagementRole;
use App\Filament\Management\Resources\Users\Pages\CreateUser;
use App\Filament\Management\Resources\Users\Pages\EditUser;
use App\Filament\Management\Resources\Users\Pages\ListUsers;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
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

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Gestao';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Usuario')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('E-mail')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    TextInput::make('password')
                        ->label('Senha')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->minLength(8),
                    CheckboxList::make('companies')
                        ->label('Empresas')
                        ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->columns(2)
                        ->searchable()
                        ->bulkToggleable(),
                    Toggle::make('is_active')
                        ->label('Ativo')
                        ->default(true),
                    Select::make('management_role')
                        ->label('Papel administrativo')
                        ->options(ManagementRole::toSelectArray())
                        ->placeholder('Usuário comum')
                        ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false)
                        ->dehydrated(fn (): bool => auth()->user()?->isSuperAdmin() ?? false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('companies.name')
                    ->label('Empresas')
                    ->badge()
                    ->separator(','),
                TextColumn::make('management_role')
                    ->label('Papel')
                    ->state(fn (User $record): string => $record->managementRole()?->description() ?? 'Usuário comum')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
