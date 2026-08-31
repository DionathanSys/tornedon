<?php

namespace App\Http\Controllers;

use App\Enum\ServiceOrder\State;
use App\Exceptions\ServiceOrderSignatureException;
use App\Models\ServiceOrder;
use App\Services\ServiceOrder\ServiceOrderSignatureLinkService;
use App\Services\ServiceOrder\Support\ServiceOrderPdfDataFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class PublicServiceOrderSignatureController extends Controller
{
    public function __construct(
        private readonly ServiceOrderSignatureLinkService $signatureLinks,
        private readonly ServiceOrderPdfDataFormatter $formatter,
    ) {}

    public function show(string $token): Response
    {
        $link = $this->signatureLinks->findByToken($token);

        if ($link === null || $link->revoked_at !== null) {
            return $this->unavailableResponse();
        }

        if ($link->used_at === null && $link->expires_at?->isPast()) {
            return $this->unavailableResponse();
        }

        $serviceOrder = $this->loadServiceOrder($link->service_order_id);

        if ($serviceOrder === null || $serviceOrder->status === State::CANCELLED) {
            return $this->unavailableResponse();
        }

        $pdfData = $this->formatter->format($serviceOrder);

        if ($link->used_at !== null || filled($serviceOrder->customer_signature)) {
            return $this->renderView('service-orders.signature-completed', compact('pdfData'));
        }

        return $this->renderView('service-orders.public-signature', [
            'token' => $token,
            'pdfData' => $pdfData,
            'serviceOrder' => $serviceOrder,
            'link' => $link,
        ]);
    }

    public function store(Request $request, string $token): Response
    {
        $validator = Validator::make($request->all(), [
            'customer_signed_by_name' => ['required', 'string', 'max:255'],
            'customer_signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:2500000'],
            'agreement' => ['accepted'],
        ], [
            'customer_signed_by_name.required' => 'Informe o nome completo de quem está assinando.',
            'customer_signed_by_name.max' => 'O nome do signatário não pode ter mais de 255 caracteres.',
            'customer_signature.required' => 'Faça sua assinatura no campo indicado.',
            'customer_signature.starts_with' => 'A assinatura deve ser enviada no formato PNG.',
            'customer_signature.max' => 'A assinatura excede o tamanho máximo permitido.',
            'agreement.accepted' => 'É necessário confirmar a leitura e concordância com a ordem de serviço.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('service-orders.signature.show', ['token' => $token])
                ->withErrors($validator)
                ->withInput($request->except('customer_signature'));
        }

        try {
            $data = $validator->validated();
            $serviceOrder = $this->signatureLinks->sign(
                $token,
                trim($data['customer_signed_by_name']),
                $data['customer_signature'],
                $request,
            );

            return $this->renderView('service-orders.signature-completed', [
                'pdfData' => $this->formatter->format($serviceOrder),
            ]);
        } catch (ServiceOrderSignatureException $exception) {
            if ($exception->status === 409) {
                $link = $this->signatureLinks->findByToken($token);
                $serviceOrder = $link ? $this->loadServiceOrder($link->service_order_id) : null;

                if ($serviceOrder !== null && filled($serviceOrder->customer_signature)) {
                    return $this->renderView('service-orders.signature-completed', [
                        'pdfData' => $this->formatter->format($serviceOrder),
                    ], 409);
                }
            }

            if ($exception->status === 410) {
                return $this->unavailableResponse($exception->getMessage());
            }

            throw $exception;
        }
    }

    private function loadServiceOrder(int $serviceOrderId): ?ServiceOrder
    {
        return ServiceOrder::query()
            ->with([
                'customer',
                'company',
                'equipment',
                'technician',
                'supervisor',
                'salesperson',
                'items.service',
                'requisition.items.product',
            ])
            ->find($serviceOrderId);
    }

    private function unavailableResponse(string $message = 'Este link de assinatura expirou ou não está mais disponível.'): Response
    {
        return $this->renderView('service-orders.signature-unavailable', [
            'message' => $message,
        ], 410);
    }

    private function renderView(string $view, array $data = [], int $status = 200): Response
    {
        return response()->view($view, $data, $status)
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
