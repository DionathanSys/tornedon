<?php

namespace Tests\Feature\Filament\Bpo;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Filament\Bpo\Pages\FiscalDocuments;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\Actions\ExportOutputFiscalDocumentsAction;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPayload;
use App\Models\FiscalDocumentXmlExport;
use App\Models\Partner;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use App\Services\FiscalDocument\Actions\ReconcileNfseRpsSequenceAction;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class FiscalDocumentsWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $user;

    private Company $company;

    private int $engagementId;

    private int $roleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
        $this->owner->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $teams = app(CompanyTeamService::class);
        $role = $teams->saveRole($this->owner, $this->company, [
            'name' => 'Fiscal BPO', 'panels' => ['bpo'], 'permissions' => ['fiscal_documents.view' => 'company'],
        ]);
        $this->roleId = $role->id;
        $this->engagementId = $teams->authorizeBpo($this->owner, $this->company, $this->user, [$role->id], false, [])->id;
    }

    private function permissions(array $permissions): void
    {
        $teams = app(CompanyTeamService::class);
        $role = $teams->saveRole($this->owner, $this->company, [
            'name' => 'Fiscal BPO', 'panels' => ['bpo'], 'permissions' => ['fiscal_documents.view' => 'company', ...$permissions],
        ], $this->roleId);
        $teams->assign($this->owner, $this->company, $this->user, [$role->id], $this->engagementId);
    }

    private function authenticate(): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('bpo'));
        Filament::setTenant($this->company);
    }

    private function document(array $data = []): FiscalDocument
    {
        return FiscalDocument::create([
            'company_id' => $this->company->id,
            'customer_id' => Partner::create(['name' => 'Parceiro fiscal', 'document_type' => 'CNPJ', 'document_number' => fake()->numerify('##############')])->id,
            'document_type' => 'nfe', 'operation_type' => '1', 'status' => Status::PENDING,
            'issued_at' => today(), 'movement_at' => today(), 'created_by' => $this->user->id,
            'nfe_status' => NfeStatus::PENDING,
            ...$data,
        ]);
    }

    public function test_filters_and_search_keep_tenant_and_document_type_boundaries(): void
    {
        $nfe = $this->document(['document_number' => '100', 'document_key' => 'CHAVE-PESQUISAVEL', 'nfe_status' => NfeStatus::REJECTED]);
        $nfse = $this->document(['document_number' => '200', 'document_type' => 'nfse', 'nfse_status' => NfeStatus::AUTHORIZED, 'nfe_status' => NfeStatus::REJECTED]);
        $pending = $this->document(['nfe_status' => null]);
        $foreign = $this->document(['company_id' => Company::factory()->create()->id]);
        $this->authenticate();

        Livewire::test(FiscalDocuments::class)->assertCanSeeTableRecords([$nfe, $nfse, $pending])->assertCanNotSeeTableRecords([$foreign])
            ->filterTable('emission_status', [NfeStatus::REJECTED->value])->assertCanSeeTableRecords([$nfe])->assertCanNotSeeTableRecords([$nfse, $pending])
            ->resetTableFilters()->filterTable('emission_status', [NfeStatus::PENDING->value])->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$nfe, $nfse])
            ->resetTableFilters()->searchTable('CHAVE-PESQUISAVEL')->assertCanSeeTableRecords([$nfe])->assertCanNotSeeTableRecords([$nfse, $pending]);
    }

    public function test_listing_does_not_repeat_authorization_queries_for_every_row_action(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->document(['nfe_status' => NfeStatus::AUTHORIZED]);
        }
        $this->permissions(['fiscal_documents.update' => 'company', 'fiscal_documents.issue' => 'company', 'fiscal_documents.cancel' => 'company']);
        $this->authenticate();
        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::test(FiscalDocuments::class)->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $counts = collect($queries)->countBy(fn (array $query) => $query['query'])->sortDesc()->take(8)->all();
        $this->assertLessThan(250, count($queries), json_encode(['total' => count($queries), 'repeated' => $counts], JSON_PRETTY_PRINT));
    }

    public function test_details_load_emission_errors_only_when_opened(): void
    {
        $record = $this->document(['errors_messages' => [['mensagem' => 'Retorno fiscal detalhado']]]);
        $this->authenticate();
        $page = Livewire::test(FiscalDocuments::class);
        $this->assertArrayNotHasKey('errors_messages', $page->instance()->getTableRecords()->first()->getAttributes());

        $page->mountAction(TestAction::make('details')->table($record))->assertActionMounted(TestAction::make('details')->table($record));
        $this->assertSame($record->errors_messages, $page->instance()->getMountedAction()->getRecord()->errors_messages);
    }

    public function test_correction_download_visibility_does_not_load_full_payloads_in_the_listing(): void
    {
        $withCorrection = $this->document(['nfe_status' => NfeStatus::AUTHORIZED]);
        $withoutCorrection = $this->document(['nfe_status' => NfeStatus::AUTHORIZED]);
        FiscalDocumentPayload::create(['company_id' => $this->company->id, 'fiscal_document_id' => $withCorrection->id, 'nfe_payload' => ['correcoes' => [['sequencial' => 1, 'pdf_base64' => base64_encode('%PDF-1.4')]]]]);
        $this->authenticate();
        $page = Livewire::test(FiscalDocuments::class)
            ->assertActionVisible(TestAction::make('baixar_carta_correcao_nfe')->table($withCorrection))
            ->assertActionHidden(TestAction::make('baixar_carta_correcao_nfe')->table($withoutCorrection));
        foreach ($page->instance()->getTableRecords() as $record) {
            $this->assertFalse($record->relationLoaded('payload'));
        }
    }

    public function test_request_local_permissions_preserve_own_scope_and_are_reloaded_after_revocation(): void
    {
        $own = $this->document(['nfe_status' => NfeStatus::AUTHORIZED]);
        $other = $this->document(['nfe_status' => NfeStatus::AUTHORIZED, 'created_by' => $this->owner->id]);
        $this->permissions(['fiscal_documents.cancel' => 'own']);
        $this->authenticate();
        $page = Livewire::test(FiscalDocuments::class)->assertActionVisible(TestAction::make('cancelar_nfe')->table($own))
            ->assertActionHidden(TestAction::make('cancelar_nfe')->table($other));
        $this->permissions([]);
        $page->call('$refresh')->assertActionHidden(TestAction::make('cancelar_nfe')->table($own));
    }

    public function test_read_only_preview_has_no_emission_submit_and_mutations_are_hidden(): void
    {
        $record = $this->document();
        $this->authenticate();
        $this->mock(NfeDocumentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('preview')->once()->andReturn(['pdf' => base64_encode('%PDF-1.4')]);
            $mock->shouldNotReceive('emitir');
        });

        $page = Livewire::test(FiscalDocuments::class)
            ->assertActionHidden(TestAction::make('emitir')->table($record))
            ->assertActionHidden(TestAction::make('exportOutputFiscalDocuments')->table())
            ->mountAction(TestAction::make('preview')->table($record));

        $this->assertNull($page->instance()->getMountedAction()->getModalSubmitAction());
        $this->assertNull($page->instance()->getMountedAction()->getActionFunction());
    }

    public function test_external_consultation_uses_the_shared_service_with_the_authenticated_actor(): void
    {
        $record = $this->document(['nfe_status' => NfeStatus::IN_PROCESSING]);
        $this->permissions(['fiscal_documents.update' => 'company']);
        $this->authenticate();
        $this->mock(NfeDocumentService::class, function (MockInterface $mock) use ($record): void {
            $mock->shouldReceive('consultar')->once()->withArgs(fn (FiscalDocument $document, int $actor) => $document->is($record) && $actor === $this->user->id)->andReturn(true);
            $mock->shouldReceive('isSuccess')->andReturn(true);
            $mock->shouldReceive('getMessage')->andReturn('Consulta concluída');
        });

        Livewire::test(FiscalDocuments::class)->callAction(TestAction::make('consultar')->table($record))->assertHasNoActionErrors();
    }

    public function test_emission_and_cancellation_require_their_own_permissions(): void
    {
        $pending = $this->document();
        $authorized = $this->document(['nfe_status' => NfeStatus::AUTHORIZED]);
        $this->permissions(['fiscal_documents.update' => 'company']);
        $this->authenticate();
        Livewire::test(FiscalDocuments::class)->assertActionHidden(TestAction::make('emitir')->table($pending))
            ->assertActionHidden(TestAction::make('cancelar_nfe')->table($authorized));

        $this->permissions(['fiscal_documents.issue' => 'company', 'fiscal_documents.cancel' => 'company']);
        Livewire::test(FiscalDocuments::class)->assertActionVisible(TestAction::make('emitir')->table($pending))
            ->assertActionVisible(TestAction::make('cancelar_nfe')->table($authorized));
    }

    public function test_incoming_documents_do_not_offer_outgoing_fiscal_mutations(): void
    {
        $record = $this->document(['operation_type' => '0', 'nfe_status' => NfeStatus::AUTHORIZED]);
        $this->permissions(['fiscal_documents.issue' => 'company', 'fiscal_documents.update' => 'company', 'fiscal_documents.cancel' => 'company']);
        $this->authenticate();
        Livewire::test(FiscalDocuments::class)->assertActionHidden(TestAction::make('cancelar_nfe')->table($record))
            ->assertActionHidden(TestAction::make('corrigir_nfe')->table($record));
    }

    public function test_authorized_documents_download_through_the_existing_services(): void
    {
        $nfe = $this->document(['nfe_status' => NfeStatus::AUTHORIZED, 'document_number' => '101']);
        $nfse = $this->document(['document_type' => 'nfse', 'nfse_status' => NfeStatus::AUTHORIZED, 'document_number' => '202']);
        $this->authenticate();
        $this->mock(NfeDocumentService::class, fn (MockInterface $mock) => $mock->shouldReceive('danfe')->once()
            ->withArgs(fn (FiscalDocument $document, int $actor) => $document->is($nfe) && $actor === $this->user->id)
            ->andReturn(base64_encode('%PDF-1.4 DANFE')));
        $this->mock(NfseDocumentService::class, fn (MockInterface $mock) => $mock->shouldReceive('pdf')->once()
            ->withArgs(fn (FiscalDocument $document, int $actor) => $document->is($nfse) && $actor === $this->user->id)
            ->andReturn(base64_encode('%PDF-1.4 NFSE')));

        Livewire::test(FiscalDocuments::class)->callAction(TestAction::make('danfe')->table($nfe))->assertFileDownloaded('DANFE-101.pdf');
        Livewire::test(FiscalDocuments::class)->callAction(TestAction::make('pdf_nfse')->table($nfse))->assertFileDownloaded('NFSE-202.pdf');
    }

    public function test_rps_reconciliation_validates_the_reason_and_uses_the_existing_action(): void
    {
        $record = $this->document(['document_type' => 'nfse', 'nfse_status' => NfeStatus::RPS_RECONCILIATION_PENDING]);
        $this->permissions(['fiscal_documents.update' => 'company']);
        $this->authenticate();
        $this->mock(ReconcileNfseRpsSequenceAction::class, function (MockInterface $mock) use ($record): void {
            $mock->shouldReceive('execute')->once()->withArgs(fn (FiscalDocument $document, string $reason, bool $clear) => $document->is($record) && strlen($reason) >= 15 && $clear)->andReturn(true);
            $mock->shouldReceive('isSuccess')->andReturn(true);
        });

        Livewire::test(FiscalDocuments::class)->callAction(TestAction::make('reconciliar_rps_nfse')->table($record), data: ['reason' => 'Curto', 'resolution' => 'clear'])
            ->assertHasActionErrors(['reason' => 'min']);
        Livewire::test(FiscalDocuments::class)->callAction(TestAction::make('reconciliar_rps_nfse')->table($record), data: ['reason' => 'Conferido o retorno antes de liberar novo envio.', 'resolution' => 'clear'])
            ->assertHasNoActionErrors();
    }

    public function test_export_respects_its_own_record_scope_in_addition_to_view_scope(): void
    {
        $own = $this->document(['status' => Status::CONFIRMED]);
        $other = $this->document(['status' => Status::CONFIRMED, 'created_by' => $this->owner->id]);
        $this->permissions(['fiscal_documents.export' => 'own']);
        $this->authenticate();
        $records = ExportOutputFiscalDocumentsAction::getRecords(['date_range' => today()->format('d/m/Y').' - '.today()->format('d/m/Y')]);
        $this->assertSame([$own->id], $records->modelKeys());
    }

    public function test_revoked_engagement_blocks_an_already_mounted_workspace(): void
    {
        $this->document();
        $this->authenticate();
        $page = Livewire::test(FiscalDocuments::class);
        app(CompanyTeamService::class)->endBpo($this->owner, $this->company, $this->engagementId);
        $page->call('$refresh')->assertForbidden();
    }

    public function test_xml_export_history_only_shows_the_current_actors_exports_in_the_current_company(): void
    {
        $data = ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'status' => 'completed',
            'starts_at' => today(), 'ends_at' => today(), 'date_column' => 'issued_at', 'total_documents' => 2,
            'successful_documents' => 2, 'zip_path' => 'exports/test.zip', 'download_token' => 'my-export-token', 'download_expires_at' => now()->addDay()];
        $own = FiscalDocumentXmlExport::create($data);
        $otherActor = FiscalDocumentXmlExport::create([...$data, 'user_id' => $this->owner->id, 'download_token' => 'other-actor-token']);
        $otherCompany = FiscalDocumentXmlExport::create([...$data, 'company_id' => Company::factory()->create()->id, 'download_token' => 'other-company-token']);
        $this->permissions(['fiscal_documents.export' => 'company']);
        $this->authenticate();

        $page = Livewire::test(FiscalDocuments::class)->mountAction(TestAction::make('xml_exports')->table())
            ->assertActionMounted(TestAction::make('xml_exports')->table());
        $content = $page->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString($own->download_token, $content);
        $this->assertStringNotContainsString($otherActor->download_token, $content);
        $this->assertStringNotContainsString($otherCompany->download_token, $content);
    }
}
