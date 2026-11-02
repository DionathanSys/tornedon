<?php

namespace Tests\Feature;

use Tests\TestCase;

class OperationPwaTest extends TestCase
{
    public function test_worker_is_publicly_served_at_the_root_with_the_correct_scope(): void
    {
        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertHeader('Service-Worker-Allowed', '/')
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, public');

        $worker = file_get_contents(public_path('build/sw.js'));
        $this->assertStringContainsString('/build/assets/', $worker);
        $this->assertStringNotContainsString('importScripts(', $worker);
    }

    public function test_operation_manifest_covers_login_and_tenant_routes_with_valid_raster_icons(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest-operation.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/operation', $manifest['id']);
        $this->assertSame('/operation', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        foreach ([$manifest['start_url'], '/operation/login', '/operation/1'] as $path) {
            $this->assertStringStartsWith($manifest['scope'], $path);
        }
        foreach ($manifest['icons'] as $icon) {
            $image = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame($icon['type'], $image['mime']);
            $this->assertSame($icon['sizes'], $image[0].'x'.$image[1]);
        }
    }

    public function test_operation_login_includes_installation_and_other_panels_do_not(): void
    {
        $this->get('/operation/login')
            ->assertOk()
            ->assertSee('manifest-operation.webmanifest')
            ->assertSee('operationPwaInstall()', false)
            ->assertSee('Instalar aplicativo')
            ->assertSee('pwa-180.png')
            ->assertSee("register('/sw.js'", false);

        $this->get('/admin/login')->assertOk()->assertDontSee('operationPwaInstall()', false);
    }
}
