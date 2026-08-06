<?php

namespace App\Filament\Clusters\Settings\Resources\FiscalRules\Schemas;

use App\Enum\FiscalDocument\OperationNature;
use App\Enum\Product\Origin as ProductOrigin;
use App\Enum\Tax\CofinsCst;
use App\Enum\Tax\IbsCbsCst;
use App\Enum\Tax\IcmsCsosn;
use App\Enum\Tax\IcmsCst;
use App\Enum\Tax\PisCst;
use App\Enum\Tax\StateTaxIndicator;
use App\Enum\Tax\TaxRegime;
use App\Models\FiscalProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class FiscalRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['md' => 2, 'lg' => 12])
            ->components([
                Section::make('Contexto da Regra')
                    ->columnSpanFull()
                    ->columns(['md' => 2, 'lg' => 12])
                    ->schema([
                        Select::make('fiscal_profile_id')
                            ->label('Perfil Fiscal')
                            ->relationship(
                                name: 'fiscalProfile',
                                titleAttribute: 'id',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Filament::getTenant()?->id),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (FiscalProfile $record): string => sprintf(
                                    '#%d - %s',
                                    $record->id,
                                    $record->tax_regime?->description() ?? (string) $record->tax_regime,
                                ),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $profile = FiscalProfile::query()
                                    ->where('company_id', Filament::getTenant()?->id)
                                    ->find($state);

                                $set('tax_regime', $profile?->tax_regime?->value ?? $profile?->tax_regime);
                            })
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        Select::make('operation_nature')
                            ->label('Natureza da Operação')
                            ->options(OperationNature::toSelectArray())
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        Select::make('tax_regime')
                            ->label('Regime Tributário')
                            ->options(TaxRegime::toSelectArray())
                            ->native(false)
                            ->disabled()
                            ->dehydrated()
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextInput::make('priority')
                            ->label('Prioridade')
                            ->integer()
                            ->required()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Maior valor = maior prioridade no desempate entre regras.')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        Toggle::make('is_active')
                            ->label('Regra ativa')
                            ->default(true)
                            ->inline(false)
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                    ]),

                Section::make('Critérios de Correspondência')
                    ->description('Preencha apenas o que deve ser obrigatório para a regra. Campos em branco significam "qualquer valor".')
                    ->columnSpanFull()
                    ->columns(['md' => 2, 'lg' => 12])
                    ->schema([
                        Toggle::make('is_interestadual')
                            ->label('Operação interestadual')
                            ->default(false)
                            ->inline(false)
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Select::make('product_origin')
                            ->label('Origem do Produto')
                            ->options(ProductOrigin::toSelectArray())
                            ->native(false)
                            ->searchable()
                            ->placeholder('Qualquer origem')
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        Select::make('is_custom_manufacturing')
                            ->label('Fabricação Própria?')
                            ->options(self::nullableBooleanOptions())
                            ->afterStateHydrated(fn (Select $component, ?object $record): mixed => $component->state(self::nullableBooleanToSelectState($record?->is_custom_manufacturing)))
                            ->dehydrateStateUsing(fn (mixed $state): ?bool => self::nullableBooleanFromSelectState($state))
                            ->native(false)
                            ->placeholder('Qualquer')
                            ->helperText('Use “Qualquer” quando a regra servir para produtos próprios e de terceiros.')
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Select::make('has_st')
                            ->label('Produto com ST?')
                            ->options(self::nullableBooleanOptions())
                            ->afterStateHydrated(fn (Select $component, ?object $record): mixed => $component->state(self::nullableBooleanToSelectState($record?->has_st)))
                            ->dehydrateStateUsing(fn (mixed $state): ?bool => self::nullableBooleanFromSelectState($state))
                            ->native(false)
                            ->placeholder('Qualquer')
                            ->helperText('Deixe em branco para não filtrar por substituição tributária.')
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Select::make('recipient_type')
                            ->label('Tipo de Destinatário')
                            ->options(StateTaxIndicator::toSelectArray())
                            ->native(false)
                            ->placeholder('Qualquer tipo')
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        Select::make('is_final_consumer')
                            ->label('Consumidor final?')
                            ->options(self::nullableBooleanOptions())
                            ->afterStateHydrated(fn (Select $component, ?object $record): mixed => $component->state(self::nullableBooleanToSelectState($record?->is_final_consumer)))
                            ->dehydrateStateUsing(fn (mixed $state): ?bool => self::nullableBooleanFromSelectState($state))
                            ->native(false)
                            ->placeholder('Qualquer')
                            ->helperText('Deixe em branco para aceitar consumidor final e não final.')
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextInput::make('ncm_prefix')
                            ->label('Prefixo NCM')
                            ->placeholder('Ex: 2710')
                            ->maxLength(8)
                            ->rule('regex:/^[0-9]{2,8}$/')
                            ->helperText('Informe de 2 a 8 dígitos para casar com o início do NCM.')
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                    ]),

                Section::make('Resultado Fiscal')
                    ->columnSpanFull()
                    ->columns(['md' => 2, 'lg' => 12])
                    ->schema([
                        TextInput::make('cfop')
                            ->label('CFOP')
                            ->required()
                            ->maxLength(4)
                            ->rule('regex:/^[0-9]{4}$/')
                            ->placeholder('Ex: 5102')
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Select::make('cst_icms')
                            ->label('CST ICMS')
                            ->options(IcmsCst::toSelectArray())
                            ->searchable()
                            ->native(false)
                            ->visible(fn (Get $get): bool => ! self::isSimplesRegime($get('tax_regime')))
                            ->columnSpan(['md' => 1, 'lg' => 5]),
                        Select::make('csosn')
                            ->label('CSOSN')
                            ->options(IcmsCsosn::toSelectArray())
                            ->searchable()
                            ->native(false)
                            ->visible(fn (Get $get): bool => self::isSimplesRegime($get('tax_regime')))
                            ->columnSpan(['md' => 1, 'lg' => 5]),
                        Select::make('cst_pis')
                            ->label('CST PIS')
                            ->options(PisCst::toSelectArray())
                            ->searchable()
                            ->native(false)
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        Select::make('cst_cofins')
                            ->label('CST COFINS')
                            ->options(CofinsCst::toSelectArray())
                            ->searchable()
                            ->native(false)
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        TextInput::make('cst_ipi')
                            ->label('CST IPI')
                            ->maxLength(3)
                            ->rule('regex:/^[0-9]{2,3}$/')
                            ->placeholder('Ex: 50')
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        TextInput::make('aliquota_icms')
                            ->label('Alíquota ICMS (%)')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->maxValue(100)
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                        TextInput::make('aliquota_pis')
                            ->label('Alíquota PIS (%)')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->maxValue(100)
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                        TextInput::make('aliquota_cofins')
                            ->label('Alíquota COFINS (%)')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->maxValue(100)
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                        TextInput::make('aliquota_ipi')
                            ->label('Alíquota IPI (%)')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->maxValue(100)
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                    ]),

                Section::make('IBS/CBS')
                    ->description('Configuração base para montagem do bloco IBS/CBS na NF-e de saída.')
                    ->columnSpanFull()
                    ->columns(['md' => 2, 'lg' => 12])
                    ->schema([
                        Select::make('cst_ibs_cbs')
                            ->label('CST IBS/CBS')
                            ->options(IbsCbsCst::toSelectArray())
                            ->searchable()
                            ->native(false)
                            ->placeholder('Não informar')
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        TextInput::make('classificacao_tributaria_ibs_cbs')
                            ->label('Classificação Tributária')
                            ->maxLength(6)
                            ->rule('regex:/^[0-9]{6}$/')
                            ->placeholder('Ex: 000001')
                            ->helperText('Código cClassTrib com 6 dígitos.')
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        Select::make('indicador_doacao_ibs_cbs')
                            ->label('Indicador Doação')
                            ->options([
                                '0' => '0 - Não',
                                '1' => '1 - Sim',
                            ])
                            ->native(false)
                            ->placeholder('Não informar')
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        TextInput::make('aliquota_ibs_estadual')
                            ->label('Alíquota IBS Estadual (%)')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->maxValue(100)
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        TextInput::make('aliquota_ibs_municipal')
                            ->label('Alíquota IBS Municipal (%)')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->maxValue(100)
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        TextInput::make('aliquota_cbs')
                            ->label('Alíquota CBS (%)')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->maxValue(100)
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                    ]),

                Section::make('Vigência e Observações')
                    ->columnSpanFull()
                    ->columns(['md' => 2, 'lg' => 12])
                    ->schema([
                        DatePicker::make('valid_from')
                            ->label('Válida a partir de')
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                        DatePicker::make('valid_until')
                            ->label('Válida até')
                            ->afterOrEqual('valid_from')
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                        Textarea::make('description')
                            ->label('Descrição')
                            ->rows(2)
                            ->maxLength(255)
                            ->columnSpan(['md' => 2, 'lg' => 6]),
                    ]),
            ]);
    }

    private static function isSimplesRegime(?string $regime): bool
    {
        return in_array($regime, [TaxRegime::MEI->value, TaxRegime::SIMPLES_NACIONAL->value], true);
    }

    /**
     * @return array<string, string>
     */
    private static function nullableBooleanOptions(): array
    {
        return [
            '1' => 'Sim',
            '0' => 'Não',
        ];
    }

    private static function nullableBooleanToSelectState(mixed $state): ?string
    {
        return match ($state) {
            true, 1, '1' => '1',
            false, 0, '0' => '0',
            default => null,
        };
    }

    private static function nullableBooleanFromSelectState(mixed $state): ?bool
    {
        return match ($state) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => null,
        };
    }
}
