<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

final class ViewLinkedServiceOrdersAction
{
    public static function make(): Action
    {
        return Action::make('viewLinkedServiceOrders')
            ->label('Ordens de Serviço')
            ->icon(Heroicon::WrenchScrewdriver)
            ->color('gray')
            ->badge(fn (Quote $record): int => $record->serviceOrders()->count())
            ->badgeColor('primary')
            ->visible(fn (Quote $record): bool => $record->serviceOrders()->exists())
            ->modalHeading('Ordens de Serviço vinculadas')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (Quote $record): HtmlString {
                $items = $record->serviceOrders()->orderBy('number')->get();

                $html = '<ul class="divide-y divide-gray-100 dark:divide-white/5">';

                foreach ($items as $so) {
                    $url   = ServiceOrderResource::getUrl('edit', ['record' => $so]);
                    $label = 'OS #' . ($so->number ?? $so->id);
                    $icon  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 inline-block mr-1 opacity-60">'
                           . '<path fill-rule="evenodd" d="M14.5 10a4.5 4.5 0 0 0 4.284-5.882c-.105-.324-.51-.391-.752-.15L15.34 6.66a.454.454 0 0 1-.493.11 3.01 3.01 0 0 1-1.618-1.616.455.455 0 0 1 .11-.494l2.694-2.692c.24-.241.174-.647-.15-.752a4.5 4.5 0 0 0-5.784 4.946l-2.98 2.98a.75.75 0 0 0-.22.53V9.5a.75.75 0 0 0-1.5 0v1.5H3.75a.75.75 0 0 0 0 1.5h1.5v1.5a.75.75 0 0 0 1.5 0v-1.5h1.5v-1.5a.75.75 0 0 0-.53-.22l-2.98-2.98a4.5 4.5 0 0 0 4.946-5.784c-.105-.324-.51-.391-.752-.15L6.66 4.34a.454.454 0 0 1-.493.11 3.01 3.01 0 0 1-1.618-1.616.455.455 0 0 1 .11-.494L7.35 0 .648C7.109.357 6.143 0 5.5 0A4.5 4.5 0 0 0 1 4.5c0 2.016 1.33 3.71 3.163 4.247L7.58 12.17c.11.11.22.11.22.22v5.86a.75.75 0 0 0 1.5 0v-5.86c0-.11.11-.11.22-.22l3.417-3.417c.537 1.833 2.23 3.163 4.247 3.163A4.5 4.5 0 0 0 14.5 10Z" clip-rule="evenodd" />'
                           . '</svg>';

                    $html .= '<li class="py-3 px-1">'
                           . '<a href="' . e($url) . '" '
                           . 'class="inline-flex items-center gap-x-2 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300 hover:underline">'
                           . $icon . e($label)
                           . '</a>'
                           . '</li>';
                }

                $html .= '</ul>';

                return new HtmlString($html);
            });
    }
}
