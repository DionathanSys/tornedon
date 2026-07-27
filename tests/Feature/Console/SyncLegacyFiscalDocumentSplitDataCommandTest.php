<?php

namespace Tests\Feature\Console;

use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncLegacyFiscalDocumentSplitDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_syncs_legacy_fiscal_document_jsons_to_split_tables(): void
    {
        [$company, $customer] = $this->makeScenario();

        $documentId = $this->createFiscalDocument($company, $customer, [
            'freight_data' => ['modalidade_frete' => '0'],
            'payment_data' => ['method' => 'pix'],
            'tax_data' => [
                'totais' => ['vNF' => 150.25],
                'regime' => 'simples',
            ],
            'nfe_payload' => ['nfe' => ['id' => 'NFe123']],
            'nfse_payload' => ['nfse' => ['id' => 'NFSe123']],
        ]);

        $this->artisan('fiscal-documents:sync-legacy-split-data')
            ->assertSuccessful();

        $taxDetail = DB::table('fiscal_document_tax_details')
            ->where('fiscal_document_id', $documentId)
            ->first();

        $this->assertSame(['modalidade_frete' => '0'], json_decode($taxDetail->freight_data, true));
        $this->assertSame(['method' => 'pix'], json_decode($taxDetail->payment_data, true));
        $this->assertSame(['totais' => ['vNF' => 150.25], 'regime' => 'simples'], json_decode($taxDetail->tax_data, true));
        $this->assertSame(['vNF' => 150.25], json_decode($taxDetail->tax_totals, true));
        $this->assertSame(['regime' => 'simples'], json_decode($taxDetail->fiscal_metadata, true));

        $payload = DB::table('fiscal_document_payloads')
            ->where('fiscal_document_id', $documentId)
            ->first();

        $this->assertSame(['nfe' => ['id' => 'NFe123']], json_decode($payload->nfe_payload, true));
        $this->assertSame(['nfse' => ['id' => 'NFSe123']], json_decode($payload->nfse_payload, true));
    }

    public function test_command_fills_only_missing_split_values(): void
    {
        [$company, $customer] = $this->makeScenario();

        $documentId = $this->createFiscalDocument($company, $customer, [
            'freight_data' => ['modalidade_frete' => 'legacy'],
            'payment_data' => ['method' => 'legacy'],
            'tax_data' => [
                'totais' => ['vNF' => 200],
                'regime' => 'legacy',
            ],
            'nfe_payload' => ['nfe' => ['id' => 'legacy']],
            'nfse_payload' => ['nfse' => ['id' => 'legacy']],
        ]);

        DB::table('fiscal_document_tax_details')->insert([
            'company_id' => $company->id,
            'fiscal_document_id' => $documentId,
            'freight_data' => json_encode(['modalidade_frete' => 'new']),
            'payment_data' => null,
            'tax_data' => null,
            'tax_totals' => json_encode(['vNF' => 999]),
            'fiscal_metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fiscal_document_payloads')->insert([
            'company_id' => $company->id,
            'fiscal_document_id' => $documentId,
            'nfe_payload' => json_encode(['nfe' => ['id' => 'new']]),
            'nfse_payload' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('fiscal-documents:sync-legacy-split-data')
            ->assertSuccessful();

        $taxDetail = DB::table('fiscal_document_tax_details')
            ->where('fiscal_document_id', $documentId)
            ->first();

        $this->assertSame(['modalidade_frete' => 'new'], json_decode($taxDetail->freight_data, true));
        $this->assertSame(['method' => 'legacy'], json_decode($taxDetail->payment_data, true));
        $this->assertSame(['totais' => ['vNF' => 200], 'regime' => 'legacy'], json_decode($taxDetail->tax_data, true));
        $this->assertSame(['vNF' => 999], json_decode($taxDetail->tax_totals, true));
        $this->assertSame(['regime' => 'legacy'], json_decode($taxDetail->fiscal_metadata, true));

        $payload = DB::table('fiscal_document_payloads')
            ->where('fiscal_document_id', $documentId)
            ->first();

        $this->assertSame(['nfe' => ['id' => 'new']], json_decode($payload->nfe_payload, true));
        $this->assertSame(['nfse' => ['id' => 'legacy']], json_decode($payload->nfse_payload, true));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createFiscalDocument(Company $company, Partner $customer, array $attributes = []): int
    {
        return DB::table('fiscal_documents')->insertGetId([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'status' => Status::PENDING->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'freight_data' => isset($attributes['freight_data']) ? json_encode($attributes['freight_data']) : null,
            'payment_data' => isset($attributes['payment_data']) ? json_encode($attributes['payment_data']) : null,
            'tax_data' => isset($attributes['tax_data']) ? json_encode($attributes['tax_data']) : null,
            'nfe_payload' => isset($attributes['nfe_payload']) ? json_encode($attributes['nfe_payload']) : null,
            'nfse_payload' => isset($attributes['nfse_payload']) ? json_encode($attributes['nfse_payload']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: Company, 1: Partner}
     */
    private function makeScenario(): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa Fiscal',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente Fiscal',
            'document_type' => 'CNPJ',
            'document_number' => '22345678000188',
            'created_by' => $user->id,
        ]);

        return [$company, $customer];
    }
}
