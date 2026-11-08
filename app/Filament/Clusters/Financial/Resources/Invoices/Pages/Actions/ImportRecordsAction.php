<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Enum\Invoice\InvoiceAction;
use App\Enum\ProductionOrder\Status as ProductionOrderStatus;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Models\Invoice;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\InvoiceActionEligibilityService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class ImportRecordsAction
{
    public static function make(): Action
    {
        return Action::make('importRecords')
            ->label('Vincular Registros')
            ->icon(Heroicon::Link)
            ->color('gray')
            ->modalHeading('Importar Registros para a Fatura')
            ->modalDescription('Selecione os registros que deseja vincular a esta fatura. Apenas registros encerrados/concluídos do mesmo cliente serão exibidos.')
            ->modalSubmitActionLabel('Importar')
            ->visible(function (Invoice $record): bool {
                // Não permitir importação se já existe documento fiscal
                return app(InvoiceActionEligibilityService::class)->evaluate($record, InvoiceAction::EDIT_COMPOSITION)['allowed'];
            })
            ->schema(function (Invoice $record): array {
                $customerId = $record->customer_id;

                return [
                    Section::make('Ordens de Serviço')
                        ->schema([
                            Select::make('service_order_ids')
                                ->label('Ordens de Serviço')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->options(
                                    ServiceOrder::query()
                                        ->where('company_id', $record->company_id)
                                        ->where('customer_id', $customerId)
                                        ->where('status', ServiceOrderState::CLOSED)
                                        ->whereNull('invoice_id')
                                        ->get()
                                        ->mapWithKeys(fn (ServiceOrder $so) => [
                                            $so->id => "#{$so->number} — ".($so->customer?->name ?? 'S/C'),
                                        ])
                                ),
                        ]),
                    Section::make('Requisições')
                        ->schema([
                            Select::make('requisition_ids')
                                ->label('Requisições')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->options(
                                    Requisition::query()
                                        ->where('company_id', $record->company_id)
                                        ->where('customer_id', $customerId)
                                        ->where('status', RequisitionStatus::CLOSED)
                                        ->whereNull('invoice_id')
                                        ->get()
                                        ->mapWithKeys(fn (Requisition $req) => [
                                            $req->id => "#{$req->number} — ".($req->customer?->name ?? 'S/C'),
                                        ])
                                ),
                        ]),
                ];
            })
            ->action(function (Invoice $record, array $data): void {
                $soIds = $data['service_order_ids'] ?? [];
                $reqIds = $data['requisition_ids'] ?? [];
                $poIds = $data['production_order_ids'] ?? [];

                if (empty($soIds) && empty($reqIds) && empty($poIds)) {
                    notify::error(message: 'Nenhum registro selecionado para importação.');

                    return;
                }

                // Verifica se a fatura ainda não possui documento fiscal
                if ($record->fiscalDocuments()->exists()) {
                    notify::error(message: 'Não é possível importar registros após a emissão de documento fiscal.');

                    return;
                }

                $userId = Auth::id();

                try {
                    DB::transaction(function () use ($record, $soIds, $reqIds, $poIds, $userId) {
                        $record = Invoice::where('company_id', $record->company_id)->lockForUpdate()->findOrFail($record->id);
                        abort_unless((int) $record->company_id === (int) Filament::getTenant()->id, 403);
                        Gate::authorize('update', $record);
                        app(InvoiceActionEligibilityService::class)->ensureAllowed($record, InvoiceAction::EDIT_COMPOSITION);
                        // Importa Ordens de Serviço
                        if (! empty($soIds)) {
                            $serviceOrders = ServiceOrder::whereIn('id', $soIds)
                                ->where('company_id', $record->company_id)
                                ->where('customer_id', $record->customer_id)
                                ->where('status', ServiceOrderState::CLOSED)
                                ->whereNull('invoice_id')
                                ->lockForUpdate()->get();

                            throw_unless($serviceOrders->count() === count(array_unique($soIds)), ValidationException::withMessages(['orders' => 'Uma das OS não está mais disponível para faturamento.']));

                            foreach ($serviceOrders as $so) {
                                Gate::authorize('update', $so);
                                $so->state()->invoice($so, $userId, $record->id);
                            }
                        }

                        // Importa Requisições
                        if (! empty($reqIds)) {
                            $requisitions = Requisition::whereIn('id', $reqIds)
                                ->where('company_id', $record->company_id)
                                ->where('customer_id', $record->customer_id)
                                ->where('status', RequisitionStatus::CLOSED)
                                ->whereNull('invoice_id')
                                ->lockForUpdate()->get();

                            throw_unless($requisitions->count() === count(array_unique($reqIds)), ValidationException::withMessages(['orders' => 'Uma das requisições não está mais disponível para faturamento.']));

                            foreach ($requisitions as $req) {
                                Gate::authorize('update', $req);
                                $req->state()->invoice($req, $userId, $record->id);
                            }
                        }

                        // Importa Ordens de Produção
                        if (! empty($poIds)) {
                            $productionOrders = ProductionOrder::whereIn('id', $poIds)
                                ->where('company_id', $record->company_id)
                                ->where('customer_id', $record->customer_id)
                                ->where('status', ProductionOrderStatus::COMPLETED)
                                ->whereNull('invoice_id')
                                ->lockForUpdate()->get();

                            foreach ($productionOrders as $po) {
                                Gate::authorize('update', $po);
                                $po->state()->invoice($record->id);
                            }
                        }
                    });

                    $total = count($soIds) + count($reqIds) + count($poIds);

                    Log::info('ImportRecordsAction: Registros importados para a fatura', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $record->id,
                        'service_order_ids' => $soIds,
                        'requisition_ids' => $reqIds,
                        'production_order_ids' => $poIds,
                    ]);

                    notify::success("{$total} registro(s) importado(s) com sucesso.");
                } catch (\Exception $e) {
                    Log::error('ImportRecordsAction: Erro ao importar registros', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $record->id,
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    notify::error(message: 'Erro ao importar registros: '.$e->getMessage());
                }
            });
    }
}
