<?php

namespace App\Filament\Forms\Components;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Services\Payment\CustomerPaymentDefaultsResolver;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

final class PaymentConditionFields
{
    public static function make(string $condition = 'payment_condition', string $details = 'payment_condition_details', bool $corporateCard = false): Group
    {
        return Group::make([
            TextInput::make("$details.installments")
                ->default(fn (?Model $record, Get $get) => data_get(self::defaults($record, $get, $details), 'installments'))
                ->label('Quantidade de parcelas')->integer()->minValue(1)->maxValue(999)->required(),
            TextInput::make("$details.first_due_days")
                ->visible(fn (Get $get): bool => ! $corporateCard || $get('payment_method') !== Method::CREDIT_CARD->value)
                ->default(fn (?Model $record, Get $get) => data_get(self::defaults($record, $get, $details), 'first_due_days'))
                ->label('Primeiro vencimento em (dias)')->integer()->minValue(0)->required()
                ->helperText('Dias após a data do documento. No cartão, prevalece o prazo de repasse da instituição.'),
            TextInput::make("$details.interval_days")
                ->visible(fn (Get $get): bool => ! $corporateCard || $get('payment_method') !== Method::CREDIT_CARD->value)
                ->default(fn (?Model $record, Get $get) => data_get(self::defaults($record, $get, $details), 'interval_days'))
                ->label('Intervalo entre parcelas (dias)')->integer()->minValue(1)->maxValue(365)->required(),
        ])
            ->columns(3)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => $get($condition) === Condition::CUSTOM->value);
    }

    private static function defaults(?Model $record, Get $get, string $details): ?array
    {
        if ($record !== null) {
            return data_get($record, $details);
        }

        $companyId = Filament::getTenant()?->id;
        if (! $companyId) {
            return null;
        }

        return app(CustomerPaymentDefaultsResolver::class)
            ->defaultsForCustomer($companyId, filled($get('customer_id')) ? (int) $get('customer_id') : null)['payment_condition_details'];
    }
}
