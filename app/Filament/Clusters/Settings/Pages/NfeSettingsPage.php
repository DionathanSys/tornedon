<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\CompanyPreference;
use App\Services\Fiscal\NfeConfigService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('consultarDocumentosPrefeitura')
                ->label('Consultar documentos (Prefeitura)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('warning')
                ->modalHeading('Consulta de documentos na prefeitura')
                ->modalDescription('Informe o intervalo de número e data de emissão para retornar o PDF com as notas.')
                ->form([
                    Forms\Components\TextInput::make('nfse_numero_inicial')
                        ->label('NFS-e número inicial')
                        ->numeric()
                        ->required()
                        ->minValue(1),
                    Forms\Components\TextInput::make('nfse_numero_final')
                        ->label('NFS-e número final')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->gte('nfse_numero_inicial'),
                    Forms\Components\DatePicker::make('data_emissao_inicial')
                        ->label('Data de emissão inicial')
                        ->required(),
                    Forms\Components\DatePicker::make('data_emissao_final')
                        ->label('Data de emissão final')
                        ->required()
                        ->afterOrEqual('data_emissao_inicial'),
                ])
                ->action(fn (array $data): StreamedResponse => $this->downloadMunicipalDocumentsPdf($data)),
        ];
    }

    private function downloadMunicipalDocumentsPdf(array $data): StreamedResponse
    {
        $companyId = Filament::getTenant()?->id;
        $payload = [
            'nfse_numero_inicial' => (string) $data['nfse_numero_inicial'],
            'nfse_numero_final' => (string) $data['nfse_numero_final'],
            'data_emissao_inicial' => (string) $data['data_emissao_inicial'],
            'data_emissao_final' => (string) $data['data_emissao_final'],
        ];

        try {
            $configService = app(\App\Services\Fiscal\NfseConfigService::class);
            $sdk = new \CloudDfe\SdkPHP\Nfse($configService->buildSdkParams($companyId));
            $response = $sdk->localiza($payload);
            $responseArray = json_decode(json_encode($response), true) ?? [];

            if (($responseArray['sucesso'] ?? false) !== true || empty($responseArray['notas'])) {
                $message = (string) ($responseArray['mensagem'] ?? 'A prefeitura não retornou o PDF das notas.');
                Notification::make()->title('Erro ao consultar documentos')->body($message)->danger()->send();
                return response()->streamDownload(fn () => null, 'notas-prefeitura.pdf');
            }

            $pdfBase64 = (string) $responseArray['notas'];

            return response()->streamDownload(function () use ($pdfBase64) {
                echo base64_decode($pdfBase64);
            }, 'notas-prefeitura.pdf', ['Content-Type' => 'application/pdf']);
        } catch (\Throwable $e) {
            Log::error('NfeSettingsPage: erro ao consultar documentos da prefeitura', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'erro' => $e->getMessage(),
                'tenant' => $companyId,
                'payload' => $payload,
            ]);

            Notification::make()
                ->title('Erro ao consultar documentos')
                ->body('Não foi possível consultar os documentos na prefeitura: ' . $e->getMessage())
                ->danger()
                ->send();

            return response()->streamDownload(fn () => null, 'notas-prefeitura.pdf');
        }
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
