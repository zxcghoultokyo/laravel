<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\Admin\ChatsList;
use App\Livewire\Admin\ChatDetail;
use App\Livewire\Admin\WidgetSettings;

Route::get('/', function () {
    return view('chat'); // resources/views/chat.blade.php
});

// Admin routes
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/chats', ChatsList::class)->name('chats.index');
    Route::get('/chats/{sessionId}', ChatDetail::class)->name('chats.show');
    Route::get('/widget', WidgetSettings::class)->name('widget.settings');
});
