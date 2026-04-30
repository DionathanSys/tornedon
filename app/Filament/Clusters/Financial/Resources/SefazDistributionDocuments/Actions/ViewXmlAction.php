<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Models\SefazDistributionDocument;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ViewXmlAction
{
    public static function make(): Action
    {
        return Action::make('viewXml')
            ->label('Visualizar XML')
            ->icon('heroicon-o-code-bracket')
            ->modalHeading('XML do documento')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->visible(fn(SefazDistributionDocument $record): bool => is_string($record->full_xml_path) || is_string($record->summary_xml_path))
            ->modalContent(function (SefazDistributionDocument $record): HtmlString {
                $path = $record->full_xml_path ?: $record->summary_xml_path;
                $xml = is_string($path) && Storage::disk('local')->exists($path)
                    ? Storage::disk('local')->get($path)
                    : 'XML não encontrado no storage.';

                return new HtmlString('<pre style="white-space: pre-wrap; font-size: 12px;">' . e($xml) . '</pre>');
            });
    }
}
