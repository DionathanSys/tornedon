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
            'webhook_secret'    => CompanyPreference::get('integranotas.webhook_secret', $companyId),
        ]);
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
                    ])
                    ->columns(['md' => 2])
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
