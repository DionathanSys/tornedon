<?php

namespace App\Filament\Clusters\Hr;

use App\Filament\Clusters\Financial\Resources\Components\SelectFinancialCategory;
use App\Models\FinancialCategory;
use App\Services\Hr\HrService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Leandrocfe\FilamentPtbrFormFields\Money;

class HrForm
{
    public static function classifications(): array
    {
        $labels = ['financial_category_id' => 'Categoria financeira', 'chart_account_id' => 'Plano de contas', 'cost_center_id' => 'Centro de custo', 'result_center_id' => 'Centro de resultado'];
        $fields = [];
        foreach (HrService::CLASSIFICATIONS as $field => $table) {
            if ($field === 'financial_category_id') {
                $fields[] = SelectFinancialCategory::make($field, 'payable')->label($labels[$field])->columnSpanFull()->live()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('chart_account_id', FinancialCategory::query()->where('company_id', Filament::getTenant()->id)->find($state)?->chart_account_id))
                    ->createOptionUsing(function (array $data): int {
                        Gate::authorize('create', FinancialCategory::class);
                        $company = Filament::getTenant();
                        $validated = Validator::make($data, [
                            'name' => ['required', 'string', 'max:255'],
                            'parent_id' => ['nullable', 'integer', Rule::exists('financial_categories', 'id')->where('company_id', $company->id)],
                            'chart_account_id' => ['nullable', 'integer', Rule::exists('chart_accounts', 'id')->where('company_id', $company->id)],
                            'sort_order' => ['nullable', 'integer', 'min:0'], 'description' => ['nullable', 'string', 'max:10000'],
                        ])->validate();

                        return FinancialCategory::create([...$validated, 'company_id' => $company->id, 'created_by' => auth()->id(), 'updated_by' => auth()->id(), 'is_active' => true, 'allow_payable' => true, 'allow_receivable' => false, 'allow_cash_movement' => true])->id;
                    })
                    ->createOptionAction(fn (Action $action) => $action->label('Nova categoria')->icon('heroicon-o-plus')->modalWidth('4xl')->visible(fn () => Gate::allows('create', FinancialCategory::class)))
                    ->helperText('Pesquise uma categoria de despesa ou cadastre uma nova sem sair desta página.');

                continue;
            }
            $fields[] = Select::make($field)->label($labels[$field])->searchable()
                ->native(false)->preload()
                ->options(fn () => DB::table($table)->where('company_id', Filament::getTenant()->id)->orderBy('name')->pluck('name', 'id'))
                ->disabled($field === 'chart_account_id')->dehydrated($field !== 'chart_account_id')
                ->helperText($field === 'chart_account_id' ? 'Definido pela categoria financeira ao confirmar o lançamento.' : 'No lançamento, deixe vazio para usar o padrão do vínculo de RH.');
        }

        return $fields;
    }

    public static function money(string $name): Money
    {
        return Money::make($name)->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2, ',', '.'));
    }

    public static function moneyValue(mixed $value): float
    {
        return (float) (is_string($value) && str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value);
    }
}
