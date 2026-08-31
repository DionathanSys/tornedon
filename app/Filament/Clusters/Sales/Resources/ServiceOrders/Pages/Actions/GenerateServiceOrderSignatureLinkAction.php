<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions;

use App\Models\ServiceOrder;
use App\Services\ServiceOrder\ServiceOrderSignatureLinkService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

final class GenerateServiceOrderSignatureLinkAction
{
    public static function make(): Action
    {
        return Action::make('generateServiceOrderSignatureLink')
            ->label('Link para assinatura')
            ->icon(Heroicon::Link)
            ->color('primary')
            ->visible(fn (ServiceOrder $record): bool => blank($record->customer_signature))
            ->modalHeading('Link para assinatura do cliente')
            ->modalDescription('Envie este link ao cliente. Ele será válido por 7 dias e poderá ser usado uma única vez.')
            ->modalContent(function (ServiceOrder $record): Htmlable {
                $tenant = Filament::getTenant();

                if (! $tenant || (int) $tenant->getKey() !== (int) $record->company_id) {
                    return new HtmlString('<p class="text-danger-600">A ordem de serviço não pertence à empresa selecionada.</p>');
                }

                try {
                    $generated = app(ServiceOrderSignatureLinkService::class)->create($record, (int) Auth::id());
                } catch (\Throwable $exception) {
                    report($exception);

                    return new HtmlString('<p class="text-danger-600">Não foi possível gerar o link de assinatura.</p>');
                }

                $url = e($generated['url']);
                $expiresAt = e($generated['expires_at']->format('d/m/Y H:i'));

                return new HtmlString(<<<HTML
                    <div class="space-y-4">
                        <div>
                            <label for="service-order-signature-link" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">
                                Link de assinatura
                            </label>
                            <div class="flex gap-2">
                                <input
                                    id="service-order-signature-link"
                                    type="text"
                                    value="{$url}"
                                    readonly
                                    onclick="this.select()"
                                    class="fi-input block min-w-0 flex-1 rounded-lg border-gray-300 bg-gray-50 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                >
                                <button
                                    type="button"
                                    onclick="const input = document.getElementById('service-order-signature-link'); input.select(); navigator.clipboard?.writeText(input.value).then(() => { this.textContent = 'Copiado'; setTimeout(() => this.textContent = 'Copiar', 1800); });"
                                    class="fi-btn fi-btn-color-primary rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500"
                                >Copiar</button>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Validade: <strong>{$expiresAt}</strong>. Gerar outro link invalida o anterior.</p>
                    </div>
                HTML);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalWidth('lg');
    }
}
