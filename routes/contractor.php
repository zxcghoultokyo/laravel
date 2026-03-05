<?php

use App\Http\Controllers\Contractor\AuthController;
use Illuminate\Support\Facades\Route;

// Contractor login (public)
Route::prefix('contractor')->middleware(['web'])->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('contractor.login');
    Route::post('/login', [AuthController::class, 'login'])->name('contractor.login.submit');
    Route::post('/logout', [AuthController::class, 'logout'])->name('contractor.logout');
});

// Contractor protected area
Route::prefix('contractor')
    ->middleware(['web', \App\Http\Middleware\ContractorAuth::class])
    ->group(function () {
        Route::get('/rozetka', \App\Livewire\Contractor\RozetkaProductList::class)
            ->name('contractor.rozetka.index');
        Route::get('/horoshop', \App\Livewire\Contractor\HoroshopProductList::class)
            ->name('contractor.horoshop.index');
    });
