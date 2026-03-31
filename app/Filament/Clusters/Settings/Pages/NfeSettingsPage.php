<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\CompanyPreference;
use App\Services\Fiscal\NfeConfigService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Página de configurações da integração NF-e (IntegraNotas).
 *
 * Visível apenas para super_admin — o token de produção/homologação
 * e o ambiente são dados sensíveis que não devem ser acessíveis por
 * usuários comuns da empresa.
 */
class NfeSettingsPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected string $view = 'filament.clusters.settings.pages.nfe-settings';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationLabel = 'Integração NF-e';

    protected static ?string $title = 'Configurações NF-e — IntegraNotas';

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public array $consulta = [];

    public array $reportRows = [];

    public array $reportMeta = [];

    public ?string $reportError = null;

    /**
     * Apenas super_admin visualiza esta página.
     */
    public static function canAccess(): bool
    {
        // return Auth::user()?->hasRole('super_admin') ?? false;
        return true; // TODO: remover esta linha após criar role super_admin e atribuir aos usuários adequados
    }

    public function mount(): void
    {
        $companyId = Filament::getTenant()?->id;

        $this->form->fill([
            'token_producao'    => CompanyPreference::get('integranotas.token_producao', $companyId),
            'token_homologacao' => CompanyPreference::get('integranotas.token_homologacao', $companyId),
            'ambiente'          => (string) (CompanyPreference::get('integranotas.ambiente', $companyId) ?? NfeConfigService::AMBIENTE_HOMOLOGACAO),
            'serie_padrao'      => CompanyPreference::get('integranotas.serie_padrao', $companyId) ?? '1',
            'nfse_serie_padrao' => CompanyPreference::get('integranotas.nfse_serie_padrao', $companyId) ?? '1',
            'webhook_secret'    => CompanyPreference::get('integranotas.webhook_secret', $companyId),
        ]);

        $this->consulta = [
            'numero_inicial' => null,
            'numero_final'   => null,
            'serie'          => CompanyPreference::get('integranotas.nfse_serie_padrao', $companyId) ?? '1',
            'pagina'         => 1,
        ];
    }

    public function consultIssuedNfse(): void
    {
        $companyId = Filament::getTenant()?->id;

        $validated = Validator::make($this->consulta, [
            'numero_inicial' => ['required', 'integer', 'min:1'],
            'numero_final'   => ['nullable', 'integer', 'gte:numero_inicial'],
            'serie'          => ['nullable', 'string', 'max:5'],
            'pagina'         => ['nullable', 'integer', 'min:1'],
        ], [
            'numero_inicial.required' => 'Informe o número inicial da NFS-e.',
            'numero_final.gte'        => 'O número final deve ser maior ou igual ao número inicial.',
        ])->validate();

        $this->reportError = null;
        $this->reportRows = [];
        $this->reportMeta = [];

        try {
            $configService = app(\App\Services\Fiscal\NfseConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfse($configService->buildSdkParams($companyId));

            $payload = array_filter([
                'numero_inicial' => (int) $validated['numero_inicial'],
                'numero_final'   => ! empty($validated['numero_final']) ? (int) $validated['numero_final'] : null,
                'serie'          => ! empty($validated['serie']) ? (string) $validated['serie'] : null,
                'pagina'         => ! empty($validated['pagina']) ? (int) $validated['pagina'] : null,
            ], fn ($value) => $value !== null && $value !== '');

            $response = $sdk->localiza($payload);
            $responseArray = json_decode(json_encode($response), true) ?? [];

            $rows = $this->extractRowsFromResponse($responseArray);

            $this->reportRows = array_values($rows);
            $this->reportMeta = [
                'codigo'            => $responseArray['codigo'] ?? null,
                'mensagem'          => $responseArray['mensagem'] ?? null,
                'total_encontrado'  => count($this->reportRows),
                'valor_total'       => collect($this->reportRows)
                    ->map(fn (array $item): float => (float) ($item['valor'] ?? $item['valor_servicos'] ?? 0))
                    ->sum(),
                'payload_utilizado' => $payload,
            ];

            Notification::make()
                ->title('Consulta finalizada')
                ->body(count($this->reportRows) > 0
                    ? 'NFS-e localizadas com sucesso.'
                    : 'Consulta executada, mas nenhum registro foi encontrado com os filtros informados.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            $this->reportError = 'Não foi possível consultar as NFS-e na prefeitura: ' . $e->getMessage();

            Log::error('NfeSettingsPage: erro ao consultar NFS-e via localiza', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'erro'   => $e->getMessage(),
                'tenant' => $companyId,
                'filtros' => $validated,
            ]);

            Notification::make()
                ->title('Erro ao consultar NFS-e')
                ->body($this->reportError)
                ->danger()
                ->send();
        }
    }

    private function extractRowsFromResponse(array $response): array
    {
        $possibleKeys = ['notas', 'nfse', 'nfses', 'dados', 'lista', 'data'];

        foreach ($possibleKeys as $key) {
            if (! isset($response[$key])) {
                continue;
            }

            $candidate = $response[$key];

            if (is_array($candidate)) {
                if ($this->isSequentialArray($candidate)) {
                    return array_map(fn ($item) => is_array($item) ? $item : (array) $item, $candidate);
                }

                foreach ($possibleKeys as $nestedKey) {
                    if (isset($candidate[$nestedKey]) && is_array($candidate[$nestedKey]) && $this->isSequentialArray($candidate[$nestedKey])) {
                        return array_map(fn ($item) => is_array($item) ? $item : (array) $item, $candidate[$nestedKey]);
                    }
                }
            }
        }

        if ($this->isSequentialArray($response)) {
            return array_map(fn ($item) => is_array($item) ? $item : (array) $item, $response);
        }

        return [];
    }

    private function isSequentialArray(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Ambiente')
                    ->description('Define se a empresa está operando em homologação ou produção.')
                    ->icon('heroicon-o-server')
                    ->schema([
                        Forms\Components\Select::make('ambiente')
                            ->label('Ambiente Ativo')
                            ->options([
                                (string) NfeConfigService::AMBIENTE_HOMOLOGACAO => 'Homologação (testes)',
                                (string) NfeConfigService::AMBIENTE_PRODUCAO    => 'Produção',
                            ])
                            ->native(false)
                            ->required()
                            ->helperText('Alterne para "Produção" somente após validar em homologação.')
                            ->columnSpan(['md' => 1]),

                        Forms\Components\TextInput::make('serie_padrao')
                            ->label('Série Padrão da NF-e')
                            ->maxLength(3)
                            ->required()
                            ->default('1')
                            ->helperText('Normalmente "1". Só altere se a SEFAZ exigir outra série.')
                            ->columnSpan(['md' => 1]),

                        Forms\Components\TextInput::make('nfse_serie_padrao')
                            ->label('Série Padrão da NFS-e (RPS)')
                            ->numeric()
                            ->maxLength(5)
                            ->minLength(1)
                            ->rule('regex:/^[0-9]{1,5}$/')
                            ->required()
                            ->default('1')
                            ->dehydrateStateUsing(fn ($state) => substr(preg_replace('/\D/', '', (string) $state) ?: '1', 0, 5))
                            ->helperText('Informe de 1 a 5 dígitos numéricos, conforme exigência do município/provedor.')
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 3])
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('Tokens de Acesso')
                    ->description('Tokens JWT fornecidos pela IntegraNotas para cada ambiente. Obtenha em gestao.integranotas.com.br (produção) ou hom-gestao.integranotas.com.br (homologação).')
                    ->icon('heroicon-o-key')
                    ->schema([
                        Forms\Components\TextInput::make('token_homologacao')
                            ->label('Token — Homologação')
                            ->password()
                            ->revealable()
                            ->maxLength(1000)
                            ->helperText('Token do ambiente de testes.')
                            ->columnSpan(['md' => 2]),

                        Forms\Components\TextInput::make('token_producao')
                            ->label('Token — Produção')
                            ->password()
                            ->revealable()
                            ->maxLength(1000)
                            ->helperText('Token do ambiente de produção. Mantenha em segredo.')
                            ->columnSpan(['md' => 2]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('Webhook')
                    ->description('A IntegraNotas enviará notificações ao endpoint POST /webhook/nfe após processar cada NF-e.')
                    ->icon('heroicon-o-arrow-path')
                    ->schema([
                        Forms\Components\TextInput::make('webhook_secret')
                            ->label('Assinatura do Webhook (signature)')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('Valor que a IntegraNotas enviará no campo "signature". Deixe em branco para desabilitar a validação.')
                            ->columnSpan(['md' => 2]),

                        TextEntry::make('webhook_url')
                            ->label('URL do Webhook')
                            ->state(fn () => url('/webhook/nfe'))
                            ->helperText('Configure esta URL no painel da IntegraNotas (Emitente → Webhook).')
                            ->columnSpan(['md' => 2]),
                    ])
                    ->columns(['md' => 2])
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data      = $this->form->getState();
        $companyId = Filament::getTenant()?->id;

        CompanyPreference::set('integranotas.ambiente', (int) $data['ambiente'], $companyId);
        CompanyPreference::set('integranotas.serie_padrao', $data['serie_padrao'], $companyId);
        CompanyPreference::set('integranotas.nfse_serie_padrao', $data['nfse_serie_padrao'], $companyId);

        if (! empty($data['token_homologacao'])) {
            CompanyPreference::set('integranotas.token_homologacao', $data['token_homologacao'], $companyId);
        }

        if (! empty($data['token_producao'])) {
            CompanyPreference::set('integranotas.token_producao', $data['token_producao'], $companyId);
        }

        if (isset($data['webhook_secret'])) {
            CompanyPreference::set('integranotas.webhook_secret', $data['webhook_secret'], $companyId);
        }

        Notification::make()
            ->title('Configurações NF-e salvas com sucesso!')
            ->success()
            ->send();
    }
}
