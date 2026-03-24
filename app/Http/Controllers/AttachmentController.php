<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\Attachments\AttachmentService;
use Illuminate\Http\Request;
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
        // Simple security: check if user is authenticated and belongs to the same company
        $user = Auth::user();
        
        if (!$user) {
            abort(401, 'Não autorizado');
        }

        Log::info('Acesso ao arquivo', [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'attachment_id' => $attachment->id,
            'attachment_company_id' => $attachment->company_id,
        ]);
        
        // Admins or support could potentially access any, but for simplicity assuming exact match
        if (! in_array($attachment->company_id, $user->companies->pluck('id')->toArray())) {
            Log::warning('Acesso negado ao arquivo', [
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'attachment_id' => $attachment->id,
                'attachment_company_id' => $attachment->company_id,
            ]); 
            abort(403, 'Acesso negado');
        }

        $response = $service->downloadResponse($attachment);
        
        if (!$response) {
            abort(404, 'Arquivo não encontrado no disco');
        }
        
        return $response;
    }
}
