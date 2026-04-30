<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class CreateSupplierAction
{
    public static function make(): Action
    {
        return Action::make('createSupplier')
            ->label('Cadastrar fornecedor')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(fn(SefazDistributionDocument $record): bool => $record->partner_id === null)
            ->schema(fn(SefazDistributionDocument $record): array => [
                Textarea::make('name')
                    ->label('Nome do fornecedor')
                    ->default($record->issuer_name)
                    ->required()
                    ->rows(2),
                Textarea::make('document_number')
                    ->label('CPF/CNPJ')
                    ->default($record->issuer_document)
                    ->required()
                    ->rows(1),
            ])
            ->action(function (SefazDistributionDocument $record, array $data): void {
                app(SefazDistributionDocumentService::class)->createAndLinkSupplier(
                    $record,
                    (string) $data['name'],
                    (string) $data['document_number'],
                    Auth::id(),
                );

                Notification::make()
                    ->title('Fornecedor cadastrado e vinculado')
                    ->success()
                    ->send();
            });
    }
}
