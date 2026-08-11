<?php

namespace App\Filament\Clusters\Financial\Resources\Components;

use App\Models\FinancialCategory;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SelectFinancialCategory
{
    public static function make(string $field, ?string $scope = null, bool $leavesOnly = true): Select
    {
        return Select::make($field)
            ->label('Categoria Financeira')
            ->options(fn (): array => $scope === null
                ? FinancialCategory::hierarchyOptionsForCompany(Filament::getTenant()->id)
                : FinancialCategory::optionsForCompany(Filament::getTenant()->id, $scope, $leavesOnly))
            ->getOptionLabelUsing(function ($value): ?string {
                if (! $value) {
                    return null;
                }

                $category = FinancialCategory::query()
                    ->where('company_id', Filament::getTenant()->id)
                    ->find($value);

                return $category?->full_name;
            })
            ->searchable()
            ->preload()
            ->native(false)
            ->createOptionForm(self::createOptionForm($scope))
            ->createOptionUsing(function (array $data) use ($scope): int {
                $companyId = Filament::getTenant()?->id;

                if (! $companyId) {
                    throw ValidationException::withMessages([
                        'name' => 'Não foi possível identificar a empresa atual.',
                    ]);
                }

                $data['company_id'] = $companyId;
                $data['created_by'] = Auth::id();
                $data['updated_by'] = Auth::id();

                if ($scope !== null) {
                    $data['allow_payable'] = $scope === 'payable';
                    $data['allow_receivable'] = $scope === 'receivable';
                    $data['allow_cash_movement'] = $scope === 'cash_movement';
                }

                return FinancialCategory::create($data)->getKey();
            })
            ->createOptionModalHeading('Nova categoria financeira')
            ->createOptionAction(fn (Action $action) => $action
                ->label('Nova categoria')
                ->icon(Heroicon::Plus)
                ->modalWidth(Width::FourExtraLarge)
            );
    }

    private static function createOptionForm(?string $scope): array
    {
        return [
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255),
            Select::make('parent_id')
                ->label('Categoria Pai')
                ->options(fn (): array => FinancialCategory::hierarchyOptionsForCompany(Filament::getTenant()->id))
                ->searchable()
                ->preload()
                ->native(false)
                ->placeholder('Raiz'),
            SelectChartAccount::make('chart_account_id', true)
                ->label('Plano de Contas')
                ->placeholder('Sem vínculo'),
            TextInput::make('sort_order')
                ->label('Ordem')
                ->numeric()
                ->default(0),
            Toggle::make('is_active')
                ->label('Ativa')
                ->inline(false)
                ->default(true),
            Toggle::make('allow_payable')
                ->label('Usar em Despesas')
                ->inline(false)
                ->default($scope === null || $scope === 'payable')
                ->hidden($scope !== null),
            Toggle::make('allow_receivable')
                ->label('Usar em Receitas')
                ->inline(false)
                ->default($scope === 'receivable')
                ->hidden($scope !== null),
            Toggle::make('allow_cash_movement')
                ->label('Usar em Transações')
                ->inline(false)
                ->default($scope === null || $scope === 'cash_movement')
                ->hidden($scope !== null),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(3),
        ];
    }
}
