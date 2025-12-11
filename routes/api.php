<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ВАЖЛИВО: alias для API ChatController, щоб не конфліктував із будь-якими іншими ChatController
use App\Http\Controllers\Api\ChatController as ApiChatController;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\DebugProductsController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// AI-чат
Route::post('/chat', [ApiChatController::class, 'handle']);

// Статус замовлення по order_id
Route::post('/order-status', [OrderStatusController::class, 'show']);

// Дебаг продуктів
Route::get('/debug/products', [DebugProductsController::class, 'index']);
