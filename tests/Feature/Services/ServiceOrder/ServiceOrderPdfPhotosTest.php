<?php

namespace Tests\Feature\Services\ServiceOrder;

use App\Enum\ServiceOrder\State;
use App\Enums\AttachmentType;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\ListServiceOrders;
use App\Filament\Mobile\Resources\MobileServiceOrders\Pages\EditMobileServiceOrder;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\ServiceOrder\Actions\PrintServiceOrderPdfAction;
use App\Services\ServiceOrder\ServiceOrderService;
use App\Services\ServiceOrder\Support\ServiceOrderPdfDataFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceOrderPdfPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_photos_are_optional_and_only_current_service_images_from_the_order_and_company_are_included(): void
    {
        $order = ServiceOrder::factory()->create();
        $this->attachment($order, 'servico.png');
        $this->attachment($order, 'contrato.png', ['type' => AttachmentType::CONTRACT]);
        $this->attachment($order, 'antiga.png', ['is_current' => false]);
        $this->attachment($order, 'excluida.png')->delete();
        $this->attachment($order, 'outra-empresa.png', ['company_id' => Company::factory()->create()->id]);
        $this->attachment(ServiceOrder::factory()->create(['company_id' => $order->company_id]), 'outra-ordem.png');
        $this->attachment($order, 'ausente.png');
        Storage::disk('local')->delete('photos/ausente.png');
        $this->attachment($order, 'invalida.png');
        Storage::disk('local')->put('photos/invalida.png', 'not an image');

        $formatter = app(ServiceOrderPdfDataFormatter::class);
        $this->assertSame([], $formatter->format($order)['service_photos']);
        $data = $formatter->format($order, true);
        $this->assertSame(['servico.png'], array_column($data['service_photos'], 'name'));
        $photo = $data['service_photos'][0];
        $this->assertStringStartsWith('data:image/png;base64,', $photo['src']);
        $this->assertLessThanOrEqual(680, $photo['width']);
        $this->assertLessThanOrEqual(800, $photo['height']);
    }

    public function test_pdf_renders_each_photo_on_an_additional_page_and_print_action_accepts_the_option(): void
    {
        $order = ServiceOrder::factory()->create();
        $this->attachment($order, 'primeira.png');
        $this->attachment($order, 'segunda.png');
        $formatter = app(ServiceOrderPdfDataFormatter::class);
        $plain = Pdf::loadView('pdf.service-order', ['record' => $order, 'pdfData' => $formatter->format($order)])->setPaper('a4');
        $plain->output();
        $withPhotos = Pdf::loadView('pdf.service-order', ['record' => $order, 'pdfData' => $formatter->format($order, true)])->setPaper('a4');
        $withPhotos->output();

        $this->assertSame(
            $plain->getDomPDF()->getCanvas()->get_page_count() + 2,
            $withPhotos->getDomPDF()->getCanvas()->get_page_count(),
        );
        $action = app(PrintServiceOrderPdfAction::class);
        $binary = $action->execute($order, true);
        $this->assertNotNull($binary, $action->getMessage() ?? '');
        $this->assertStringStartsWith('%PDF-', base64_decode($binary));
    }

    public function test_admin_preview_refreshes_when_optional_photos_are_enabled(): void
    {
        $this->assertPreviewOption('admin', EditServiceOrder::class);
    }

    public function test_mobile_preview_refreshes_when_optional_photos_are_enabled(): void
    {
        $this->assertPreviewOption('mobile', EditMobileServiceOrder::class);
    }

    public function test_download_passes_the_optional_choice_and_defaults_to_no_photos(): void
    {
        $order = $this->authenticateOrder();
        $service = $this->mock(ServiceOrderService::class);
        foreach ([false, true] as $includePhotos) {
            $service->shouldReceive('pdf')->once()
                ->withArgs(fn ($record, $userId, $option) => $record->is($order) && $userId === $order->created_by && $option === $includePhotos)
                ->andReturn(base64_encode('%PDF-test'));
        }

        $page = Livewire::test(EditServiceOrder::class, ['record' => $order->id]);
        $page->callAction('downloadServiceOrderPdf')->assertFileDownloaded('ordem-servico-'.$order->number.'.pdf');
        $page->callAction('downloadServiceOrderPdf', data: ['include_service_photos' => true])
            ->assertFileDownloaded('ordem-servico-'.$order->number.'.pdf');
    }

    public function test_detailed_bulk_export_passes_the_photo_choice_for_each_order(): void
    {
        $order = $this->authenticateOrder();
        $second = ServiceOrder::factory()->create(['company_id' => $order->company_id, 'created_by' => $order->created_by]);
        $formatter = app(ServiceOrderPdfDataFormatter::class);
        $mock = $this->mock(ServiceOrderPdfDataFormatter::class);
        foreach ([$order, $second] as $record) {
            $this->attachment($record, 'foto-'.$record->id.'.png');
            $mock->shouldReceive('format')->once()->withArgs(fn ($actual, $includePhotos) => $actual->is($record) && $includePhotos === true)
                ->andReturn($formatter->format($record, true));
        }

        Livewire::test(ListServiceOrders::class)
            ->set('activeTab', 'all')
            ->selectTableRecords([$order->id, $second->id])
            ->callAction(TestAction::make('exportSelectedDetailedServiceOrdersPdf')->table()->bulk(), data: ['include_service_photos' => true])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded();
    }

    private function authenticateOrder(): ServiceOrder
    {
        $user = User::factory()->create();
        $order = ServiceOrder::factory()->create(['created_by' => $user->id, 'status' => State::CLOSED]);
        $user->companies()->attach($order->company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($order->company);

        return $order;
    }

    private function assertPreviewOption(string $panel, string $page): void
    {
        $user = User::factory()->create();
        $order = ServiceOrder::factory()->create(['created_by' => $user->id]);
        $user->companies()->attach($order->company, ['role' => 'admin', 'is_active' => true]);
        $this->actingAs($user);
        Filament::setCurrentPanel($panel);
        Filament::setTenant($order->company);

        $service = $this->mock(ServiceOrderService::class);
        $service->shouldReceive('preview')->withArgs(fn ($record, $userId, $includePhotos) => $record->is($order) && $userId === $user->id && $includePhotos === false)
            ->atLeast()->once()->andReturn(['pdf' => base64_encode('plain')]);
        $service->shouldReceive('preview')->withArgs(fn ($record, $userId, $includePhotos) => $record->is($order) && $userId === $user->id && $includePhotos === true)
            ->atLeast()->once()->andReturn(['pdf' => base64_encode('photos')]);

        Livewire::test($page, ['record' => $order->id])
            ->mountAction('previewServiceOrderPdf')
            ->assertMountedActionModalSee('Incluir fotos do serviço no PDF')
            ->assertMountedActionModalSee('iframe', false)
            ->set('mountedActions.0.data.include_service_photos', true)
            ->assertMountedActionModalSee('iframe', false)
            ->assertHasNoErrors();
    }

    private function attachment(ServiceOrder $order, string $name, array $overrides = []): Attachment
    {
        $image = UploadedFile::fake()->image($name, 1600, 2400);
        Storage::disk('local')->put('photos/'.$name, $image->getContent());

        return $order->attachments()->create(array_merge([
            'company_id' => $order->company_id,
            'type' => AttachmentType::SERVICE_PHOTO,
            'version' => $order->attachments()->withTrashed()->count() + 1,
            'is_current' => true,
            'disk' => 'local',
            'path' => 'photos/'.$name,
            'original_name' => $name,
            'stored_name' => $name,
            'mime_type' => 'image/png',
            'size_bytes' => $image->getSize(),
        ], $overrides));
    }
}
