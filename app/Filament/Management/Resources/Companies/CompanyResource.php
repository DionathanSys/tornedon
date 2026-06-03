<?php

namespace App\Filament\Management\Resources\Companies;

use App\Filament\Management\Resources\Companies\Pages\CreateCompany;
use App\Filament\Management\Resources\Companies\Pages\EditCompany;
use App\Filament\Management\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use BackedEnum;
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

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Gestao';

    protected static ?string $modelLabel = 'Empresa';

    protected static ?string $pluralModelLabel = 'Empresas';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Empresa')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('document_number')
                        ->label('Documento')
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('E-mail')
                        ->email()
                        ->maxLength(255),
                    TextInput::make('phone')
                        ->label('Telefone')
                        ->maxLength(255),
                    TextInput::make('state_tax_id')
                        ->label('Inscricao estadual')
                        ->maxLength(255),
                    TextInput::make('municipal_tax_id')
                        ->label('Inscricao municipal')
                        ->maxLength(255),
                    TextInput::make('certificate')
                        ->label('Certificado')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Toggle::make('is_active')
                        ->label('Ativa')
                        ->default(true),
                ]),
            Section::make('Endereco')
                ->columns(2)
                ->schema([
                    TextInput::make('address.street')
                        ->label('Rua'),
                    TextInput::make('address.number')
                        ->label('Numero'),
                    TextInput::make('address.complement')
                        ->label('Complemento'),
                    TextInput::make('address.zip_code')
                        ->label('CEP'),
                    TextInput::make('address.city')
                        ->label('Cidade'),
                    TextInput::make('address.state')
                        ->label('UF'),
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
                TextColumn::make('document_number')
                    ->label('Documento')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),
                TextColumn::make('users_count')
                    ->label('Usuarios')
                    ->counts('users')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Atualizada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
