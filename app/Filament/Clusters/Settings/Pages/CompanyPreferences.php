<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\CompanyPreference;
use BackedEnum;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Facades\Filament;

class CompanyPreferences extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.clusters.settings.pages.company-preferences';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationLabel = 'Preferências';

    protected static ?string $title = 'Preferências da Empresa';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public function mount(): void
    {
        $companyId = Filament::getTenant()?->id;

        $this->form->fill([
            'default_payment_method' => CompanyPreference::getDefaultPaymentMethod($companyId),
            'default_payment_condition' => CompanyPreference::getDefaultPaymentCondition($companyId),
            'default_quote_validity_days' => CompanyPreference::getDefaultQuoteValidityDays($companyId) ?? 30,
            'default_profit_margin' => CompanyPreference::getDefaultProfitMargin($companyId),
            
            // Configurações de notificação
            'notify_new_order' => CompanyPreference::get('notify_new_order', $companyId, true),
            'notify_status_change' => CompanyPreference::get('notify_status_change', $companyId, true),
            'notify_low_stock' => CompanyPreference::get('notify_low_stock', $companyId, true),
            'notify_overdue_payments' => CompanyPreference::get('notify_overdue_payments', $companyId, true),
            
            // Configurações de email
            'email_signature' => CompanyPreference::get('email_signature', $companyId),
            'email_cc' => CompanyPreference::get('email_cc', $companyId),
            
            // Outras configurações
            'default_warranty_days' => CompanyPreference::get('default_warranty_days', $companyId, 90),
            'require_approval_threshold' => CompanyPreference::get('require_approval_threshold', $companyId),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Pagamento')
                    ->description('Configurações padrão para pagamentos')
                    ->icon('heroicon-o-credit-card')
                    ->schema([
                        Forms\Components\Select::make('default_payment_method')
                            ->label('Método de Pagamento Padrão')
                            ->options(PaymentMethod::toSelectArray())
                            ->native(false)
                            ->searchable()
                            ->placeholder('Selecione um método padrão')
                            ->helperText('Este método será pré-selecionado em novos pedidos')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\Select::make('default_payment_condition')
                            ->label('Condição de Pagamento Padrão')
                            ->options(PaymentCondition::toGroupedSelectArray())
                            ->native(false)
                            ->searchable()
                            ->placeholder('Selecione uma condição padrão')
                            ->helperText('Esta condição será pré-selecionada em novos pedidos')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                    ])
                    ->columns(['md' => 2, 'lg' => 2])
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('Vendas e Orçamentos')
                    ->description('Configurações relacionadas a vendas e orçamentos')
                    ->icon('heroicon-o-shopping-cart')
                    ->schema([
                        Forms\Components\TextInput::make('default_quote_validity_days')
                            ->label('Validade Padrão de Orçamentos (dias)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->default(30)
                            ->helperText('Número de dias que um orçamento permanece válido')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\TextInput::make('default_profit_margin')
                            ->label('Margem de Lucro Padrão (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.1)
                            ->suffix('%')
                            ->helperText('Margem de lucro padrão aplicada em produtos')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\TextInput::make('default_warranty_days')
                            ->label('Garantia Padrão (dias)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(3650)
                            ->default(90)
                            ->helperText('Período de garantia padrão para serviços')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\TextInput::make('require_approval_threshold')
                            ->label('Limite para Aprovação (R$)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('R$')
                            ->helperText('Ordens acima deste valor requerem aprovação')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                    ])
                    ->columns(['md' => 2, 'lg' => 2])
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('Notificações')
                    ->description('Configure quais notificações você deseja receber')
                    ->icon('heroicon-o-bell')
                    ->schema([
                        Forms\Components\Toggle::make('notify_new_order')
                            ->label('Notificar em Novos Pedidos')
                            ->helperText('Receber notificação quando um novo pedido for criado')
                            ->inline(false)
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\Toggle::make('notify_status_change')
                            ->label('Notificar Mudança de Status')
                            ->helperText('Receber notificação quando o status de um pedido mudar')
                            ->inline(false)
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\Toggle::make('notify_low_stock')
                            ->label('Notificar Estoque Baixo')
                            ->helperText('Receber notificação quando o estoque estiver abaixo do mínimo')
                            ->inline(false)
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\Toggle::make('notify_overdue_payments')
                            ->label('Notificar Pagamentos Atrasados')
                            ->helperText('Receber notificação de pagamentos vencidos')
                            ->inline(false)
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                    ])
                    ->columns(['md' => 2, 'lg' => 2])
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('E-mail')
                    ->description('Configurações de e-mail corporativo')
                    ->icon('heroicon-o-envelope')
                    ->schema([
                        Forms\Components\Textarea::make('email_signature')
                            ->label('Assinatura de E-mail')
                            ->rows(4)
                            ->placeholder('Digite a assinatura padrão para e-mails')
                            ->helperText('Assinatura que será incluída nos e-mails enviados')
                            ->columnSpanFull(),
                        
                        Forms\Components\TextInput::make('email_cc')
                            ->label('E-mail para Cópia (CC)')
                            ->email()
                            ->placeholder('gerencia@empresa.com')
                            ->helperText('E-mail que receberá cópia de todas as comunicações')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                    ])
                    ->columns(['md' => 2, 'lg' => 2])
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $companyId = Filament::getTenant()?->id;

        if (!$companyId) {
            Notification::make()
                ->title('Erro ao salvar')
                ->body('Empresa não identificada.')
                ->danger()
                ->send();
            return;
        }

        try {
            // Preferências de pagamento
            if (isset($data['default_payment_method'])) {
                CompanyPreference::setDefaultPaymentMethod($data['default_payment_method'], $companyId);
            }
            
            if (isset($data['default_payment_condition'])) {
                CompanyPreference::setDefaultPaymentCondition($data['default_payment_condition'], $companyId);
            }

            // Preferências de vendas
            if (isset($data['default_quote_validity_days'])) {
                CompanyPreference::setDefaultQuoteValidityDays($data['default_quote_validity_days'], $companyId);
            }
            
            if (isset($data['default_profit_margin'])) {
                CompanyPreference::setDefaultProfitMargin($data['default_profit_margin'], $companyId);
            }

            // Outras preferências de negócio
            if (isset($data['default_warranty_days'])) {
                CompanyPreference::set('default_warranty_days', $data['default_warranty_days'], $companyId);
            }
            
            if (isset($data['require_approval_threshold'])) {
                CompanyPreference::set('require_approval_threshold', $data['require_approval_threshold'], $companyId);
            }

            // Notificações
            CompanyPreference::set('notify_new_order', $data['notify_new_order'] ?? false, $companyId);
            CompanyPreference::set('notify_status_change', $data['notify_status_change'] ?? false, $companyId);
            CompanyPreference::set('notify_low_stock', $data['notify_low_stock'] ?? false, $companyId);
            CompanyPreference::set('notify_overdue_payments', $data['notify_overdue_payments'] ?? false, $companyId);

            // Configurações de email
            if (isset($data['email_signature'])) {
                CompanyPreference::set('email_signature', $data['email_signature'], $companyId);
            }
            
            if (isset($data['email_cc'])) {
                CompanyPreference::set('email_cc', $data['email_cc'], $companyId);
            }

            // Limpar cache
            CompanyPreference::clearCache($companyId);

            Notification::make()
                ->title('Preferências salvas')
                ->body('As preferências da empresa foram atualizadas com sucesso.')
                ->success()
                ->send();

        } catch (\Exception $e) {
            Notification::make()
                ->title('Erro ao salvar')
                ->body('Ocorreu um erro ao salvar as preferências: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
