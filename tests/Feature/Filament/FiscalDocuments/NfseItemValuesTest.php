<?php

namespace Tests\Feature\Filament\FiscalDocuments;

use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use Tests\TestCase;

class NfseItemValuesTest extends TestCase
{
    public function test_fixed_discount_survives_price_blur_and_save_and_reopen(): void
    {
        [$component, $form] = $this->makeForm();
        $form->fill(['quantity' => 1, 'unit_price' => 300, 'discount_amount' => 0, 'total_price' => 300]);

        $component->data['discount_amount'] = '170,00';
        $form->getComponentByStatePath('discount_amount')->callAfterStateUpdated();

        $this->assertSame('56,67', $component->data['discount_percentage']);
        $this->assertSame('130,00', $component->data['total_price']);

        // Money envia o preço novamente ao perder o foco, mesmo sem mudar o valor.
        $form->getComponentByStatePath('unit_price')->callAfterStateUpdated();

        $this->assertSame('170,00', $component->data['discount_amount']);
        $this->assertSame('130,00', $component->data['total_price']);

        $form->getComponentByStatePath('discount_percentage')->callAfterStateUpdated();
        $this->assertSame('170,00', $component->data['discount_amount']);
        $this->assertSame('130,00', $component->data['total_price']);

        $state = $form->getState();
        $this->assertSame(300.0, (float) $state['total_price']);
        $this->assertSame(170.0, (float) $state['discount_amount']);

        // O item fiscal persiste o valor do desconto, mas não o percentual.
        unset($state['discount_percentage']);
        [$reopened, $reopenedForm] = $this->makeForm();
        $reopenedForm->fill($state);

        $this->assertSame('130,00', $reopened->data['total_price']);
        $this->assertSame('56,67', $reopened->data['discount_percentage']);
        $this->assertSame(300.0, (float) $reopenedForm->getState()['total_price']);
    }

    public function test_reopening_a_draft_with_a_previously_saved_net_total_rebuilds_the_gross_total(): void
    {
        [$component, $form] = $this->makeForm();
        $form->fill(['quantity' => 1, 'unit_price' => 300, 'discount_amount' => 170, 'total_price' => 130]);

        $this->assertSame('130,00', $component->data['total_price']);
        $this->assertSame(300.0, (float) $form->getState()['total_price']);
    }

    public function test_percentage_input_still_calculates_the_discount(): void
    {
        [$component, $form] = $this->makeForm();
        $form->fill(['quantity' => 1, 'unit_price' => 300, 'discount_amount' => 0, 'total_price' => 300]);
        $component->data['discount_percentage'] = '10,00';
        $form->getComponentByStatePath('discount_percentage')->callAfterStateUpdated();

        $this->assertSame('30,00', $component->data['discount_amount']);
        $this->assertSame('270,00', $component->data['total_price']);
        $this->assertSame(300.0, (float) $form->getState()['total_price']);
    }

    public function test_shared_commercial_form_keeps_its_existing_net_total_behavior(): void
    {
        [$component, $form] = $this->makeForm(persistGrossTotal: false);
        $form->fill(['quantity' => 1, 'unit_price' => 300, 'discount_amount' => 170, 'total_price' => 130]);

        $this->assertSame('130,00', $component->data['total_price']);
        $this->assertSame(130.0, (float) $form->getState()['total_price']);
    }

    private function makeForm(bool $persistGrossTotal = true): array
    {
        $component = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;

            public array $data = [];
        };

        $form = Schema::make($component)
            ->statePath('data')
            ->components([ItemValueGroup::make([
                'totalAmountField' => 'total_price',
                'persistGrossTotal' => $persistGrossTotal,
            ])]);

        return [$component, $form];
    }
}
