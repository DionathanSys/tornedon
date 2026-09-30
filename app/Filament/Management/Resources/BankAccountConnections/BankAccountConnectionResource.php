<?php

namespace App\Filament\Management\Resources\BankAccountConnections;

use App\Filament\Management\Resources\BankAccountConnections\Pages\CreateBankAccountConnection;
use App\Filament\Management\Resources\BankAccountConnections\Pages\EditBankAccountConnection;
use App\Filament\Management\Resources\BankAccountConnections\Pages\ListBankAccountConnections;
use App\Models\Bank;
use App\Models\BankAccountConnection;
use App\Models\BillingProvider;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class BankAccountConnectionResource extends Resource
{
    protected static ?string $model = BankAccountConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $modelLabel = 'Conexao bancaria';

    protected static ?string $pluralModelLabel = 'Conexoes bancarias';

    protected static ?int $navigationSort = 12;

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->canManageProviders();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conexao')
                ->columns(2)
                ->schema([
                    Select::make('company_id')
                        ->label('Empresa')
                        ->options(fn (): array => Company::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),
                    Select::make('financial_account_id')
                        ->label('Conta financeira')
                        ->options(fn (Get $get): array => FinancialAccount::query()
                            ->where('company_id', $get('company_id'))
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('bank_id')
                        ->label('Banco')
                        ->options(fn (): array => Bank::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Bank $bank): array => [$bank->id => $bank->display_name])
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('billing_provider_id')
                        ->label('Provider')
                        ->options(fn (): array => BillingProvider::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('environment')
                        ->label('Ambiente')
                        ->options([
                            'sandbox' => 'Homologacao',
                            'production' => 'Producao',
                        ])
                        ->default('production')
                        ->required(),
                    Select::make('status')
                        ->label('Status')
                        ->options([
                            'active' => 'Ativa',
                            'inactive' => 'Inativa',
                        ])
                        ->default('active')
                        ->required(),
                ]),
            Section::make('Credenciais IntegraBancos')
                ->description('Os valores sao gravados criptografados. Em uma edicao, deixe o campo vazio para manter o valor atual.')
                ->columns(2)
                ->schema([
                    TextInput::make('credential_client_id')
                        ->label('Client ID')
                        ->maxLength(255)
                        ->dehydrated(fn (mixed $state): bool => filled($state)),
                    TextInput::make('credential_client_secret')
                        ->label('Client Secret')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn (mixed $state): bool => filled($state)),
                    TextInput::make('credential_username')
                        ->label('Usuario OAuth')
                        ->maxLength(255)
                        ->dehydrated(fn (mixed $state): bool => filled($state)),
                    TextInput::make('credential_password')
                        ->label('Senha OAuth')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn (mixed $state): bool => filled($state)),
                    TextInput::make('credential_x_api_key')
                        ->label('x_api_key do emitente')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn (mixed $state): bool => filled($state)),
                    TextInput::make('credential_secret_key')
                        ->label('Chave de encriptacao')
                        ->password()
                        ->revealable()
                        ->maxLength(32)
                        ->dehydrated(fn (mixed $state): bool => filled($state)),
                    TextInput::make('credential_access_token')
                        ->label('Access token opcional')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn (mixed $state): bool => filled($state)),
                    TextInput::make('credential_refresh_token')
                        ->label('Refresh token opcional')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn (mixed $state): bool => filled($state)),
                ]),
        ]);
    }

    public static function credentialsFromFormData(array $data, array $existing = []): array
    {
        foreach ([
            'client_id',
            'client_secret',
            'username',
            'password',
            'x_api_key',
            'secret_key',
            'access_token',
            'refresh_token',
        ] as $key) {
            $field = 'credential_'.$key;

            if (array_key_exists($field, $data) && filled($data[$field])) {
                $existing[$key] = $data[$field];
            }

            unset($data[$field]);
        }

        return $existing;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')->label('Empresa')->searchable()->sortable(),
                TextColumn::make('financialAccount.name')->label('Conta financeira')->searchable(),
                TextColumn::make('bank.name')->label('Banco')->searchable(),
                TextColumn::make('billingProvider.name')->label('Provider')->searchable(),
                TextColumn::make('environment')->label('Ambiente')->badge(),
                IconColumn::make('status')->label('Ativa')->boolean(
                    fn (BankAccountConnection $record): bool => $record->status === 'active'
                ),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()->label('Conexao bancaria'),
            ])
            ->defaultSort('company_id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankAccountConnections::route('/'),
            'create' => CreateBankAccountConnection::route('/create'),
            'edit' => EditBankAccountConnection::route('/{record}/edit'),
        ];
    }
}
