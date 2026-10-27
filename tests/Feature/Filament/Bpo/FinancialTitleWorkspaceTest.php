<?php

namespace Tests\Feature\Filament\Bpo;

use App\Filament\Bpo\Resources\AccountPayableResource;
use App\Filament\Bpo\Resources\AccountPayables\Pages\ViewAccount as ViewPayable;
use App\Filament\Bpo\Resources\AccountPayables\RelationManagers\InstallmentsRelationManager as PayableInstallments;
use App\Filament\Bpo\Resources\AccountPayables\RelationManagers\PaymentsRelationManager as PayablePayments;
use App\Filament\Bpo\Resources\AccountReceivableResource;
use App\Filament\Bpo\Resources\AccountReceivables\Pages\ViewAccount as ViewReceivable;
use App\Filament\Bpo\Resources\AccountReceivables\RelationManagers\InstallmentsRelationManager as ReceivableInstallments;
use App\Filament\Bpo\Resources\AccountReceivables\RelationManagers\PaymentsRelationManager as ReceivablePayments;
use App\Livewire\Bpo\RecordAttachments;
use App\Models\AccountPayable;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use App\Services\Access\PermissionCatalog;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Attachments\AttachmentService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FinancialTitleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $user;

    private Company $company;

    private CompanyTeamService $teams;

    private int $engagementId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['attachments.default_disk' => 'local']);
        $this->owner = User::factory()->create();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
        $this->owner->companies()->attach($this->company, ['role' => 'owner', 'is_active' => true]);
        $this->teams = app(CompanyTeamService::class);
        $role = $this->teams->saveRole($this->owner, $this->company, [
            'name' => 'Financeiro', 'permissions' => PermissionCatalog::templates()['Financeiro'], 'panels' => ['bpo'],
        ]);
        $this->engagementId = $this->teams->authorizeBpo($this->owner, $this->company, $this->user, [$role->id], false, [])->id;
    }

    private function authenticate(): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('bpo'));
        Filament::setTenant($this->company);
    }

    private function title(bool $payable = true)
    {
        $service = app($payable ? AccountPayableService::class : AccountReceivableService::class);
        $record = $service->create(['company_id' => $this->company->id, 'manual_counterparty_name' => $payable ? 'Fornecedor do espaço financeiro' : 'Cliente do espaço financeiro',
            'description' => 'Documento financeiro do layout customizado', 'document_number' => 'DOC-2026',
            'due_date' => today()->toDateString(), 'due_amount' => 200, 'installment_count' => 2], $this->user->id);
        $this->assertNotNull($record, $service->getMessage());

        return $record;
    }

    private function widget($record, array $options = [])
    {
        return Livewire::test(RecordAttachments::class, ['module' => $record instanceof AccountPayable ? 'account_payables' : 'account_receivables', 'recordId' => $record->id, ...$options]);
    }

    public function test_both_account_types_render_the_custom_header_attachments_and_two_managers_without_tabs(): void
    {
        $payable = $this->title();
        $receivable = $this->title(false);
        $this->authenticate();
        foreach ([[$payable, AccountPayableResource::class, PayableInstallments::class, PayablePayments::class], [$receivable, AccountReceivableResource::class, ReceivableInstallments::class, ReceivablePayments::class]] as [$record, $resource, $installments, $payments]) {
            $response = $this->get($resource::getUrl('view', ['record' => $record], panel: 'bpo', tenant: $this->company))
                ->assertOk()->assertSee('bpo-account-top-grid', false)->assertSee('bpo-account-relationship-grid', false)
                ->assertSee('Anexos do lançamento')->assertSee('Saldo considerado')->assertSee('DOC-2026')
                ->assertSeeLivewire(RecordAttachments::class)->assertSeeLivewire($installments)->assertSeeLivewire($payments);
            $response->assertDontSee('relationManagerTabs');
        }
    }

    public function test_payments_and_receipts_keep_the_existing_financial_workflow_and_refresh_the_summary(): void
    {
        $records = [$this->title(), $this->title(false)];
        $account = FinancialAccount::create(['company_id' => $this->company->id, 'name' => 'Caixa do lançamento', 'type' => 'cash', 'is_active' => true, 'created_by' => $this->user->id]);
        $this->authenticate();
        foreach ($records as $record) {
            $payable = $record instanceof AccountPayable;
            $view = $payable ? ViewPayable::class : ViewReceivable::class;
            $installments = $payable ? PayableInstallments::class : ReceivableInstallments::class;
            $payments = $payable ? PayablePayments::class : ReceivablePayments::class;
            $page = Livewire::test($view, ['record' => $record->id]);
            $this->assertSame(200.0, $page->instance()->accountDetails()['balance']);
            Livewire::test($installments, ['ownerRecord' => $record, 'pageClass' => $view])
                ->callAction(TestAction::make('register_payment')->table($record->installments()->first()), data: [
                    'payment_date' => today()->toDateString(), 'amount' => '40,00', 'interest_amount' => '0,00', 'fine_amount' => '0,00', 'discount_amount' => '0,00',
                    'financial_account_id' => $account->id, 'description' => 'Baixa pela tela customizada',
                ])->assertHasNoActionErrors()->assertDispatched('refresh-payments')->assertDispatched('refresh-installments');
            $payment = $record->payments()->sole();
            $this->assertSame(40.0, (float) $payment->amount);
            Livewire::test($payments, ['ownerRecord' => $record, 'pageClass' => $view])->assertCanSeeTableRecords([$payment]);
            $page->dispatch('refresh-payments');
            $this->assertSame(160.0, $page->instance()->accountDetails()['balance']);
            $this->assertSame(40.0, $page->instance()->accountDetails()['settled']);
        }
        $this->assertDatabaseCount('cash_movements', 2);
    }

    public function test_attachment_widget_uploads_previews_edits_and_soft_deletes_owned_files(): void
    {
        $record = $this->title();
        $this->authenticate();
        $widget = $this->widget($record)->call('openUpload')->set('description', 'Comprovante inicial')
            ->set('files', [UploadedFile::fake()->create('comprovante.pdf', 2, 'application/pdf')])->call('uploadAttachments')->assertHasNoErrors();
        $attachment = $record->currentAttachments()->sole();
        Storage::disk('local')->assertExists($attachment->path);
        $widget->assertSee('comprovante.pdf')->assertSet('files', [])->call('viewAttachment', $attachment->id)->assertDispatched('open-modal')
            ->call('editDescription', $attachment->id)->set('editingDescription', 'Comprovante revisado')->call('saveDescription')->assertHasNoErrors();
        $this->assertSame('Comprovante revisado', $attachment->fresh()->description);
        $widget->call('deleteAttachment', $attachment->id)->assertDontSee('comprovante.pdf');
        $this->assertSoftDeleted('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_financial_attachment_routes_authorize_the_parent_without_a_filament_tenant_in_the_url(): void
    {
        $record = $this->title(false);
        $this->authenticate();
        $attachment = app(AttachmentService::class)->upload($record, UploadedFile::fake()->create('documento.pdf', 2, 'application/pdf'), 'generic');
        Filament::setTenant(null);
        Filament::setCurrentPanel(null);
        $this->get($attachment->url)->assertOk()->assertDownload('documento.pdf');
        $this->get($attachment->preview_url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->teams->endBpo($this->owner, $this->company, $this->engagementId);
        $this->get($attachment->url)->assertForbidden();
        $this->get($attachment->preview_url)->assertForbidden();
    }

    public function test_read_only_access_hides_mutations_and_rechecks_upload_requests(): void
    {
        $record = $this->title();
        $this->authenticate();
        $role = $this->teams->saveRole($this->owner, $this->company, ['name' => 'Consulta', 'permissions' => ['account_payables.view' => 'company'], 'panels' => ['bpo']]);
        $this->teams->assign($this->owner, $this->company, $this->user, [$role->id], $this->engagementId);
        $this->get(AccountPayableResource::getUrl('view', ['record' => $record], panel: 'bpo', tenant: $this->company))->assertOk()->assertDontSee('Editar lançamento');
        $this->widget($record)->assertSee('Consulta de documentos')->assertDontSee('Adicionar arquivos')->call('openUpload')->assertForbidden();
        $this->widget($record)->call('uploadAttachments')->assertForbidden();
        Livewire::test(PayableInstallments::class, ['ownerRecord' => $record, 'pageClass' => ViewPayable::class])
            ->assertCanSeeTableRecords($record->installments)->assertActionHidden(TestAction::make('register_payment')->table($record->installments->first()));
    }

    public function test_widget_cannot_operate_on_an_attachment_belonging_to_a_different_record(): void
    {
        $first = $this->title();
        $second = $this->title();
        $this->authenticate();
        $foreign = app(AttachmentService::class)->upload($second, UploadedFile::fake()->create('outra-conta.pdf', 2, 'application/pdf'), 'generic');
        $this->widget($first)->call('deleteAttachment', $foreign->id)->assertNotFound();
        $this->widget($first)->call('viewAttachment', $foreign->id)->assertNotFound();
        $this->assertNotSoftDeleted('attachments', ['id' => $foreign->id]);
    }

    public function test_upload_validates_type_mime_size_and_preserves_single_latest_versioning(): void
    {
        $record = $this->title();
        $this->authenticate();
        $this->widget($record, ['allowedTypes' => ['fiscal_document']])->set('attachmentType', 'generic')
            ->set('files', [UploadedFile::fake()->create('arquivo.txt', 1, 'text/plain')])->call('uploadAttachments')->assertHasErrors('attachmentType');
        $this->widget($record, ['allowedTypes' => ['fiscal_document']])->set('files', [UploadedFile::fake()->create('imagem.jpg', 1, 'image/jpeg')])
            ->call('uploadAttachments')->assertHasErrors('files.0');
        config(['attachments.types.fiscal_document.max_size' => 1]);
        $this->widget($record, ['allowedTypes' => ['fiscal_document']])->set('files', [UploadedFile::fake()->create('grande.pdf', 2, 'application/pdf')])
            ->call('uploadAttachments')->assertHasErrors('files.0');
        $this->assertDatabaseCount('attachments', 0);
        $widget = $this->widget($record, ['heading' => 'Documentos contratuais', 'allowedTypes' => ['contract']])->assertSee('Documentos contratuais');
        $widget->set('files', [UploadedFile::fake()->create('contrato-v1.pdf', 1, 'application/pdf')])->call('uploadAttachments')->assertHasNoErrors();
        $widget->set('files', [UploadedFile::fake()->create('contrato-v2.pdf', 1, 'application/pdf')])->call('uploadAttachments')->assertHasNoErrors();
        $this->assertSame(2, $record->attachments()->count());
        $this->assertSame('contrato-v2.pdf', $record->currentAttachments()->sole()->original_name);
        $this->assertSame(2, $record->currentAttachments()->sole()->version);
    }

    public function test_disabled_widget_mutations_and_revoked_components_fail_closed(): void
    {
        $record = $this->title();
        $this->authenticate();
        $attachment = app(AttachmentService::class)->upload($record, UploadedFile::fake()->create('evidencia.pdf', 1, 'application/pdf'), 'generic');
        $this->widget($record, ['uploadsEnabled' => false, 'deletionsEnabled' => false])->assertDontSee('Adicionar arquivos')
            ->call('deleteAttachment', $attachment->id)->assertForbidden();
        $widget = $this->widget($record);
        $relation = Livewire::test(PayablePayments::class, ['ownerRecord' => $record, 'pageClass' => ViewPayable::class]);
        $this->teams->endBpo($this->owner, $this->company, $this->engagementId);
        $widget->call('viewAttachment', $attachment->id)->assertForbidden();
        $relation->call('$refresh')->assertForbidden();
    }
}
