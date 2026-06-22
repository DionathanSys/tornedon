<?php

namespace App\Services\TemporaryMigration;

use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\TemporaryEquipmentMigrationLink;
use App\Models\TemporaryPartnerMigrationLink;
use App\Models\TemporaryServiceMigrationLink;
use App\Models\TemporaryServiceOrderItemMigrationLink;
use App\Models\TemporaryServiceOrderMigrationLink;
use App\Models\User;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TemporaryServiceOrderImportService
{
    use HandlesServiceResponse;

    private ?bool $serviceMappingTableExists = null;

    public function __construct(private ServiceOrderMigrationApiClient $client) {}

    public function import(int $companyId, int $userId, array $filters = []): ?array
    {
        $this->resetResponse();

        if (! Company::query()->find($companyId)) {
            $this->setError('Empresa nao encontrada.', ['company_id' => ['Empresa informada nao existe.']], 404);
            return null;
        }

        if (! User::query()->find($userId)) {
            $this->setError('Usuario nao encontrado.', ['user_id' => ['Usuario informado nao existe.']], 404);
            return null;
        }

        $summary = [
            'company_id' => $companyId,
            'user_id' => $userId,
            'pages' => 0,
            'records_received' => 0,
            'orders_created' => 0,
            'orders_updated' => 0,
            'items_created' => 0,
            'items_updated' => 0,
            'errors' => [],
            'last_after_id' => (int) ($filters['after_id'] ?? 0),
        ];

        $afterId = (int) ($filters['after_id'] ?? 0);
        $pageLimit = max(1, min(500, (int) ($filters['limit'] ?? 200)));
        $maxPages = max(0, (int) ($filters['max_pages'] ?? 0));

        try {
            do {
                if ($maxPages > 0 && $summary['pages'] >= $maxPages) {
                    break;
                }

                $payload = $this->client->fetchPage([
                    'limit' => $pageLimit,
                    'after_id' => $afterId,
                    'updated_from' => $filters['updated_from'] ?? null,
                    'parceiro_id' => $filters['parceiro_id'] ?? null,
                    'equipamento_id' => $filters['equipamento_id'] ?? null,
                    'fatura_id' => $filters['fatura_id'] ?? null,
                    'status' => $filters['status'] ?? null,
                ]);

                $summary['pages']++;

                foreach ($payload['data'] as $record) {
                    $summary['records_received']++;

                    try {
                        $result = $this->importRecord($companyId, $userId, $record);
                        $summary['orders_created'] += (int) $result['order_created'];
                        $summary['orders_updated'] += (int) $result['order_updated'];
                        $summary['items_created'] += (int) $result['items_created'];
                        $summary['items_updated'] += (int) $result['items_updated'];
                        $summary['last_after_id'] = max($summary['last_after_id'], (int) $result['legacy_id']);
                    } catch (\Throwable $e) {
                        $summary['errors'][] = [
                            'legacy_id' => (int) ($record['legacy_id'] ?? 0),
                            'message' => $e->getMessage(),
                        ];

                        Log::error(__METHOD__ . '@' . __LINE__, [
                            'message' => 'Falha ao importar ordem de servico da API de migracao',
                            'company_id' => $companyId,
                            'user_id' => $userId,
                            'legacy_id' => (int) ($record['legacy_id'] ?? 0),
                            'payload' => $record,
                            'exception' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }

                $meta = is_array($payload['meta']) ? $payload['meta'] : [];
                $hasMore = (bool) ($meta['has_more'] ?? false);
                $nextAfterId = (int) ($meta['next_after_id'] ?? 0);

                if ($hasMore && $nextAfterId <= $afterId) {
                    throw new \RuntimeException('Paginacao invalida da API de migracao: next_after_id nao avancou.');
                }

                $afterId = $nextAfterId;
            } while ($hasMore);

            $this->setSuccess(
                $summary['errors'] === [] ? 'Importacao temporaria de ordens de servico concluida.' : 'Importacao temporaria de ordens de servico concluida com falhas.',
                $summary,
                $summary['errors'] === [] ? 200 : 207,
            );

            return $summary;
        } catch (\Throwable $e) {
            Log::error(__METHOD__ . '@' . __LINE__, ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->setError('Falha ao importar ordens de servico da API de migracao.', [$e->getMessage()], 500);
            return null;
        }
    }

    private function importRecord(int $companyId, int $userId, array $record): array
    {
        $normalized = $this->normalizeOrder($record);

        $partnerLink = TemporaryPartnerMigrationLink::query()->where('company_id', $companyId)->where('legacy_id', $normalized['legacy_partner_id'])->first();
        $equipmentLink = TemporaryEquipmentMigrationLink::query()->where('company_id', $companyId)->where('legacy_id', $normalized['legacy_equipment_id'])->first();

        if (! $partnerLink?->partner_id) {
            throw new \RuntimeException(sprintf('Parceiro legado %s ainda nao foi importado para a empresa %s.', $normalized['legacy_partner_id'], $companyId));
        }

        if (! $equipmentLink?->equipment_id) {
            throw new \RuntimeException(sprintf('Equipamento legado %s ainda nao foi importado para a empresa %s.', $normalized['legacy_equipment_id'], $companyId));
        }

        return DB::transaction(function () use ($companyId, $userId, $record, $normalized, $partnerLink, $equipmentLink): array {
            $link = TemporaryServiceOrderMigrationLink::query()->where('company_id', $companyId)->where('legacy_id', $normalized['legacy_id'])->first();

            $order = $this->resolveOrder($companyId, $link, $normalized);
            $created = false;
            $updated = false;

            if (! $order) {
                $order = new ServiceOrder();
                $order->created_by = $userId;
                $created = true;
            } else {
                $updated = true;
            }

            $order->forceFill([
                'number' => (string) $normalized['legacy_id'],
                'customer_id' => (int) $partnerLink->partner_id,
                'company_id' => $companyId,
                'order_date' => $normalized['order_date'],
                'completion_date' => $normalized['completion_date'],
                'status' => $normalized['status'],
                'priority' => $normalized['priority'],
                'type' => $normalized['type'],
                'solution' => $normalized['status_process'] ?? null,
                'equipment_id' => (int) $equipmentLink->equipment_id,
                'location' => $normalized['plate'],
                'customer_observations' => $normalized['customer_observations'],
                'general_observations' => $normalized['general_observations'],
                'internal_observations' => $normalized['internal_observations'],
                'travel_value' => 0,
                'invoice_id' => null,
                'items_received' => $normalized['items_received'],
                'additional_info' => [
                    'migration' => [
                        'legacy_id' => $normalized['legacy_id'],
                        'legacy_discount_amount' => $normalized['discount_amount'],
                        'legacy_invoice_id' => $normalized['legacy_invoice_id'],
                        'legacy_note_entry_id' => $normalized['legacy_note_entry_id'],
                        'legacy_note_return_id' => $normalized['legacy_note_return_id'],
                        'legacy_path_pdf' => $normalized['legacy_path_pdf'],
                        'legacy_img_equipment' => $normalized['legacy_img_equipment'],
                    ],
                ],
                'updated_by' => $userId,
            ])->saveQuietly();

            TemporaryServiceOrderMigrationLink::query()->updateOrCreate(
                ['company_id' => $companyId, 'legacy_id' => $normalized['legacy_id']],
                [
                    'legacy_partner_id' => $normalized['legacy_partner_id'],
                    'legacy_equipment_id' => $normalized['legacy_equipment_id'],
                    'legacy_invoice_id' => $normalized['legacy_invoice_id'],
                    'service_order_id' => $order->id,
                    'customer_partner_id' => (int) $partnerLink->partner_id,
                    'equipment_id' => (int) $equipmentLink->equipment_id,
                    'legacy_updated_at' => $normalized['updated_at'],
                    'payload' => $record,
                    'last_imported_at' => now(),
                ]
            );

            $itemSummary = $this->syncItems($companyId, $userId, $order, $normalized['items']);

            return [
                'legacy_id' => $normalized['legacy_id'],
                'order_created' => $created,
                'order_updated' => $updated,
                'items_created' => $itemSummary['created'],
                'items_updated' => $itemSummary['updated'],
            ];
        });
    }

    private function syncItems(int $companyId, int $userId, ServiceOrder $order, array $items): array
    {
        $created = 0;
        $updated = 0;

        foreach ($items as $item) {
            $normalized = $this->normalizeItem($item);
            $service = $this->resolveImportedService($companyId, $normalized['legacy_service_id']);

            if (! $service) {
                throw new \RuntimeException(sprintf('Servico legado %s ainda nao foi importado para a empresa %s.', $normalized['legacy_service_id'], $companyId));
            }

            $link = TemporaryServiceOrderItemMigrationLink::query()->where('company_id', $companyId)->where('legacy_id', $normalized['legacy_id'])->first();
            $orderItem = $this->resolveOrderItem($order, $link, (int) $service->id);

            if (! $orderItem) {
                $orderItem = new ServiceOrderItem();
                $orderItem->service_order_id = $order->id;
                $orderItem->created_by = $userId;
                $created++;
            } else {
                $updated++;
            }

            $grossAmount = round($normalized['quantity'] * $normalized['unit_price'], 2);
            $discountPercentage = $grossAmount > 0 ? round(($normalized['discount_amount'] / $grossAmount) * 100, 2) : 0;

            $orderItem->forceFill([
                'service_order_id' => $order->id,
                'service_id' => (int) $service->id,
                'quantity' => $normalized['quantity'],
                'unit_price' => $normalized['unit_price'],
                'discount_percentage' => $discountPercentage,
                'discount_amount' => $normalized['discount_amount'],
                'observations' => $normalized['observations'],
                'additional_info' => [
                    'migration' => [
                        'legacy_id' => $normalized['legacy_id'],
                        'legacy_service_id' => $normalized['legacy_service_id'],
                        'legacy_total_amount' => $normalized['total_amount'],
                        'legacy_warranty' => $normalized['warranty'],
                    ],
                ],
                'updated_by' => $userId,
            ])->saveQuietly();

            TemporaryServiceOrderItemMigrationLink::query()->updateOrCreate(
                ['company_id' => $companyId, 'legacy_id' => $normalized['legacy_id']],
                [
                    'legacy_service_order_id' => $normalized['legacy_service_order_id'],
                    'legacy_service_id' => $normalized['legacy_service_id'],
                    'service_order_id' => $order->id,
                    'service_order_item_id' => $orderItem->id,
                    'service_id' => (int) $service->id,
                    'payload' => $item,
                    'last_imported_at' => now(),
                ]
            );
        }

        return ['created' => $created, 'updated' => $updated];
    }

    private function resolveOrder(int $companyId, ?TemporaryServiceOrderMigrationLink $link, array $normalized): ?ServiceOrder
    {
        if ($link?->service_order_id) {
            $order = ServiceOrder::query()->find($link->service_order_id);
            if ($order) {
                return $order;
            }
        }

        $existingOrder = ServiceOrder::query()
            ->where('company_id', $companyId)
            ->where('number', (string) $normalized['legacy_id'])
            ->first();

        if ($existingOrder !== null) {
            Log::warning(__METHOD__ . '@' . __LINE__, [
                'message' => 'Duplicidade detectada para numero de ordem de servico durante importacao temporaria',
                'company_id' => $companyId,
                'legacy_id' => $normalized['legacy_id'],
                'existing_service_order_id' => $existingOrder->id,
                'existing_number' => $existingOrder->number,
            ]);

            throw new \RuntimeException(sprintf(
                'Ja existe uma ordem de servico local com number %s na empresa %s.',
                $normalized['legacy_id'],
                $companyId,
            ));
        }

        return null;
    }

    private function resolveOrderItem(ServiceOrder $order, ?TemporaryServiceOrderItemMigrationLink $link, int $serviceId): ?ServiceOrderItem
    {
        if ($link?->service_order_item_id) {
            $item = ServiceOrderItem::query()->find($link->service_order_item_id);
            if ($item) {
                return $item;
            }
        }

        $matchingItems = $order->items()
            ->where('service_id', $serviceId)
            ->get();

        if ($matchingItems->count() > 1) {
            Log::warning(__METHOD__ . '@' . __LINE__, [
                'message' => 'Duplicidade detectada para itens de ordem sem mapeamento legado durante importacao temporaria',
                'service_order_id' => $order->id,
                'service_id' => $serviceId,
                'matching_item_ids' => $matchingItems->pluck('id')->all(),
            ]);

            throw new \RuntimeException(sprintf(
                'Existem multiplos itens locais para service_order_id %s e service_id %s sem mapeamento legado.',
                $order->id,
                $serviceId,
            ));
        }

        return $matchingItems->first();
    }

    private function normalizeOrder(array $record): array
    {
        $legacyId = (int) ($record['legacy_id'] ?? 0);
        $legacyPartnerId = (int) ($record['legacy_parceiro_id'] ?? 0);
        $legacyEquipmentId = (int) ($record['legacy_equipamento_id'] ?? 0);

        if ($legacyId <= 0 || $legacyPartnerId <= 0 || $legacyEquipmentId <= 0) {
            throw new \InvalidArgumentException('Ordem legado sem chaves obrigatorias validas.');
        }

        return [
            'legacy_id' => $legacyId,
            'legacy_partner_id' => $legacyPartnerId,
            'legacy_equipment_id' => $legacyEquipmentId,
            'legacy_invoice_id' => $record['legacy_fatura_id'] ?? null,
            'plate' => ($plate = trim((string) ($record['placa'] ?? ''))) === '' ? null : $plate,
            'order_date' => $this->parseDate($record['data_ordem'] ?? null) ?? now()->toDateString(),
            'completion_date' => $this->parseDate($record['data_encerrado'] ?? null),
            'discount_amount' => (float) ($record['desconto'] ?? 0),
            'priority' => $this->mapPriority($record['prioridade'] ?? null),
            'type' => $this->mapType($record['tipo_manutencao'] ?? null),
            'status' => $this->mapStatus($record['status'] ?? null),
            'status_process' => ($statusProcess = trim((string) ($record['status_processo'] ?? ''))) === '' ? null : $statusProcess,
            'customer_observations' => $this->nullableText($record['relato_cliente'] ?? null),
            'items_received' => $this->nullableText($record['itens_recebidos'] ?? null),
            'general_observations' => $this->nullableText($record['observacao_geral'] ?? null),
            'internal_observations' => $this->nullableText($record['observacao_interna'] ?? null),
            'legacy_path_pdf' => $this->normalizeScalarOrArray($record['path_pdf'] ?? null),
            'legacy_img_equipment' => $this->normalizeScalarOrArray($record['img_equipamento'] ?? null),
            'legacy_note_entry_id' => $record['nota_entrada_id'] ?? null,
            'legacy_note_return_id' => $record['nota_retorno_id'] ?? null,
            'updated_at' => $this->parseDateTime($record['updated_at'] ?? null),
            'items' => array_map(fn (array $item): array => $item, $record['itens'] ?? []),
        ];
    }

    private function normalizeItem(array $item): array
    {
        $legacyId = (int) ($item['legacy_id'] ?? 0);
        $legacyOrderId = (int) ($item['legacy_ordem_servico_id'] ?? 0);
        $legacyServiceId = (int) ($item['legacy_servico_id'] ?? 0);

        if ($legacyId <= 0 || $legacyOrderId <= 0 || $legacyServiceId <= 0) {
            throw new \InvalidArgumentException('Item de ordem legado sem chaves validas.');
        }

        return [
            'legacy_id' => $legacyId,
            'legacy_service_order_id' => $legacyOrderId,
            'legacy_service_id' => $legacyServiceId,
            'quantity' => max(0.001, (float) ($item['quantidade'] ?? 1)),
            'unit_price' => max(0, (float) ($item['valor_unitario'] ?? 0)),
            'total_amount' => max(0, (float) ($item['valor_total'] ?? 0)),
            'discount_amount' => max(0, (float) ($item['desconto'] ?? 0)),
            'observations' => $this->nullableText($item['observacao'] ?? null),
            'warranty' => (bool) ($item['garantia'] ?? false),
        ];
    }

    private function mapStatus(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return match ($value) {
            'aberta', 'em andamento', 'andamento' => State::OPEN->value,
            'encerrada', 'fechada', 'finalizada' => State::CLOSED->value,
            'faturada' => State::INVOICED->value,
            'cancelada', 'cancelado' => State::CANCELLED->value,
            default => State::OPEN->value,
        };
    }

    private function mapPriority(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return match ($value) {
            'baixa' => Priority::LOW->value,
            'alta' => Priority::HIGH->value,
            'urgente' => Priority::URGENT->value,
            default => Priority::NORMAL->value,
        };
    }

    private function mapType(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return match ($value) {
            'instalacao' => Type::INSTALLATION->value,
            'manutencao', 'preventiva' => Type::MAINTENANCE->value,
            'corretiva', 'reparo' => Type::REPAIR->value,
            'consultoria' => Type::CONSULTATION->value,
            'inspecao' => Type::INSPECTION->value,
            'configuracao' => Type::CONFIGURATION->value,
            default => Type::OTHER->value,
        };
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function normalizeScalarOrArray(mixed $value): string|array|null
    {
        if (is_array($value)) {
            $normalized = array_values(array_filter(array_map(function (mixed $item): ?string {
                $text = trim((string) $item);

                return $text === '' ? null : $text;
            }, $value)));

            return $normalized === [] ? null : $normalized;
        }

        return $this->nullableText($value);
    }

    private function parseDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function parseDateTime(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function resolveImportedService(int $companyId, int $legacyServiceId): ?Service
    {
        if ($this->serviceMappingTableExists()) {
            $serviceLink = TemporaryServiceMigrationLink::query()
                ->where('company_id', $companyId)
                ->where('legacy_id', $legacyServiceId)
                ->first();

            if ($serviceLink?->service_id) {
                $service = Service::query()->find($serviceLink->service_id);

                if ($service) {
                    return $service;
                }
            }
        }

        return Service::query()
            ->where('company_id', $companyId)
            ->where('service_code', (string) $legacyServiceId)
            ->first();
    }

    private function serviceMappingTableExists(): bool
    {
        if ($this->serviceMappingTableExists !== null) {
            return $this->serviceMappingTableExists;
        }

        $this->serviceMappingTableExists = Schema::hasTable('temporary_service_migration_links');

        if (! $this->serviceMappingTableExists) {
            Log::warning(__METHOD__ . '@' . __LINE__, [
                'message' => 'Tabela temporary_service_migration_links nao existe. Importacao de ordens resolvera servicos por service_code legado.',
            ]);
        }

        return $this->serviceMappingTableExists;
    }
}
