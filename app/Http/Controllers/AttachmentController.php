<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\Attachments\AttachmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Handle the downloading of an attachment.
     */
    public function download(Attachment $attachment, AttachmentService $service): StreamedResponse
    {
        $this->authorizeAttachmentAccess($attachment);

        $response = $service->downloadResponse($attachment);

        if (! $response) {
            abort(404, 'Arquivo não encontrado no disco');
        }

        return $response;
    }

    public function preview(Attachment $attachment, AttachmentService $service): StreamedResponse
    {
        $this->authorizeAttachmentAccess($attachment);

        $response = $service->previewResponse($attachment);

        if (! $response) {
            abort(404, 'Arquivo não encontrado ou não suportado para visualização');
        }

        return $response;
    }

    private function authorizeAttachmentAccess(Attachment $attachment): void
    {
        $user = Auth::user();

        if (! $user) {
            abort(401, 'Não autorizado');
        }

        Log::info('Acesso ao arquivo', [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'attachment_id' => $attachment->id,
            'attachment_company_id' => $attachment->company_id,
        ]);

        if (! in_array($attachment->company_id, $user->companies->pluck('id')->toArray())) {
            Log::warning('Acesso negado ao arquivo', [
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'attachment_id' => $attachment->id,
                'attachment_company_id' => $attachment->company_id,
            ]);

            abort(403, 'Acesso negado');
        }
    }
}
