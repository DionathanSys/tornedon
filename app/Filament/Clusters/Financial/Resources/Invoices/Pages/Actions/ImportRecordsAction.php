<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Enum\ProductionOrder\Status as ProductionOrderStatus;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Models\Invoice;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                return $record->fiscalDocuments()->doesntExist();
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
                                        ->where('customer_id', $customerId)
                                        ->where('status', ServiceOrderState::CLOSED)
                                        ->whereNull('invoice_id')
                                        ->get()
                                        ->mapWithKeys(fn (ServiceOrder $so) => [
                                            $so->id => "#{$so->number} — " . ($so->customer?->name ?? 'S/C'),
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
                                        ->where('customer_id', $customerId)
                                        ->where('status', RequisitionStatus::CLOSED)
                                        ->whereNull('invoice_id')
                                        ->get()
                                        ->mapWithKeys(fn (Requisition $req) => [
                                            $req->id => "#{$req->number} — " . ($req->customer?->name ?? 'S/C'),
                                        ])
                                ),
                        ]),
                ];
            })
            ->action(function (Invoice $record, array $data): void {
                $soIds  = $data['service_order_ids'] ?? [];
                $reqIds = $data['requisition_ids'] ?? [];
                $poIds  = $data['production_order_ids'] ?? [];

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
                        // Importa Ordens de Serviço
                        if (! empty($soIds)) {
                            $serviceOrders = ServiceOrder::whereIn('id', $soIds)
                                ->where('customer_id', $record->customer_id)
                                ->where('status', ServiceOrderState::CLOSED)
                                ->whereNull('invoice_id')
                                ->get();

                            foreach ($serviceOrders as $so) {
                                $so->state()->invoice($so, $userId, $record->id);
                            }
                        }

                        // Importa Requisições
                        if (! empty($reqIds)) {
                            $requisitions = Requisition::whereIn('id', $reqIds)
                                ->where('customer_id', $record->customer_id)
                                ->where('status', RequisitionStatus::CLOSED)
                                ->whereNull('invoice_id')
                                ->get();

                            foreach ($requisitions as $req) {
                                $req->state()->invoice($req, $userId, $record->id);
                            }
                        }

                        // Importa Ordens de Produção
                        if (! empty($poIds)) {
                            $productionOrders = ProductionOrder::whereIn('id', $poIds)
                                ->where('customer_id', $record->customer_id)
                                ->where('status', ProductionOrderStatus::COMPLETED)
                                ->whereNull('invoice_id')
                                ->get();

                            foreach ($productionOrders as $po) {
                                $po->state()->invoice($record->id);
                            }
                        }
                    });

                    $total = count($soIds) + count($reqIds) + count($poIds);

                    Log::info('ImportRecordsAction: Registros importados para a fatura', [
                        'metodo'              => __METHOD__ . '@' . __LINE__,
                        'invoice_id'          => $record->id,
                        'service_order_ids'   => $soIds,
                        'requisition_ids'     => $reqIds,
                        'production_order_ids' => $poIds,
                    ]);

                    notify::success("{$total} registro(s) importado(s) com sucesso.");
                } catch (\Exception $e) {
                    Log::error('ImportRecordsAction: Erro ao importar registros', [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'invoice_id' => $record->id,
                        'exception'  => $e->getMessage(),
                        'trace'      => $e->getTraceAsString(),
                    ]);

                    notify::error(message: 'Erro ao importar registros: ' . $e->getMessage());
                }
            });
    }
}
