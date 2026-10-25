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
            'quotes' => ['label' => 'Propostas', 'model' => Models\Quote::class, 'owner' => 'created_by', 'assigned' => []],
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
        [$module, $action] = explode('.', $permission, 2);
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
        $all = array_fill_keys(array_keys(self::labels()), 'company');
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
