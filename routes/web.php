<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Livewire\TenantDashboard;
use Illuminate\Support\Facades\Route;

// Google Search Console verification
Route::get('/google3a1c663d9af9f4c5.html', function () {
    return response('google-site-verification: google3a1c663d9af9f4c5.html', 200)
        ->header('Content-Type', 'text/html');
});

Route::get('/', function () {
    return view('welcome');
});

// Legal pages
Route::get('/privacy', function () {
    return view('legal.privacy');
})->name('privacy');

Route::get('/terms', function () {
    return view('legal.terms');
})->name('terms');

Route::get('/refund', function () {
    return view('legal.refund');
})->name('refund');

Route::get('/offer', function () {
    return view('legal.offer');
})->name('offer');

// Dashboard - tenant scoped (protected by trial middleware)
Route::get('/dashboard', TenantDashboard::class)
    ->middleware(['auth', 'verified', 'trial.active'])
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
    
    // Enrichment progress (AJAX endpoint)
    Route::get('/enrichment-progress', [OnboardingController::class, 'enrichmentProgress'])->name('enrichment.progress');
});

// Widget embed route (public, no auth required)
Route::get('/widget/{slug}.js', [\App\Http\Controllers\Api\TenantWidgetController::class, 'serveWidget'])
    ->name('widget.serve');

require __DIR__.'/auth.php';
