<?php

namespace App\Services\FiscalDocument;

use App\Models\FiscalDocument;

class FiscalCancellationPolicy
{
    public function evaluate(FiscalDocument $document): array
    {
        $authorized = $document->isNfe() ? $document->isNfeAuthorized() : $document->isNfseAuthorized();
        if (! $authorized) {
            return ['allowed' => false, 'reason' => 'not_authorized', 'message' => 'Somente uma nota autorizada pode ser cancelada.', 'deadline' => null];
        }
        if ($document->hasOpenFiscalIssue() && $document->fiscalIssues()->where('active_slot', 1)->where('status', 'awaiting_confirmation')->exists()) {
            return ['allowed' => false, 'reason' => 'cancellation_confirmation_pending', 'message' => 'Existe um cancelamento com resultado pendente. Consulte o órgão emissor antes de repetir a solicitação.', 'deadline' => null];
        }
        // NFS-e has no local time restriction; acceptance belongs to the provider.
        if ($document->isNfse()) {
            return ['allowed' => true, 'reason' => null, 'message' => null, 'deadline' => null];
        }
        if (! $document->authorized_at || $document->authorized_at->isFuture()) {
            return ['allowed' => false, 'reason' => 'unknown_authorization_time', 'message' => 'Horário de autorização da NF-e não confirmado. Consulte a SEFAZ ou encaminhe para tratamento fiscal.', 'deadline' => null];
        }
        $deadline = $document->authorized_at->copy()->addHours(24);
        if (now()->greaterThan($deadline)) {
            return ['allowed' => false, 'reason' => 'cancellation_deadline_expired', 'message' => 'Prazo de 24 horas após a autorização da NF-e encerrado. É necessário tratamento fiscal pelo administrador da empresa.', 'deadline' => $deadline];
        }

        return ['allowed' => true, 'reason' => null, 'message' => null, 'deadline' => $deadline];
    }
}
