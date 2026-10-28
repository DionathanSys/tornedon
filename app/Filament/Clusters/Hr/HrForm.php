<?php

namespace App\Filament\Clusters\Hr;

use App\Services\Hr\HrService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;

class HrForm
{
    public static function classifications(): array
    {
        $labels = ['financial_category_id' => 'Categoria financeira', 'chart_account_id' => 'Plano de contas', 'cost_center_id' => 'Centro de custo', 'result_center_id' => 'Centro de resultado'];
        $fields = [];
        foreach (HrService::CLASSIFICATIONS as $field => $table) {
            $fields[] = Select::make($field)->label($labels[$field])->searchable()
                ->options(fn () => DB::table($table)->where('company_id', Filament::getTenant()->id)->orderBy('name')->pluck('name', 'id'))
                ->disabled($field === 'chart_account_id')->dehydrated($field !== 'chart_account_id')
                ->helperText($field === 'chart_account_id' ? 'Definido pela categoria financeira ao confirmar o lançamento.' : 'No lançamento, deixe vazio para usar o padrão do vínculo de RH.');
        }

        return $fields;
    }
}
