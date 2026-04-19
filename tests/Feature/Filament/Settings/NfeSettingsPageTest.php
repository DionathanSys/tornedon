<?php

namespace Tests\Feature\Filament\Settings;

use App\Filament\Clusters\Settings\Pages\NfeSettingsPage;
use App\Models\Company;
use App\Models\CompanyPreference;
use App\Models\User;
use App\Services\Fiscal\Sefaz\CompanySefazCertificateService;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionResult;
use App\Services\Fiscal\Sefaz\SefazDfeDistributionService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class NfeSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $compiledPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tornedon-views-test-' . Str::uuid();
        File::ensureDirectoryExists($compiledPath);

        config(['view.compiled' => $compiledPath]);

        $bladeCompiler = app('blade.compiler');
        $reflection = new \ReflectionClass($bladeCompiler);
        $cachePath = $reflection->getProperty('cachePath');
        $cachePath->setAccessible(true);
        $cachePath->setValue($bladeCompiler, $compiledPath);
    }

    public function test_page_saves_sefaz_a1_password_preference(): void
    {
        [, $company] = $this->createAuthenticatedTenant();

        Livewire::test(NfeSettingsPage::class)
            ->set('data.sefaz_a1_password', 'senha-sefaz')
            ->call('save');

        $this->assertSame(
            'senha-sefaz',
            CompanyPreference::get(CompanySefazCertificateService::PASSWORD_PREFERENCE_KEY, $company->id),
        );
    }

    public function test_consultar_dfe_recebidas_action_saves_last_nsu_and_downloads_zip(): void
    {
        [, $company] = $this->createAuthenticatedTenant();

        $service = Mockery::mock(SefazDfeDistributionService::class);
        $service->shouldReceive('distribute')
            ->once()
            ->withArgs(function (Company $tenant, string $mode, string $value) use ($company): bool {
                return $tenant->is($company)
                    && $mode === 'ultimo_nsu'
                    && $value === '27';
            })
            ->andReturn(new DfeDistributionResult(
                success: true,
                statusCode: '138',
                statusMessage: 'Documentos localizados',
                ultNsu: '000000000000027',
                maxNsu: '000000000000050',
                rawXml: '<retDistDFeInt/>',
                documents: [
                    new DfeDistributionDocument(
                        nsu: '000000000000027',
                        schema: 'resNFe_v1.01.xsd',
                        xml: '<resNFe><chNFe>35260412345678000199550010000003211000000321</chNFe></resNFe>',
                        accessKey: '35260412345678000199550010000003211000000321',
                    ),
                ],
            ));

        $this->app->instance(SefazDfeDistributionService::class, $service);

        Livewire::test(NfeSettingsPage::class)
            ->mountAction('consultarDfeRecebidas')
            ->setActionData([
                'modo' => 'ultimo_nsu',
                'ultimo_nsu' => '27',
                'salvar_ultimo_nsu' => true,
            ])
            ->callMountedAction()
            ->assertFileDownloaded('dfe-recebidos.zip', contentType: 'application/zip')
            ->assertHasNoActionErrors();

        $this->assertSame('000000000000027', CompanyPreference::get('sefaz.distribuicao_dfe.ultimo_nsu', $company->id));
    }

    public function test_consultar_dfe_recebidas_action_handles_missing_certificate_gracefully(): void
    {
        [, $company] = $this->createAuthenticatedTenant();

        $service = Mockery::mock(SefazDfeDistributionService::class);
        $service->shouldReceive('distribute')
            ->once()
            ->andThrow(new \RuntimeException('Nenhum certificado A1 foi configurado para esta empresa.'));

        $this->app->instance(SefazDfeDistributionService::class, $service);

        Livewire::test(NfeSettingsPage::class)
            ->mountAction('consultarDfeRecebidas')
            ->setActionData([
                'modo' => 'ultimo_nsu',
                'ultimo_nsu' => '0',
                'salvar_ultimo_nsu' => true,
            ])
            ->callMountedAction()
            ->assertFileDownloaded('dfe-recebidos.zip')
            ->assertHasNoActionErrors();

        $this->assertNull(CompanyPreference::get('sefaz.distribuicao_dfe.ultimo_nsu', $company->id));
    }

    /**
     * @return array{User,Company}
     */
    private function createAuthenticatedTenant(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa SEFAZ ' . Str::uuid(),
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa-sefaz@example.com',
            'certificate' => 'certificados/empresa-a1.pfx',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $user->companies()->attach($company, [
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($company);

        return [$user, $company];
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
