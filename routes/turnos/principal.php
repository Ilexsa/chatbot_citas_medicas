<?php

use App\Http\Controllers\TurnosController;
use Illuminate\Support\Facades\Route;

Route::get('/listado', [TurnosController::class, 'listado'])->name('litadoTurnos');
