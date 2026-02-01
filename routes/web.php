<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ErrorTicketController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/error-tickets/create', [ErrorTicketController::class, 'create'])
    ->name('error-tickets.create')
    ->middleware(['web', 'auth']);
