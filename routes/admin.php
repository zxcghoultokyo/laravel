<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Analytics;
use App\Livewire\Admin\ConversionAnalytics;
use App\Livewire\Admin\ChatsList;
use App\Livewire\Admin\ChatDetail;
use App\Livewire\Admin\WidgetSettings;
use App\Livewire\Admin\GreetingsManager;
use App\Livewire\Admin\PromptPresetsManager;
use App\Livewire\Admin\TenantsManager;
use App\Livewire\Admin\CannedResponsesManager;
use App\Livewire\Admin\ExportsManager;
use App\Livewire\Admin\SyncReports;
use App\Livewire\Admin\ProactiveTriggersManager;

/*
|--------------------------------------------------------------------------
| Admin Web Routes
|--------------------------------------------------------------------------
|
| Admin panel routes using Livewire components.
| Super Admin has access to all routes.
| Tenant owners/admins have access to their own tenant data.
|
*/

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    
    // Dashboard
    Route::get('/', Dashboard::class)->name('dashboard');
    
    // Analytics
    Route::get('/analytics', Analytics::class)->name('analytics');
    Route::get('/conversions', ConversionAnalytics::class)->name('conversions');
    
    // Chats
    Route::get('/chats', ChatsList::class)->name('chats.index');
    Route::get('/chats/{sessionId}', ChatDetail::class)->name('chats.show');
    
    // Settings
    Route::get('/widget', WidgetSettings::class)->name('widget.settings');
    Route::get('/greetings', GreetingsManager::class)->name('greetings');
    Route::get('/prompts', PromptPresetsManager::class)->name('prompts');
    Route::get('/triggers', ProactiveTriggersManager::class)->name('triggers');
    
    // Operator tools
    Route::get('/canned-responses', CannedResponsesManager::class)->name('canned-responses');
    
    // Exports
    Route::get('/exports', ExportsManager::class)->name('exports');
    
    // Super Admin only routes
    Route::middleware('super-admin')->group(function () {
        Route::get('/tenants', TenantsManager::class)->name('tenants');
        Route::get('/sync-reports', SyncReports::class)->name('sync-reports');
    });
});
