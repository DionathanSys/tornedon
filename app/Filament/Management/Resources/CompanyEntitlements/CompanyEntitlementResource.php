<?php

namespace App\Filament\Management\Resources\CompanyEntitlements;

use App\Filament\Management\Resources\CompanyEntitlements\Pages\CreateCompanyEntitlement;
use App\Filament\Management\Resources\CompanyEntitlements\Pages\EditCompanyEntitlement;
use App\Filament\Management\Resources\CompanyEntitlements\Pages\ListCompanyEntitlements;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
            Section::make('Direito de emissao')
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
                        ->required(),
                    Select::make('feature')
                        ->label('Recurso')
                        ->options([
                            'bank_slip_issuance' => 'Emissão de boletos',
                            'pix_charge_issuance' => 'Emissão de cobranças PIX',
                        ])
                        ->required(),
                    Toggle::make('enabled')
                        ->label('Habilitado')
                        ->default(true),
                    DateTimePicker::make('starts_at')
                        ->label('Inicio'),
                    DateTimePicker::make('ends_at')
                        ->label('Fim'),
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
