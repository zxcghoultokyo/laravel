<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;
use App\Livewire\Admin\ChatsList;
use App\Livewire\Admin\ChatDetail;
use App\Livewire\Admin\WidgetSettings;
use App\Livewire\Admin\Dashboard;

Route::get('/', function () {
    return view('demo'); // AI Chat Demo landing page
});

// Test chat page
Route::get('/chat', function () {
    return view('chat');
});

// Widget with proper cache control headers
Route::get('/widget.js', function () {
    $path = public_path('widget.js');
    
    if (!File::exists($path)) {
        abort(404);
    }
    
    $content = File::get($path);
    $lastModified = File::lastModified($path);
    $etag = md5($content);
    
    return response($content)
        ->header('Content-Type', 'application/javascript; charset=utf-8')
        ->header('Cache-Control', 'no-cache, must-revalidate')
        ->header('ETag', $etag)
        ->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT')
        ->header('Access-Control-Allow-Origin', '*')
        ->header('X-Widget-Version', '2.0.0');
});

// Admin routes
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/chats', ChatsList::class)->name('chats.index');
    Route::get('/chats/{sessionId}', ChatDetail::class)->name('chats.show');
    Route::get('/widget', WidgetSettings::class)->name('widget.settings');
});
