<?php

namespace Tests\Feature\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\NfseSequence;
use App\Models\Partner;
use App\Models\User;
use App\Services\FiscalDocument\Actions\ReconcileNfseRpsSequenceAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileNfseRpsSequenceActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_releases_rps_and_rewinds_sequence_when_document_is_sequence_tail(): void
    {
        [$user, $company, $customer] = $this->createContext();

        $document = $this->createDocument($company, $customer, $user, [
            'rps_number' => '10',
            'rps_series' => '1',
            'nfse_status' => NfeStatus::RPS_RECONCILIATION_PENDING->value,
        ]);

        $sequence = NfseSequence::query()->create([
            'company_id' => $company->id,
            'serie' => '1',
            'last_number' => 10,
        ]);

        $document->update([
            'nfse_sequence_id' => $sequence->id,
        ]);

        $action = new ReconcileNfseRpsSequenceAction;

        $this->assertTrue($action->execute($document->fresh(), 'Liberação manual após falha antes da API.', true));

        $document->refresh();
        $sequence->refresh();

        $this->assertNull($document->rps_number);
        $this->assertNull($document->rps_series);
        $this->assertNull($document->nfse_sequence_id);
        $this->assertSame(NfeStatus::PENDING, $document->nfse_status);
        $this->assertSame(9, $sequence->last_number);
        $this->assertTrue((bool) data_get($document->errors_messages, '0.contexto.released_document_number'));
    }

    public function test_it_keeps_reconciliation_and_justifies_gap_when_newer_rps_exists(): void
    {
        [$user, $company, $customer] = $this->createContext();

        $document = $this->createDocument($company, $customer, $user, [
            'rps_number' => '9',
            'rps_series' => '1',
            'nfse_status' => NfeStatus::RPS_RECONCILIATION_PENDING->value,
        ]);

        $otherDocument = $this->createDocument($company, $customer, $user, [
            'rps_number' => '10',
            'rps_series' => '1',
            'nfse_status' => NfeStatus::AUTHORIZED->value,
        ]);

        $sequence = NfseSequence::query()->create([
            'company_id' => $company->id,
            'serie' => '1',
            'last_number' => 10,
        ]);

        $document->update([
            'nfse_sequence_id' => $sequence->id,
        ]);

        $otherDocument->update([
            'nfse_sequence_id' => $sequence->id,
        ]);

        $action = new ReconcileNfseRpsSequenceAction;

        $this->assertTrue($action->execute($document->fresh(), 'RPS ficou para trás na sequência.', true));

        $document->refresh();
        $sequence->refresh();

        $this->assertNull($document->rps_number);
        $this->assertNull($document->rps_series);
        $this->assertSame(NfeStatus::PENDING, $document->nfse_status);
        $this->assertSame(10, $sequence->last_number);
        $this->assertTrue((bool) data_get($document->errors_messages, '0.contexto.gap_justification_required'));
        $this->assertFalse((bool) data_get($document->errors_messages, '0.contexto.released_document_number'));
        $this->assertTrue((bool) data_get($document->errors_messages, '0.contexto.document_cleared_for_new_rps'));
    }

    public function test_it_keeps_document_in_reconciliation_when_only_registering_reason(): void
    {
        [$user, $company, $customer] = $this->createContext();

        $document = $this->createDocument($company, $customer, $user, [
            'rps_number' => '9',
            'rps_series' => '1',
            'nfse_status' => NfeStatus::RPS_RECONCILIATION_PENDING->value,
        ]);

        $this->createDocument($company, $customer, $user, [
            'rps_number' => '10',
            'rps_series' => '1',
            'nfse_status' => NfeStatus::AUTHORIZED->value,
        ]);

        NfseSequence::query()->create([
            'company_id' => $company->id,
            'serie' => '1',
            'last_number' => 10,
        ]);

        $action = new ReconcileNfseRpsSequenceAction;

        $this->assertTrue($action->execute($document->fresh(), 'Somente registrar a justificativa da lacuna.', false));

        $document->refresh();

        $this->assertSame('9', $document->rps_number);
        $this->assertSame('1', $document->rps_series);
        $this->assertSame(NfeStatus::RPS_RECONCILIATION_PENDING, $document->nfse_status);
        $this->assertFalse((bool) data_get($document->errors_messages, '0.contexto.document_cleared_for_new_rps'));
    }

    private function createContext(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Conciliação RPS',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Chapeco', 'state' => 'SC'],
            'created_by' => $user->id,
        ]);

        $customer = Partner::query()->create([
            'name' => 'Cliente Conciliação RPS',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        return [$user, $company, $customer];
    }

    private function createDocument(Company $company, Partner $customer, User $user, array $overrides = []): FiscalDocument
    {
        return FiscalDocument::query()->create(array_merge([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'status' => Status::PENDING->value,
            'document_type' => DocumentModel::NFSE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'nfse_status' => NfeStatus::PENDING->value,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ], $overrides));
    }
}
