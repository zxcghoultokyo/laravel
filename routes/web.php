<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TenantDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Dashboard - tenant scoped
Route::get('/dashboard', [TenantDashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Profile
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Billing
Route::middleware(['auth'])->prefix('billing')->name('billing.')->group(function () {
    Route::get('/', [BillingController::class, 'index'])->name('index');
    Route::get('/checkout/{plan}', [BillingController::class, 'checkout'])->name('checkout');
    Route::post('/subscribe/{plan}', [BillingController::class, 'subscribe'])->name('subscribe');
    Route::post('/cancel', [BillingController::class, 'cancel'])->name('cancel');
    Route::post('/resume', [BillingController::class, 'resume'])->name('resume');
    Route::get('/success', [BillingController::class, 'success'])->name('success');
    Route::get('/cancel', [BillingController::class, 'cancelled'])->name('cancelled');
    Route::get('/history', [BillingController::class, 'history'])->name('history');
    Route::get('/invoice/{payment}', [BillingController::class, 'invoice'])->name('invoice');
});

// Onboarding Wizard
Route::middleware(['auth'])->prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('/', [OnboardingController::class, 'index'])->name('index');
    
    // Step 1: Platform selection
    Route::get('/platform', [OnboardingController::class, 'step1'])->name('step1');
    Route::post('/platform', [OnboardingController::class, 'saveStep1'])->name('step1.save');
    
    // Step 2: Credentials
    Route::get('/credentials', [OnboardingController::class, 'step2'])->name('step2');
    Route::post('/credentials', [OnboardingController::class, 'saveStep2'])->name('step2.save');
    
    // Step 3: Sync
    Route::get('/sync', [OnboardingController::class, 'step3'])->name('step3');
    Route::post('/sync/start', [OnboardingController::class, 'startSync'])->name('step3.start');
    Route::get('/sync/status', [OnboardingController::class, 'syncStatus'])->name('step3.status');
    Route::post('/sync', [OnboardingController::class, 'saveStep3'])->name('step3.save');
    
    // Step 4: Widget
    Route::get('/widget', [OnboardingController::class, 'step4'])->name('step4');
    Route::post('/widget', [OnboardingController::class, 'saveStep4'])->name('step4.save');
    
    // Step 5: Embed
    Route::get('/embed', [OnboardingController::class, 'step5'])->name('step5');
    Route::post('/complete', [OnboardingController::class, 'complete'])->name('complete');
});

require __DIR__.'/auth.php';
