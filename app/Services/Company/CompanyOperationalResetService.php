<?php

namespace App\Services\Company;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\ProductionOrder;
use App\Models\Quote;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Explicit disposal policy, checked against the actual FK graph before any writes.
 * Unknown dependencies fail closed; FK constraints are never disabled.
 */
class CompanyOperationalResetService
{
    private const OPERATIONAL_TABLES = [
        'quotes', 'quote_items', 'invoices', 'service_orders', 'service_order_items',
        'service_order_received_assets', 'service_order_signature_links',
        'requisitions', 'requisition_items', 'production_orders', 'production_order_items',
        'production_requests', 'production_request_items', 'warranty_claims',
        'account_payables', 'account_payable_installments', 'account_payable_installment_payments',
        'account_receivables', 'account_receivable_installments', 'account_receivable_installment_payments',
        'bank_slips', 'bank_slip_events', 'pix_charges', 'cash_movements',
        'bank_statement_imports', 'bank_statement_import_runs', 'bank_statement_lines',
        'company_card_transactions', 'company_card_statements', 'company_card_statement_items',
        'company_card_statement_payments', 'purchase_closings', 'purchase_closing_fiscal_documents',
        'purchase_return_credits', 'purchase_return_credit_usages', 'stock_movements',
        'temporary_service_order_migration_links', 'temporary_service_order_item_migration_links',
    ];

    private const DRAFT_DEPENDENCIES = [
        'fiscal_document_items', 'fiscal_document_tax_details', 'fiscal_document_payloads',
        'fiscal_document_item_origins', 'remittance_assets', 'fiscal_document_xml_export_items',
    ];

    private const DETACHABLE = [
        'fiscal_documents.invoice_id',
        'sefaz_distribution_documents.account_payable_id',
        'sefaz_distribution_documents.fiscal_document_id',
    ];

    private array $columns = [];

    private array $foreignKeys = [];

    public function plan(int $companyId): array
    {
        $company = Company::query()->findOrFail($companyId);
        $this->loadSchema();
        $plan = [
            'company' => ['id' => $company->id, 'name' => $company->name, 'document' => $company->document_number],
            'delete' => [], 'detach' => [], 'blockers' => [], 'fiscal' => [], 'banking' => [],
            'preserve' => [], 'balances' => [], 'order' => [],
            'validation' => [
                'Empresa/CNPJ corretos e backup do banco e arquivos disponível.',
                'Conferir IDs/status fiscais no provedor; envio indefinido impede a execução.',
                'Conferir cobranças bancárias: exclusão somente local, sem cancelamento externo. Baixas posteriores não recriam o financeiro.',
                'Conferir saldos e estoque: serão zerados; cadastrar novos saldos iniciais após o reinício, se desejado.',
                'Na execução: manutenção em todos os nós, workers/agendadores pausados e tarefas em andamento encerradas.',
                'Arquivos físicos permanecem no storage; registros/anexos operacionais são removidos do sistema.',
            ],
        ];

        if (! in_array('operational_reset_at', $this->columns['fiscal_documents'] ?? [], true)) {
            $plan['blockers'][] = 'Execute as migrations: falta fiscal_documents.operational_reset_at.';
        }

        foreach (self::OPERATIONAL_TABLES as $table) {
            if ($this->hasColumn($table, 'company_id')) {
                $plan['delete'][$table] = DB::table($table)->where('company_id', $companyId)->pluck('id')->all();
            }
        }

        foreach (DB::table('fiscal_documents')->where('company_id', $companyId)->orderBy('id')->get() as $doc) {
            $status = $doc->document_type === 'nfse' ? $doc->nfse_status : $doc->nfe_status;
            $keep = in_array($status, ['authorized', 'canceled', 'rejected'], true);
            $draft = ! $keep
                && in_array($status, [null, 'pending', 'queued'], true)
                && $doc->status === 'pending'
                && ! $doc->document_key && ! $doc->nfe_protocolo && ! $doc->nfse_protocol
                && ! $doc->emission_requested_at;
            $decision = $keep ? 'preservar' : ($draft ? 'excluir rascunho' : 'CONFERIR NO PROVEDOR');
            $plan['fiscal'][] = [
                'id' => $doc->id, 'type' => $doc->document_type, 'number' => $doc->document_number,
                'status' => $status, 'decision' => $decision,
            ];
            if ($draft) {
                $plan['delete']['fiscal_documents'][] = $doc->id;
            } elseif (! $keep) {
                $plan['blockers'][] = "Documento fiscal #{$doc->id}: consultar/conciliar no provedor antes de excluir.";
            }
        }

        // Resolve children without company_id through their parent, never by unscoped deletion.
        do {
            $changed = false;
            foreach ($this->foreignKeys as $fk) {
                $parentIds = $plan['delete'][$fk['parent']] ?? [];
                $operational = in_array($fk['table'], self::OPERATIONAL_TABLES, true);
                $draftDependency = in_array($fk['table'], self::DRAFT_DEPENDENCIES, true)
                    && in_array($fk['parent'], ['fiscal_documents', 'fiscal_document_items', 'remittance_assets'], true);
                if (! $parentIds || (! $operational && ! $draftDependency)) {
                    continue;
                }
                $query = $this->references($fk, $parentIds);
                $this->checkTenant($query, $fk['table'], $companyId, $plan);
                if ($this->hasColumn($fk['table'], 'company_id')) {
                    $query->where('company_id', $companyId);
                }
                $old = $plan['delete'][$fk['table']] ?? [];
                $ids = array_values(array_unique([...$old, ...$query->pluck('id')->all()]));
                if (count($ids) !== count($old)) {
                    $plan['delete'][$fk['table']] = $ids;
                    $changed = true;
                }
            }
        } while ($changed);

        // A reference from retained fiscal history must never be silently cascaded away.
        foreach (['fiscal_document_item_origins', 'remittance_assets'] as $table) {
            foreach ($plan['delete'][$table] ?? [] as $id) {
                $row = DB::table($table)->find($id);
                foreach ($this->foreignKeys as $fk) {
                    if ($fk['table'] === $table && in_array($fk['parent'], ['fiscal_documents', 'fiscal_document_items'], true)
                        && $row->{$fk['column']} !== null
                        && ! in_array($row->{$fk['column']}, $plan['delete'][$fk['parent']] ?? [])) {
                        $plan['blockers'][] = "{$table} #{$id} liga um rascunho ao histórico fiscal preservado.";
                    }
                }
            }
        }

        $this->planPolymorphicRecords($companyId, $plan);
        $this->planReferences($companyId, $plan);

        // Resetting a balance must not affect a record referenced by another tenant.
        foreach (['financial_accounts', 'product_stocks'] as $table) {
            $ids = DB::table($table)->where('company_id', $companyId)->pluck('id')->all();
            foreach ($this->foreignKeys as $fk) {
                if ($fk['parent'] === $table) {
                    $this->checkTenant($this->references($fk, $ids), $fk['table'], $companyId, $plan);
                }
            }
        }

        foreach (['partners', 'products', 'services', 'equipments', 'financial_accounts', 'bank_account_connections',
            'nfe_sequences', 'nfse_sequences', 'sefaz_distribution_documents', 'remittance_assets'] as $table) {
            if ($this->hasColumn($table, 'company_id')) {
                $plan['preserve'][$table] = DB::table($table)->where('company_id', $companyId)
                    ->whereIntegerNotInRaw('id', $plan['delete'][$table] ?? [])->count();
            }
        }
        $plan['preserve']['company_partner'] = DB::table('company_partner')->where('company_id', $companyId)->count();
        $plan['preserve']['fiscal_documents'] = DB::table('fiscal_documents')->where('company_id', $companyId)
            ->whereIntegerNotInRaw('id', $plan['delete']['fiscal_documents'] ?? [])->count();

        foreach (['bank_slips', 'pix_charges'] as $table) {
            $plan['banking'][$table] = DB::table($table)->where('company_id', $companyId)->orderBy('id')
                ->get(['id', 'bank_account_connection_id', 'provider_identification', 'status', 'amount', 'due_date'])->toArray();
            foreach ($plan['banking'][$table] as $charge) {
                $charge->amount = round((float) $charge->amount / 100, 2);
            }
        }
        $plan['balances']['financial_accounts'] = FinancialAccount::withTrashed()->where('company_id', $companyId)
            ->get()->map(fn ($account) => [
                'id' => $account->id, 'name' => $account->name,
                'opening_balance' => $account->opening_balance, 'current_balance' => $account->current_balance,
            ])->all();
        $plan['balances']['stock'] = DB::table('product_stocks')->where('company_id', $companyId)
            ->get(['id', 'product_id', 'quantity_total', 'quantity_reserved', 'average_cost'])->toArray();
        $plan['blockers'] = array_values(array_unique($plan['blockers']));

        return $plan;
    }

    public function execute(int $companyId): array
    {
        return DB::transaction(function () use ($companyId): array {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            // Always rebuild the plan within the transaction; never trust a previous dry-run.
            $plan = $this->plan($companyId);
            if ($plan['blockers']) {
                throw new RuntimeException(implode(PHP_EOL, $plan['blockers']));
            }

            DB::table('fiscal_documents')->where('company_id', $companyId)
                ->whereIntegerNotInRaw('id', $plan['delete']['fiscal_documents'] ?? [])
                ->whereNull('operational_reset_at')->update(['operational_reset_at' => now()]);

            foreach ($plan['detach'] as $change) {
                foreach (array_chunk($change['ids'], 500) as $ids) {
                    DB::table($change['table'])->whereIn('id', $ids)->update([$change['column'] => null]);
                }
            }
            foreach ($plan['order'] as $table) {
                foreach (array_chunk($plan['delete'][$table], 500) as $ids) {
                    DB::table($table)->whereIn('id', $ids)->delete();
                }
            }

            DB::table('financial_accounts')->where('company_id', $companyId)->update(['opening_balance' => 0]);
            DB::table('product_stocks')->where('company_id', $companyId)->update([
                'quantity_total' => 0, 'quantity_reserved' => 0, 'average_cost' => 0, 'last_cost' => 0,
                'last_movement_date' => null, 'last_movement_type' => null,
            ]);

            return $plan;
        });
    }

    private function loadSchema(): void
    {
        $this->columns = [];
        $this->foreignKeys = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $metadata = collect(Schema::getColumns($table))->keyBy('name');
            $this->columns[$table] = $metadata->keys()->all();
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (count($fk['columns']) !== 1 || $fk['foreign_columns'] !== ['id']) {
                    throw new RuntimeException("Chave composta/não suportada em {$table}; revisar rotina antes de executar.");
                }
                $column = $fk['columns'][0];
                $this->foreignKeys[] = [
                    'table' => $table, 'column' => $column, 'parent' => $fk['foreign_table'],
                    'nullable' => $metadata[$column]['nullable'],
                ];
            }
        }
    }

    private function planReferences(int $companyId, array &$plan): void
    {
        $edges = [];
        foreach ($this->foreignKeys as $fk) {
            $parents = $plan['delete'][$fk['parent']] ?? [];
            if (! $parents) {
                continue;
            }
            $query = $this->references($fk, $parents);
            $this->checkTenant($query, $fk['table'], $companyId, $plan);
            $deleting = $plan['delete'][$fk['table']] ?? [];
            $retained = (clone $query)->whereIntegerNotInRaw('id', $deleting)->pluck('id')->all();
            if ($retained) {
                if ($fk['nullable'] && in_array($fk['table'].'.'.$fk['column'], self::DETACHABLE, true)) {
                    $plan['detach'][] = ['table' => $fk['table'], 'column' => $fk['column'], 'ids' => $retained];
                } else {
                    $plan['blockers'][] = "Dependência preservada: {$fk['table']}.{$fk['column']} IDs ".implode(', ', $retained).'.';
                }
            }
            $childIds = (clone $query)->whereIntegerInRaw('id', $deleting)->pluck('id')->all();
            if (! $childIds) {
                continue;
            }
            if ($fk['nullable']) {
                $plan['detach'][] = ['table' => $fk['table'], 'column' => $fk['column'], 'ids' => $childIds];
            } else {
                $edges[$fk['parent']][] = $fk['table'];
            }
        }

        $visited = [];
        $visiting = [];
        $visit = function (string $table) use (&$visit, &$visited, &$visiting, $edges, &$plan): void {
            if (isset($visited[$table])) {
                return;
            }
            if (isset($visiting[$table])) {
                $plan['blockers'][] = "Ciclo de dependências obrigatório em {$table}.";

                return;
            }
            $visiting[$table] = true;
            foreach ($edges[$table] ?? [] as $child) {
                $visit($child);
            }
            unset($visiting[$table]);
            $visited[$table] = true;
            $plan['order'][] = $table;
        };
        foreach ($plan['delete'] as $table => $ids) {
            if ($ids) {
                $visit($table);
            }
        }
    }

    private function planPolymorphicRecords(int $companyId, array &$plan): void
    {
        foreach ($plan['delete'] as $table => $ids) {
            if (! $ids) {
                continue;
            }
            $model = collect([ServiceOrder::class, Invoice::class,
                Quote::class, Requisition::class, ProductionOrder::class,
                CashMovement::class, AccountPayable::class, AccountReceivable::class,
                FiscalDocument::class])->first(fn ($class) => (new $class)->getTable() === $table);
            if ($model) {
                $types = array_unique([$model, (new $model)->getMorphClass()]);
                foreach (['attachments' => 'attachable', 'media' => 'model'] as $morphTable => $prefix) {
                    if (! isset($this->columns[$morphTable])) {
                        continue;
                    }
                    $query = DB::table($morphTable)->whereIn($prefix.'_type', $types)->whereIntegerInRaw($prefix.'_id', $ids);
                    $this->checkTenant($query, $morphTable, $companyId, $plan);
                    $plan['delete'][$morphTable] = array_values(array_unique([
                        ...($plan['delete'][$morphTable] ?? []), ...$query->pluck('id')->all(),
                    ]));
                }
            }
        }
        // Fiscal email history is preserved; only operational notifications are removed.
        $plan['delete']['email_dispatches'] = DB::table('email_dispatches')->where('company_id', $companyId)
            ->where(function (Builder $query) use ($plan): void {
                foreach (['quote' => 'quotes', 'service_order' => 'service_orders', 'invoice' => 'invoices',
                    'production_order' => 'production_orders', 'fiscal_document' => 'fiscal_documents'] as $type => $table) {
                    $query->orWhere(fn (Builder $q) => $q->where('document_type', $type)->whereIntegerInRaw('document_id', $plan['delete'][$table] ?? []));
                }
            })->pluck('id')->all();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns[$table] ?? [], true);
    }

    private function references(array $fk, array $ids): Builder
    {
        return DB::table($fk['table'])->whereIntegerInRaw($fk['column'], $ids);
    }

    private function checkTenant(Builder $query, string $table, int $companyId, array &$plan): void
    {
        if ($this->hasColumn($table, 'company_id')) {
            $foreign = (clone $query)->where(fn (Builder $q) => $q->where('company_id', '!=', $companyId)->orWhereNull('company_id'))->pluck('id')->all();
            if ($foreign) {
                $plan['blockers'][] = "Vínculo fora da empresa em {$table}, IDs ".implode(', ', $foreign).'.';
            }
        }
    }
}
