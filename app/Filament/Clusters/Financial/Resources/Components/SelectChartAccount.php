<?php

namespace App\Filament\Clusters\Financial\Resources\Components;

use App\Enum\Financial\AccountingNature;
use App\Enum\Financial\ChartAccountType;
use App\Models\ChartAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class SelectChartAccount
{
    public static function make(string $field, bool $postableOnly = false): Select
    {
        return Select::make($field)
            ->label('Plano de Contas')
            ->options(fn (): array => ChartAccount::optionsForCompany(Filament::getTenant()->id, $postableOnly))
            ->getOptionLabelUsing(function ($value): ?string {
                if (! $value) {
                    return null;
                }

                $account = ChartAccount::query()
                    ->where('company_id', Filament::getTenant()->id)
                    ->find($value);

                return $account?->full_name;
            })
            ->searchable()
            ->preload()
            ->native(false)
            ->createOptionForm(self::createOptionForm($postableOnly))
            ->createOptionUsing(function (array $data) use ($postableOnly): int {
                $companyId = Filament::getTenant()?->id;

                if (! $companyId) {
                    throw ValidationException::withMessages([
                        'name' => 'Não foi possível identificar a empresa atual.',
                    ]);
                }

                $data['company_id'] = $companyId;

                if ($postableOnly) {
                    $data['is_postable'] = true;
                }

                return ChartAccount::create($data)->getKey();
            })
            ->createOptionModalHeading('Nova conta do plano')
            ->createOptionAction(fn (Action $action) => $action
                ->label('Nova conta')
                ->icon(Heroicon::Plus)
                ->modalWidth(Width::FourExtraLarge)
            );
    }

    private static function createOptionForm(bool $postableOnly): array
    {
        return [
            TextInput::make('code')
                ->label('Código')
                ->maxLength(255),
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255),
            Select::make('parent_id')
                ->label('Conta Pai')
                ->options(fn (): array => ChartAccount::optionsForCompany(Filament::getTenant()->id))
                ->searchable()
                ->preload()
                ->native(false)
                ->placeholder('Raiz'),
            Select::make('type')
                ->label('Tipo')
                ->options(ChartAccountType::toSelectArray())
                ->required()
                ->native(false),
            Select::make('nature')
                ->label('Natureza')
                ->options(AccountingNature::toSelectArray())
                ->native(false),
            TextInput::make('sort_order')
                ->label('Ordem')
                ->numeric()
                ->default(0),
            Toggle::make('is_postable')
                ->label('Permite lançamento?')
                ->inline(false)
                ->default(true)
                ->hidden($postableOnly),
            Toggle::make('is_active')
                ->label('Ativa')
                ->inline(false)
                ->default(true),
        ];
    }
}
