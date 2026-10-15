<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductoController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/productos', [ProductoController::class, 'index']);
    Route::post('/productos', [ProductoController::class, 'store']);
    Route::patch('/productos/{producto}/frecuencia', [ProductoController::class, 'actualizarFrecuencia']);
    Route::post('/productos/extraer', [ProductoController::class, 'extraerDatos']);
    Route::post('/productos/inspeccionar', [ProductoController::class, 'inspeccionarSelectores']);
});
