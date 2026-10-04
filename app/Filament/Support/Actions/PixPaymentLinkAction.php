<?php

namespace App\Filament\Support\Actions;

use App\Enum\Financial\PixChargeStatus;
use App\Models\AccountReceivableInstallment;
use App\Models\PixCharge;
use App\Services\Financial\Pix\PixChargePublicLinkService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Js;

final class PixPaymentLinkAction
{
    public static function make(): Action
    {
        return self::base('copy_pix_payment_link')
            ->label('Copiar link PIX')
            ->icon(Heroicon::ClipboardDocument)
            ->alpineClickHandler(function (Model $record): string {
                $url = Js::from(self::paymentUrl($record));

                return "navigator.clipboard.writeText({$url}).then(() => { \$tooltip('Link PIX copiado', { timeout: 2000 }); }).catch(() => { \$tooltip('Não foi possível copiar o link', { timeout: 3000 }); });";
            });
    }

    public static function open(): Action
    {
        return self::base('open_pix_payment_link')
            ->label('Abrir pagamento PIX')
            ->icon(Heroicon::ArrowTopRightOnSquare)
            ->url(fn (Model $record): string => self::paymentUrl($record), shouldOpenInNewTab: true);
    }

    private static function base(string $name): Action
    {
        return Action::make($name)
            ->color('info')
            ->visible(function (Model $record): bool {
                $charge = self::resolveCharge($record);

                return $charge?->status === PixChargeStatus::REGISTERED
                    && (filled($charge->qr_code) || filled($charge->pix_copy_paste));
            });
    }

    private static function paymentUrl(Model $record): string
    {
        $charge = self::resolveCharge($record);

        if (! $charge || $charge->status !== PixChargeStatus::REGISTERED) {
            return '';
        }

        return app(PixChargePublicLinkService::class)->generate($charge)['url'];
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
