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

Route::resource('gestor-users.accounts', App\Http\Controllers\UserSubsystemAccountController::class)
    ->parameters([
        'gestor-users' => 'gestorUser',
        'accounts' => 'userSubsystemAccount',
    ]);

Route::post('subsystems/{subsystem}/test-connection', [SubsystemController::class, 'testConnection'])
    ->name('subsystems.test-connection');

Route::post('subsystems/{subsystem}/accounts/{userSubsystemAccount}/action', [SubsystemController::class, 'accountAction'])
    ->name('subsystems.accounts.action');

Route::resource('subsystems', SubsystemController::class);