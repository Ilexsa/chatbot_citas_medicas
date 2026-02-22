<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-chat', function () {
    $telefono = request('telefono', '123456789');
    $mensaje = request('mensaje', 'Hola');

    $controller = new \App\Http\Controllers\ChatBotController();
    return $controller->chatbot($telefono, $mensaje);
});
