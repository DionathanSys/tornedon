<?php

namespace App\Services\Financial\Dre;

use App\Enum\Financial\ChartAccountType;
use App\Enum\Financial\DreLineType;
use App\Enum\Financial\DreOperation;
use App\Models\ChartAccount;
use App\Models\DreModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetupDreService
{
    public const GROUPS = [
        'revenue' => 'Receitas de vendas e serviços',
        'deduction' => 'Deduções e impostos sobre vendas',
        'cost' => 'Custos',
        'expense' => 'Despesas operacionais',
        'financial_result' => 'Receitas e despesas financeiras',
        'other' => 'Outros resultados',
        'exclude' => 'Não incluir nesta DRE',
    ];

    public function suggestions(int $companyId): array
    {
        return ChartAccount::forCompany($companyId)->orderBy('code')->get()
            ->map(fn (ChartAccount $account): array => [
                'account_id' => $account->id,
                'account_name' => $account->full_name,
                'group' => $account->type?->value ?? ChartAccountType::OTHER->value,
            ])->all();
    }

    public function create(int $companyId, array $data): DreModel
    {
        return DB::transaction(function () use ($companyId, $data): DreModel {
            $accounts = ChartAccount::forCompany($companyId)->get()->keyBy('id');
            $mapping = [];
            foreach ($data['accounts'] ?? [] as $row) {
                $id = (int) ($row['account_id'] ?? 0);
                $group = $row['group'] ?? '';
                if (! $accounts->has($id) || isset($mapping[$id]) || ! array_key_exists($group, self::GROUPS)) {
                    throw ValidationException::withMessages(['accounts' => 'Revise as contas: existem vínculos inválidos ou duplicados.']);
                }
                $mapping[$id] = $group;
            }
            if (count($mapping) !== $accounts->count() || ! collect($mapping)->contains(fn ($group) => $group !== 'exclude')) {
                throw ValidationException::withMessages(['accounts' => 'Classifique todas as contas e inclua ao menos uma na DRE. Cadastre o plano de contas caso esteja vazio.']);
            }

            $model = DreModel::create([
                'company_id' => $companyId,
                'name' => $data['name'],
                'template_key' => 'dre_gerencial_guiada_v1',
                'template_version' => 1,
                'is_default' => (bool) ($data['is_default'] ?? true),
                'is_active' => true,
                'description' => 'Modelo guiado, com sinais naturais e descontos financeiros separados. Cada conta é vinculada sem descendentes para evitar duplicidades.',
            ]);

            $structure = [
                ['RECEITAS', 'Receitas de vendas e serviços', 'revenue'],
                ['DEDUCOES', 'Deduções e impostos sobre vendas', 'deduction'],
                ['RECEITA_LIQUIDA', 'Receita líquida', null],
                ['CUSTOS', 'Custos', 'cost'],
                ['LUCRO_BRUTO', 'Lucro bruto', null],
                ['DESPESAS', 'Despesas operacionais', 'expense'],
                ['RESULTADO_OPERACIONAL', 'Resultado operacional', null],
                ['FINANCEIRO', 'Receitas e despesas financeiras', 'financial_result'],
                ['DESCONTOS_RECEBIMENTO', 'Despesas financeiras — descontos concedidos', 'discounts'],
                ['OUTROS', 'Outros resultados', 'other'],
                ['RESULTADO', 'Resultado final', null],
            ];
            foreach ($structure as $order => [$code, $name, $group]) {
                $line = $model->lines()->create([
                    'code' => $code, 'name' => $name,
                    'line_type' => $group === null ? DreLineType::RUNNING_TOTAL : ($group === 'discounts' ? DreLineType::RECEIPT_DISCOUNTS : DreLineType::ACCOUNT_GROUP),
                    'operation' => DreOperation::ADD,
                    'sort_order' => $order + 1,
                    'is_bold' => $group === null,
                    'is_visible' => true,
                    'display_depth' => 0,
                ]);
                if ($group !== null && $group !== 'discounts') {
                    $line->chartAccounts()->sync(collect($mapping)->filter(fn ($value) => $value === $group)
                        ->map(fn () => ['include_descendants' => false])->all());
                }
            }
            $model->refreshStructureHash();

            return $model;
        });
    }
}
