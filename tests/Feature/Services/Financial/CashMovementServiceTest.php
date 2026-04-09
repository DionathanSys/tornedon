<?php

namespace Tests\Feature\Services\Financial;

use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\FinancialAccountType;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Partner;
use App\Models\User;
use App\Services\Financial\CashMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashMovementService $service;
    private User $user;
    private Company $company;
    private FinancialAccount $mainAccount;
    private FinancialAccount $secondaryAccount;
    private FinancialCategory $category;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CashMovementService::class);
        $this->user = User::factory()->create();

        $this->company = Company::create([
            'name' => 'Empresa Movimento',
            'document_number' => '12345678000111',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $this->user->id,
        ]);

        $this->mainAccount = FinancialAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Conta Principal',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 1000,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->secondaryAccount = FinancialAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Conta Reserva',
            'type' => FinancialAccountType::BANK->value,
            'opening_balance' => 500,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $categoryParent = FinancialCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Operacional',
            'allow_cash_movement' => false,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->category = FinancialCategory::create([
            'company_id' => $this->company->id,
            'parent_id' => $categoryParent->id,
            'name' => 'Diversos',
            'allow_cash_movement' => true,
            'is_active' => true,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $this->partner = Partner::create([
            'name' => 'Parceiro Manual',
            'document_type' => 'CPF',
            'document_number' => '12345678909',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_manual_cash_movement_without_counterparty_remains_valid(): void
    {
        $movement = $this->service->createManual([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->mainAccount->id,
            'financial_category_id' => $this->category->id,
            'direction' => CashMovementDirection::OUTFLOW->value,
            'transaction_date' => '2026-04-09',
            'amount' => 150,
            'description' => 'Pagamento avulso',
            'notes' => 'Sem contraparte',
        ], $this->user->id);

        $this->assertNotNull($movement, $this->service->getMessage());
        $this->assertNull($movement->counterparty_partner_id);
        $this->assertNull($movement->counterparty_financial_account_id);
        $this->assertSame('Manual', $movement->origin_label);
        $this->assertSame('Empresa Movimento', $movement->party_from_label);
        $this->assertSame('Nao informado', $movement->party_to_label);
        $this->assertStringContainsString('Conta Principal', $movement->tracking_label);
    }

    public function test_manual_cash_movement_tracks_partner_and_accounts_with_snapshot(): void
    {
        $movement = $this->service->createManual([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->mainAccount->id,
            'financial_category_id' => $this->category->id,
            'direction' => CashMovementDirection::OUTFLOW->value,
            'transaction_date' => '2026-04-09',
            'amount' => 200,
            'description' => 'Transferencia operacional',
            'counterparty_partner_id' => $this->partner->id,
            'counterparty_financial_account_id' => $this->secondaryAccount->id,
        ], $this->user->id);

        $this->assertNotNull($movement, $this->service->getMessage());
        $this->assertSame($this->partner->id, $movement->counterparty_partner_id);
        $this->assertSame($this->secondaryAccount->id, $movement->counterparty_financial_account_id);
        $this->assertSame('Empresa Movimento', $movement->party_from_label);
        $this->assertSame('Parceiro Manual', $movement->party_to_label);
        $this->assertStringContainsString('Conta Principal', $movement->account_from_label);
        $this->assertStringContainsString('Conta Reserva', $movement->account_to_label);

        $partnerNameAtCreation = data_get($movement->participants_snapshot, 'counterparty_partner_name');
        $secondaryLabelAtCreation = data_get($movement->participants_snapshot, 'counterparty_financial_account_name');

        $this->partner->update(['name' => 'Parceiro Renomeado']);
        $this->secondaryAccount->update(['name' => 'Conta Renomeada']);

        $movement = $movement->fresh();

        $this->assertSame($partnerNameAtCreation, data_get($movement->participants_snapshot, 'counterparty_partner_name'));
        $this->assertSame($secondaryLabelAtCreation, data_get($movement->participants_snapshot, 'counterparty_financial_account_name'));
        $this->assertSame('Parceiro Manual', $movement->party_to_label);
        $this->assertStringContainsString('Conta Reserva', $movement->account_to_label);
    }

    public function test_manual_cash_movement_inflow_inverts_party_labels(): void
    {
        $movement = $this->service->createManual([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->mainAccount->id,
            'financial_category_id' => $this->category->id,
            'direction' => CashMovementDirection::INFLOW->value,
            'transaction_date' => '2026-04-09',
            'amount' => 75,
            'description' => 'Recebimento eventual',
            'counterparty_partner_id' => $this->partner->id,
        ], $this->user->id);

        $this->assertNotNull($movement, $this->service->getMessage());
        $this->assertSame('Parceiro Manual', $movement->party_from_label);
        $this->assertSame('Empresa Movimento', $movement->party_to_label);
    }

    public function test_manual_cash_movement_rejects_same_counterparty_account(): void
    {
        $movement = $this->service->createManual([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->mainAccount->id,
            'financial_category_id' => $this->category->id,
            'direction' => CashMovementDirection::OUTFLOW->value,
            'transaction_date' => '2026-04-09',
            'amount' => 90,
            'description' => 'Transferencia invalida',
            'counterparty_financial_account_id' => $this->mainAccount->id,
        ], $this->user->id);

        $this->assertNull($movement);
        $this->assertArrayHasKey('counterparty_financial_account_id', $this->service->getErrors());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_update_manual_cash_movement_can_clear_counterparty(): void
    {
        $movement = CashMovement::create([
            'company_id' => $this->company->id,
            'financial_account_id' => $this->mainAccount->id,
            'financial_category_id' => $this->category->id,
            'direction' => CashMovementDirection::OUTFLOW->value,
            'transaction_date' => '2026-04-09',
            'amount' => 120,
            'description' => 'Movimento inicial',
            'origin_type' => 'manual',
            'counterparty_partner_id' => $this->partner->id,
            'counterparty_financial_account_id' => $this->secondaryAccount->id,
            'participants_snapshot' => [
                'company_name' => $this->company->name,
                'financial_account_name' => $this->mainAccount->display_name,
                'counterparty_partner_name' => $this->partner->name,
                'counterparty_financial_account_name' => $this->secondaryAccount->display_name,
            ],
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $updated = $this->service->updateManual($movement, [
            'company_id' => $this->company->id,
            'financial_account_id' => $this->mainAccount->id,
            'financial_category_id' => $this->category->id,
            'direction' => CashMovementDirection::OUTFLOW->value,
            'transaction_date' => '2026-04-10',
            'amount' => 150,
            'description' => 'Movimento atualizado',
            'counterparty_partner_id' => null,
            'counterparty_financial_account_id' => null,
        ], $this->user->id);

        $this->assertNotNull($updated, $this->service->getMessage());
        $this->assertNull($updated->counterparty_partner_id);
        $this->assertNull($updated->counterparty_financial_account_id);
        $this->assertArrayNotHasKey('counterparty_partner_name', $updated->participants_snapshot);
        $this->assertArrayNotHasKey('counterparty_financial_account_name', $updated->participants_snapshot);
    }
}
