<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\DebugProductsController;
use App\Http\Controllers\Api\AdminJobsController;
use App\Http\Controllers\Api\ProductSearchController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// OPTIONS для CORS preflight
Route::options('/chat', function () {
    return response('', 200);
})->middleware('widget.cors');

Route::options('/widget/settings', function () {
    return response('', 200);
})->middleware('widget.cors');

// AI-чат — БЕЗ use ChatController, прямо повний namespace
Route::post('/chat', [\App\Http\Controllers\Api\ChatController::class, 'handle'])
    ->middleware('widget.cors');

// Віджет налаштування
Route::get('/widget/settings', function () {
    $settings = \App\Models\WidgetSettings::first();
    if (!$settings) {
        return response()->json([
            'enabled' => true,
            'primary_color' => '#2563eb',
            'text_color' => '#ffffff',
            'position' => 'right',
            'border_radius' => 12,
            'welcome_message' => 'Вітаю! 👋 Я AILure асистент. Чим можу допомогти?',
            'input_placeholder' => 'Напишіть повідомлення...',
            'consent_notice' => null,
        ]);
    }
    
    return response()->json([
        'enabled' => $settings->enabled,
        'primary_color' => $settings->primary_color,
        'text_color' => $settings->text_color,
        'position' => $settings->position,
        'border_radius' => $settings->border_radius,
        'welcome_message' => $settings->welcome_message,
        'input_placeholder' => $settings->input_placeholder,
        'consent_notice' => $settings->consent_notice,
    ]);
})->middleware('widget.cors');

// Статус замовлення по order_id
Route::post('/order-status', [OrderStatusController::class, 'show']);

// Дебаг продуктів
Route::get('/debug/products', [DebugProductsController::class, 'index']);

Route::get('/admin/jobs/sync-horoshop', [AdminJobsController::class, 'syncHoroshop']);

Route::get('/admin/jobs/rebuild-category-index', [AdminJobsController::class, 'rebuildCategoryIndex']);

Route::get('/search/products', [ProductSearchController::class, 'index']);
