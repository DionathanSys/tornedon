<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enum\FiscalDocument\NfseModel;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\CompanyPreference;
use App\Services\Fiscal\NfeConfigService;
use App\Services\Fiscal\Sefaz\CompanySefazCertificateService;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionResult;
use App\Services\Fiscal\Sefaz\SefazDfeDistributionService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

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
            'nfse_serie_padrao'    => CompanyPreference::get('integranotas.nfse_serie_padrao', $companyId) ?? '1',
            'nfse_modelo_padrao'   => CompanyPreference::get('integranotas.nfse_modelo_padrao', $companyId) ?? NfseModel::MUNICIPAL->value,
            'webhook_secret'       => CompanyPreference::get('integranotas.webhook_secret', $companyId),
            'sefaz_a1_password'    => CompanyPreference::get(CompanySefazCertificateService::PASSWORD_PREFERENCE_KEY, $companyId),
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
                ->schema([
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
            Action::make('consultarDfeRecebidas')
                ->label('Consultar DF-e recebidos (SEFAZ)')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('success')
                ->modalHeading('Consulta de DF-e recebidos (NF-e modelo 55)')
                ->modalDescription('Busca documentos emitidos contra o CNPJ da empresa e gera um ZIP com XMLs + resposta completa da API.')
                ->schema([
                    Forms\Components\ToggleButtons::make('modo')
                        ->label('Modo da consulta')
                        ->options([
                            'ultimo_nsu' => 'A partir do último NSU',
                            'numero_nsu' => 'NSU específico',
                        ])
                        ->inline()
                        ->default('ultimo_nsu')
                        ->required(),
                    Forms\Components\TextInput::make('ultimo_nsu')
                        ->label('Último NSU')
                        ->helperText('Use o último NSU retornado com sucesso para sincronização contínua.')
                        ->default((string) (CompanyPreference::get('sefaz.distribuicao_dfe.ultimo_nsu', Filament::getTenant()?->id) ?? '0'))
                        ->visible(fn (Get $get): bool => $get('modo') === 'ultimo_nsu')
                        ->required(fn (Get $get): bool => $get('modo') === 'ultimo_nsu')
                        ->rule('regex:/^[0-9]{1,15}$/'),
                    Forms\Components\TextInput::make('numero_nsu')
                        ->label('Número NSU')
                        ->helperText('Quando informado, retorna somente o documento daquele NSU.')
                        ->visible(fn (Get $get): bool => $get('modo') === 'numero_nsu')
                        ->required(fn (Get $get): bool => $get('modo') === 'numero_nsu')
                        ->rule('regex:/^[0-9]{1,15}$/'),
                    Forms\Components\Toggle::make('salvar_ultimo_nsu')
                        ->label('Salvar automaticamente o último NSU retornado')
                        ->default(true),
                ])
                ->action(fn (array $data): StreamedResponse => $this->downloadReceivedFiscalDocuments($data)),
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

    private function downloadReceivedFiscalDocuments(array $data): StreamedResponse
    {
        $company = Filament::getTenant();
        $companyId = $company?->id;
        $payload = [
            'ultimo_nsu' => $data['modo'] === 'ultimo_nsu' ? (string) ($data['ultimo_nsu'] ?? '0') : null,
            'numero_nsu' => $data['modo'] === 'numero_nsu' ? (string) ($data['numero_nsu'] ?? '') : null,
        ];

        try {
            if (! $company) {
                throw new \RuntimeException('Empresa não identificada para consultar DF-e na SEFAZ.');
            }

            $mode = $data['modo'] === 'numero_nsu' ? 'numero_nsu' : 'ultimo_nsu';
            $value = $mode === 'numero_nsu'
                ? (string) ($payload['numero_nsu'] ?? '')
                : (string) ($payload['ultimo_nsu'] ?? '0');

            $result = app(SefazDfeDistributionService::class)->distribute($company, $mode, $value);

            if (! $result->success) {
                $message = trim("{$result->statusCode} - {$result->statusMessage}", ' -');
                Notification::make()
                    ->title('Erro ao consultar DF-e recebidos')
                    ->body($message !== '' ? $message : 'Não foi possível consultar os DF-e recebidos para este CNPJ.')
                    ->danger()
                    ->send();

                return response()->streamDownload(fn () => null, 'dfe-recebidos.zip');
            }

            if (($data['salvar_ultimo_nsu'] ?? true) === true && $result->ultNsu !== null) {
                CompanyPreference::set('sefaz.distribuicao_dfe.ultimo_nsu', $result->ultNsu, $companyId);
            }

            $zipPath = $this->buildDfeZip($result);

            Notification::make()
                ->title('Consulta realizada com sucesso')
                ->body(sprintf(
                    'Foram encontrados %d documento(s). %s',
                    count($result->documents),
                    count($result->documents) > 0 ? 'O ZIP está sendo gerado para download.' : 'Somente a resposta da SEFAZ será incluída no ZIP.'
                ))
                ->success()
                ->send();

            return response()->streamDownload(function () use ($zipPath) {
                $stream = fopen($zipPath, 'rb');
                if ($stream === false) {
                    return;
                }

                while (! feof($stream)) {
                    echo fread($stream, 8192);
                }

                fclose($stream);
                @unlink($zipPath);
            }, 'dfe-recebidos.zip', ['Content-Type' => 'application/zip']);
        } catch (\Throwable $e) {
            Log::error('NfeSettingsPage: erro ao consultar DF-e recebidos', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'erro' => $e->getMessage(),
                'tenant' => $companyId,
                'payload' => $payload,
                'company_document' => $company?->document_number,
            ]);

            Notification::make()
                ->title('Erro ao consultar DF-e recebidos')
                ->body('Não foi possível consultar os documentos emitidos contra o CNPJ da empresa: ' . $e->getMessage())
                ->danger()
                ->send();

            return response()->streamDownload(fn () => null, 'dfe-recebidos.zip');
        }
    }

    private function buildDfeZip(DfeDistributionResult $result): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'dfe_recebidos_');
        if ($tmpFile === false) {
            throw new \RuntimeException('Não foi possível preparar o arquivo temporário do ZIP.');
        }

        $zipPath = "{$tmpFile}.zip";
        @unlink($tmpFile);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível criar o arquivo ZIP de retorno.');
        }

        $zip->addFromString('resposta-sefaz.xml', $result->rawXml);
        $zip->addFromString('resumo.json', json_encode($result->toSummary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        foreach ($result->documents as $index => $document) {
            $zip->addFromString(
                sprintf('%03d-%s', $index + 1, $this->sanitizeFilename($document)),
                $document->xml,
            );
        }

        $zip->close();

        return $zipPath;
    }

    private function sanitizeFilename(DfeDistributionDocument $document): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $document->filename()) ?? 'dfe.xml';

        return trim($name, '-') !== '' ? $name : 'dfe.xml';
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

                        Forms\Components\Select::make('nfse_modelo_padrao')
                            ->label('Modelo Padrão da NFS-e')
                            ->options(NfseModel::toSelectArray())
                            ->native(false)
                            ->required()
                            ->default(NfseModel::MUNICIPAL->value)
                            ->helperText('Define se a NFS-e será emitida pelo modelo Municipal ou Nacional ao confirmar faturas.')
                            ->columnSpan(['md' => 1]),
                    ])
                    ->columns(['md' => 4])
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

                \Filament\Schemas\Components\Section::make('Consulta DF-e via SEFAZ')
                    ->description('Configurações usadas exclusivamente para consultar NF-e recebidas diretamente no Ambiente Nacional.')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        Forms\Components\TextInput::make('sefaz_a1_password')
                            ->label('Senha do certificado A1')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('Senha do arquivo A1 vinculado à empresa para autenticação mútua TLS na SEFAZ.')
                            ->columnSpanFull(),
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
        CompanyPreference::set('integranotas.nfse_modelo_padrao', $data['nfse_modelo_padrao'], $companyId);

        if (! empty($data['token_homologacao'])) {
            CompanyPreference::set('integranotas.token_homologacao', $data['token_homologacao'], $companyId);
        }

        if (! empty($data['token_producao'])) {
            CompanyPreference::set('integranotas.token_producao', $data['token_producao'], $companyId);
        }

        if (isset($data['webhook_secret'])) {
            CompanyPreference::set('integranotas.webhook_secret', $data['webhook_secret'], $companyId);
        }

        if (isset($data['sefaz_a1_password'])) {
            CompanyPreference::set(CompanySefazCertificateService::PASSWORD_PREFERENCE_KEY, (string) $data['sefaz_a1_password'], $companyId);
        }

        Notification::make()
            ->title('Configurações NF-e salvas com sucesso!')
            ->success()
            ->send();
    }
}
