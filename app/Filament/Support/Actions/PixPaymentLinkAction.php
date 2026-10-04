<?php

namespace App\Filament\Support\Actions;

use App\Enum\Financial\PixChargeStatus;
use App\Models\AccountReceivableInstallment;
use App\Models\PixCharge;
use App\Services\Financial\Pix\PixChargePublicLinkService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

final class PixPaymentLinkAction
{
    public static function make(): Action
    {
        return Action::make('pix_payment_link')
            ->label('Link de pagamento')
            ->icon(Heroicon::Link)
            ->color('info')
            ->visible(function (Model $record): bool {
                $charge = self::resolveCharge($record);

                return $charge?->status === PixChargeStatus::REGISTERED
                    && (filled($charge->qr_code) || filled($charge->pix_copy_paste));
            })
            ->modalHeading('Link de pagamento PIX')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (Model $record) {
                $charge = self::resolveCharge($record);

                if (! $charge) {
                    return new HtmlString('<p>Não foi possível localizar a cobrança PIX.</p>');
                }

                $link = app(PixChargePublicLinkService::class)->generate($charge);

                return view('filament.actions.pix-payment-link', [
                    'charge' => $charge,
                    'url' => $link['url'],
                    'expiresAt' => $link['expires_at'],
                ]);
            });
    }

    private static function resolveCharge(Model $record): ?PixCharge
    {
        if ($record instanceof PixCharge) {
            return $record;
        }

        if ($record instanceof AccountReceivableInstallment) {
            return $record->latestPixCharge;
        }

        return null;
    }
}
