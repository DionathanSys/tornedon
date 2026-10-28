<?php

namespace App\Http\Controllers;

use App\Services\Hr\HrReceiptSignatureService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PublicHrReceiptSignatureController extends Controller
{
    public function __construct(private HrReceiptSignatureService $signatures) {}

    public function show(string $token): Response
    {
        try {
            $context = $this->signatures->publicContext($token);

            return response()->view('hr.public-receipt-signature', [...$context, 'token' => $token])->withHeaders($this->headers());
        } catch (HttpExceptionInterface $exception) {
            return $this->unavailable($exception->getMessage());
        }
    }

    public function store(Request $request, string $token): Response
    {
        try {
            $request->validate(['agreement' => ['accepted']], ['agreement.accepted' => 'Confirme a leitura do recibo e o recebimento do valor indicado.']);
            $this->signatures->signByToken($token, $request->only(['signed_by_name', 'signature']), $request);

            return redirect()->route('hr.signature.show', ['token' => $token])->withHeaders($this->headers());
        } catch (ValidationException $exception) {
            $name = $request->input('signed_by_name');

            return redirect()->route('hr.signature.show', ['token' => $token])->withErrors($exception->errors())
                ->withInput(['signed_by_name' => is_string($name) ? mb_substr($name, 0, 255) : ''])->withHeaders($this->headers());
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() === 409
                ? $this->show($token)->setStatusCode(409)
                : $this->unavailable($exception->getMessage());
        }
    }

    public function pdf(string $token): Response
    {
        try {
            $context = $this->signatures->publicContext($token);
            $receipt = $context['receipt'];

            return Pdf::loadView('pdf.hr-receipt', ['data' => $receipt->snapshot, 'receipt' => $receipt])->setPaper('a4')->stream('recibo-rh-'.$receipt->id.'.pdf')
                ->withHeaders($this->headers());
        } catch (HttpExceptionInterface $exception) {
            return $this->unavailable($exception->getMessage());
        }
    }

    private function unavailable(string $message): Response
    {
        return response()->view('service-orders.signature-unavailable', ['message' => $message], 410)->withHeaders($this->headers());
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY', 'X-Content-Type-Options' => 'nosniff'];
    }
}
