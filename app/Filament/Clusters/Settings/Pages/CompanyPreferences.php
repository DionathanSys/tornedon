<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\CompanyEmailPolicy;
use App\Models\CompanyPreference;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class CompanyPreferences extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.clusters.settings.pages.company-preferences';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationLabel = 'Preferencias';

    protected static ?string $title = 'Preferencias da Empresa';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public function mount(): void
    {
        $companyId = Filament::getTenant()?->id;
        if (! $companyId) {
            $this->form->fill([]);
            return;
        }

        $serviceOrderPolicy = CompanyEmailPolicy::resolve($companyId, 'service_order', 'closed');
        $requisitionPolicy = CompanyEmailPolicy::resolve($companyId, 'requisition', 'closed');
        $productionOrderPolicy = CompanyEmailPolicy::resolve($companyId, 'production_order', 'closed');
        $invoicePolicy = CompanyEmailPolicy::resolve($companyId, 'invoice', 'confirmed');
        $fiscalPolicy = CompanyEmailPolicy::resolve($companyId, 'fiscal_document', 'confirmed');

        $this->form->fill([
            'default_payment_method' => CompanyPreference::getDefaultPaymentMethod($companyId),
            'default_payment_condition' => CompanyPreference::getDefaultPaymentCondition($companyId),
            'default_quote_validity_days' => CompanyPreference::getDefaultQuoteValidityDays($companyId) ?? 30,
            'default_profit_margin' => CompanyPreference::getDefaultProfitMargin($companyId),
            'default_value_km' => CompanyPreference::get('default_value_km', $companyId, 3.5),
            'notify_new_order' => CompanyPreference::get('notify_new_order', $companyId, true),
            'notify_status_change' => CompanyPreference::get('notify_status_change', $companyId, true),
            'notify_low_stock' => CompanyPreference::get('notify_low_stock', $companyId, true),
            'notify_overdue_payments' => CompanyPreference::get('notify_overdue_payments', $companyId, true),
            'email_signature' => CompanyPreference::get('email_signature', $companyId),
            'email_cc' => CompanyPreference::get('email_cc', $companyId),
            'default_warranty_days' => CompanyPreference::get('default_warranty_days', $companyId, 90),
            'require_approval_threshold' => CompanyPreference::get('require_approval_threshold', $companyId),

            'policy_service_order_enabled' => (bool) $serviceOrderPolicy->enabled,
            'policy_service_order_subject' => (string) ($serviceOrderPolicy->subject_template ?? ''),
            'policy_service_order_body' => (string) ($serviceOrderPolicy->body_template ?? ''),
            'policy_service_order_required_attachments' => (array) ($serviceOrderPolicy->required_attachments ?? ['pdf']),
            'policy_service_order_optional_attachments' => (array) ($serviceOrderPolicy->optional_attachments ?? []),
            'policy_service_order_max_total_size_mb' => (int) ($serviceOrderPolicy->max_total_size_mb ?? 20),
            'policy_service_order_fallback_mode' => (string) ($serviceOrderPolicy->fallback_mode ?? 'signed_link'),

            'policy_requisition_enabled' => (bool) $requisitionPolicy->enabled,
            'policy_requisition_subject' => (string) ($requisitionPolicy->subject_template ?? ''),
            'policy_requisition_body' => (string) ($requisitionPolicy->body_template ?? ''),
            'policy_requisition_required_attachments' => (array) ($requisitionPolicy->required_attachments ?? ['pdf']),
            'policy_requisition_optional_attachments' => (array) ($requisitionPolicy->optional_attachments ?? []),
            'policy_requisition_max_total_size_mb' => (int) ($requisitionPolicy->max_total_size_mb ?? 20),
            'policy_requisition_fallback_mode' => (string) ($requisitionPolicy->fallback_mode ?? 'signed_link'),

            'policy_production_order_enabled' => (bool) $productionOrderPolicy->enabled,
            'policy_production_order_subject' => (string) ($productionOrderPolicy->subject_template ?? ''),
            'policy_production_order_body' => (string) ($productionOrderPolicy->body_template ?? ''),
            'policy_production_order_required_attachments' => (array) ($productionOrderPolicy->required_attachments ?? ['pdf']),
            'policy_production_order_optional_attachments' => (array) ($productionOrderPolicy->optional_attachments ?? []),
            'policy_production_order_max_total_size_mb' => (int) ($productionOrderPolicy->max_total_size_mb ?? 20),
            'policy_production_order_fallback_mode' => (string) ($productionOrderPolicy->fallback_mode ?? 'signed_link'),

            'policy_invoice_enabled' => (bool) $invoicePolicy->enabled,
            'policy_invoice_subject' => (string) ($invoicePolicy->subject_template ?? ''),
            'policy_invoice_body' => (string) ($invoicePolicy->body_template ?? ''),
            'policy_invoice_required_attachments' => (array) ($invoicePolicy->required_attachments ?? ['pdf']),
            'policy_invoice_optional_attachments' => (array) ($invoicePolicy->optional_attachments ?? []),
            'policy_invoice_max_total_size_mb' => (int) ($invoicePolicy->max_total_size_mb ?? 20),
            'policy_invoice_fallback_mode' => (string) ($invoicePolicy->fallback_mode ?? 'signed_link'),

            'policy_fiscal_enabled' => (bool) $fiscalPolicy->enabled,
            'policy_fiscal_subject' => (string) ($fiscalPolicy->subject_template ?? ''),
            'policy_fiscal_body' => (string) ($fiscalPolicy->body_template ?? ''),
            'policy_fiscal_required_attachments' => (array) ($fiscalPolicy->required_attachments ?? ['danfe']),
            'policy_fiscal_optional_attachments' => (array) ($fiscalPolicy->optional_attachments ?? ['xml']),
            'policy_fiscal_max_total_size_mb' => (int) ($fiscalPolicy->max_total_size_mb ?? 20),
            'policy_fiscal_fallback_mode' => (string) ($fiscalPolicy->fallback_mode ?? 'signed_link'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Pagamento')
                    ->description('Configuracoes padrao para pagamentos')
                    ->icon('heroicon-o-credit-card')
                    ->schema([
                        Forms\Components\Select::make('default_payment_method')
                            ->label('Metodo de Pagamento Padrao')
                            ->options(PaymentMethod::toSelectArray())
                            ->native(false)
                            ->searchable()
                            ->placeholder('Selecione um metodo padrao')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                        Forms\Components\Select::make('default_payment_condition')
                            ->label('Condicao de Pagamento Padrao')
                            ->options(PaymentCondition::toGroupedSelectArray())
                            ->native(false)
                            ->searchable()
                            ->placeholder('Selecione uma condicao padrao')
                            ->columnSpan(['md' => 1, 'lg' => 1]),
                    ])
                    ->columns(['md' => 2, 'lg' => 2])
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('Vendas e Orcamentos')
                    ->description('Configuracoes relacionadas a vendas e orcamentos')
                    ->icon('heroicon-o-shopping-cart')
                    ->schema([
                        Forms\Components\TextInput::make('default_quote_validity_days')
                            ->label('Validade Padrao de Orcamentos (dias)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->default(30),
                        Forms\Components\TextInput::make('default_profit_margin')
                            ->label('Margem de Lucro Padrao (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.1)
                            ->suffix('%'),
                        Money::make('default_value_km')
                            ->label('Valor Padrao por KM')
                            ->default(3.5),
                        Forms\Components\TextInput::make('default_warranty_days')
                            ->label('Garantia Padrao (dias)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(3650)
                            ->default(90),
                        Forms\Components\TextInput::make('require_approval_threshold')
                            ->label('Limite para Aprovacao (R$)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('R$'),
                    ])
                    ->columns(['md' => 2, 'lg' => 2])
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('Notificacoes')
                    ->description('Configure quais notificacoes internas deseja receber')
                    ->icon('heroicon-o-bell')
                    ->schema([
                        Forms\Components\Toggle::make('notify_new_order')
                            ->label('Notificar em Novos Pedidos')
                            ->inline(false),
                        Forms\Components\Toggle::make('notify_status_change')
                            ->label('Notificar Mudanca de Status')
                            ->inline(false),
                        Forms\Components\Toggle::make('notify_low_stock')
                            ->label('Notificar Estoque Baixo')
                            ->inline(false),
                        Forms\Components\Toggle::make('notify_overdue_payments')
                            ->label('Notificar Pagamentos Atrasados')
                            ->inline(false),
                    ])
                    ->columns(['md' => 2, 'lg' => 2])
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('Politicas de E-mail por Documento')
                    ->description('Envio apenas no encerramento/confirmacao: OS, REQ, OP, FAT e NF')
                    ->icon('heroicon-o-paper-airplane')
                    ->schema([
                        Fieldset::make('Ordem de Servico (encerrada)')
                            ->schema($this->policyFields(prefix: 'policy_service_order', requiredOptions: ['pdf' => 'PDF'], optionalOptions: [])),
                        Fieldset::make('Requisicao (encerrada)')
                            ->schema($this->policyFields(prefix: 'policy_requisition', requiredOptions: ['pdf' => 'PDF'], optionalOptions: [])),
                        Fieldset::make('Ordem de Producao (encerrada)')
                            ->schema($this->policyFields(prefix: 'policy_production_order', requiredOptions: ['pdf' => 'PDF'], optionalOptions: [])),
                        Fieldset::make('Fatura (confirmada)')
                            ->schema($this->policyFields(prefix: 'policy_invoice', requiredOptions: ['pdf' => 'PDF'], optionalOptions: [])),
                        Fieldset::make('Documento Fiscal (confirmado)')
                            ->schema($this->policyFields(prefix: 'policy_fiscal', requiredOptions: ['danfe' => 'DANFE PDF'], optionalOptions: ['xml' => 'XML (opcional)'])),
                    ])
                    ->columns(1)
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('E-mail')
                    ->description('Configuracoes corporativas gerais')
                    ->icon('heroicon-o-envelope')
                    ->schema([
                        Forms\Components\Textarea::make('email_signature')
                            ->label('Assinatura de E-mail')
                            ->rows(4)
                            ->placeholder('Digite a assinatura padrao para e-mails')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('email_cc')
                            ->label('CC Global')
                            ->placeholder('gerencia@empresa.com;financeiro@empresa.com')
                            ->helperText('Separador por ; ou ,'),
                    ])
                    ->columns(['md' => 2, 'lg' => 2])
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int,mixed>
     */
    private function policyFields(string $prefix, array $requiredOptions, array $optionalOptions): array
    {
        return [
            Forms\Components\Toggle::make("{$prefix}_enabled")
                ->label('Habilitado')
                ->inline(false)
                ->default(true),
            Forms\Components\TextInput::make("{$prefix}_subject")
                ->label('Assunto')
                ->placeholder('Use {{partner_name}} e {{document_number}}'),
            Forms\Components\Textarea::make("{$prefix}_body")
                ->label('Corpo HTML')
                ->rows(3)
                ->helperText('Variaveis: {{partner_name}}, {{document_number}}, {{document_type}}, {{event_name}}'),
            Forms\Components\CheckboxList::make("{$prefix}_required_attachments")
                ->label('Anexos obrigatorios')
                ->options($requiredOptions)
                ->columns(2),
            Forms\Components\CheckboxList::make("{$prefix}_optional_attachments")
                ->label('Anexos opcionais')
                ->options($optionalOptions)
                ->columns(2),
            Forms\Components\TextInput::make("{$prefix}_max_total_size_mb")
                ->label('Limite total (MB)')
                ->numeric()
                ->default(20)
                ->minValue(1)
                ->maxValue(50),
            Forms\Components\Select::make("{$prefix}_fallback_mode")
                ->label('Fallback quando excede limite')
                ->native(false)
                ->options([
                    'signed_link' => 'Link assinado',
                    'skip_optional' => 'Ignorar opcionais',
                    'fail' => 'Falhar envio',
                ])
                ->default('signed_link'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $companyId = Filament::getTenant()?->id;

        if (! $companyId) {
            Notification::make()
                ->title('Erro ao salvar')
                ->body('Empresa nao identificada.')
                ->danger()
                ->send();
            return;
        }

        try {
            if (isset($data['default_payment_method'])) {
                CompanyPreference::setDefaultPaymentMethod($data['default_payment_method'], $companyId);
            }

            if (isset($data['default_payment_condition'])) {
                CompanyPreference::setDefaultPaymentCondition($data['default_payment_condition'], $companyId);
            }

            if (isset($data['default_quote_validity_days'])) {
                CompanyPreference::setDefaultQuoteValidityDays((int) $data['default_quote_validity_days'], $companyId);
            }

            if (isset($data['default_profit_margin'])) {
                CompanyPreference::setDefaultProfitMargin((float) $data['default_profit_margin'], $companyId);
            }

            if (isset($data['default_value_km'])) {
                CompanyPreference::set('default_value_km', $data['default_value_km'], $companyId);
            }

            if (isset($data['default_warranty_days'])) {
                CompanyPreference::set('default_warranty_days', (int) $data['default_warranty_days'], $companyId);
            }

            if (isset($data['require_approval_threshold'])) {
                CompanyPreference::set('require_approval_threshold', $data['require_approval_threshold'], $companyId);
            }

            CompanyPreference::set('notify_new_order', (bool) ($data['notify_new_order'] ?? false), $companyId);
            CompanyPreference::set('notify_status_change', (bool) ($data['notify_status_change'] ?? false), $companyId);
            CompanyPreference::set('notify_low_stock', (bool) ($data['notify_low_stock'] ?? false), $companyId);
            CompanyPreference::set('notify_overdue_payments', (bool) ($data['notify_overdue_payments'] ?? false), $companyId);
            CompanyPreference::set('email_signature', (string) ($data['email_signature'] ?? ''), $companyId);
            CompanyPreference::set('email_cc', (string) ($data['email_cc'] ?? ''), $companyId);

            $this->savePolicy($companyId, 'service_order', 'closed', $data, 'policy_service_order');
            $this->savePolicy($companyId, 'requisition', 'closed', $data, 'policy_requisition');
            $this->savePolicy($companyId, 'production_order', 'closed', $data, 'policy_production_order');
            $this->savePolicy($companyId, 'invoice', 'confirmed', $data, 'policy_invoice');
            $this->savePolicy($companyId, 'fiscal_document', 'confirmed', $data, 'policy_fiscal');

            CompanyPreference::clearCache($companyId);

            Notification::make()
                ->title('Preferencias salvas')
                ->body('As preferencias da empresa foram atualizadas com sucesso.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro ao salvar')
                ->body('Ocorreu um erro ao salvar as preferencias: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function savePolicy(int $companyId, string $documentType, string $event, array $data, string $prefix): void
    {
        $policy = CompanyEmailPolicy::resolve($companyId, $documentType, $event);

        $policy->fill([
            'enabled' => (bool) ($data["{$prefix}_enabled"] ?? true),
            'subject_template' => (string) ($data["{$prefix}_subject"] ?? ''),
            'body_template' => (string) ($data["{$prefix}_body"] ?? ''),
            'required_attachments' => array_values($data["{$prefix}_required_attachments"] ?? []),
            'optional_attachments' => array_values($data["{$prefix}_optional_attachments"] ?? []),
            'max_total_size_mb' => (int) ($data["{$prefix}_max_total_size_mb"] ?? 20),
            'allowed_mime_types' => ['application/pdf', 'application/xml', 'text/xml', 'text/plain'],
            'fallback_mode' => (string) ($data["{$prefix}_fallback_mode"] ?? 'signed_link'),
        ]);

        $policy->save();
    }
}
