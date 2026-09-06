<?php

namespace Tests\Feature\Filament\Settings;

use App\Filament\Clusters\Settings\Pages\CompanySettings;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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

    public function test_existing_logo_preview_uses_the_authenticated_application_url(): void
    {
        [, $company] = $this->createAuthenticatedTenant();
        $path = 'logos/'.$company->id.'/logo.png';

        Storage::fake('r2');
        config(['uploads.logo_disk' => 'r2']);
        Storage::disk('r2')->put($path, 'logo');
        $company->update(['logo_path' => $path]);

        $livewire = Livewire::test(CompanySettings::class);
        $fileUpload = $livewire->instance()->getForm('form')->getComponent('logo_path');
        $uploadedFile = array_values($fileUpload->getUploadedFiles())[0];

        $this->assertStringStartsWith('/companies/'.$company->id.'/logo', $uploadedFile['url']);
        $this->get($uploadedFile['url'])->assertOk();
    }

    public function test_company_logo_preview_requires_membership_in_the_company(): void
    {
        [, $company] = $this->createAuthenticatedTenant();
        $path = 'logos/'.$company->id.'/logo.png';

        Storage::fake('r2');
        config(['uploads.logo_disk' => 'r2']);
        Storage::disk('r2')->put($path, 'logo');
        $company->update(['logo_path' => $path]);

        $url = URL::temporarySignedRoute(
            'companies.logo',
            now()->addMinutes(5),
            ['company' => $company],
            absolute: false,
        );
        $this->actingAs(User::factory()->create());

        $this->get($url)->assertForbidden();
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
