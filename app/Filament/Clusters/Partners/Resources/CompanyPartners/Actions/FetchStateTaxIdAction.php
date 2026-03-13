<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Actions;

use App\Notification\NotifyService as notify;
use App\Services\Cnpj\CnpjConsultationService;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;

class FetchStateTaxIdAction
{
    public static function make(): Action
    {
        return Action::make('fetch-state-tax-id')
            ->label('Buscar')
            ->icon(Heroicon::MagnifyingGlass)
            ->color('info')
            ->size(Size::Small)
            ->action(function (Action $action): void {
                $livewire = $action->getLivewire();
                
                // Obtém o valor do documento (CNPJ)
                $documentNumber = $livewire->data['document_number'] ?? null;
                $documentType = $livewire->data['document_type'] ?? null;

                if (!$documentNumber) {
                    notify::warning(
                        title: 'CNPJ não informado',
                        message: 'Informe o CNPJ para buscar a inscrição estadual.',
                    );
                    return;
                }

                // Valida se é CNPJ
                if ($documentType !== 'cnpj') {
                    notify::warning(
                        title: 'Tipo de documento inválido',
                        message: 'Apenas CNPJ permite busca de inscrição estadual.',
                    );
                    return;
                }

                // Consulta o CNPJ
                $cnpjService = new CnpjConsultationService();
                $vo = $cnpjService->consult($documentNumber);

                if ($cnpjService->hasError()) {
                    notify::error(
                        title: 'Erro ao consultar CNPJ',
                        message: $cnpjService->getMessage(),
                    );
                    return;
                }

                // Obtém a inscrição estadual principal
                $registration = $vo->getMainStateRegistration();

                if (!$registration) {
                    notify::warning(
                        title: 'Inscrição Estadual não encontrada',
                        message: 'Nenhuma inscrição estadual habilitada foi encontrada para este CNPJ.',
                    );
                    return;
                }

                // Preenche o campo com a inscrição estadual
                $livewire->data['state_tax_id'] = $registration->number;

                notify::success(
                    title: 'Inscrição Estadual encontrada',
                    message: "IE: {$registration->number} - {$registration->state}",
                );
            })
            ->visible(fn(?string $state, Get $get): bool =>
                ($get('document_type') === 'cnpj') && !empty($get('document_number'))
            );
    }
}
