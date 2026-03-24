<?php

use App\Http\Controllers\ErrorTicketController;
use App\Http\Controllers\EmailDispatchAttachmentController;
use App\Http\Controllers\NfeWebhookController;
use App\Http\Controllers\OrderAttachmentController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\PdfPreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Webhook IntegraNotas — NF-e / NFS-e
|--------------------------------------------------------------------------
| Sem autenticação de header (exigência da IntegraNotas).
| Deve retornar HTTP 200 sempre.
| A validação é feita via campo 'signature' do payload.
| O controller detecta automaticamente NF-e vs NFS-e pelo document_type.
*/
Route::post('/webhook/nfe', [NfeWebhookController::class, 'handle'])
    ->name('webhook.nfe')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, 'auth', 'verified']);

Route::post('/error-tickets/create', [ErrorTicketController::class, 'create'])
    ->name('error-tickets.create')
    ->middleware(['web', 'auth']);

Route::get('/email-dispatches/{emailDispatch}/attachments/{token}', [EmailDispatchAttachmentController::class, 'show'])
    ->name('email-dispatch.attachment')
    ->middleware('signed');


Route::get('/attachments/{attachment:public_id}/download', [AttachmentController::class, 'download'])
    ->name('attachments.download')
    ->middleware(['web', 'auth']);

Route::get('/pdf-preview/{token}', [PdfPreviewController::class, 'show'])
    ->name('pdf-preview.show')
    ->middleware(['web', 'auth', 'signed']);
