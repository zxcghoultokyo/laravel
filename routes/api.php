<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\DebugProductsController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// AI-чат
Route::post('/chat', [ChatController::class, 'handle']);

// Статус замовлення по order_id
Route::post('/order-status', [OrderStatusController::class, 'show']);

Route::get('/debug/products', [DebugProductsController::class, 'index']);
