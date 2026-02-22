<?php

use App\Http\Controllers\TurnosController;
use Illuminate\Support\Facades\Route;

Route::get('/listado', [TurnosController::class, 'listado'])->name('listadoTurnos');
Route::get('/turnosPorMedico', [TurnosController::class, 'turnosPorMedico'])->name('turnosPorMedico');
Route::post('/crear', [TurnosController::class, 'store'])->name('crearTurno');
