<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\OrderSearchController;
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
// Rate limit: 30 requests per minute per IP
Route::post('/chat', [\App\Http\Controllers\Api\ChatController::class, 'handle'])
    ->middleware(['widget.cors', 'throttle:30,1']);

// Streaming chat via Server-Sent Events (SSE)
// Usage: GET /api/chat/stream?message=плитоноска&session_id=xxx
Route::get('/chat/stream', [\App\Http\Controllers\Api\StreamingChatController::class, 'stream'])
    ->middleware(['widget.cors', 'throttle:30,1']);

// Clear chat session (delete from DB and cache)
Route::delete('/chat/session/{sessionId}', [\App\Http\Controllers\Api\ChatController::class, 'clearSession'])
    ->middleware(['widget.cors']);

// Віджет налаштування
Route::get('/widget/settings', [WidgetController::class, 'settings'])
    ->middleware('widget.cors');

// Статус замовлення по order_id
Route::post('/order-status', [OrderStatusController::class, 'show']);

// Пошук замовлень за гнучкими критеріями
Route::post('/orders/search', [OrderSearchController::class, 'search']);

// Дебаг продуктів
Route::get('/debug/products', [DebugProductsController::class, 'index']);

// Diagnostic API (key protected)
Route::prefix('diagnostic')->group(function () {
    Route::get('/db-stats', [\App\Http\Controllers\Api\DiagnosticController::class, 'dbStats']);
    Route::get('/search-db', [\App\Http\Controllers\Api\DiagnosticController::class, 'searchDb']);
    Route::get('/search-meili', [\App\Http\Controllers\Api\DiagnosticController::class, 'searchMeili']);
    Route::get('/meili-stats', [\App\Http\Controllers\Api\DiagnosticController::class, 'meiliStats']);
    Route::get('/product/{id}', [\App\Http\Controllers\Api\DiagnosticController::class, 'product']);
    Route::get('/variants/{parentArticle}', [\App\Http\Controllers\Api\DiagnosticController::class, 'variants']);
    Route::get('/category-products', [\App\Http\Controllers\Api\DiagnosticController::class, 'categoryProducts']);
    Route::get('/test-chat', [\App\Http\Controllers\Api\DiagnosticController::class, 'testChat']);
    Route::get('/sync-sample', [\App\Http\Controllers\Api\DiagnosticController::class, 'syncSample']);
    Route::post('/reindex-meili', [\App\Http\Controllers\Api\DiagnosticController::class, 'reindexMeili']);
});

// Cross-sell suggestions (async, called after main chat response)
Route::get('/cross-sell', [\App\Http\Controllers\Api\CrossSellController::class, 'suggestions'])
    ->middleware(['widget.cors', 'throttle:60,1']);

// Analytics endpoints
Route::prefix('analytics')->middleware('widget.cors')->group(function () {
    // Receive events batch from widget
    Route::post('/events', [\App\Http\Controllers\Api\AnalyticsController::class, 'events']);
    // Track conversion (add_to_cart, purchase, lead)
    Route::post('/conversion', [\App\Http\Controllers\Api\AnalyticsController::class, 'conversion']);
    // Webhook from merchant platform (order created, etc.)
    Route::post('/webhook', [\App\Http\Controllers\Api\AnalyticsController::class, 'webhook']);
    // Debug endpoint - check recent events
    Route::get('/debug-events', [\App\Http\Controllers\Api\AnalyticsController::class, 'debugEvents']);
    // Dashboard data (protected)
    Route::get('/dashboard', [\App\Http\Controllers\Api\AnalyticsController::class, 'dashboard'])
        ->middleware('admin.token');
});

Route::get('/admin/jobs/sync-horoshop', [AdminJobsController::class, 'syncHoroshop']);

Route::get('/admin/jobs/rebuild-category-index', [AdminJobsController::class, 'rebuildCategoryIndex']);

Route::get('/search/products', [ProductSearchController::class, 'index']);

// Health check endpoint
Route::get('/health', \App\Http\Controllers\Api\HealthController::class);

// Admin API (token protected)
Route::prefix('admin')->middleware('admin.token')->group(function () {
    // Circuit breaker management
    Route::get('/circuit-breaker', [\App\Http\Controllers\Api\Admin\CircuitBreakerController::class, 'index']);
    Route::post('/circuit-breaker/{service}/reset', [\App\Http\Controllers\Api\Admin\CircuitBreakerController::class, 'reset']);
    
    // Metrics
    Route::get('/metrics', [\App\Http\Controllers\Api\Admin\MetricsController::class, 'index']);
    
    // Live chat sessions
    Route::get('/chats/active', [\App\Http\Controllers\Api\Admin\LiveChatController::class, 'active']);
    Route::post('/chats/{sessionId}/takeover', [\App\Http\Controllers\Api\Admin\LiveChatController::class, 'takeover']);
    Route::post('/chats/{sessionId}/release', [\App\Http\Controllers\Api\Admin\LiveChatController::class, 'release']);
    Route::post('/chats/{sessionId}/message', [\App\Http\Controllers\Api\Admin\LiveChatController::class, 'sendMessage']);
});
