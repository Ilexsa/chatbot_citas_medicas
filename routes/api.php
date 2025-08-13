<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/testei', function () {
    return response()->json(['message' => 'Hello from testei']);
});

Route::get('/webhook', [WebhookController::class, 'handleWebhook']);
