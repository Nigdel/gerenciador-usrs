<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\SubsystemController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);
Route::get('/users/create', [UserController::class, 'create']);

Route::resource('subsystems', SubsystemController::class);