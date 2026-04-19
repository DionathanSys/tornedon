<?php

namespace Tests\Feature\Filament\Settings;

use App\Filament\Clusters\Settings\Pages\CompanySettings;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CompanySettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $compiledPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tornedon-views-test-' . Str::uuid();
        File::ensureDirectoryExists($compiledPath);

        config(['view.compiled' => $compiledPath]);

        $bladeCompiler = app('blade.compiler');
        $reflection = new \ReflectionClass($bladeCompiler);
        $cachePath = $reflection->getProperty('cachePath');
        $cachePath->setAccessible(true);
        $cachePath->setValue($bladeCompiler, $compiledPath);
    }

    public function test_company_settings_page_saves_uploaded_certificate_path_and_deletes_previous_file(): void
    {
        [, $company] = $this->createAuthenticatedTenant();

        Storage::disk('local')->put('certificates/' . $company->id . '/old_certificate.pfx', 'old');
        Storage::disk('local')->put('certificates/' . $company->id . '/new_certificate.p12', 'new');

        $company->update([
            'certificate' => 'certificates/' . $company->id . '/old_certificate.pfx',
        ]);

        Livewire::test(CompanySettings::class)
            ->set('data.certificate', ['certificates/' . $company->id . '/new_certificate.p12'])
            ->call('save');

        $this->assertSame(
            'certificates/' . $company->id . '/new_certificate.p12',
            $company->fresh()->certificate,
        );

        Storage::disk('local')->assertExists('certificates/' . $company->id . '/new_certificate.p12');
    }

    /**
     * @return array{User,Company}
     */
    private function createAuthenticatedTenant(): array
    {
        $user = User::factory()->create();

        $company = Company::query()->create([
            'name' => 'Empresa Config ' . Str::uuid(),
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'email' => 'empresa-config@example.com',
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
}
