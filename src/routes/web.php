<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\SubsystemController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::resource('users', UserController::class);

Route::resource('subsystems', SubsystemController::class);