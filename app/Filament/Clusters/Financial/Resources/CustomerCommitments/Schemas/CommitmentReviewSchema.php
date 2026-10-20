<?php

namespace App\Filament\Clusters\Financial\Resources\CustomerCommitments\Schemas;

use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\AuditEntry;
use App\Models\CustomerCommitment;
use App\Services\Commitments\CommitmentMatchEngine;
use Carbon\Carbon;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class CommitmentReviewSchema
{
    public static function components(): array
    {
        return [
            Section::make('Empenho e situação da conciliação')->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('number')->label('Empenho')->formatStateUsing(fn ($state, CustomerCommitment $record) => $state.'/'.$record->fiscal_year),
                TextEntry::make('customer.name')->label('Cliente'),
                TextEntry::make('status')->label('Conciliação')->badge()->formatStateUsing(fn ($state) => $state->description())->color(fn ($state) => $state->color()),
                TextEntry::make('patrimony')->label('Patrimônio')->placeholder('Não identificado'),
                TextEntry::make('issued_at')->label('Emissão')->date('d/m/Y'),
                TextEntry::make('importFile.original_name')->label('PDF de origem'),
                TextEntry::make('amount')->label('Valor bruto a conciliar')->money('BRL', locale: 'pt_BR'),
                TextEntry::make('retained_amount')->label('Retenções')->money('BRL', locale: 'pt_BR'),
                TextEntry::make('net_amount')->label('Valor líquido')->money('BRL', locale: 'pt_BR'),
                TextEntry::make('object')->label('Objeto')->columnSpanFull(),
                TextEntry::make('review_reason')->label('Última revisão')->placeholder('Nenhuma revisão manual registrada')->columnSpanFull(),
            ]),
            Section::make('Conferência da extração')->columnSpanFull()->schema([
                TextEntry::make('pending_issues')->label('Pendências ainda não confirmadas')->listWithLineBreaks()->color('warning')
                    ->state(fn (CustomerCommitment $record) => app(CommitmentMatchEngine::class)->issues($record))->placeholder('Nenhuma pendência de extração aguardando confirmação.'),
            ]),
            Section::make('OS aprovada')->columnSpanFull()->visible(fn (CustomerCommitment $record) => $record->billing !== null)
                ->columns(['default' => 1, 'md' => 3])->schema([
                    TextEntry::make('billing.serviceOrder.number')->label('Ordem de serviço')
                        ->url(fn (CustomerCommitment $record) => ServiceOrderResource::getUrl('edit', ['record' => $record->billing->service_order_id]))->openUrlInNewTab(),
                    TextEntry::make('billing.serviceOrder.order_date')->label('Abertura')->date('d/m/Y'),
                    TextEntry::make('billing.serviceOrder.equipment.name')->label('Veículo / equipamento'),
                    TextEntry::make('billing.approval_snapshot.selected_order.total_amount_cents')->label('Valor da OS na aprovação')->money('BRL', divideBy: 100, locale: 'pt_BR'),
                    TextEntry::make('billing.approved_at')->label('Aprovado em')->dateTime('d/m/Y H:i'),
                    TextEntry::make('billing.invoice_id')->label('Fatura')->placeholder('Aguardando faturamento')
                        ->url(fn (CustomerCommitment $record) => $record->billing?->invoice_id ? InvoiceResource::getUrl('edit', ['record' => $record->billing->invoice_id]) : null)->openUrlInNewTab(),
                ]),
            Section::make('Faturamento e emissão')->columnSpanFull()->visible(fn (CustomerCommitment $record) => $record->billing !== null)->columns(2)->schema([
                TextEntry::make('billing.status')->label('Etapa')->badge()->formatStateUsing(fn ($state) => $state->description()),
                TextEntry::make('billing.completed_step')->label('Última etapa concluída')->formatStateUsing(fn ($state) => $state->description())->placeholder('Aguardando faturamento'),
                TextEntry::make('billing.active_batch_id')->label('Lote de faturamento')->placeholder('—'),
                TextEntry::make('billing.attempts')->label('Tentativas'),
                TextEntry::make('billing.last_error')->label('Diagnóstico de falha')->placeholder('Sem falhas registradas')->columnSpanFull(),
                TextEntry::make('billing.settings_snapshot.nfse_item_description')->label('Corpo da NFS-e utilizado')->placeholder('Não faturado'),
                TextEntry::make('billing.settings_snapshot.nfse_additional_information')->label('Rodapé da NFS-e utilizado')->placeholder('Não faturado'),
                RepeatableEntry::make('billing_batch_history')->label('Tentativas de faturamento')->columnSpanFull()
                    ->state(fn (CustomerCommitment $record) => $record->billingBatchItems()->forCustomer($record->company_id, $record->customer_id)->latest('id')->limit(20)->get()->map(fn ($item) => [
                        'batch' => $item->commitment_billing_batch_id, 'status' => match ($item->status) {
                            'queued' => 'Na fila', 'processing' => 'Processando', 'completed' => 'Concluído', 'failed' => 'Falhou', default => $item->status,
                        }, 'error' => $item->last_error, 'finished_at' => $item->finished_at,
                    ])->all())
                    ->schema([TextEntry::make('batch')->label('Lote'), TextEntry::make('status')->label('Execução'), TextEntry::make('error')->label('Diagnóstico')->placeholder('—'), TextEntry::make('finished_at')->label('Finalizado em')->dateTime('d/m/Y H:i')->placeholder('—')]),
                RepeatableEntry::make('billing_documents')->label('Documentos fiscais existentes')->columnSpanFull()
                    ->state(fn (CustomerCommitment $record) => $record->billing?->invoice?->fiscalDocuments->map(fn ($doc) => ['id' => $doc->id, 'number' => $doc->document_number ?? $doc->id, 'status' => $doc->nfse_status?->description() ?? 'Pendente de emissão'])->all() ?? [])
                    ->schema([
                        TextEntry::make('number')->label('Documento')->url(fn (TextEntry $component) => FiscalDocumentResource::getUrl('edit', ['record' => $component->getContainer()->getConstantState()['id']]))->openUrlInNewTab(),
                        TextEntry::make('status')->label('Situação fiscal real'),
                    ]),
            ]),
            Section::make('Comparação com ordens de serviço')->columnSpanFull()
                ->description('Este fluxo atende somente NFS-e: serviços líquidos + deslocamento. OS com requisição não são elegíveis. As OS estão ordenadas pela abertura, da mais antiga para a mais nova.')
                ->visible(fn (CustomerCommitment $record) => $record->billing === null && $record->status->value !== 'ignored')->schema([
                    TextEntry::make('match_reason')->label('Resultado da busca')->state(fn (CustomerCommitment $record) => app(CommitmentMatchEngine::class)->analyze($record)['reason']),
                    RepeatableEntry::make('candidates')->hiddenLabel()->columnSpanFull()
                        ->state(function (CustomerCommitment $record): array {
                            $result = app(CommitmentMatchEngine::class)->analyze($record);

                            return array_map(fn ($row) => [...$row, 'oldest' => $row['service_order_id'] === ($result['eligible_ids'][0] ?? null)], $result['candidates']);
                        })->table([
                            TableColumn::make('OS / abertura'), TableColumn::make('Equipamento'), TableColumn::make('Serviços'),
                            TableColumn::make('Deslocamento'), TableColumn::make('Requisição'), TableColumn::make('Total'),
                            TableColumn::make('Diferença'), TableColumn::make('Elegibilidade'),
                        ])->schema([
                            TextEntry::make('number')->url(fn (TextEntry $component) => ServiceOrderResource::getUrl('edit', ['record' => $component->getContainer()->getConstantState()['service_order_id']]))->openUrlInNewTab()
                                ->helperText(function (TextEntry $component): string {
                                    $row = $component->getContainer()->getConstantState();

                                    return ($row['oldest'] ? 'Mais antiga elegível · ' : '').Carbon::parse($row['order_date'])->format('d/m/Y');
                                }),
                            TextEntry::make('equipment_name'),
                            TextEntry::make('services_amount_cents')->money('BRL', divideBy: 100, locale: 'pt_BR'),
                            TextEntry::make('travel_amount_cents')->money('BRL', divideBy: 100, locale: 'pt_BR'),
                            TextEntry::make('requisition_amount_cents')->money('BRL', divideBy: 100, locale: 'pt_BR'),
                            TextEntry::make('total_amount_cents')->money('BRL', divideBy: 100, locale: 'pt_BR'),
                            TextEntry::make('difference_cents')->money('BRL', divideBy: 100, locale: 'pt_BR'),
                            TextEntry::make('reason')->badge()->color(fn (TextEntry $component) => $component->getContainer()->getConstantState()['eligible'] ? 'success' : 'warning'),
                        ])->placeholder('Nenhuma OS candidata para exibir.'),
                ]),
            Section::make('Dados originalmente extraídos')->columnSpanFull()->collapsible()->collapsed()->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('original_data.original_number')->label('Número original')->placeholder('—'),
                TextEntry::make('original_data.patrimony')->label('Patrimônio original')->placeholder('Não identificado'),
                TextEntry::make('original_data.amount_cents')->label('Valor original')->money('BRL', divideBy: 100, locale: 'pt_BR'),
                TextEntry::make('original_data.object')->label('Objeto original')->columnSpanFull(),
            ]),
            Section::make('Histórico da conciliação')->columnSpanFull()->collapsible()->schema([
                RepeatableEntry::make('review_history')->hiddenLabel()->state(fn (CustomerCommitment $record) => AuditEntry::query()
                    ->where('company_id', $record->company_id)->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)
                    ->latest('id')->limit(30)->get(['occurred_at', 'actor_name', 'summary'])->toArray())
                    ->table([TableColumn::make('Quando'), TableColumn::make('Usuário'), TableColumn::make('Operação')])->schema([
                        TextEntry::make('occurred_at')->dateTime('d/m/Y H:i'),
                        TextEntry::make('actor_name')->placeholder('Sistema'),
                        TextEntry::make('summary'),
                    ])->placeholder('Nenhuma operação registrada.'),
            ]),
        ];
    }
}
