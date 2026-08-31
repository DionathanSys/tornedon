<?php

namespace Tests\Feature\Services\ServiceOrder;

use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Models\Company;
use App\Models\Partner;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSignatureLink;
use App\Models\User;
use App\Services\ServiceOrder\ServiceOrderSignatureLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PublicServiceOrderSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_link_does_not_expose_order_id_and_displays_order_data(): void
    {
        [$user, $serviceOrder] = $this->createServiceOrder();
        $service = app(ServiceOrderSignatureLinkService::class);
        $generated = $service->create($serviceOrder, $user->id);

        $path = parse_url($generated['url'], PHP_URL_PATH);

        $this->assertMatchesRegularExpression('#^/assinar-os/[a-f0-9]{64}$#', $path);
        $this->assertStringNotContainsString('/'.$serviceOrder->id.'/', $path);
        $this->assertDatabaseHas('service_order_signature_links', [
            'service_order_id' => $serviceOrder->id,
            'token_hash' => hash('sha256', $generated['token']),
        ]);
        $this->assertStringNotContainsString($generated['token'], (string) ServiceOrderSignatureLink::query()->value('token_hash'));

        $response = $this->get($generated['url']);

        $response->assertOk()
            ->assertSee($serviceOrder->number)
            ->assertSee('Cliente da OS')
            ->assertDontSee('Observação interna que nunca deve aparecer');
    }

    public function test_public_signature_records_signer_and_invalidates_the_link(): void
    {
        [$user, $serviceOrder] = $this->createServiceOrder();
        $generated = app(ServiceOrderSignatureLinkService::class)->create($serviceOrder, $user->id);
        $signature = 'data:image/png;base64,'.base64_encode('signature');

        $response = $this->post($generated['url'], [
            'customer_signed_by_name' => 'Maria da Silva',
            'customer_signature' => $signature,
            'agreement' => '1',
        ]);

        $response->assertOk()
            ->assertSee('Ordem de serviço assinada')
            ->assertSee('Maria da Silva');

        $serviceOrder->refresh();
        $link = ServiceOrderSignatureLink::query()->firstOrFail();

        $this->assertSame($signature, $serviceOrder->customer_signature);
        $this->assertSame('Maria da Silva', $serviceOrder->customer_signed_by_name);
        $this->assertNotNull($serviceOrder->customer_signed_at);
        $this->assertSame('public_signature_link', $serviceOrder->customer_signature_metadata['channel']);
        $this->assertNotNull($link->used_at);
        $this->assertDatabaseHas('audit_entries', [
            'auditable_type' => 'service_order',
            'auditable_id' => $serviceOrder->id,
            'event' => 'service_order.customer_signed',
            'source' => 'public',
        ]);
    }

    public function test_expired_link_cannot_be_used(): void
    {
        [$user, $serviceOrder] = $this->createServiceOrder();
        $generated = app(ServiceOrderSignatureLinkService::class)->create($serviceOrder, $user->id);

        ServiceOrderSignatureLink::query()->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->get($generated['url'])
            ->assertStatus(410)
            ->assertSee('Link indisponível');
    }

    public function test_generating_a_new_link_revokes_the_previous_one(): void
    {
        [$user, $serviceOrder] = $this->createServiceOrder();
        $service = app(ServiceOrderSignatureLinkService::class);
        $first = $service->create($serviceOrder, $user->id);
        $second = $service->create($serviceOrder, $user->id);

        $this->get($first['url'])
            ->assertStatus(410)
            ->assertSee('Link indisponível');
        $this->get($second['url'])->assertOk();

        $this->assertDatabaseHas('service_order_signature_links', [
            'token_hash' => hash('sha256', $first['token']),
        ]);
        $this->assertNotNull(ServiceOrderSignatureLink::query()
            ->where('token_hash', hash('sha256', $first['token']))
            ->value('revoked_at'));
    }

    public function test_cancelled_order_cannot_be_signed(): void
    {
        [$user, $serviceOrder] = $this->createServiceOrder();
        $generated = app(ServiceOrderSignatureLinkService::class)->create($serviceOrder, $user->id);

        $serviceOrder->update(['status' => State::CANCELLED]);

        $this->get($generated['url'])
            ->assertStatus(410)
            ->assertSee('Link indisponível');
    }

    public function test_a_second_signature_attempt_is_rejected(): void
    {
        [$user, $serviceOrder] = $this->createServiceOrder();
        $generated = app(ServiceOrderSignatureLinkService::class)->create($serviceOrder, $user->id);
        $payload = [
            'customer_signed_by_name' => 'Maria da Silva',
            'customer_signature' => 'data:image/png;base64,'.base64_encode('signature'),
            'agreement' => '1',
        ];

        $this->post($generated['url'], $payload)->assertOk();

        $this->post($generated['url'], [
            ...$payload,
            'customer_signed_by_name' => 'Outra Pessoa',
        ])
            ->assertStatus(409)
            ->assertSee('Ordem de serviço assinada')
            ->assertSee('Maria da Silva')
            ->assertDontSee('Outra Pessoa');
    }

    private function createServiceOrder(): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Empresa da OS',
            'document_number' => '12345678000199',
            'address' => ['city' => 'Sao Paulo', 'state' => 'SP'],
            'created_by' => $user->id,
        ]);
        $customer = Partner::query()->create([
            'name' => 'Cliente da OS',
            'document_type' => 'CPF',
            'document_number' => '12345678901',
            'created_by' => $user->id,
        ]);
        $serviceOrder = ServiceOrder::query()->create([
            'number' => 'OS-PUBLIC-SIGNATURE-001',
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'status' => State::CLOSED,
            'priority' => Priority::NORMAL,
            'type' => Type::MAINTENANCE,
            'internal_observations' => 'Observação interna que nunca deve aparecer',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $serviceOrder];
    }
}
