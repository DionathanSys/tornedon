<?php

namespace App\Services\Access;

use App\Enum\User\AccessScope;
use App\Models;

/** Stable permission keys; role names are tenant-owned presentation only. */
class PermissionCatalog
{
    public static function modules(): array
    {
        $modules = [
            'quotes' => ['label' => 'Orçamentos', 'model' => Models\Quote::class, 'owner' => 'created_by', 'assigned' => []],
            'service_orders' => ['label' => 'Ordens de serviço', 'model' => Models\ServiceOrder::class, 'owner' => 'created_by', 'assigned' => ['technician_id', 'supervisor_id', 'salesperson_id']],
            'requisitions' => ['label' => 'Requisições', 'model' => Models\Requisition::class, 'owner' => 'created_by', 'assigned' => ['salesperson_id']],
            'production_requests' => ['label' => 'Pedidos', 'model' => Models\ProductionRequest::class, 'owner' => 'created_by', 'assigned' => []],
            'production_orders' => ['label' => 'Produção', 'model' => Models\ProductionOrder::class, 'owner' => 'created_by', 'assigned' => []],
            'account_payables' => ['label' => 'Contas a pagar', 'model' => Models\AccountPayable::class, 'owner' => 'created_by', 'assigned' => []],
            'account_receivables' => ['label' => 'Contas a receber', 'model' => Models\AccountReceivable::class, 'owner' => 'created_by', 'assigned' => []],
            'cash_movements' => ['label' => 'Caixa', 'model' => Models\CashMovement::class, 'owner' => 'created_by', 'assigned' => []],
            'invoices' => ['label' => 'Faturas', 'model' => Models\Invoice::class, 'owner' => 'created_by', 'assigned' => []],
            'fiscal_documents' => ['label' => 'Documentos fiscais', 'model' => Models\FiscalDocument::class, 'owner' => 'created_by', 'assigned' => []],
            'products' => ['label' => 'Produtos', 'model' => Models\Product::class, 'owner' => 'created_by', 'assigned' => []],
            'services' => ['label' => 'Serviços', 'model' => Models\Service::class, 'owner' => 'created_by', 'assigned' => []],
            'equipments' => ['label' => 'Equipamentos', 'model' => Models\Equipment::class, 'owner' => 'created_by', 'assigned' => []],
            'stock_movements' => ['label' => 'Movimentos de estoque', 'model' => Models\StockMovement::class, 'owner' => 'created_by', 'assigned' => []],
            'product_stocks' => ['label' => 'Estoque', 'model' => Models\ProductStock::class, 'owner' => 'created_by', 'assigned' => []],
            'categories' => ['label' => 'Categorias de produtos', 'model' => Models\Category::class, 'owner' => 'created_by', 'assigned' => []],
            'financial_accounts' => ['label' => 'Contas financeiras', 'model' => Models\FinancialAccount::class, 'owner' => 'created_by', 'assigned' => []],
            'bank_statements' => ['label' => 'Conciliação bancária', 'model' => Models\BankStatementImport::class, 'owner' => 'created_by', 'assigned' => []],
            'purchase_closings' => ['label' => 'Compras', 'model' => Models\PurchaseClosing::class, 'owner' => 'created_by', 'assigned' => []],
            'warranty_claims' => ['label' => 'Garantias', 'model' => Models\WarrantyClaim::class, 'owner' => 'created_by', 'assigned' => []],
        ];
        foreach ([
            'partners' => [Models\CompanyPartner::class, 'Parceiros'],
            'financial_categories' => [Models\FinancialCategory::class, 'Categorias financeiras'],
            'chart_accounts' => [Models\ChartAccount::class, 'Plano de contas'],
            'cost_centers' => [Models\CostCenter::class, 'Centros de custo'],
            'result_centers' => [Models\ResultCenter::class, 'Centros de resultado'],
            'dre_models' => [Models\DreModel::class, 'Modelos DRE'],
            'card_institutions' => [Models\CardInstitution::class, 'Instituições de cartão'],
            'company_credit_cards' => [Models\CompanyCreditCard::class, 'Cartões da empresa'],
            'company_card_statements' => [Models\CompanyCardStatement::class, 'Faturas de cartão'],
            'company_card_transactions' => [Models\CompanyCardTransaction::class, 'Transações de cartão'],
            'banking_events' => [Models\BankingEvent::class, 'Eventos bancários'],
            'sefaz_documents' => [Models\SefazDistributionDocument::class, 'Documentos recebidos SEFAZ'],
            'fiscal_rules' => [Models\FiscalRule::class, 'Regras fiscais'],
            'email_dispatches' => [Models\EmailDispatch::class, 'Envios de e-mail'],
        ] as $key => [$model, $label]) {
            $modules[$key] = ['label' => $label, 'model' => $model, 'owner' => null, 'assigned' => []];
        }

        return $modules;
    }

    public static function actions(): array
    {
        return ['view' => 'Consultar', 'create' => 'Criar', 'update' => 'Alterar', 'delete' => 'Excluir', 'approve' => 'Aprovar', 'settle' => 'Registrar baixa', 'reverse' => 'Estornar', 'cancel' => 'Cancelar', 'export' => 'Exportar', 'issue' => 'Emitir/faturar', 'assign' => 'Alterar responsável'];
    }

    public static function labels(): array
    {
        $permissions = [
            'team.manage' => 'Equipe: administrar vínculos e papéis',
            'company.update' => 'Empresa: alterar cadastro',
            'reports.view' => 'Relatórios gerenciais: consultar',
            'settings.manage' => 'Configurações: administrar',
            'account_payables.change_status_without_movement' => 'Pagar: ignorar/restaurar contas e parcelas',
            'account_receivables.change_status_without_movement' => 'Receber: ignorar/restaurar contas e parcelas',
        ];
        foreach (self::modules() as $module => $definition) {
            foreach (self::actions() as $action => $label) {
                $permissions["{$module}.{$action}"] = "{$definition['label']}: {$label}";
            }
        }

        return $permissions;
    }

    public static function scopeOptions(string $permission): array
    {
        $options = AccessScope::options();
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, '');
        $definition = self::modules()[$module] ?? null;
        if (! $definition || $action === 'create') {
            return array_intersect_key($options, array_flip(['none', 'company']));
        }
        if (! $definition['owner']) {
            unset($options['own']);
        }
        if (! $definition['assigned']) {
            unset($options['assigned']);
        }

        return $options;
    }

    /** Presentation only: stable authorization keys and existing role grants remain compatible. */
    public static function editorGroups(): array
    {
        $basic = ['view', 'create', 'update', 'delete', 'export'];
        $actions = [
            'quotes' => [...$basic, 'approve'],
            'service_orders' => [...$basic, 'approve', 'cancel', 'issue', 'assign'],
            'requisitions' => [...$basic, 'cancel', 'issue', 'assign'],
            'production_requests' => [...$basic, 'approve', 'cancel'],
            'production_orders' => [...$basic, 'approve', 'cancel'],
            'account_payables' => [...$basic, 'approve', 'settle', 'reverse', 'cancel', 'change_status_without_movement'],
            'account_receivables' => [...$basic, 'approve', 'settle', 'reverse', 'cancel', 'change_status_without_movement'],
            'cash_movements' => [...$basic, 'reverse'],
            'invoices' => [...$basic, 'approve', 'cancel'],
            'fiscal_documents' => [...$basic, 'approve', 'cancel', 'issue'],
            'bank_statements' => [...$basic, 'reverse'],
        ];
        $areas = [
            'Vendas e atendimento' => ['quotes', 'service_orders', 'requisitions', 'production_requests', 'production_orders', 'warranty_claims'],
            'Financeiro' => ['account_payables', 'account_receivables', 'cash_movements', 'invoices', 'financial_accounts', 'bank_statements', 'financial_categories', 'chart_accounts', 'cost_centers', 'result_centers', 'dre_models', 'card_institutions', 'company_credit_cards', 'company_card_statements', 'company_card_transactions', 'banking_events', 'purchase_closings'],
            'Fiscal' => ['fiscal_documents', 'sefaz_documents', 'fiscal_rules'],
            'Cadastros e estoque' => ['partners', 'products', 'services', 'equipments', 'stock_movements', 'product_stocks', 'categories'],
            'Comunicação' => ['email_dispatches'],
        ];
        $descriptions = [
            'quotes' => 'Orçamentos enviados aos clientes no módulo de vendas.',
            'service_orders' => 'Atendimentos, execução dos serviços e responsáveis pelas OS.',
            'account_payables' => 'Despesas e pagamentos registrados no financeiro.',
            'account_receivables' => 'Receitas e recebimentos registrados no financeiro.',
            'bank_statements' => 'Importação de extratos e conciliação dos lançamentos bancários.',
            'fiscal_documents' => 'Notas fiscais de produtos e serviços.',
        ];
        $hints = [
            'view' => 'Abrir listas e consultar os detalhes dos registros.',
            'create' => 'Cadastrar novos registros neste módulo.',
            'update' => 'Editar os registros, respeitando as regras do módulo.',
            'delete' => 'Excluir quando o estado do registro permitir.',
            'approve' => 'Realizar as aprovações previstas neste módulo.',
            'settle' => 'Registrar que um valor foi pago ou recebido. Não executa pagamento no banco.',
            'reverse' => 'Reverter um lançamento ou desfazer uma operação permitida.',
            'cancel' => 'Cancelar registros conforme o fluxo do módulo.',
            'export' => 'Exportar registros e documentos autorizados.',
            'issue' => 'Emitir documentos ou faturar a operação.',
            'assign' => 'Definir ou alterar os responsáveis pelo registro.',
            'change_status_without_movement' => 'Desconsiderar ou restaurar títulos, sem registrar movimentação financeira.',
        ];
        $groups = [];
        foreach ($areas as $area => $modules) {
            foreach ($modules as $module) {
                $definition = self::modules()[$module];
                $permissions = [];
                foreach ($actions[$module] ?? $basic as $action) {
                    $key = $module.'.'.$action;
                    $options = self::scopeOptions($key);
                    if (count($options) === 2) {
                        $options['company'] = 'Permitido';
                    }
                    $permissions[] = [
                        'key' => $key, 'label' => self::actions()[$action] ?? 'Ignorar / restaurar títulos',
                        'description' => $hints[$action], 'options' => $options,
                    ];
                }
                $groups[] = ['key' => $module, 'label' => $definition['label'], 'area' => $area,
                    'description' => $descriptions[$module] ?? 'Controle das ações disponíveis em '.$definition['label'].'.',
                    'permissions' => $permissions];
            }
        }
        $groups[] = [
            'key' => 'administration', 'label' => 'Gestão da empresa', 'area' => 'Administração',
            'description' => 'Equipe, identidade da empresa, configurações e relatórios gerenciais.',
            'permissions' => collect([
                'team.manage' => ['Administrar equipe e papéis', 'Convidar pessoas, definir permissões e autorizar atendimentos BPO.'],
                'company.update' => ['Alterar dados da empresa', 'Editar a identidade e o cadastro da empresa.'],
                'reports.view' => ['Consultar relatórios gerenciais', 'Acessar DRE e relatórios gerenciais da empresa.'],
                'settings.manage' => ['Administrar configurações', 'Alterar preferências e configurações operacionais e fiscais.'],
            ])->map(fn ($definition, $key) => ['key' => $key, 'label' => $definition[0], 'description' => $definition[1],
                'options' => ['none' => 'Não permitido', 'company' => 'Permitido']])->values()->all(),
        ];

        return $groups;
    }

    public static function visiblePermissionCount(array $permissions): int
    {
        $keys = collect(self::editorGroups())->pluck('permissions')->flatten(1)->pluck('key')->all();

        return count(array_filter(array_intersect_key($permissions, array_flip($keys)), fn ($scope) => $scope !== 'none' && $scope !== []));
    }

    public static function definition(string $model): ?array
    {
        foreach (self::modules() as $module => $definition) {
            if ($definition['model'] === $model) {
                return [...$definition, 'module' => $module];
            }
        }

        return null;
    }

    /** Child records inherit visibility from the aggregate, never from their own creator. */
    public static function children(): array
    {
        return [
            Models\QuoteItem::class => ['quote', Models\Quote::class],
            Models\ServiceOrderItem::class => ['serviceOrder', Models\ServiceOrder::class],
            Models\RequisitionItem::class => ['requisition', Models\Requisition::class],
            Models\ProductionRequestItem::class => ['productionRequest', Models\ProductionRequest::class],
            Models\ProductionOrderItem::class => ['productionOrder', Models\ProductionOrder::class],
            Models\FiscalDocumentItem::class => ['fiscalDocument', Models\FiscalDocument::class],
            Models\AccountPayableInstallment::class => ['accountPayable', Models\AccountPayable::class],
            Models\AccountReceivableInstallment::class => ['accountReceivable', Models\AccountReceivable::class],
            Models\AccountPayableInstallmentPayment::class => ['installment.accountPayable', Models\AccountPayable::class],
            Models\AccountReceivableInstallmentPayment::class => ['installment.accountReceivable', Models\AccountReceivable::class],
            Models\BankStatementLine::class => ['import', Models\BankStatementImport::class],
            Models\BankSlip::class => ['installment.accountReceivable', Models\AccountReceivable::class],
            Models\ProductTax::class => ['product', Models\Product::class],
            Models\FiscalDocumentPayload::class => ['fiscalDocument', Models\FiscalDocument::class],
            Models\FiscalDocumentTaxDetail::class => ['fiscalDocument', Models\FiscalDocument::class],
            Models\ServiceOrderDefect::class => ['serviceOrder', Models\ServiceOrder::class],
            Models\DreLine::class => ['dreModel', Models\DreModel::class],
        ];
    }

    public static function templates(): array
    {
        $keys = collect(self::editorGroups())->pluck('permissions')->flatten(1)->pluck('key')->all();
        $all = array_fill_keys($keys, 'company');
        $financialModules = ['account_payables', 'account_receivables', 'cash_movements', 'invoices', 'financial_accounts', 'bank_statements', 'financial_categories', 'chart_accounts', 'cost_centers', 'result_centers', 'dre_models', 'card_institutions', 'company_credit_cards', 'company_card_statements', 'company_card_transactions', 'banking_events'];
        $financial = array_filter($all, fn ($key) => in_array(explode('.', $key)[0], $financialModules, true) || $key === 'reports.view', ARRAY_FILTER_USE_KEY);
        $financial += ['partners.view' => 'company', 'partners.create' => 'company', 'partners.update' => 'company'];

        return [
            'Administrador da empresa' => $all,
            'Financeiro' => $financial,
            'BPO responsável' => $financial,
            'Auxiliar BPO' => array_intersect_key($financial, array_flip(['account_payables.view', 'account_payables.create', 'account_receivables.view', 'account_receivables.create', 'cash_movements.view'])),
            'Vendedor' => ['quotes.view' => 'own', 'quotes.create' => 'company', 'quotes.update' => 'own', 'requisitions.view' => 'assigned', 'products.view' => 'company', 'services.view' => 'company', 'partners.view' => 'company'],
            'Técnico' => ['service_orders.view' => 'assigned', 'service_orders.update' => 'assigned', 'services.view' => 'company', 'equipments.view' => 'company', 'partners.view' => 'company'],
            'Emissor fiscal' => ['fiscal_documents.view' => 'company', 'fiscal_documents.create' => 'company', 'fiscal_documents.update' => 'company', 'fiscal_documents.issue' => 'company'],
            'Consulta' => array_filter($all, fn ($key) => str_ends_with($key, '.view'), ARRAY_FILTER_USE_KEY),
        ];
    }
}
