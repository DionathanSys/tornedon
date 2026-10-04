<?php

namespace App\Http\Controllers;

use App\Enum\Financial\PixChargeStatus;
use App\Models\PixCharge;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

final class PublicPixChargeController extends Controller
{
    public function show(Request $request, PixCharge $pixCharge): Response
    {
        $pixCharge->loadMissing('company');

        $status = $pixCharge->status;
        $qrCode = $pixCharge->qr_code;
        $pixCopyPaste = $pixCharge->pix_copy_paste;
        $isPayable = $status === PixChargeStatus::REGISTERED
            && (filled($qrCode) || filled($pixCopyPaste));

        $statusMessage = match ($status) {
            PixChargeStatus::PAID => 'Esta cobrança PIX já foi paga.',
            PixChargeStatus::CANCELED => 'Esta cobrança PIX foi cancelada.',
            PixChargeStatus::EXPIRED => 'Esta cobrança PIX expirou.',
            PixChargeStatus::PENDING_REGISTRATION => 'A cobrança PIX ainda está sendo registrada.',
            PixChargeStatus::REGISTRATION_FAILED => 'Não foi possível registrar esta cobrança PIX.',
            PixChargeStatus::NEEDS_REVIEW => 'Esta cobrança PIX está em revisão e não pode ser paga agora.',
            PixChargeStatus::REGISTERED => 'Os dados de pagamento desta cobrança ainda não estão disponíveis.',
            default => 'Esta cobrança PIX não está disponível.',
        };

        $responseStatus = $isPayable
            ? Response::HTTP_OK
            : match ($status) {
                PixChargeStatus::PENDING_REGISTRATION,
                PixChargeStatus::REGISTRATION_FAILED => Response::HTTP_CONFLICT,
                default => Response::HTTP_GONE,
            };

        $linkExpiresAt = null;
        $expires = $request->query('expires');

        if (is_numeric($expires)) {
            $linkExpiresAt = Carbon::createFromTimestamp((int) $expires);
        }

        return response()
            ->view('pix.public-charge', [
                'charge' => $pixCharge,
                'companyName' => $pixCharge->company?->name ?? 'Pagamento PIX',
                'statusLabel' => $status?->description() ?? 'Indisponível',
                'statusMessage' => $statusMessage,
                'isPayable' => $isPayable,
                'qrCodeDataUri' => $this->qrCodeDataUri($qrCode),
                'pixCopyPaste' => $pixCopyPaste,
                'linkExpiresAt' => $linkExpiresAt,
            ], $responseStatus)
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function qrCodeDataUri(?string $qrCode): ?string
    {
        if (blank($qrCode)) {
            return null;
        }

        return str_starts_with($qrCode, 'data:image/')
            ? $qrCode
            : 'data:image/png;base64,'.$qrCode;
    }
}
