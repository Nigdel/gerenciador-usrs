<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\GestorUserController;
use App\Http\Controllers\SubsystemController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::resource('users', UserController::class);

Route::resource('gestor-users', GestorUserController::class)
    ->parameters(['gestor-users' => 'gestorUser']);

Route::post('subsystems/{subsystem}/test-connection', [SubsystemController::class, 'testConnection'])
    ->name('subsystems.test-connection');

Route::resource('subsystems', SubsystemController::class);