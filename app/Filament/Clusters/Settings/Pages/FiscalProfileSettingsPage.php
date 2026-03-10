<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enum\FiscalDocument\OperationNature;
use App\Enum\Tax\CofinsCst;
use App\Enum\Tax\IcmsCst;
use App\Enum\Tax\PisCst;
use App\Enum\Tax\TaxRegime;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\FiscalProfile;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class FiscalProfileSettingsPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected string $view = 'filament.clusters.settings.pages.fiscal-profile-settings';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationLabel = 'Perfil Fiscal';

    protected static ?string $title = 'Configuração do Perfil Fiscal';

    protected static ?int $navigationSort = 8;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return true; // TODO: restringir por permissão (Shield)
    }

    public function mount(): void
    {
        $companyId = Filament::getTenant()?->id;

        $profile = FiscalProfile::where('company_id', $companyId)->first();

        $this->form->fill([
            'tax_regime' => $profile?->tax_regime?->value,
            'cnae_principal' => $profile?->cnae_principal,

            // ICMS
            'icms_cst_default' => $profile?->icms_cst_default,
            'icms_csosn_default' => $profile?->icms_csosn_default,
            'icms_aliquota_interna' => $profile?->icms_aliquota_interna,
            'icms_reducao_base' => $profile?->icms_reducao_base,
            'icms_modalidade_base_calculo' => $profile?->icms_modalidade_base_calculo,

            // ICMS ST
            'icms_st_aliquota' => $profile?->icms_st_aliquota,
            'icms_st_mva' => $profile?->icms_st_mva,
            'icms_st_reducao_base' => $profile?->icms_st_reducao_base,

            // Interestaduais
            'icms_aliquotas_interestaduais' => $profile?->icms_aliquotas_interestaduais ?? [],

            // PIS
            'pis_cst_default' => $profile?->pis_cst_default,
            'pis_aliquota_default' => $profile?->pis_aliquota_default,

            // COFINS
            'cofins_cst_default' => $profile?->cofins_cst_default,
            'cofins_aliquota_default' => $profile?->cofins_aliquota_default,

            // IPI
            'ipi_cst_default' => $profile?->ipi_cst_default,
            'ipi_aliquota_default' => $profile?->ipi_aliquota_default,
            'ipi_enquadramento' => $profile?->ipi_enquadramento,

            // CFOP
            'cfop_rules' => collect($profile?->cfop_rules ?? [])
                ->filter(fn ($rule) => is_array($rule))
                ->map(fn ($rule, $nature) => [
                    'operation_nature' => $nature,
                    'cfop' => $rule['default_cfop'] ?? null,
                    'cfop_exceptions' => $rule['exceptions'] ?? [],
                ])
                ->values()
                ->toArray(),

            // Info adicional
            'informacoes_adicionais_fisco' => $profile?->informacoes_adicionais_fisco,
            'informacoes_adicionais_contribuinte' => $profile?->informacoes_adicionais_contribuinte,
            'informacoes_adicionais_compra_nota_empenho' => data_get($profile?->informacoes_adicionais_compra, 'nota_empenho'),
            'informacoes_adicionais_compra_pedido' => data_get($profile?->informacoes_adicionais_compra, 'pedido'),
            'informacoes_adicionais_compra_contrato' => data_get($profile?->informacoes_adicionais_compra, 'contrato'),
            'observacoes_contribuinte' => collect($profile?->observacoes_contribuinte ?? [])
                ->map(fn ($item) => [
                    'campo' => data_get($item, 'campo'),
                    'texto' => data_get($item, 'texto'),
                ])->toArray(),
            'observacoes_fisco' => collect($profile?->observacoes_fisco ?? [])
                ->map(fn ($item) => [
                    'campo' => data_get($item, 'campo'),
                    'texto' => data_get($item, 'texto'),
                ])->toArray(),
            'informacoes_complementares_padrao' => $profile?->informacoes_complementares_padrao,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // Regime Tributário
                \Filament\Schemas\Components\Section::make('Regime Tributário')
                    ->description('Define o regime tributário da empresa e impacta diretamente o cálculo de todos os impostos.')
                    ->icon('heroicon-o-building-office')
                    ->schema([
                        Forms\Components\Select::make('tax_regime')
                            ->label('Regime Tributário')
                            ->options(TaxRegime::toSelectArray())
                            ->native(false)
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $this->applyRegimeDefaults($set, $state))
                            ->columnSpan(['md' => 1]),

                        Forms\Components\TextInput::make('cnae_principal')
                            ->label('CNAE Principal')
                            ->maxLength(10)
                            ->placeholder('Ex: 4520-0/01')
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 2]),

                // ICMS
                \Filament\Schemas\Components\Section::make('ICMS')
                    ->description('Configuração padrão do ICMS para operações de saída.')
                    ->icon('heroicon-o-receipt-percent')
                    ->schema([
                        // Somente exibe CST para regime normal, ocultar para Simples/MEI
                        Forms\Components\Select::make('icms_cst_default')
                            ->label('CST ICMS Padrão')
                            ->options(IcmsCst::toSelectArray())
                            ->native(false)
                            ->visible(fn (Get $get) => $this->isRegimeNormal($get('tax_regime')))
                            ->columnSpan(['md' => 1]),

                        // Somente exibe CSOSN para Simples/MEI, ocultar para regime normal
                        Forms\Components\Select::make('icms_csosn_default')
                            ->label('CSOSN Padrão')
                            ->options(self::csosnOptions())
                            ->native(false)
                            ->visible(fn (Get $get) => $this->isRegimeSimplesOrMei($get('tax_regime')))
                            ->columnSpan(['md' => 1]),

                        Forms\Components\TextInput::make('icms_aliquota_interna')
                            ->label('Alíquota Interna (%)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->columnSpan(['md' => 1]),

                        Forms\Components\TextInput::make('icms_reducao_base')
                            ->label('Redução de Base (%)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->columnSpan(['md' => 1]),

                        Forms\Components\Select::make('icms_modalidade_base_calculo')
                            ->label('Modalidade Base de Cálculo')
                            ->options([
                                '0' => '0 - Margem Valor Agregado (%)',
                                '1' => '1 - Pauta (Valor)',
                                '2' => '2 - Preço Tabelado Máx. (Valor)',
                                '3' => '3 - Valor da operação',
                            ])
                            ->native(false)
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible(),

                // ICMS ST
                \Filament\Schemas\Components\Section::make('ICMS Substituição Tributária')
                    ->description('Configuração padrão do ICMS-ST. Preencha apenas se aplicável.')
                    ->icon('heroicon-o-arrows-right-left')
                    ->schema([
                        Forms\Components\TextInput::make('icms_st_aliquota')
                            ->label('Alíquota ST (%)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->columnSpan(['md' => 1]),

                        Forms\Components\TextInput::make('icms_st_mva')
                            ->label('MVA (%)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->helperText('Margem de Valor Agregado')
                            ->columnSpan(['md' => 1]),

                        Forms\Components\TextInput::make('icms_st_reducao_base')
                            ->label('Redução de Base ST (%)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 3])
                    ->collapsible()
                    ->collapsed(),

                // Alíquotas Interestaduais
                \Filament\Schemas\Components\Section::make('Alíquotas Interestaduais')
                    ->description('Alíquotas de ICMS para vendas interestaduais por UF de destino.')
                    ->icon('heroicon-o-map')
                    ->schema([
                        Forms\Components\KeyValue::make('icms_aliquotas_interestaduais')
                            ->label('')
                            ->keyLabel('UF')
                            ->valueLabel('Alíquota (%)')
                            ->keyPlaceholder('Ex: SP')
                            ->valuePlaceholder('Ex: 12')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),

                // PIS
                \Filament\Schemas\Components\Section::make('PIS')
                    ->description('Configuração padrão do PIS.')
                    ->icon('heroicon-o-receipt-percent')
                    ->schema([
                        Forms\Components\Select::make('pis_cst_default')
                            ->label('CST PIS Padrão')
                            ->options(PisCst::toSelectArray())
                            ->native(false)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\TextInput::make('pis_aliquota_default')
                            ->label('Alíquota PIS (%)')
                            ->numeric()
                            ->step(0.0001)
                            ->suffix('%')
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible(),

                // COFINS
                \Filament\Schemas\Components\Section::make('COFINS')
                    ->description('Configuração padrão do COFINS.')
                    ->icon('heroicon-o-receipt-percent')
                    ->schema([
                        Forms\Components\Select::make('cofins_cst_default')
                            ->label('CST COFINS Padrão')
                            ->options(CofinsCst::toSelectArray())
                            ->native(false)
                            ->columnSpan(['md' => 1]),

                        Forms\Components\TextInput::make('cofins_aliquota_default')
                            ->label('Alíquota COFINS (%)')
                            ->numeric()
                            ->step(0.0001)
                            ->suffix('%')
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible(),

                // IPI
                \Filament\Schemas\Components\Section::make('IPI')
                    ->description('Configuração padrão do IPI. Preencha apenas se aplicável.')
                    ->icon('heroicon-o-beaker')
                    ->schema([
                        Forms\Components\TextInput::make('ipi_cst_default')
                            ->label('CST IPI Padrão')
                            ->maxLength(3)
                            ->placeholder('Ex: 50, 99')
                            ->columnSpan(['md' => 1]),

                        Forms\Components\TextInput::make('ipi_aliquota_default')
                            ->label('Alíquota IPI (%)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%')
                            ->columnSpan(['md' => 1]),

                        Forms\Components\TextInput::make('ipi_enquadramento')
                            ->label('Código Enquadramento Legal')
                            ->maxLength(10)
                            ->placeholder('Ex: 999')
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 3])
                    ->collapsible()
                    ->collapsed(),

                // CFOP Rules
                \Filament\Schemas\Components\Section::make('Regras de CFOP por Operação')
                    ->description('Define automaticamente o CFOP com base na natureza da operação.')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->schema([
                        Forms\Components\Repeater::make('cfop_rules')
                            ->hiddenLabel()
                            ->compact()
                            ->schema([
                                Forms\Components\Select::make('operation_nature')
                                    ->label('Natureza da Operação')
                                    ->options(OperationNature::toSelectArray())
                                    ->native(false)
                                    ->required()
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\TextInput::make('cfop')
                                    ->label('CFOP Padrão')
                                    ->required()
                                    ->maxLength(4)
                                    ->placeholder('Ex: 5102')
                                    ->columnSpan(['md' => 1]),

                                Forms\Components\KeyValue::make('cfop_exceptions')
                                    ->label('Exceções por Prefixo NCM')
                                    ->keyLabel('Prefixo NCM')
                                    ->valueLabel('CFOP')
                                    ->keyPlaceholder('Ex: 2710')
                                    ->valuePlaceholder('Ex: 5405')
                                    ->helperText('Quando o NCM do item começar com o prefixo informado, será usado o CFOP da exceção.')
                                    ->columnSpanFull(),
                            ])
                            ->columns(['md' => 2])
                            ->addActionLabel('Adicionar regra CFOP')
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                // Informações complementares
                \Filament\Schemas\Components\Section::make('Informações Complementares')
                    ->description('Texto padrão para informações adicionais da NF-e.')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Forms\Components\Textarea::make('informacoes_adicionais_fisco')
                            ->label('Informações adicionais de interesse do fisco (infAdFisco)')
                            ->rows(3)
                            ->minLength(1)
                            ->maxLength(2000)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('informacoes_adicionais_contribuinte')
                            ->label('Informações adicionais de interesse do contribuinte (infCpl)')
                            ->rows(4)
                            ->minLength(1)
                            ->maxLength(5000)
                            ->columnSpanFull(),

                        \Filament\Schemas\Components\Section::make('Informações adicionais de compra')
                            ->schema([
                                Forms\Components\TextInput::make('informacoes_adicionais_compra_nota_empenho')
                                    ->label('Nota de Empenho')
                                    ->maxLength(60),

                                Forms\Components\TextInput::make('informacoes_adicionais_compra_pedido')
                                    ->label('Pedido')
                                    ->maxLength(60),

                                Forms\Components\TextInput::make('informacoes_adicionais_compra_contrato')
                                    ->label('Contrato')
                                    ->maxLength(60),
                            ])
                            ->columns(['md' => 3])
                            ->columnSpanFull()
                            ->collapsible(),

                        Forms\Components\Repeater::make('observacoes_contribuinte')
                            ->label('Observações do Contribuinte')
                            ->schema([
                                Forms\Components\TextInput::make('campo')
                                    ->label('Campo')
                                    ->maxLength(20),
                                Forms\Components\TextInput::make('texto')
                                    ->label('Texto')
                                    ->maxLength(60),
                            ])
                            ->columns(['md' => 2])
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columnSpanFull(),

                        Forms\Components\Repeater::make('observacoes_fisco')
                            ->label('Observações do Fisco')
                            ->schema([
                                Forms\Components\TextInput::make('campo')
                                    ->label('Campo')
                                    ->maxLength(20),
                                Forms\Components\TextInput::make('texto')
                                    ->label('Texto')
                                    ->maxLength(60),
                            ])
                            ->columns(['md' => 2])
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('informacoes_complementares_padrao')
                            ->label('Texto padrão')
                            ->helperText('Campo legado. Preferir os campos estruturados acima (infAdFisco/infCpl/compra/observações).')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $companyId = Filament::getTenant()?->id;
        $userId = Auth::id();

        // Criar ou atualizar FiscalProfile
        $profile = FiscalProfile::updateOrCreate(
            ['company_id' => $companyId],
            [
                'tax_regime' => $data['tax_regime'],
                'cnae_principal' => $data['cnae_principal'] ?? null,
                'is_active' => true,
                'updated_by' => $userId,
            ]
        );

        if ($profile->wasRecentlyCreated) {
            $profile->update(['created_by' => $userId]);
        }

        $profile->update([
            // ICMS
            'icms_cst_default' => $data['icms_cst_default'] ?? null,
            'icms_csosn_default' => $data['icms_csosn_default'] ?? null,
            'icms_aliquota_interna' => $data['icms_aliquota_interna'] ?? null,
            'icms_reducao_base' => $data['icms_reducao_base'] ?? null,
            'icms_modalidade_base_calculo' => $data['icms_modalidade_base_calculo'] ?? null,

            // ICMS ST
            'icms_st_aliquota' => $data['icms_st_aliquota'] ?? null,
            'icms_st_mva' => $data['icms_st_mva'] ?? null,
            'icms_st_reducao_base' => $data['icms_st_reducao_base'] ?? null,

            // Interestaduais
            'icms_aliquotas_interestaduais' => $data['icms_aliquotas_interestaduais'] ?? null,

            // PIS
            'pis_cst_default' => $data['pis_cst_default'] ?? null,
            'pis_aliquota_default' => $data['pis_aliquota_default'] ?? null,

            // COFINS
            'cofins_cst_default' => $data['cofins_cst_default'] ?? null,
            'cofins_aliquota_default' => $data['cofins_aliquota_default'] ?? null,

            // IPI
            'ipi_cst_default' => $data['ipi_cst_default'] ?? null,
            'ipi_aliquota_default' => $data['ipi_aliquota_default'] ?? null,
            'ipi_enquadramento' => $data['ipi_enquadramento'] ?? null,

            // CFOP
            'cfop_rules' => collect($data['cfop_rules'] ?? [])
                ->filter(fn (array $rule) => ! empty($rule['operation_nature']) && ! empty($rule['cfop']))
                ->mapWithKeys(function (array $rule): array {
                    $exceptions = collect($rule['cfop_exceptions'] ?? [])
                        ->filter(fn ($cfop, $prefix) => ! empty($prefix) && ! empty($cfop))
                        ->toArray();

                    return [
                        $rule['operation_nature'] => [
                            'default_cfop' => $rule['cfop'],
                            'exceptions' => $exceptions,
                        ],
                    ];
                })
                ->toArray(),

            // Info complementar
            'informacoes_adicionais_fisco' => $data['informacoes_adicionais_fisco'] ?? null,
            'informacoes_adicionais_contribuinte' => $data['informacoes_adicionais_contribuinte'] ?? null,
            'informacoes_adicionais_compra' => [
                'nota_empenho' => $data['informacoes_adicionais_compra_nota_empenho'] ?? null,
                'pedido' => $data['informacoes_adicionais_compra_pedido'] ?? null,
                'contrato' => $data['informacoes_adicionais_compra_contrato'] ?? null,
            ],
            'observacoes_contribuinte' => collect($data['observacoes_contribuinte'] ?? [])
                ->filter(fn (array $item) => ! empty($item['campo']) || ! empty($item['texto']))
                ->values()
                ->toArray(),
            'observacoes_fisco' => collect($data['observacoes_fisco'] ?? [])
                ->filter(fn (array $item) => ! empty($item['campo']) || ! empty($item['texto']))
                ->values()
                ->toArray(),
            'informacoes_complementares_padrao' => $data['informacoes_complementares_padrao'] ?? null,
        ]);

        Notification::make()
            ->title('Perfil fiscal salvo com sucesso!')
            ->body('As configurações do perfil fiscal foram atualizadas.')
            ->success()
            ->send();
    }

    /**
     * Aplica defaults sugeridos ao trocar de regime tributário.
     */
    private function applyRegimeDefaults(Set $set, ?string $regimeValue): void
    {
        if ($regimeValue === null) {
            return;
        }

        $regime = TaxRegime::tryFrom($regimeValue);

        if ($regime === null) {
            return;
        }

        match ($regime) {
            TaxRegime::MEI => $this->setMeiDefaults($set),
            TaxRegime::SIMPLES_NACIONAL => $this->setSimplesDefaults($set),
            TaxRegime::LUCRO_PRESUMIDO => $this->setLucroPresumidoDefaults($set),
            TaxRegime::LUCRO_REAL => $this->setLucroRealDefaults($set),
        };
    }

    private function setMeiDefaults(Set $set): void
    {
        $set('icms_csosn_default', '102');
        $set('icms_cst_default', null);
        $set('icms_aliquota_interna', 0);
        $set('pis_cst_default', '99');
        $set('pis_aliquota_default', 0);
        $set('cofins_cst_default', '99');
        $set('cofins_aliquota_default', 0);
    }

    private function setSimplesDefaults(Set $set): void
    {
        $set('icms_csosn_default', '102');
        $set('icms_cst_default', null);
        $set('icms_aliquota_interna', 0);
        $set('pis_cst_default', '49');
        $set('pis_aliquota_default', 0.65);
        $set('cofins_cst_default', '49');
        $set('cofins_aliquota_default', 3.00);
    }

    private function setLucroPresumidoDefaults(Set $set): void
    {
        $set('icms_cst_default', '00');
        $set('icms_csosn_default', null);
        $set('icms_modalidade_base_calculo', '3');
        $set('pis_cst_default', '01');
        $set('pis_aliquota_default', 1.65);
        $set('cofins_cst_default', '01');
        $set('cofins_aliquota_default', 7.60);
    }

    private function setLucroRealDefaults(Set $set): void
    {
        $set('icms_cst_default', '00');
        $set('icms_csosn_default', null);
        $set('icms_modalidade_base_calculo', '3');
        $set('pis_cst_default', '01');
        $set('pis_aliquota_default', 1.65);
        $set('cofins_cst_default', '01');
        $set('cofins_aliquota_default', 7.60);
    }

    private function isRegimeNormal(?string $regime): bool
    {
        return in_array($regime, [TaxRegime::LUCRO_PRESUMIDO->value, TaxRegime::LUCRO_REAL->value]);
    }

    private function isRegimeSimplesOrMei(?string $regime): bool
    {
        return in_array($regime, [TaxRegime::MEI->value, TaxRegime::SIMPLES_NACIONAL->value]);
    }

    public static function csosnOptions(): array
    {
        return [
            '101' => '101 - Tributada com permissão de crédito',
            '102' => '102 - Tributada sem permissão de crédito',
            '103' => '103 - Isenção do ICMS para faixa de receita bruta',
            '201' => '201 - Tributada com permissão de crédito e com cobrança do ICMS por ST',
            '202' => '202 - Tributada sem permissão de crédito e com cobrança do ICMS por ST',
            '203' => '203 - Isenção do ICMS para faixa de receita bruta e com cobrança do ICMS por ST',
            '300' => '300 - Imune',
            '400' => '400 - Não tributada',
            '500' => '500 - ICMS cobrado anteriormente por ST ou por antecipação',
            '900' => '900 - Outros',
        ];
    }
}
