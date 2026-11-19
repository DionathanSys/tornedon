<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Models\AccountPayable;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionFinancialLinkService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

final class LinkAccountPayableAction
{
    public static function make(): Action
    {
        return Action::make('linkAccountPayable')->label('Vincular conta a pagar existente')->icon('heroicon-o-link')
            ->authorize(fn (SefazDistributionDocument $record): bool => PayableAccess::allows($record, 'update'))
            ->visible(fn (SefazDistributionDocument $record): bool => ! $record->account_payable_id && (bool) $record->partner_id)
            ->modalDescription('Selecione uma conta da mesma empresa e fornecedor. Valores, parcelas e baixas serão preservados.')
            ->schema([
                Select::make('account_payable_id')->label('Conta a pagar')->required()->searchable()->native(false)
                    ->options(fn (SefazDistributionDocument $record): array => AccountPayable::query()
                        ->where('company_id', Filament::getTenant()->id)->where('supplier_id', $record->partner_id)
                        ->where('status', '!=', 'cancelled')
                        ->whereNotIn('id', SefazDistributionDocument::query()->whereNotNull('account_payable_id')->select('account_payable_id'))
                        ->orderByDesc('id')->get()
                        ->filter(fn (AccountPayable $payable): bool => PayableAccess::allows($record, 'update', $payable))
                        ->mapWithKeys(fn (AccountPayable $payable): array => [$payable->id => '#'.$payable->id.' · '.($payable->note_number ?: $payable->document_number).' · '.$payable->description.' · R$ '.number_format((float) $payable->due_amount, 2, ',', '.')])->all()),
            ])
            ->action(function (SefazDistributionDocument $record, array $data): void {
                $payable = AccountPayable::query()->where('company_id', Filament::getTenant()->id)->findOrFail($data['account_payable_id']);
                abort_unless(PayableAccess::allows($record, 'update', $payable), 403);
                app(SefazDistributionFinancialLinkService::class)->synchronize($record, $payable, actorId: Filament::auth()->id());
                $record->refresh();
                Notification::make()->title('Conta a pagar vinculada')->success()->send();
            });
    }

    public static function repair(): Action
    {
        return Action::make('synchronizeFinancialLinks')->label('Sincronizar vínculos financeiros')->icon('heroicon-o-arrow-path')
            ->authorize(fn (SefazDistributionDocument $record): bool => PayableAccess::allows($record, 'update', $record->accountPayable))
            ->requiresConfirmation()->modalDescription('Reconhece a nota pela chave de acesso e reaproveita a conta vinculada. Não altera valores, parcelas ou baixas.')
            ->action(function (SefazDistributionDocument $record): void {
                app(SefazDistributionFinancialLinkService::class)->synchronize($record, actorId: Filament::auth()->id());
                $record->refresh();
                Notification::make()->title('Vínculos financeiros sincronizados')->success()->send();
            });
    }
}
