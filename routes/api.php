<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\DebugProductsController;
use App\Http\Controllers\Api\AdminJobsController;
use App\Http\Controllers\Api\ProductSearchController;
use App\Http\Controllers\Api\WidgetController;

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
Route::get('/widget/settings', [WidgetController::class, 'settings'])
    ->middleware('widget.cors');

// Статус замовлення по order_id
Route::post('/order-status', [OrderStatusController::class, 'show']);

// Дебаг продуктів
Route::get('/debug/products', [DebugProductsController::class, 'index']);

Route::get('/admin/jobs/sync-horoshop', [AdminJobsController::class, 'syncHoroshop']);

Route::get('/admin/jobs/rebuild-category-index', [AdminJobsController::class, 'rebuildCategoryIndex']);

Route::get('/search/products', [ProductSearchController::class, 'index']);
