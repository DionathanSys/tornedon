<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages\Actions;

use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

final class ViewLinkedRequisitionsAction
{
    public static function make(): Action
    {
        return Action::make('viewLinkedRequisitions')
            ->label('Requisições')
            ->icon(Heroicon::ClipboardDocumentList)
            ->color('gray')
            ->badge(fn (Quote $record): int => $record->requisitions()->count())
            ->badgeColor('primary')
            ->visible(fn (Quote $record): bool => $record->requisitions()->exists())
            ->modalHeading('Requisições vinculadas')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (Quote $record): HtmlString {
                $items = $record->requisitions()->orderBy('number')->get();

                $html = '<ul class="divide-y divide-gray-100 dark:divide-white/5">';

                foreach ($items as $req) {
                    $url = RequisitionResource::getUrl('edit', ['record' => $req]);
                    $label = 'Requisição #' . ($req->number ?? $req->id);
                    $icon  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 inline-block mr-1 opacity-60">'
                           . '<path fill-rule="evenodd" d="M4.25 2A2.25 2.25 0 0 0 2 4.25v11.5A2.25 2.25 0 0 0 4.25 18h11.5A2.25 2.25 0 0 0 18 15.75V4.25A2.25 2.25 0 0 0 15.75 2H4.25Zm4.03 6.28a.75.75 0 0 0-1.06-1.06L5.97 8.47a.75.75 0 0 0 0 1.06l1.25 1.25a.75.75 0 1 0 1.06-1.06l-.72-.72h3.69a.75.75 0 0 0 0-1.5H7.56l.72-.72Zm4.5-1.06a.75.75 0 1 0-1.06 1.06l.72.72H8.75a.75.75 0 0 0 0 1.5h3.44l-.72.72a.75.75 0 1 0 1.06 1.06l1.25-1.25a.75.75 0 0 0 0-1.06l-1.25-1.25Z" clip-rule="evenodd" />'
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
