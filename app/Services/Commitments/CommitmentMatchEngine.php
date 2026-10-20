<?php

namespace App\Services\Commitments;

use App\Enum\ServiceOrder\State;
use App\Models\CustomerCommitment;
use App\Models\Equipment;
use App\Models\ServiceOrder;

class CommitmentMatchEngine
{
    public function issues(CustomerCommitment $record): array
    {
        $issues = data_get($record->importFile->extracted_data, 'issues', []);
        if ($issues !== [] && data_get($record->corrected_data, 'acknowledged_issues') !== $issues) {
            return $issues;
        }

        return [];
    }

    public function analyze(CustomerCommitment $record): array
    {
        $result = ['reason' => '', 'issues' => [], 'candidates' => [], 'eligible_ids' => []];
        if (! in_array($record->importFile->status->value, ['processed', 'needs_review'], true)) {
            return [...$result, 'reason' => 'A leitura do PDF precisa estar concluída.'];
        }
        if ($record->status->value === 'ignored') {
            return [...$result, 'reason' => 'Empenho ignorado. Reabra para buscar uma OS.'];
        }
        $result['issues'] = $this->issues($record);
        if ($result['issues'] !== []) {
            return [...$result, 'reason' => 'Revise e confirme as pendências da extração antes de conciliar.'];
        }
        if (! preg_match('/^\d{1,60}$/D', (string) $record->patrimony)) {
            return [...$result, 'reason' => 'Patrimônio ausente ou inválido. Corrija os dados extraídos.'];
        }
        if ((int) $record->getRawOriginal('amount') <= 0
            || (int) $record->getRawOriginal('amount') - (int) $record->getRawOriginal('retained_amount') !== (int) $record->getRawOriginal('net_amount')) {
            return [...$result, 'reason' => 'Revise os valores bruto, retido e líquido do empenho.'];
        }
        $patrimony = CommitmentMatchValues::identifier($record->patrimony);
        $equipmentIds = Equipment::query()->where('company_id', $record->company_id)->where('owner_id', $record->customer_id)
            ->get(['id', 'name'])->filter(fn ($equipment) => CommitmentMatchValues::equipmentPatrimonies($equipment->name) === [$patrimony])->modelKeys();
        if ($equipmentIds === []) {
            return [...$result, 'reason' => 'Nenhum equipamento do cliente com este PAT foi encontrado na empresa.'];
        }
        $orders = ServiceOrder::query()->where('company_id', $record->company_id)->where('customer_id', $record->customer_id)
            ->whereIn('equipment_id', $equipmentIds)->where('status', State::CLOSED->value)->whereNull('invoice_id')->whereNotNull('order_date')
            ->with(['equipment', 'items', 'requisition.items', 'commitmentBilling'])->orderBy('order_date')->orderBy('id')->get();
        foreach ($orders as $order) {
            $result['candidates'][] = $this->candidate($record, $order);
        }
        $result['eligible_ids'] = array_values(array_column(array_filter($result['candidates'], fn ($row) => $row['eligible']), 'service_order_id'));
        $result['reason'] = match (true) {
            $result['eligible_ids'] !== [] => 'Patrimônio e valor coincidem. A OS elegível mais antiga está destacada.',
            $result['candidates'] !== [] => 'Há OS para o patrimônio, mas nenhuma está disponível com o mesmo valor. Confira as diferenças abaixo.',
            default => 'Nenhuma OS encerrada e ainda não faturada foi encontrada para este patrimônio.',
        };

        return $result;
    }

    public function candidate(CustomerCommitment $record, ServiceOrder $order): array
    {
        $order->loadMissing(['equipment', 'items', 'requisition.items', 'commitmentBilling']);
        $services = (int) $order->items->sum(fn ($item) => (int) $item->getRawOriginal('total_amount'));
        $travel = CommitmentMatchValues::cents($order->getRawOriginal('travel_value'));
        $products = (int) ($order->requisition?->items->sum(fn ($item) => (int) $item->getRawOriginal('total_amount')) ?? 0);
        $total = $services + $travel + $products;
        $difference = $total - (int) $record->getRawOriginal('amount');
        $reason = match (true) {
            (int) $order->company_id !== (int) $record->company_id || (int) $order->customer_id !== (int) $record->customer_id => 'OS de outra empresa ou cliente.',
            ! $order->equipment || (int) $order->equipment->company_id !== (int) $record->company_id || (int) $order->equipment->owner_id !== (int) $record->customer_id => 'Equipamento incompatível com empresa/cliente.',
            CommitmentMatchValues::equipmentPatrimonies($order->equipment->name) !== [CommitmentMatchValues::identifier((string) $record->patrimony)] => 'Patrimônio diferente.',
            $order->status !== State::CLOSED || $order->invoice_id !== null => 'A OS deve estar encerrada e não faturada.',
            $order->order_date === null => 'Data de abertura não informada.',
            $order->items->isEmpty() => 'OS sem itens de serviço.',
            $order->commitmentBilling && (int) $order->commitmentBilling->customer_commitment_id !== (int) $record->id => 'OS já reservada para outro empenho.',
            $order->requisition !== null => 'Esta OS possui requisição. Use o faturamento normal; este fluxo atende somente serviços/NFS-e.',
            $difference !== 0 => 'Valor total diferente do empenho.',
            default => null,
        };

        return [
            'service_order_id' => $order->id, 'number' => $order->number,
            'order_date' => $order->order_date?->toDateString(), 'completion_date' => $order->completion_date?->toDateString(),
            'equipment_id' => $order->equipment_id, 'equipment_name' => $order->equipment?->name,
            'services_amount_cents' => $services, 'travel_amount_cents' => $travel, 'requisition_amount_cents' => $products,
            'total_amount_cents' => $total, 'difference_cents' => $difference,
            'eligible' => $reason === null, 'reason' => $reason ?? 'Compatível',
        ];
    }
}
