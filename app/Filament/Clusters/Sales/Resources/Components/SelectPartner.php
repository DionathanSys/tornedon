<?php

namespace App\Filament\Clusters\Sales\Resources\Components;

use App\Notification\NotifyService;
use App\Services\Partner\PartnerService;
use App\Services\Partner\QuickCreateCustomerPartnerService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class SelectPartner
{
    public static function make(string $field, ?string $type = 'customer'): Select
    {
        $select = Select::make($field)
            ->label('Parceiro')
            ->searchable()
            ->required()
            ->columnSpan(['md' => 2, 'lg' => 8])
            ->getSearchResultsUsing(
                fn(string $search): array => (new PartnerService())
                    ->searchForSelect($search, Filament::getTenant()->id, $type)
            )
            ->getOptionLabelUsing(
                fn($value): ?string => (new PartnerService())
                    ->getLabelForSelect((int) $value)
            );

        if ($type !== 'customer') {
            return $select;
        }

        return $select
            ->createOptionForm(QuickCreateCustomerPartnerForm::schema())
            ->createOptionUsing(function (array $data): int {
                $companyId = Filament::getTenant()?->id;
                $userId = auth()->id();

                if (! $companyId || ! $userId) {
                    throw ValidationException::withMessages([
                        'document_number' => 'Não foi possível identificar a empresa ou o usuário autenticado.',
                    ]);
                }

                $service = app(QuickCreateCustomerPartnerService::class);
                $companyPartner = $service->create($userId, $companyId, $data);

                if (! $companyPartner) {
                    NotifyService::error(message: $service->getMessageUser());

                    throw ValidationException::withMessages(
                        self::normalizeErrors($service->getErrors(), $service->getMessageUser())
                    );
                }

                NotifyService::success(message: $service->getMessage());

                return $companyPartner->partner_id;
            })
            ->createOptionModalHeading('Cadastro rápido de cliente')
            ->createOptionAction(fn (Action $action) => $action
                ->label('Novo cliente')
                ->icon(Heroicon::Plus)
                ->modalWidth(Width::FiveExtraLarge)
            );
    }

    private static function normalizeErrors(array $errors, string $fallback): array
    {
        if ($errors === []) {
            return ['document_number' => $fallback];
        }

        $normalized = [];

        foreach ($errors as $key => $value) {
            $normalized[is_string($key) ? $key : 'document_number'] = is_array($value)
                ? implode(' ', array_map('strval', $value))
                : (string) $value;
        }

        return $normalized;
    }
}
