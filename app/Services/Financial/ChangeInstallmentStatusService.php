<?php

namespace App\Services\Financial;

use App\Enum\AccountPayable\Status as PayableStatus;
use App\Enum\AccountReceivable\Status as ReceivableStatus;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\Company;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ChangeInstallmentStatusService
{
    public static function module(string $model): string
    {
        return match ($model) {
            AccountPayableInstallment::class => 'account_payables',
            AccountReceivableInstallment::class => 'account_receivables',
        };
    }

    public static function options(string $model): array
    {
        $enum = self::module($model) === 'account_payables' ? PayableStatus::class : ReceivableStatus::class;

        return array_diff_key($enum::toSelectArray(), ['pending' => true]);
    }

    public function allows(User $user, Company $company, string $model, ?string $panel): bool
    {
        $access = app(UserAccessService::class);
        $module = self::module($model);

        return $access->allows($user, $company, "$module.access", $panel)
            && in_array("$module.change_status_without_movement", $access->settings($user, $company)?->permissions ?? [], true);
    }

    public function change(Collection $records, string $model, string $status, User $user, Company $company, ?string $panel): int
    {
        abort_unless($this->allows($user, $company, $model, $panel), 403);
        Validator::make(['status' => $status], [
            'status' => ['required', Rule::in(array_keys(self::options($model)))],
        ])->validate();

        return DB::transaction(function () use ($records, $model, $status, $company): int {
            abort_unless($records->every(fn ($record) => $record instanceof $model), 403);
            $locked = $model::query()->whereKey($records->modelKeys())->orderBy('id')->lockForUpdate()->get();
            abort_unless($locked->count() === $records->count()
                && $locked->every(fn ($record) => (int) $record->company_id === (int) $company->id), 403);

            $updated = 0;
            foreach ($locked as $record) {
                if ($record->status->value === $status) {
                    continue;
                }

                $record->update(['status' => $status]);
                $updated++;
            }

            return $updated;
        });
    }
}
