<?php

use App\Http\Controllers\ErrorTicketController;
use App\Http\Controllers\NfeWebhookController;
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
    ->withoutMiddleware(['auth', 'verified']);

Route::post('/webhook/nfse', [NfeWebhookController::class, 'handle'])
    ->name('webhook.nfse')
    ->withoutMiddleware(['auth', 'verified']);

Route::post('/error-tickets/create', [ErrorTicketController::class, 'create'])
    ->name('error-tickets.create')
    ->middleware(['web', 'auth']);
