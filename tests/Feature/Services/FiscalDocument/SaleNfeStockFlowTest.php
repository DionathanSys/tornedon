<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status as FiscalStatus;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status;
use App\Enum\StockMovement\Type;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Requisition;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FiscalDocument\Actions\ProcessAuthorizedNfeStockMovementsAction;
use App\Services\FiscalDocument\RecoverNfeRequisitionLinksService;
use App\Services\FiscalDocument\SaleNfeStockService;
use App\Services\Requisition\RequisitionItemReservationService;
use App\Services\Requisition\RequisitionService;
use App\Services\RequisitionItem\RequisitionItemService;
use App\Services\StockMovement\StockMovementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SaleNfeStockFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_reserves_immediately_but_faturamento_does_not_lower_stock_or_release_reservation(): void
    {
        [$owner, $product, $stock, $requisition, $item] = $this->context();
        $this->assertSame(3.0, (float) $stock->fresh()->quantity_reserved);
        $document = $this->note($requisition, [$item]);
        $this->assertSame(10.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame(3.0, (float) $stock->fresh()->quantity_reserved);
        $this->assertNull($item->fresh()->stock_consumed_at);
        $this->assertSame(0, StockMovement::where('type', Type::EXIT)->count());
        $service = app(SaleNfeStockService::class);
        $service->prepare($document);
        $this->assertSame('prepared', $document->stockEffects()->sole()->status);
        $this->assertFalse($service->apply($document));
        $this->assertSame(0, StockMovement::where('type', Type::EXIT)->count());
    }

    public function test_authorization_lowers_once_and_traces_actual_fiscal_item(): void
    {
        [, , $stock, $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        $this->authorize($document);
        $action = app(ProcessAuthorizedNfeStockMovementsAction::class);
        $this->assertTrue($action->execute($document), (string) $action->getMessage());
        $this->assertTrue($action->execute($document));
        $this->assertSame(7.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame(0.0, (float) $stock->fresh()->quantity_reserved);
        $this->assertNotNull($item->fresh()->stock_consumed_at);
        $movement = StockMovement::where('type', Type::EXIT)->sole();
        $this->assertSame('fiscal_document_item', $movement->source_type);
        $this->assertSame($document->items()->sole()->id, $movement->source_id);
        $this->assertSame($item->id, $document->stockEffects()->sole()->requisition_item_id);
        $this->assertSame($movement->id, $document->stockEffects()->sole()->stock_movement_id);
    }

    public function test_product_without_stock_control_needs_no_stock_record_and_generates_no_movements(): void
    {
        [, , $stock, $requisition, $item] = $this->context(controlled: false, hasStock: false);
        $document = $this->note($requisition, [$item]);
        $this->authorize($document);
        $service = app(SaleNfeStockService::class);
        $this->assertTrue($service->apply($document), (string) $service->getMessage());
        $this->assertSame(0, ProductStock::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('not_applicable', $document->stockEffects()->sole()->status);
        $this->assertNull($item->fresh()->stock_consumed_at);
        $this->assertFalse($requisition->fresh()->stock_consumed);
    }

    public function test_reservation_is_rejected_when_available_balance_is_insufficient_and_negative_is_forbidden(): void
    {
        [$owner, $product, $stock, $requisition] = $this->context(quantity: 0);
        $service = app(RequisitionItemService::class);
        $this->assertNull($service->create($this->itemData($requisition, $product, 11), $owner->id));
        $this->assertSame(0, $requisition->items()->count());
        $this->assertSame(0.0, (float) $stock->fresh()->quantity_reserved);
    }

    public function test_missing_stock_reservation_failure_rolls_back_the_new_item(): void
    {
        [$owner, $product, , $requisition] = $this->context(quantity: 0, hasStock: false);
        $service = app(RequisitionItemService::class);
        $this->assertNull($service->create($this->itemData($requisition, $product, 1), $owner->id));
        $this->assertSame(0, $requisition->items()->count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_allow_negative_keeps_control_and_tracks_reservation_and_authorized_exit(): void
    {
        [, , $stock, $requisition, $item] = $this->context(quantity: 12, negative: true);
        $this->assertSame(12.0, (float) $stock->fresh()->quantity_reserved);
        $document = $this->note($requisition, [$item]);
        app(SaleNfeStockService::class)->prepare($document);
        $this->authorize($document);
        $this->assertTrue(app(SaleNfeStockService::class)->apply($document));
        $this->assertSame(-2.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame(0.0, (float) $stock->fresh()->quantity_reserved);
        $this->assertSame(1, StockMovement::where('type', Type::EXIT)->count());
    }

    public function test_reservation_edit_failure_preserves_original_quantity_and_balance(): void
    {
        [$owner, , $stock, , $item] = $this->context();
        $mock = $this->mock(StockMovementService::class);
        $mock->shouldReceive('create')->once()->andReturnNull();
        $mock->shouldReceive('getMessage')->andReturn('Falha simulada na reserva.');
        $service = app(RequisitionItemService::class);
        $this->assertNull($service->update($item, ['quantity' => 4], $owner->id));
        $this->assertSame(3.0, (float) $item->fresh()->quantity);
        $this->assertSame(3.0, (float) $stock->fresh()->quantity_reserved);
    }

    public function test_repeated_reservation_events_and_deletion_release_only_the_items_own_quantity(): void
    {
        [$owner, $product, $stock, $requisition, $item] = $this->context();
        $service = app(RequisitionItemService::class);
        $other = $service->create($this->itemData($requisition, $product, 2), $owner->id);
        app(RequisitionItemReservationService::class)->sync($item, $owner->id);
        $this->assertSame(5.0, (float) $stock->fresh()->quantity_reserved);
        $this->assertTrue($service->delete($item));
        $this->assertSame(2.0, (float) $stock->fresh()->quantity_reserved);
        $this->assertNotNull($other->fresh());
    }

    public function test_preflight_credits_own_reservations_and_revalidates_actual_balance(): void
    {
        [$owner, $product, $stock, $requisition, $item] = $this->context(quantity: 6);
        $service = app(RequisitionItemService::class);
        $other = $service->create($this->itemData($requisition, $product, 4), $owner->id);
        $document = $this->note($requisition, [$item]);
        app(SaleNfeStockService::class)->prepare($document);
        $this->assertSame(0.0, (float) $stock->fresh()->quantity_available);
        $stock->update(['quantity_total' => 5]);
        $this->expectException(ValidationException::class);
        app(SaleNfeStockService::class)->prepare($document);
    }

    public function test_quantities_of_repeated_products_are_validated_as_a_group(): void
    {
        [$owner, $product, $stock, $requisition, $item] = $this->context(quantity: 4);
        $other = app(RequisitionItemService::class)->create($this->itemData($requisition, $product, 4), $owner->id);
        $document = $this->note($requisition, [$item, $other]);
        // The note now asks for 12 units in total, not 8.
        foreach ($document->items as $fiscalItem) {
            $fiscalItem->update(['quantity' => 6]);
        }
        $this->expectException(ValidationException::class);
        app(SaleNfeStockService::class)->prepare($document);
    }

    public function test_authorized_note_is_preserved_when_stock_fails_and_can_be_reprocessed(): void
    {
        [, , $stock, $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        app(SaleNfeStockService::class)->prepare($document);
        $stock->update(['quantity_total' => 2]);
        $this->authorize($document);
        $service = app(SaleNfeStockService::class);
        $this->assertFalse($service->apply($document));
        $this->assertSame(NfeStatus::AUTHORIZED, $document->fresh()->nfe_status);
        $this->assertTrue($document->hasPendingStockEffect());
        $this->assertSame(0, StockMovement::where('type', Type::EXIT)->count());
        $this->assertSame(3.0, (float) $stock->fresh()->quantity_reserved);
        $stock->update(['quantity_total' => 10]);
        $this->assertTrue($service->apply($document), (string) $service->getMessage());
        $this->assertFalse($document->hasPendingStockEffect());
        $this->assertSame(7.0, (float) $stock->fresh()->quantity_total);
    }

    public function test_reissue_after_cancellation_reuses_physical_exit_and_does_not_return_or_consume_again(): void
    {
        [, , $stock, $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        $this->authorize($document);
        $service = app(SaleNfeStockService::class);
        $this->assertTrue($service->apply($document));
        $document->update(['status' => FiscalStatus::CANCELLED, 'nfe_status' => NfeStatus::CANCELED]);
        $this->assertSame(7.0, (float) $stock->fresh()->quantity_total);
        $new = $this->note($requisition, [$item]);
        $service->prepare($new);
        $this->authorize($new);
        $this->assertTrue($service->apply($new), (string) $service->getMessage());
        $this->assertSame(1, StockMovement::where('type', Type::EXIT)->count());
        $this->assertSame(7.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame($document->stockEffects()->sole()->stock_movement_id, $new->stockEffects()->sole()->stock_movement_id);
    }

    public function test_policy_and_unit_conversion_snapshots_survive_later_product_changes(): void
    {
        [$owner, $product, $stock, $requisition] = $this->context(quantity: 0);
        $conversion = $product->alternativeUnitConversions()->create(['unit' => Unit::PC->value, 'conversion_factor' => 2]);
        $item = app(RequisitionItemService::class)->create($this->itemData($requisition, $product, 2) + ['unused' => null], $owner->id);
        $item = app(RequisitionItemService::class)->update($item, ['unit_of_measure' => Unit::PC->value, 'quantity' => 2], $owner->id);
        $document = $this->note($requisition, [$item]);
        app(SaleNfeStockService::class)->prepare($document);
        $conversion->update(['conversion_factor' => 3]);
        $product->update(['has_stock_control' => false]);
        $this->authorize($document);
        $this->assertTrue(app(SaleNfeStockService::class)->apply($document));
        $this->assertSame(6.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame(4.0, (float) $document->stockEffects()->sole()->base_quantity);
    }

    public function test_foreign_requisition_origin_is_rejected_without_moving_stock(): void
    {
        [, , $stock, $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        [, , , , $foreign] = $this->context();
        $document->items()->sole()->update(['requisition_item_id' => $foreign->id]);
        try {
            app(SaleNfeStockService::class)->prepare($document);
            $this->fail('Origem de outra empresa deve ser recusada.');
        } catch (ValidationException) {
            $this->assertSame(10.0, (float) $stock->fresh()->quantity_total);
            $this->assertSame(0, StockMovement::where('type', Type::EXIT)->count());
        }
    }

    public function test_changing_base_unit_does_not_reinterpret_a_prepared_stock_quantity(): void
    {
        [, $product, $stock, $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        app(SaleNfeStockService::class)->prepare($document);
        $product->update(['unit' => Unit::PC]);
        $this->authorize($document);
        $service = app(SaleNfeStockService::class);
        $this->assertFalse($service->apply($document));
        $this->assertSame(NfeStatus::AUTHORIZED, $document->fresh()->nfe_status);
        $this->assertSame(10.0, (float) $stock->fresh()->quantity_total);
        $this->assertStringContainsString('unidade base', $service->getMessage());
        $this->assertTrue($document->hasPendingStockEffect());
    }

    public function test_failure_on_second_fiscal_line_rolls_back_all_exits_and_reservation_releases(): void
    {
        [$owner, $product, $stock, $requisition, $item] = $this->context();
        $other = app(RequisitionItemService::class)->create($this->itemData($requisition, $product, 2), $owner->id);
        $document = $this->note($requisition, [$item, $other]);
        $this->authorize($document);
        $secondId = $document->items()->orderBy('id')->get()->last()->id;
        $mock = $this->mock(StockMovementService::class);
        $mock->shouldReceive('create')->andReturnUsing(function ($data, $actor) use ($secondId) {
            if ($data['type'] === Type::EXIT->value && $data['source_id'] === $secondId) {
                return null;
            }

            return (new StockMovementService)->create($data, $actor);
        });
        $mock->shouldReceive('hasError')->andReturnFalse();
        $mock->shouldReceive('getMessage')->andReturn('Falha simulada na segunda saída.');
        $service = app(SaleNfeStockService::class);
        $this->assertFalse($service->apply($document));
        $this->assertSame(10.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame(5.0, (float) $stock->fresh()->quantity_reserved);
        $this->assertSame(0, StockMovement::where('type', Type::EXIT)->count());
        $this->assertNull($item->fresh()->stock_consumed_at);
        $this->assertSame(2, $document->stockEffects()->where('status', 'blocked')->count());
        $this->assertSame(NfeStatus::AUTHORIZED, $document->fresh()->nfe_status);
    }

    public function test_processed_fiscal_exit_cannot_be_edited_or_deleted_through_manual_inventory_actions(): void
    {
        [, , , $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        $this->authorize($document);
        $this->assertTrue(app(SaleNfeStockService::class)->apply($document));
        $this->expectException(\LogicException::class);
        $document->stockEffects()->sole()->stockMovement->update(['quantity' => 1]);
    }

    public function test_pending_stock_can_be_reprocessed_from_the_note_only_by_company_administrator(): void
    {
        [$owner, , $stock, $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        $stock->update(['quantity_total' => 2]);
        $this->authorize($document);
        $this->assertFalse(app(SaleNfeStockService::class)->apply($document));
        $member = User::factory()->create();
        $member->companies()->attach($requisition->company_id, ['role' => 'member', 'is_active' => true]);
        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($requisition->company);
        Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
            ->assertActionHidden('reprocessar_estoque');
        $this->actingAs($owner);
        $stock->update(['quantity_total' => 10]);
        Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
            ->assertActionVisible('reprocessar_estoque')->callAction('reprocessar_estoque')->assertHasNoActionErrors();
        $this->assertSame(7.0, (float) $stock->fresh()->quantity_total);
        $this->assertFalse($document->hasPendingStockEffect());
    }

    public function test_legacy_link_recovery_diagnoses_without_writing_then_applies_idempotently_without_stock_changes(): void
    {
        [, , $stock, $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        $document->items()->sole()->update(['requisition_item_id' => null]);
        $movements = StockMovement::count();
        $diagnosis = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id);
        $this->assertSame([], $diagnosis['errors'], json_encode($diagnosis));

        $this->artisan('fiscal:recover-requisition-links', ['document' => $document->id])
            ->expectsOutputToContain('Nenhuma alteração gravada')->assertSuccessful();
        $this->assertNull($document->items()->sole()->requisition_item_id);
        $this->artisan('fiscal:recover-requisition-links', ['document' => $document->id, '--apply' => true])
            ->expectsOutputToContain('1 vínculo(s) recuperado(s)')->assertSuccessful();
        $this->assertSame($item->id, $document->items()->sole()->requisition_item_id);
        $this->artisan('fiscal:recover-requisition-links', ['document' => $document->id, '--apply' => true])
            ->expectsOutputToContain('Todos os itens já possuem vínculos compatíveis')->assertSuccessful();
        $this->assertSame($movements, StockMovement::count());
        $this->assertSame(10.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame(3.0, (float) $stock->fresh()->quantity_reserved);
        $this->assertSame(0, $document->stockEffects()->count());
        app(SaleNfeStockService::class)->prepare($document);
        $this->authorize($document);
        $this->assertTrue(app(SaleNfeStockService::class)->apply($document));
        $this->assertSame(7.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame(1, StockMovement::where('type', Type::EXIT)->count());
    }

    public function test_legacy_link_recovery_rejects_ambiguous_sources_and_rolls_back_all_links(): void
    {
        [$owner, $product, , $requisition, $item] = $this->context();
        $other = app(RequisitionItemService::class)->create($this->itemData($requisition, $product, 2), $owner->id);
        app(RequisitionItemService::class)->create($this->itemData($requisition, $product, 3), $owner->id);
        $document = $this->note($requisition, [$item, $other]);
        foreach ($document->items as $line) {
            $line->update(['requisition_item_id' => null]);
        }
        $report = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true);
        $this->assertNotEmpty($report['errors']);
        $this->assertFalse($report['applied']);
        $this->assertSame(0, $document->items()->whereNotNull('requisition_item_id')->count());
    }

    public function test_legacy_link_recovery_does_not_assign_one_source_to_two_fiscal_lines(): void
    {
        [, , , $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item, $item]);
        foreach ($document->items as $line) {
            $line->update(['requisition_item_id' => null]);
        }
        $report = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true);
        $this->assertStringContainsString('disputam a mesma origem', implode(' ', $report['errors']));
        $this->assertSame(0, $document->items()->whereNotNull('requisition_item_id')->count());
    }

    public function test_legacy_link_recovery_reports_previous_exit_without_writing_or_lowering_stock_again(): void
    {
        [$owner, $product, $stock, $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        $document->items()->sole()->update(['requisition_item_id' => null]);
        $movement = StockMovement::create([
            'company_id' => $document->company_id, 'product_id' => $product->id,
            'product_stock_id' => $stock->id, 'type' => Type::EXIT,
            'quantity' => 3, 'unit_price' => 10, 'source_type' => 'requisition_item',
            'source_id' => $item->id, 'created_by' => $owner->id,
        ]);
        $report = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true);
        $this->assertNotEmpty($report['errors']);
        $this->assertStringContainsString('movimentos: '.$movement->id, $report['rows'][0]['message']);
        $this->assertNull($document->items()->sole()->requisition_item_id);
        $this->assertSame(10.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame(1, StockMovement::where('type', Type::EXIT)->count());
    }

    public function test_legacy_link_recovery_checks_consumption_flags_even_without_a_traced_movement(): void
    {
        [, , , $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        $document->items()->sole()->update(['requisition_item_id' => null]);
        // Reproduce a legacy consumption record, bypassing invoiced-item editing guards.
        $item->newQuery()->whereKey($item->id)->update(['stock_consumed' => true]);
        $report = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true);
        $this->assertNotEmpty($report['errors']);
        $this->assertStringContainsString('sem movimento rastreado', $report['rows'][0]['message']);
        $this->assertNull($document->items()->sole()->requisition_item_id);
    }

    public function test_legacy_link_recovery_detects_old_exits_traced_only_to_the_requisition(): void
    {
        [$owner, $product, $stock, $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        $document->items()->sole()->update(['requisition_item_id' => null]);
        $movement = StockMovement::create([
            'company_id' => $document->company_id, 'product_id' => $product->id,
            'product_stock_id' => $stock->id, 'type' => Type::EXIT,
            'quantity' => 3, 'unit_price' => 10, 'source_type' => 'requisition',
            'source_id' => $requisition->id, 'created_by' => $owner->id,
        ]);
        $report = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true);
        $this->assertNotEmpty($report['errors']);
        $this->assertStringContainsString('movimentos: '.$movement->id, $report['rows'][0]['message']);
        $this->assertNull($document->items()->sole()->requisition_item_id);
    }

    public function test_legacy_link_recovery_detects_consumption_marked_only_on_the_requisition(): void
    {
        [, , , $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        $document->items()->sole()->update(['requisition_item_id' => null]);
        $requisition->update(['stock_consumed' => true]);
        $report = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true);
        $this->assertNotEmpty($report['errors']);
        $this->assertNull($document->items()->sole()->requisition_item_id);
    }

    public function test_legacy_link_recovery_is_scoped_to_the_document_company_and_invoice(): void
    {
        [, $product, , $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        [$foreignOwner, , , $foreignRequisition] = $this->context(quantity: 0);
        // Matching product/unit/quantity in another invoice must never be considered.
        $foreignRequisition->items()->create($this->itemData($foreignRequisition, $product, 4) + ['created_by' => $foreignOwner->id]);
        $document->items()->sole()->update(['requisition_item_id' => null, 'quantity' => 4]);
        $report = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true);
        $this->assertSame('', $report['rows'][0]['candidate_ids']);
        $this->assertNotEmpty($report['errors']);
        $this->artisan('fiscal:recover-requisition-links', ['document' => $document->id, '--company' => $foreignRequisition->company_id, '--apply' => true])
            ->assertFailed();
        $this->assertNull($document->items()->sole()->requisition_item_id);
    }

    public function test_legacy_link_recovery_preserves_existing_links_and_excludes_their_sources(): void
    {
        [$owner, $product, , $requisition, $item] = $this->context();
        $other = app(RequisitionItemService::class)->create($this->itemData($requisition, $product, 3), $owner->id);
        $document = $this->note($requisition, [$item, $other]);
        $line = $document->items()->orderByDesc('id')->first();
        $line->update(['requisition_item_id' => null]);
        $report = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true);
        $this->assertTrue($report['applied']);
        $this->assertSame($other->id, $line->fresh()->requisition_item_id);
        $this->assertSame($item->id, $document->items()->orderBy('id')->first()->requisition_item_id);
    }

    public function test_legacy_link_recovery_rejects_authorized_documents(): void
    {
        [, , , $requisition, $item] = $this->context();
        $document = $this->note($requisition, [$item]);
        $document->items()->sole()->update(['requisition_item_id' => null]);
        $this->authorize($document);
        $this->artisan('fiscal:recover-requisition-links', ['document' => $document->id, '--apply' => true])->assertFailed();
        $this->assertNull($document->items()->sole()->requisition_item_id);
    }

    public function test_legacy_exit_can_be_adopted_and_authorized_without_a_second_physical_exit(): void
    {
        [$document, $source, $stock, $movement] = $this->legacyNote();
        $this->artisan('fiscal:recover-requisition-links', ['document' => $document->id, '--reuse-legacy-stock' => true])
            ->expectsOutputToContain('1 saída(s) antiga(s) validada(s)')->assertSuccessful();
        $this->assertNull($document->items()->sole()->requisition_item_id);
        $this->assertSame(0, $document->stockEffects()->count());

        $this->artisan('fiscal:recover-requisition-links', ['document' => $document->id, '--reuse-legacy-stock' => true, '--apply' => true])
            ->expectsOutputToContain('Nenhuma nova baixa gerada')->assertSuccessful();
        $effect = $document->stockEffects()->sole();
        $this->assertNull($effect->processed_at);
        $this->assertSame($source->id, $document->items()->sole()->requisition_item_id);
        $this->assertSame($movement->id, $effect->stock_movement_id);
        $this->assertSame($movement->id, $effect->snapshot['reuse_movement_id']);

        // Repeated recovery/previews are stable; no balance is required for an already completed exit.
        $this->artisan('fiscal:recover-requisition-links', ['document' => $document->id, '--reuse-legacy-stock' => true, '--apply' => true])->assertSuccessful();
        $stock->update(['quantity_total' => 0]);
        app(SaleNfeStockService::class)->prepare($document);
        app(SaleNfeStockService::class)->prepare($document);
        $this->authorize($document);
        $service = app(SaleNfeStockService::class);
        $this->assertTrue($service->apply($document), (string) $service->getMessage());
        $this->assertTrue($service->apply($document));
        $this->assertSame(0.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame(0.0, (float) $stock->fresh()->quantity_reserved);
        $this->assertSame(1, StockMovement::where('type', Type::EXIT)->count());
        $this->assertSame($movement->id, $document->stockEffects()->sole()->stock_movement_id);
        $this->assertNotNull($document->stockEffects()->sole()->processed_at);
        $this->assertSame('requisition', $movement->fresh()->source_type);

        // A reissue after a confirmed cancellation uses the existing fiscal reuse flow.
        $document->update(['status' => FiscalStatus::CANCELLED, 'nfe_status' => NfeStatus::CANCELED]);
        $new = $this->note($source->requisition, [$source]);
        $this->authorize($new);
        $this->assertTrue($service->apply($new), (string) $service->getMessage());
        $this->assertSame($movement->id, $new->stockEffects()->sole()->stock_movement_id);
        $this->assertSame(1, StockMovement::where('type', Type::EXIT)->count());
    }

    public function test_legacy_reuse_requires_matching_units_quantities_stock_and_complete_snapshots(): void
    {
        [, , $otherStock] = $this->context();
        foreach ([['base_quantity' => 2], ['base_unit' => 'JG'], ['operational_quantity' => 2],
            ['conversion_factor_snapshot' => 2], ['base_quantity' => null], ['product_stock_id' => $otherStock->id]] as $changes) {
            [$document, , , $movement] = $this->legacyNote();
            $movement->update($changes);
            $report = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true, true);
            $this->assertNotEmpty($report['errors'], json_encode($changes));
            $this->assertNull($document->items()->sole()->requisition_item_id);
            $this->assertSame(0, $document->stockEffects()->count());
        }
    }

    public function test_legacy_reuse_rejects_a_return_or_another_physical_movement_in_the_same_origin(): void
    {
        [$document, , , $movement] = $this->legacyNote();
        $return = $movement->replicate();
        $return->type = Type::RETURN;
        $return->save();
        $report = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true, true);
        $this->assertNotEmpty($report['errors']);
        $this->assertNull($document->items()->sole()->requisition_item_id);
        $this->assertSame(0, $document->stockEffects()->count());
    }

    public function test_legacy_reuse_cannot_claim_an_exit_already_reserved_for_another_note(): void
    {
        [$document, $source, , $movement] = $this->legacyNote();
        $other = $this->note($source->requisition, [$source]);
        $service = app(RecoverNfeRequisitionLinksService::class);
        $this->assertTrue($service->execute($document->id, $document->company_id, true, true)['applied']);
        $report = $service->execute($other->id, $other->company_id, true, true);
        $this->assertStringContainsString('outro efeito fiscal', implode(' ', $report['errors']));
        $this->assertSame(0, $other->stockEffects()->count());
        $this->assertSame($movement->id, $document->stockEffects()->sole()->stock_movement_id);
    }

    public function test_recovered_legacy_exit_is_protected_even_before_authorization(): void
    {
        [$document, , , $movement] = $this->legacyNote();
        app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true, true);
        $this->expectException(\LogicException::class);
        $movement->update(['quantity' => 2]);
    }

    public function test_legacy_reuse_is_revalidated_after_a_fiscal_item_changes(): void
    {
        [$document] = $this->legacyNote();
        app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true, true);
        $document->items()->sole()->update(['quantity' => 2]);
        $this->expectException(ValidationException::class);
        app(SaleNfeStockService::class)->prepare($document);
    }

    public function test_legacy_reuse_cannot_be_bypassed_by_disabling_product_stock_control(): void
    {
        [$document] = $this->legacyNote();
        app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true, true);
        $document->items()->sole()->product->update(['has_stock_control' => false]);
        // The source policy snapshot may still require control; change the requisition policy as well.
        $document->invoice->requisitions()->update(['stock_control_enabled' => false]);
        $this->expectException(ValidationException::class);
        app(SaleNfeStockService::class)->prepare($document);
    }

    public function test_legacy_recovery_rolls_back_links_and_effects_if_stock_preparation_fails(): void
    {
        [$document, $source, $legacyStock] = $this->legacyNote();
        // The unique new line is recoverable but requires unavailable stock.
        $source->requisition->update(['stock_consumed' => false]);
        $product = Product::factory()->create(['company_id' => $document->company_id, 'has_stock_control' => true, 'unit' => Unit::UN]);
        $otherSource = $source->replicate(['total_amount', 'gross_amount', 'commission_amount']);
        $otherSource->fill(['product_id' => $product->id, 'stock_consumed' => false, 'stock_consumed_at' => null]);
        $otherSource->save();
        $stock = $legacyStock->replicate(['quantity_available', 'total_cost']);
        $stock->fill(['product_id' => $product->id, 'quantity_total' => 0, 'quantity_reserved' => 0]);
        $stock->save();
        $document->items()->create([
            'product_id' => $product->id, 'description' => $product->name, 'item_number' => 2,
            'quantity' => 3, 'unit_of_measure' => Unit::UN->value, 'unit_price' => 10, 'total_price' => 30,
        ]);
        $report = null;
        try {
            $report = app(RecoverNfeRequisitionLinksService::class)->execute($document->id, $document->company_id, true, true);
            $this->fail('Saldo insuficiente deve desfazer a recuperação inteira.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Saldo insuficiente', $exception->getMessage());
        }
        $this->assertNull($report);
        $this->assertSame(0, $document->items()->whereNotNull('requisition_item_id')->count());
        $this->assertSame(0, $document->stockEffects()->count());
    }

    public function test_legacy_reuse_recovers_alternative_unit_conversion_without_repeating_the_base_exit(): void
    {
        [$owner, $product, $stock, $requisition] = $this->context(quantity: 0);
        $product->alternativeUnitConversions()->create(['unit' => Unit::PC->value, 'conversion_factor' => 2]);
        $source = app(RequisitionItemService::class)->create([
            ...$this->itemData($requisition, $product, 2), 'unit_of_measure' => Unit::PC->value,
        ], $owner->id);
        $document = $this->note($requisition, [$source]);
        $document->items()->sole()->update(['requisition_item_id' => null]);
        app(RequisitionItemReservationService::class)->sync($source, $owner->id, true);
        $stock->update(['quantity_total' => 6]);
        $movement = StockMovement::create([
            'company_id' => $document->company_id, 'product_id' => $product->id, 'product_stock_id' => $stock->id,
            'type' => Type::EXIT, 'quantity' => 4, 'base_quantity' => 4, 'base_unit' => Unit::UN->value,
            'operational_quantity' => 2, 'operational_unit' => Unit::PC->value, 'conversion_factor_snapshot' => 2,
            'unit_price' => 10, 'source_type' => 'requisition', 'source_id' => $requisition->id, 'created_by' => $owner->id,
        ]);
        $service = app(RecoverNfeRequisitionLinksService::class);
        $this->assertTrue($service->execute($document->id, $document->company_id, true, true)['applied']);
        $this->authorize($document);
        $this->assertTrue(app(SaleNfeStockService::class)->apply($document));
        $this->assertSame(6.0, (float) $stock->fresh()->quantity_total);
        $this->assertSame($movement->id, $document->stockEffects()->sole()->stock_movement_id);
    }

    private function legacyNote(): array
    {
        [$owner, $product, $stock, $requisition, $source] = $this->context();
        $document = $this->note($requisition, [$source]);
        $document->items()->sole()->update(['requisition_item_id' => null]);
        app(RequisitionItemReservationService::class)->sync($source, $owner->id, true);
        $stock->update(['quantity_total' => 7]);
        $source->newQuery()->whereKey($source->id)->update(['stock_consumed' => true, 'stock_consumed_at' => now()]);
        $requisition->update(['stock_consumed' => true]);
        $movement = StockMovement::create([
            'company_id' => $document->company_id, 'product_id' => $product->id, 'product_stock_id' => $stock->id,
            'type' => Type::EXIT, 'quantity' => 3, 'base_quantity' => 3, 'base_unit' => Unit::UN->value,
            'operational_quantity' => 3, 'operational_unit' => Unit::UN->value, 'conversion_factor_snapshot' => 1,
            'unit_price' => 10, 'source_type' => 'requisition', 'source_id' => $requisition->id, 'created_by' => $owner->id,
        ]);

        return [$document, $source->fresh(), $stock->fresh(), $movement];
    }

    private function context(float $quantity = 3, bool $controlled = true, bool $hasStock = true, bool $negative = false): array
    {
        $owner = User::factory()->create();
        $company = Company::factory()->create();
        $owner->companies()->attach($company, ['role' => 'owner', 'is_active' => true]);
        $customer = Partner::factory()->create();
        $customer->companies()->attach($company, ['is_active' => true, 'type' => '["customer"]']);
        $product = Product::factory()->create(['company_id' => $company->id, 'has_stock_control' => $controlled, 'unit' => Unit::UN]);
        $stock = $hasStock ? ProductStock::create(['company_id' => $company->id, 'product_id' => $product->id, 'quantity_total' => 10, 'quantity_reserved' => 0, 'quantity_minimum' => 0, 'quantity_maximum' => 100, 'average_cost' => 5, 'last_cost' => 5, 'last_sale_price' => 10, 'is_active' => true, 'allow_negative' => $negative, 'created_by' => $owner->id]) : null;
        $requisition = Requisition::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'number' => fake()->unique()->bothify('REQ-####'), 'sale_date' => today(), 'status' => Status::OPEN, 'stock_control_enabled' => true, 'stock_consumed' => false, 'created_by' => $owner->id]);
        $item = null;
        if ($quantity > 0) {
            $service = app(RequisitionItemService::class);
            $item = $service->create($this->itemData($requisition, $product, $quantity), $owner->id);
            $this->assertNotNull($item, (string) $service->getMessage());
        }

        return [$owner, $product, $stock, $requisition, $item];
    }

    private function itemData(Requisition $requisition, Product $product, float $quantity): array
    {
        return ['requisition_id' => $requisition->id, 'product_id' => $product->id, 'unit_of_measure' => Unit::UN->value, 'quantity' => $quantity, 'unit_price' => 10];
    }

    private function note(Requisition $requisition, array $items): FiscalDocument
    {
        $requisition->refresh();
        if (! $requisition->invoice_id) {
            $service = app(RequisitionService::class);
            $this->assertNotNull($service->close($requisition, $requisition->created_by, false), (string) $service->getMessage());
            $invoice = $service->invoice($requisition->fresh(), $requisition->created_by);
            $this->assertNotNull($invoice, (string) $service->getMessage());
            $requisition->refresh();
        }
        $document = FiscalDocument::create(['company_id' => $requisition->company_id, 'customer_id' => $requisition->customer_id, 'invoice_id' => $requisition->invoice_id, 'document_type' => 'nfe', 'operation_type' => '1', 'status' => FiscalStatus::PENDING, 'nfe_status' => NfeStatus::PENDING, 'issued_at' => today(), 'movement_at' => today(), 'created_by' => $requisition->created_by]);
        foreach ($items as $index => $source) {
            $document->items()->create(['requisition_item_id' => $source->id, 'product_id' => $source->product_id, 'product_code' => $source->product->product_code, 'description' => $source->product->name, 'item_number' => $index + 1, 'quantity' => $source->quantity, 'unit_of_measure' => $source->unit_of_measure, 'unit_price' => 10, 'total_price' => (float) $source->quantity * 10]);
        }

        return $document;
    }

    private function authorize(FiscalDocument $document): void
    {
        $document->update(['nfe_status' => NfeStatus::AUTHORIZED, 'status' => FiscalStatus::CONFIRMED]);
    }
}
