<?php

use App\Http\Controllers\MedicosController;
use Illuminate\Support\Facades\Route;

Route::get('/listado', [MedicosController::class, 'listado'])->name('listadoMedicos');
Route::get('/especialidad/{idEspecialidad}', [MedicosController::class, 'medicosEspecialidad'])->name('medicosEspecialidad');
