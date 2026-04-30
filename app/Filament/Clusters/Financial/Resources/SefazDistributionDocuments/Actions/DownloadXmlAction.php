<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Models\SefazDistributionDocument;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class DownloadXmlAction
{
    public static function make(): Action
    {
        return Action::make('downloadXml')
            ->label('Baixar XML')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn(SefazDistributionDocument $record): bool => is_string($record->full_xml_path) || is_string($record->summary_xml_path))
            ->action(function (SefazDistributionDocument $record) {
                $path = $record->full_xml_path ?: $record->summary_xml_path;

                if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
                    Notification::make()
                        ->title('XML não encontrado')
                        ->body('O arquivo XML não está disponível no storage.')
                        ->danger()
                        ->send();

                    return null;
                }

                return response()->download(Storage::disk('local')->path($path), basename($path));
            });
    }
}
