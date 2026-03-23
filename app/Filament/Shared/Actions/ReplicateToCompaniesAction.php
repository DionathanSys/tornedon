<?php

namespace App\Filament\Shared\Actions;

use App\Jobs\ReplicateCompanyPartnerJob;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Notification\NotifyService as notify;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ReplicateToCompaniesAction
{
    public static function make(): Action
    {
        return Action::make('replicateToCompanies')
            ->label('Replicar para outras empresas')
            ->icon(Heroicon::ArrowUturnRight)
            ->color('warning')
            ->tooltip('Replicar para outras empresas')
            ->schema([
                CheckboxList::make('target_company_ids')
                    ->label('Empresas de destino')
                    ->helperText('Selecione as empresas para as quais deseja copiar este parceiro')
                    ->columnSpanFull()
                    ->options(function (Model $record) {
                        $currentUser        = Auth::user();
                        $userCompanies      = $currentUser->companies->pluck('id');
                        $currentCompanyId   = $record->company_id ?? $currentUser->current_company_id;

                        return Company::query()
                            ->whereIn('id', $userCompanies)
                            ->where('id', '!=', $currentCompanyId)
                            ->pluck('name', 'id');
                    })
                    ->columns(2)
                    ->required()
                    ->minItems(1),
            ])
            ->action(function (CompanyPartner $record, array $data): void {
                $userId = Auth::id();

                if (! $userId) {
                    notify::error(message: 'Não foi possível identificar o usuário que solicitou a replicação.');
                    return;
                }

                ReplicateCompanyPartnerJob::dispatch(
                    $record->id,
                    array_map(fn ($item) => intval($item), $data['target_company_ids']),
                    $userId
                );

                notify::info(
                    title: 'Replicação agendada',
                    message: 'O parceiro será replicado em segundo plano. O resultado será enviado por notificação no sistema.'
                );
            })
            ->modalHeading('Replicar Registro')
            ->modalSubmitActionLabel('Replicar')
            ->modalCancelActionLabel('Cancelar');
    }
}
