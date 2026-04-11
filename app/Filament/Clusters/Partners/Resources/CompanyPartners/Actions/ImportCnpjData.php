<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Actions;

use App\Models\CompanyPartner;
use App\Notification\NotifyService as notify;
use App\Services\Partner\CompanyPartnerCnpjImportService;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;

class ImportCnpjData
{
    public static function make(): Action
    {
        return Action::make('import-cnpj-data')
            ->label('Importar dados via CNPJ')
            ->icon(Heroicon::ArrowDownTray)
            ->color('info')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->modalHeading('Importar dados via CNPJ')
            ->modalDescription(
                'Esta ação irá consultar os dados cadastrais do CNPJ deste parceiro na Receita Federal '
                . 'e importará automaticamente o endereço e o contato (telefone e e-mail) registrados. '
                . 'Novos registros serão criados mesmo que já existam outros cadastrados. '
                . 'Deseja continuar?'
            )
            ->modalSubmitActionLabel('Importar')
            ->modalCancelActionLabel('Cancelar')
            ->visible(fn($operation, Get $get): bool =>
                $operation === 'edit'
                && ($get('document_type') === 'cnpj')
            )
            ->action(function (Action $action): void {
                /** @var CompanyPartner $record */
                $record = $action->getRecord();
                $service = app(CompanyPartnerCnpjImportService::class);
                $imported = $service->import($record->id, auth()->id());

                if (! $imported) {
                    notify::error(
                        title: 'Erro ao importar dados',
                        message: $service->getMessageUser(),
                    );
                    return;
                }

                notify::success(
                    title: 'Dados importados',
                    message: $service->getMessage(),
                );

                // 5. Recarrega o formulário para exibir os novos registros
                $livewire = $action->getLivewire();
                if ($livewire && method_exists($livewire, 'refreshFormData')) {
                    $record->refresh();
                    $record->load(['addresses', 'contacts']);
                    $livewire->refreshFormData(['addresses', 'contacts']);
                }
            });
    }
}
