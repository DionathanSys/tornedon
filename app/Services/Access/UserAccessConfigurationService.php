<?php

namespace App\Services\Access;

use App\Filament\Operation\OperationModules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserAccessConfigurationService
{
    public function formData(User $user): array
    {
        return $user->companies()->get()->map(function ($company) use ($user): array {
            $settings = app(UserAccessService::class)->settings($user, $company);

            return [
                'company_id' => $company->id,
                'operation_enabled' => $settings?->panels['operation'] ?? true,
                'permissions' => $settings?->permissions ?? array_keys(OperationModules::permissions()),
                'order' => array_map(fn (string $module): array => ['module' => $module], $settings?->navigation['operation'] ?? array_keys(OperationModules::definitions())),
            ];
        })->all();
    }

    public function validate(array $rows, array $companyIds): array
    {
        return Validator::make(['data' => ['company_access' => $rows]], [
            'data.company_access' => ['array'],
            'data.company_access.*.company_id' => ['required', 'integer', 'distinct', Rule::in($companyIds)],
            'data.company_access.*.operation_enabled' => ['required', 'boolean'],
            'data.company_access.*.permissions' => ['present', 'array'],
            'data.company_access.*.permissions.*' => ['string', Rule::in(array_keys(OperationModules::permissions()))],
            'data.company_access.*.order' => ['present', 'array'],
            'data.company_access.*.order.*.module' => ['required', Rule::in(array_keys(OperationModules::definitions()))],
        ])->validate()['data']['company_access'];
    }

    public function save(User $user, array $rows): void
    {
        foreach ($rows as $row) {
            $existing = $user->companyAccesses()->where('company_id', $row['company_id'])->first();
            $user->companyAccesses()->updateOrCreate(['company_id' => $row['company_id']], [
                'permissions' => array_values(array_unique($row['permissions'])),
                'panels' => [...($existing?->panels ?? []), 'operation' => (bool) $row['operation_enabled']],
                'navigation' => [...($existing?->navigation ?? []), 'operation' => array_values(array_unique(array_column($row['order'], 'module')))],
            ]);
        }
        $companyIds = $user->companies()->pluck('companies.id')->all();
        $user->companyAccesses()->whereNotIn('company_id', $companyIds)->delete();
        foreach ($user->companyAccesses()->whereNotIn('company_id', array_column($rows, 'company_id'))->get() as $settings) {
            $panels = $settings->panels;
            $navigation = $settings->navigation;
            unset($panels['operation'], $navigation['operation']);
            if ($panels === [] && $navigation === []) {
                $settings->delete();

                continue;
            }
            $settings->update([
                'panels' => $panels,
                'navigation' => $navigation,
                'permissions' => array_values(array_unique([
                    ...array_keys(OperationModules::permissions()),
                    ...array_diff($settings->permissions, array_keys(OperationModules::permissions())),
                ])),
            ]);
        }
    }
}
