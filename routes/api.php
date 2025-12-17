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

// AI-чат — БЕЗ use ChatController, прямо повний namespace
Route::post('/chat', [\App\Http\Controllers\Api\ChatController::class, 'handle']);

// Статус замовлення по order_id
Route::post('/order-status', [OrderStatusController::class, 'show']);

// Дебаг продуктів
Route::get('/debug/products', [DebugProductsController::class, 'index']);

Route::get('/admin/jobs/sync-horoshop', [AdminJobsController::class, 'syncHoroshop']);

Route::get('/admin/jobs/rebuild-category-index', [AdminJobsController::class, 'rebuildCategoryIndex']);

Route::get('/search/products', [ProductSearchController::class, 'index']);
