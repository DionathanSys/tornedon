<?php

namespace App\Livewire;

use App\Enum\Financial\PixChargeStatus;
use App\Enum\ServiceOrder\State;
use App\Filament\Operation\OperationModules;
use App\Models\PixCharge;
use App\Models\ServiceOrder;
use App\Services\Financial\Pix\PixChargePublicLinkService;
use App\Support\ServiceOrderPixPayment;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class OperationServiceOrderPix extends Component
{
    #[Locked]
    public int $orderId;

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
    }

    private function order(): ServiceOrder
    {
        abort_unless(Filament::getCurrentPanel()?->getId() === 'operation' && OperationModules::allows('service_orders'), 403);

        $order = ServiceOrder::query()->where('company_id', Filament::getTenant()?->getKey())->find($this->orderId);
        abort_unless($order, 404);

        return $order;
    }

    private function charge(ServiceOrder $order): ?PixCharge
    {
        return PixCharge::query()->where('company_id', $order->company_id)->where('service_order_id', $order->id)->latest('id')->first();
    }

    #[On('service-order-pix-updated')]
    public function refreshPayment(): void
    {
        $this->order();
    }

    public function render(): View
    {
        $order = $this->order();
        $charge = $this->charge($order);
        $allowed = ServiceOrderPixPayment::allows(Filament::auth()->user(), (int) $order->company_id);
        $paid = $charge?->status === PixChargeStatus::PAID;
        $payable = $allowed && $charge?->status === PixChargeStatus::REGISTERED;
        $qrCode = $payable ? $charge->qr_code : null;

        return view('livewire.operation-service-order-pix', [
            'visible' => $allowed || $charge !== null,
            'allowed' => $allowed,
            'canGenerate' => $allowed && ! $charge && $order->status !== State::CANCELLED,
            'poll' => $allowed && $charge && in_array($charge->status, [PixChargeStatus::PENDING_REGISTRATION, PixChargeStatus::PROCESSING, PixChargeStatus::UNCERTAIN, PixChargeStatus::REGISTERED, PixChargeStatus::NEEDS_REVIEW], true),
            'paid' => $paid,
            'payable' => $payable,
            'amount' => 'R$ '.number_format((float) ($charge?->amount ?? $order->grand_total_amount), 2, ',', '.'),
            'paidAt' => $paid ? $charge->paid_at?->format('d/m/Y H:i:s') : null,
            'status' => $charge ? match ($charge->status) {
                PixChargeStatus::PAID => 'Pagamento confirmado',
                PixChargeStatus::REGISTERED => 'Aguardando pagamento',
                PixChargeStatus::PENDING_REGISTRATION => 'Gerando cobrança PIX',
                PixChargeStatus::PROCESSING, PixChargeStatus::UNCERTAIN => 'Confirmando emissão com o banco',
                PixChargeStatus::NEEDS_REVIEW, PixChargeStatus::PARTIALLY_PAID => 'Pagamento em análise pelo financeiro',
                PixChargeStatus::REGISTRATION_FAILED => 'Falha na emissão. Solicite a revisão do financeiro.',
                default => $charge->status->description(),
            } : 'Receber no atendimento',
            'qrCode' => $qrCode ? (str_starts_with($qrCode, 'data:image/') ? $qrCode : 'data:image/png;base64,'.$qrCode) : null,
            'copyPaste' => $payable ? $charge->pix_copy_paste : null,
            'publicUrl' => $payable ? app(PixChargePublicLinkService::class)->generate($charge)['url'] : null,
        ]);
    }
}
