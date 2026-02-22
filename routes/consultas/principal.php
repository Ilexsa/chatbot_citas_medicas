<?php

use App\Http\Controllers\ConsultasController;
use Illuminate\Support\Facades\Route;


Route::get('/listadoPorEspecialidad', [ConsultasController::class, 'listadoConsultasPorEspecialidad'])->name('listadoConsultasPorEspecialidad');
