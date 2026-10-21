<?php

namespace App\Filament\Management\Resources\CompanyEntitlements;

use App\Filament\Management\Resources\CompanyEntitlements\Pages\CreateCompanyEntitlement;
use App\Filament\Management\Resources\CompanyEntitlements\Pages\EditCompanyEntitlement;
use App\Filament\Management\Resources\CompanyEntitlements\Pages\ListCompanyEntitlements;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\FinancialCategory;
use App\Models\User;
use App\Services\Financial\Pix\PixEligibilityService;
use App\Support\ServiceOrderOperationalSheet;
use App\Support\ServiceOrderPixPayment;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use UnitEnum;

class CompanyEntitlementResource extends Resource
{
    protected static ?string $model = CompanyEntitlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $modelLabel = 'Direito da empresa';

    protected static ?string $pluralModelLabel = 'Direitos das empresas';

    protected static ?int $navigationSort = 13;

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->canManageProviders();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Direito da empresa')
                ->columns(2)
                ->schema([
                    Select::make('company_id')
                        ->live()
                        ->afterStateUpdated(fn ($set) => $set('metadata', ['user_access' => 'selected']))
                        ->label('Empresa')
                        ->options(fn (): array => Company::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('feature')
                        ->label('Recurso')
                        ->options([
                            'bank_slip_issuance' => 'Emissão de boletos',
                            'pix_charge_issuance' => 'Emissão de cobranças PIX',
                            ServiceOrderPixPayment::FEATURE => 'Recebimento PIX na ordem de serviço',
                            ServiceOrderOperationalSheet::FEATURE => 'Ficha operacional da OS',
                        ])
                        ->live()
                        ->required(),
                    Toggle::make('enabled')
                        ->label('Habilitado')
                        ->default(true),
                    DateTimePicker::make('starts_at')
                        ->label('Inicio'),
                    DateTimePicker::make('ends_at')
                        ->label('Fim'),
                ]),
            Section::make('Recebimento PIX no operation')
                ->visible(fn (Get $get): bool => $get('feature') === ServiceOrderPixPayment::FEATURE)
                ->columns(2)
                ->schema([
                    Select::make('metadata.user_access')
                        ->label('Disponível para')
                        ->options(['selected' => 'Somente usuários selecionados', 'all' => 'Todos os usuários elegíveis'])
                        ->in(['selected', 'all'])
                        ->default('selected')->required()->live(),
                    Select::make('metadata.user_ids')
                        ->label('Usuários autorizados')->multiple()->searchable()->preload()
                        ->nestedRecursiveRules(['integer', fn (Get $get) => Rule::exists('company_user', 'user_id')->where('company_id', $get('company_id'))->where('is_active', true)])
                        ->visible(fn (Get $get): bool => $get('metadata.user_access') !== 'all')
                        ->options(fn (Get $get): array => User::query()
                            ->where('is_active', true)
                            ->whereHas('companies', fn ($query) => $query->where('companies.id', $get('company_id'))->where('company_user.is_active', true))
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('Sem usuários selecionados, ninguém poderá gerar cobranças.'),
                    Select::make('metadata.financial_account_id')
                        ->label('Conta financeira PIX')->required()->searchable()
                        ->options(fn (Get $get): array => app(PixEligibilityService::class)->financialAccountOptions((int) $get('company_id')))
                        ->rules(fn (Get $get): array => [Rule::in(array_keys(app(PixEligibilityService::class)->financialAccountOptions((int) $get('company_id'))))]),
                    Select::make('metadata.financial_category_id')
                        ->label('Categoria financeira')->required()->searchable()
                        ->options(fn (Get $get): array => FinancialCategory::query()->where('company_id', $get('company_id'))
                            ->where('is_active', true)->where('allow_receivable', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->rules(fn (Get $get): array => [Rule::exists('financial_categories', 'id')->where('company_id', $get('company_id'))
                            ->where('is_active', true)->where('allow_receivable', true)]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')->label('Empresa')->searchable()->sortable(),
                TextColumn::make('feature')->label('Recurso')->badge(),
                IconColumn::make('enabled')->label('Habilitado')->boolean(),
                TextColumn::make('starts_at')->label('Inicio')->dateTime('d/m/Y H:i')->placeholder('-'),
                TextColumn::make('ends_at')->label('Fim')->dateTime('d/m/Y H:i')->placeholder('-'),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()->label('Direito da empresa'),
            ])
            ->defaultSort('company_id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyEntitlements::route('/'),
            'create' => CreateCompanyEntitlement::route('/create'),
            'edit' => EditCompanyEntitlement::route('/{record}/edit'),
        ];
    }
}
