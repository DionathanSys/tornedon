<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\CompanyPreference;
use BackedEnum;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Facades\Filament;

class CompanyPreferences extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.clusters.settings.pages.company-preferences';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationLabel = 'PreferÃªncias';

    protected static ?string $title = 'PreferÃªncias da Empresa';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public function mount(): void
    {
        $companyId = Filament::getTenant()?->id;

        $statusNotificationConfig = CompanyPreference::get(
            CompanyPreference::CUSTOMER_STATUS_NOTIFICATION_CONFIG_KEY,
            $companyId,
            CompanyPreference::getDefaultCustomerStatusNotificationConfig(),
        );

        $statusNotificationTemplates = CompanyPreference::get(
            CompanyPreference::CUSTOMER_STATUS_NOTIFICATION_TEMPLATES_KEY,
            $companyId,
            CompanyPreference::getDefaultCustomerStatusNotificationTemplates(),
        );

        if (! is_array($statusNotificationConfig)) {
            $statusNotificationConfig = CompanyPreference::getDefaultCustomerStatusNotificationConfig();
        }

        if (! is_array($statusNotificationTemplates)) {
            $statusNotificationTemplates = CompanyPreference::getDefaultCustomerStatusNotificationTemplates();
        }

        $this->form->fill([
            'default_payment_method' => CompanyPreference::getDefaultPaymentMethod($companyId),
            'default_payment_condition' => CompanyPreference::getDefaultPaymentCondition($companyId),
            'default_quote_validity_days' => CompanyPreference::getDefaultQuoteValidityDays($companyId) ?? 30,
            'default_profit_margin' => CompanyPreference::getDefaultProfitMargin($companyId),
            
            // ConfiguraÃ§Ãµes de notificaÃ§Ã£o
            'notify_new_order' => CompanyPreference::get('notify_new_order', $companyId, true),
            'notify_status_change' => CompanyPreference::get('notify_status_change', $companyId, true),
            'notify_low_stock' => CompanyPreference::get('notify_low_stock', $companyId, true),
            'notify_overdue_payments' => CompanyPreference::get('notify_overdue_payments', $companyId, true),
            
            // ConfiguraÃ§Ãµes de email
            'email_signature' => CompanyPreference::get('email_signature', $companyId),
            'email_cc' => CompanyPreference::get('email_cc', $companyId),
            
            // Outras configuraÃ§Ãµes
            'default_warranty_days' => CompanyPreference::get('default_warranty_days', $companyId, 90),
            'require_approval_threshold' => CompanyPreference::get('require_approval_threshold', $companyId),

            // NotificaÃ§Ãµes de status para cliente
            'customer_notify_service_order_enabled' => (bool) data_get($statusNotificationConfig, 'service_order.enabled', true),
            'customer_notify_service_order_statuses' => (array) data_get($statusNotificationConfig, 'service_order.statuses', ['encerrada']),
            'customer_notify_service_order_subject' => (string) data_get($statusNotificationTemplates, 'service_order.subject', ''),
            'customer_notify_service_order_body' => (string) data_get($statusNotificationTemplates, 'service_order.body', ''),

            'customer_notify_requisition_enabled' => (bool) data_get($statusNotificationConfig, 'requisition.enabled', false),
            'customer_notify_requisition_statuses' => (array) data_get($statusNotificationConfig, 'requisition.statuses', ['closed']),
            'customer_notify_requisition_subject' => (string) data_get($statusNotificationTemplates, 'requisition.subject', ''),
            'customer_notify_requisition_body' => (string) data_get($statusNotificationTemplates, 'requisition.body', ''),

            'customer_notify_invoice_enabled' => (bool) data_get($statusNotificationConfig, 'invoice.enabled', true),
            'customer_notify_invoice_statuses' => (array) data_get($statusNotificationConfig, 'invoice.statuses', ['confirmed']),
            'customer_notify_invoice_subject' => (string) data_get($statusNotificationTemplates, 'invoice.subject', ''),
            'customer_notify_invoice_body' => (string) data_get($statusNotificationTemplates, 'invoice.body', ''),

            'customer_notify_fiscal_document_enabled' => (bool) data_get($statusNotificationConfig, 'fiscal_document.enabled', true),
            'customer_notify_fiscal_document_statuses' => (array) data_get($statusNotificationConfig, 'fiscal_document.statuses', ['confirmed']),
            'customer_notify_fiscal_document_subject' => (string) data_get($statusNotificationTemplates, 'fiscal_document.subject', ''),
            'customer_notify_fiscal_document_body' => (string) data_get($statusNotificationTemplates, 'fiscal_document.body', ''),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Pagamento')
                    ->description('ConfiguraÃ§Ãµes padrÃ£o para pagamentos')
                    ->icon('heroicon-o-credit-card')
                    ->schema([
                        Forms\Components\Select::make('default_payment_method')
                            ->label('MÃ©todo de Pagamento PadrÃ£o')
                            ->options(PaymentMethod::toSelectArray())
                            ->native(false)
                            ->searchable()
                            ->placeholder('Selecione um mÃ©todo padrÃ£o')
                            ->helperText('Este mÃ©todo serÃ¡ prÃ©-selecionado em novos pedidos')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\Select::make('default_payment_condition')
                            ->label('CondiÃ§Ã£o de Pagamento PadrÃ£o')
                            ->options(PaymentCondition::toGroupedSelectArray())
                            ->native(false)
                            ->searchable()
                            ->placeholder('Selecione uma condiÃ§Ã£o padrÃ£o')
                            ->helperText('Esta condiÃ§Ã£o serÃ¡ prÃ©-selecionada em novos pedidos')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                    ])
                    ->columns(['md' => 2, 'lg' => 2])
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('Vendas e OrÃ§amentos')
                    ->description('ConfiguraÃ§Ãµes relacionadas a vendas e orÃ§amentos')
                    ->icon('heroicon-o-shopping-cart')
                    ->schema([
                        Forms\Components\TextInput::make('default_quote_validity_days')
                            ->label('Validade PadrÃ£o de OrÃ§amentos (dias)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->default(30)
                            ->helperText('NÃºmero de dias que um orÃ§amento permanece vÃ¡lido')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\TextInput::make('default_profit_margin')
                            ->label('Margem de Lucro PadrÃ£o (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.1)
                            ->suffix('%')
                            ->helperText('Margem de lucro padrÃ£o aplicada em produtos')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\TextInput::make('default_warranty_days')
                            ->label('Garantia PadrÃ£o (dias)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(3650)
                            ->default(90)
                            ->helperText('PerÃ­odo de garantia padrÃ£o para serviÃ§os')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\TextInput::make('require_approval_threshold')
                            ->label('Limite para AprovaÃ§Ã£o (R$)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('R$')
                            ->helperText('Ordens acima deste valor requerem aprovaÃ§Ã£o')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                    ])
                    ->columns(['md' => 2, 'lg' => 2])
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('NotificaÃ§Ãµes')
                    ->description('Configure quais notificaÃ§Ãµes vocÃª deseja receber')
                    ->icon('heroicon-o-bell')
                    ->schema([
                        Forms\Components\Toggle::make('notify_new_order')
                            ->label('Notificar em Novos Pedidos')
                            ->helperText('Receber notificaÃ§Ã£o quando um novo pedido for criado')
                            ->inline(false)
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\Toggle::make('notify_status_change')
                            ->label('Notificar MudanÃ§a de Status')
                            ->helperText('Receber notificaÃ§Ã£o quando o status de um pedido mudar')
                            ->inline(false)
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\Toggle::make('notify_low_stock')
                            ->label('Notificar Estoque Baixo')
                            ->helperText('Receber notificaÃ§Ã£o quando o estoque estiver abaixo do mÃ­nimo')
                            ->inline(false)
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        
                        Forms\Components\Toggle::make('notify_overdue_payments')
                            ->label('Notificar Pagamentos Atrasados')
                            ->helperText('Receber notificaÃ§Ã£o de pagamentos vencidos')
                            ->inline(false)
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                    ])
                    ->columns(['md' => 2, 'lg' => 2])
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('NotificaÃ§Ãµes por Status para Cliente')
                    ->description('Defina quais documentos e status devem disparar e-mail para o cliente')
                    ->icon('heroicon-o-paper-airplane')
                    ->schema([
                        Fieldset::make('Ordem de Serviço')
                            ->schema([
                                Forms\Components\Toggle::make('customer_notify_service_order_enabled')
                                    ->label('Notificar OS')
                                    ->inline(false),
                                Forms\Components\CheckboxList::make('customer_notify_service_order_statuses')
                                    ->label('Status notificados')
                                    ->options(ServiceOrderState::toSelectArray())
                                    ->columns(2),
                                Forms\Components\TextInput::make('customer_notify_service_order_subject')
                                    ->label('Assunto do e-mail'),
                                Forms\Components\Textarea::make('customer_notify_service_order_body')
                                    ->label('Corpo do e-mail')
                                    ->rows(3)
                                    ->helperText('Variáveis: {{partner_name}}, {{document_number}}, {{old_status}}, {{new_status}}'),
                            ]),

                        Fieldset::make('Requisição')
                            ->schema([
                                Forms\Components\Toggle::make('customer_notify_requisition_enabled')
                                    ->label('Notificar Requisição')
                                    ->inline(false),
                                Forms\Components\CheckboxList::make('customer_notify_requisition_statuses')
                                    ->label('Status notificados')
                                    ->options(RequisitionStatus::toSelectArray())
                                    ->columns(2),
                                Forms\Components\TextInput::make('customer_notify_requisition_subject')
                                    ->label('Assunto do e-mail'),
                                Forms\Components\Textarea::make('customer_notify_requisition_body')
                                    ->label('Corpo do e-mail')
                                    ->rows(3)
                                    ->helperText('Variáveis: {{partner_name}}, {{document_number}}, {{old_status}}, {{new_status}}'),
                            ]),

                        Fieldset::make('Fatura')
                            ->schema([
                                Forms\Components\Toggle::make('customer_notify_invoice_enabled')
                                    ->label('Notificar Fatura')
                                    ->inline(false),
                                Forms\Components\CheckboxList::make('customer_notify_invoice_statuses')
                                    ->label('Status notificados')
                                    ->options(InvoiceStatus::toSelectArray())
                                    ->columns(2),
                                Forms\Components\TextInput::make('customer_notify_invoice_subject')
                                    ->label('Assunto do e-mail'),
                                Forms\Components\Textarea::make('customer_notify_invoice_body')
                                    ->label('Corpo do e-mail')
                                    ->rows(3)
                                    ->helperText('Variáveis: {{partner_name}}, {{document_number}}, {{old_status}}, {{new_status}}'),
                            ]),

                        Fieldset::make('Documento Fiscal')
                            ->schema([
                                Forms\Components\Toggle::make('customer_notify_fiscal_document_enabled')
                                    ->label('Notificar Documento Fiscal')
                                    ->inline(false),
                                Forms\Components\CheckboxList::make('customer_notify_fiscal_document_statuses')
                                    ->label('Status notificados')
                                    ->options(FiscalDocumentStatus::toSelectArray())
                                    ->columns(2),
                                Forms\Components\TextInput::make('customer_notify_fiscal_document_subject')
                                    ->label('Assunto do e-mail'),
                                Forms\Components\Textarea::make('customer_notify_fiscal_document_body')
                                    ->label('Corpo do e-mail')
                                    ->rows(3)
                                    ->helperText('Variáveis: {{partner_name}}, {{document_number}}, {{old_status}}, {{new_status}}'),
                            ]),
                    ])
                    ->columns(1)
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('E-mail')
                    ->description('Configurações de e-mail corporativo')
                    ->icon('heroicon-o-envelope')
                    ->schema([
                        Forms\Components\Textarea::make('email_signature')
                            ->label('Assinatura de E-mail')
                            ->rows(4)
                            ->placeholder('Digite a assinatura padrÃ£o para e-mails')
                            ->helperText('Assinatura que serÃ¡ incluÃ­da nos e-mails enviados')
                            ->columnSpanFull(),
                        
                        Forms\Components\TextInput::make('email_cc')
                            ->label('E-mail para CÃ³pia (CC)')
                            ->email()
                            ->placeholder('gerencia@empresa.com')
                            ->helperText('E-mail que receberÃ¡ cÃ³pia de todas as comunicaÃ§Ãµes')
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
                ->body('Empresa nÃ£o identificada.')
                ->danger()
                ->send();
            return;
        }

        try {
            // PreferÃªncias de pagamento
            if (isset($data['default_payment_method'])) {
                CompanyPreference::setDefaultPaymentMethod($data['default_payment_method'], $companyId);
            }
            
            if (isset($data['default_payment_condition'])) {
                CompanyPreference::setDefaultPaymentCondition($data['default_payment_condition'], $companyId);
            }

            // PreferÃªncias de vendas
            if (isset($data['default_quote_validity_days'])) {
                CompanyPreference::setDefaultQuoteValidityDays($data['default_quote_validity_days'], $companyId);
            }
            
            if (isset($data['default_profit_margin'])) {
                CompanyPreference::setDefaultProfitMargin($data['default_profit_margin'], $companyId);
            }

            // Outras preferÃªncias de negÃ³cio
            if (isset($data['default_warranty_days'])) {
                CompanyPreference::set('default_warranty_days', $data['default_warranty_days'], $companyId);
            }
            
            if (isset($data['require_approval_threshold'])) {
                CompanyPreference::set('require_approval_threshold', $data['require_approval_threshold'], $companyId);
            }

            // NotificaÃ§Ãµes
            CompanyPreference::set('notify_new_order', $data['notify_new_order'] ?? false, $companyId);
            CompanyPreference::set('notify_status_change', $data['notify_status_change'] ?? false, $companyId);
            CompanyPreference::set('notify_low_stock', $data['notify_low_stock'] ?? false, $companyId);
            CompanyPreference::set('notify_overdue_payments', $data['notify_overdue_payments'] ?? false, $companyId);

            // ConfiguraÃ§Ãµes de email
            if (isset($data['email_signature'])) {
                CompanyPreference::set('email_signature', $data['email_signature'], $companyId);
            }
            
            if (isset($data['email_cc'])) {
                CompanyPreference::set('email_cc', $data['email_cc'], $companyId);
            }

            CompanyPreference::set(
                CompanyPreference::CUSTOMER_STATUS_NOTIFICATION_CONFIG_KEY,
                [
                    'service_order' => [
                        'enabled' => (bool) ($data['customer_notify_service_order_enabled'] ?? false),
                        'statuses' => array_values($data['customer_notify_service_order_statuses'] ?? []),
                    ],
                    'requisition' => [
                        'enabled' => (bool) ($data['customer_notify_requisition_enabled'] ?? false),
                        'statuses' => array_values($data['customer_notify_requisition_statuses'] ?? []),
                    ],
                    'invoice' => [
                        'enabled' => (bool) ($data['customer_notify_invoice_enabled'] ?? false),
                        'statuses' => array_values($data['customer_notify_invoice_statuses'] ?? []),
                    ],
                    'fiscal_document' => [
                        'enabled' => (bool) ($data['customer_notify_fiscal_document_enabled'] ?? false),
                        'statuses' => array_values($data['customer_notify_fiscal_document_statuses'] ?? []),
                    ],
                ],
                $companyId
            );

            CompanyPreference::set(
                CompanyPreference::CUSTOMER_STATUS_NOTIFICATION_TEMPLATES_KEY,
                [
                    'service_order' => [
                        'subject' => (string) ($data['customer_notify_service_order_subject'] ?? ''),
                        'body' => (string) ($data['customer_notify_service_order_body'] ?? ''),
                    ],
                    'requisition' => [
                        'subject' => (string) ($data['customer_notify_requisition_subject'] ?? ''),
                        'body' => (string) ($data['customer_notify_requisition_body'] ?? ''),
                    ],
                    'invoice' => [
                        'subject' => (string) ($data['customer_notify_invoice_subject'] ?? ''),
                        'body' => (string) ($data['customer_notify_invoice_body'] ?? ''),
                    ],
                    'fiscal_document' => [
                        'subject' => (string) ($data['customer_notify_fiscal_document_subject'] ?? ''),
                        'body' => (string) ($data['customer_notify_fiscal_document_body'] ?? ''),
                    ],
                ],
                $companyId
            );

            // Limpar cache
            CompanyPreference::clearCache($companyId);

            Notification::make()
                ->title('PreferÃªncias salvas')
                ->body('As preferÃªncias da empresa foram atualizadas com sucesso.')
                ->success()
                ->send();

        } catch (\Exception $e) {
            Notification::make()
                ->title('Erro ao salvar')
                ->body('Ocorreu um erro ao salvar as preferÃªncias: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
}


