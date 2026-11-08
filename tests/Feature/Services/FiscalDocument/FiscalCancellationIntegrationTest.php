<?php

namespace Tests\Feature\Services\FiscalDocument;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Partner;
use App\Services\AccountReceivable\AccountReceivableGenerationService;
use App\Services\Fiscal\IntegranotasRateLimiter;
use App\Services\Fiscal\NfeConfigService;
use App\Services\Fiscal\NfseConfigService;
use App\Services\FiscalDocument\Actions\CancelNfeAction;
use App\Services\FiscalDocument\Actions\CancelNfseAction;
use App\Services\FiscalDocument\Actions\ConsultNfeAction;
use App\Services\FiscalDocument\Actions\StoreFiscalDocumentAttachmentsAction;
use App\Services\FiscalDocument\FiscalDocumentIssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class FiscalCancellationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sefaz_refusal_registers_treatment_without_canceling_document(): void
    {
        $document = $this->document();
        $this->mock(NfeConfigService::class)->shouldReceive('buildSdkParams')->once()->andReturn(['token' => 'test', 'ambiente' => 2, 'options' => []]);
        Mockery::mock('overload:CloudDfe\SdkPHP\Nfe')->shouldReceive('cancela')->once()->andReturn((object) ['sucesso' => false, 'mensagem' => 'Cancelamento não permitido pelo órgão emissor']);
        $this->assertFalse(app(CancelNfeAction::class)->execute($document, 'Cancelamento solicitado pela empresa'));
        $this->assertSame(NfeStatus::AUTHORIZED, $document->fresh()->nfe_status);
        $this->assertSame('cancellation_refused', $document->fiscalIssues()->sole()->reason);
        $this->assertSame('open', $document->fiscalIssues()->sole()->status);
    }

    public function test_nfse_can_be_cancelled_after_its_emission_month_when_provider_accepts(): void
    {
        $document = $this->document('nfse');
        $document->update(['issued_at' => today()->subMonths(3), 'authorized_at' => now()->subMonths(3)]);
        $this->mock(NfseConfigService::class)->shouldReceive('buildSdkParams')->once()->andReturn(['token' => 'test', 'ambiente' => 2, 'options' => []]);
        Mockery::mock('overload:CloudDfe\SdkPHP\Nfse')->shouldReceive('cancela')->once()->andReturn((object) ['sucesso' => true, 'mensagem' => 'Cancelada']);
        $action = app(CancelNfseAction::class);
        $this->assertTrue($action->execute($document, '9', 'Cancelamento solicitado pela empresa'), (string) $action->getMessage());
        $this->assertTrue($document->fresh()->isFiscalCancellationConfirmed());
        $this->assertTrue($document->fresh()->canceled);
    }

    public function test_nfse_uncertain_result_requires_consultation_before_retry(): void
    {
        $document = $this->document('nfse');
        $this->mock(NfseConfigService::class)->shouldReceive('buildSdkParams')->once()->andReturn(['token' => 'test', 'ambiente' => 2, 'options' => []]);
        Mockery::mock('overload:CloudDfe\SdkPHP\Nfse')->shouldReceive('cancela')->once()->andThrow(new \RuntimeException('Timeout'));
        $this->assertFalse(app(CancelNfseAction::class)->execute($document, '9', 'Cancelamento solicitado pela empresa'));
        $this->assertSame('awaiting_confirmation', $document->fiscalIssues()->sole()->status);
        $action = new CancelNfseAction;
        $this->assertFalse($action->execute($document, '9', 'Cancelamento solicitado pela empresa'));
        $this->assertStringContainsString('Consulte', $action->getMessage());
        $this->assertSame(NfeStatus::AUTHORIZED, $document->fresh()->nfse_status);
    }

    public function test_consultation_does_not_extend_nfe_deadline_and_reconciles_an_uncertain_cancellation(): void
    {
        $document = $this->document();
        $document->update(['authorized_at' => now()->subDays(2)->startOfSecond()]);
        $time = $document->authorized_at;
        $issue = app(FiscalDocumentIssueService::class)->open($document, 'cancellation_uncertain', 'Resultado do cancelamento desconhecido');
        $config = $this->mock(NfeConfigService::class);
        $config->shouldReceive('buildSdkParams')->andReturn(['token' => 'test', 'ambiente' => 2, 'options' => []]);
        $config->shouldReceive('resolveToken')->andReturn('test');
        Mockery::mock('overload:CloudDfe\SdkPHP\Nfe');
        $this->mock(IntegranotasRateLimiter::class)->shouldReceive('run')->once()->andReturn((object) ['sucesso' => true, 'status' => 'autorizada', 'data_hora_autorizacao' => now()->toIso8601String()]);
        $this->mock(StoreFiscalDocumentAttachmentsAction::class)->shouldReceive('execute')->andReturnTrue();
        $this->mock(AccountReceivableGenerationService::class)->shouldReceive('generateFromFiscalDocument')->andReturnTrue();
        $action = app(ConsultNfeAction::class);
        $this->assertTrue($action->execute($document), (string) $action->getMessage());
        $this->assertTrue($time->equalTo($document->fresh()->authorized_at));
        $this->assertSame('open', $issue->fresh()->status);
        $this->assertSame('cancellation_not_confirmed', $issue->fresh()->reason);
    }

    private function document(string $type = 'nfe'): FiscalDocument
    {
        return FiscalDocument::create([
            'company_id' => Company::factory()->create()->id, 'customer_id' => Partner::factory()->create()->id,
            'document_type' => $type, 'status' => Status::CONFIRMED, 'issued_at' => today(), 'movement_at' => today(),
            'document_key' => fake()->numerify('############################################'),
            $type === 'nfe' ? 'nfe_status' : 'nfse_status' => NfeStatus::AUTHORIZED,
            'authorized_at' => now(), 'nfse_model' => 'municipal',
        ]);
    }
}
