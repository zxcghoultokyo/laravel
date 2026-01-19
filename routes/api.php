<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\OrderSearchController;
use App\Http\Controllers\Api\DebugProductsController;
use App\Http\Controllers\Api\AdminJobsController;
use App\Http\Controllers\Api\ProductSearchController;
use App\Http\Controllers\Api\WidgetController;
use App\Http\Controllers\Api\BillingWebhookController;

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
    ->middleware(['widget.cors', 'tenant', 'throttle:30,1']);

// Streaming chat via Server-Sent Events (SSE)
// Usage: GET /api/chat/stream?message=плитоноска&session_id=xxx
Route::get('/chat/stream', [\App\Http\Controllers\Api\StreamingChatController::class, 'stream'])
    ->middleware(['widget.cors', 'tenant', 'throttle:30,1']);

// Clear chat session (delete from DB and cache)
Route::delete('/chat/session/{sessionId}', [\App\Http\Controllers\Api\ChatController::class, 'clearSession'])
    ->middleware(['widget.cors', 'tenant']);

// Poll for operator messages (used by widget when operator takes over)
Route::get('/chat/poll/{sessionId}', [\App\Http\Controllers\Api\ChatController::class, 'poll'])
    ->middleware(['widget.cors', 'tenant', 'throttle:60,1']);

// Віджет налаштування
Route::get('/widget/settings', [WidgetController::class, 'settings'])
    ->middleware(['widget.cors', 'tenant']);

// Dynamic greeting based on visitor context
Route::get('/widget/greeting', [WidgetController::class, 'greeting'])
    ->middleware(['widget.cors', 'tenant']);

// Proactive triggers API
Route::prefix('triggers')->middleware(['widget.cors', 'tenant'])->group(function () {
    Route::get('/rules', [\App\Http\Controllers\Api\ProactiveTriggersController::class, 'getRules']);
    Route::post('/event', [\App\Http\Controllers\Api\ProactiveTriggersController::class, 'trackEvent']);
    Route::post('/check', [\App\Http\Controllers\Api\ProactiveTriggersController::class, 'checkTrigger']);
    Route::get('/stats', [\App\Http\Controllers\Api\ProactiveTriggersController::class, 'getStats']);
});

// AI context settings (admin)
Route::get('/widget/ai-context', [WidgetController::class, 'getAiContext']);
Route::put('/widget/ai-context', [WidgetController::class, 'updateAiContext']);

// Статус замовлення по order_id
Route::post('/order-status', [OrderStatusController::class, 'show']);

// Пошук замовлень за гнучкими критеріями
Route::post('/orders/search', [OrderSearchController::class, 'search']);

// Дебаг продуктів
Route::get('/debug/products', [DebugProductsController::class, 'index']);

// Diagnostic API (key protected)
Route::prefix('diagnostic')->group(function () {
    Route::get('/db-stats', [\App\Http\Controllers\Api\DiagnosticController::class, 'dbStats']);
    Route::get('/categories', [\App\Http\Controllers\Api\DiagnosticController::class, 'categories']);
    Route::get('/search-db', [\App\Http\Controllers\Api\DiagnosticController::class, 'searchDb']);
    Route::get('/product-by-article', [\App\Http\Controllers\Api\DiagnosticController::class, 'productByArticle']);
    Route::get('/search-meili', [\App\Http\Controllers\Api\DiagnosticController::class, 'searchMeili']);
    Route::get('/meili-stats', [\App\Http\Controllers\Api\DiagnosticController::class, 'meiliStats']);
    Route::get('/product/{id}', [\App\Http\Controllers\Api\DiagnosticController::class, 'product']);
    Route::get('/variants/{parentArticle}', [\App\Http\Controllers\Api\DiagnosticController::class, 'variants']);
    Route::get('/category-products', [\App\Http\Controllers\Api\DiagnosticController::class, 'categoryProducts']);
    Route::get('/test-chat', [\App\Http\Controllers\Api\DiagnosticController::class, 'testChat']);
    Route::get('/sync-sample', [\App\Http\Controllers\Api\DiagnosticController::class, 'syncSample']);
    Route::post('/reindex-meili', [\App\Http\Controllers\Api\DiagnosticController::class, 'reindexMeili']);
    Route::post('/cleanup-meili', [\App\Http\Controllers\Api\DiagnosticController::class, 'cleanupMeili']);
    Route::post('/sync-horoshop', [\App\Http\Controllers\Api\DiagnosticController::class, 'syncHoroshop']);
    Route::get('/chat-sessions', [\App\Http\Controllers\Api\DiagnosticController::class, 'chatSessions']);
    Route::get('/chat-history/{sessionId}', [\App\Http\Controllers\Api\DiagnosticController::class, 'chatHistory']);
    Route::post('/sync-faq', [\App\Http\Controllers\Api\DiagnosticController::class, 'syncFaq']);
    Route::get('/widget-settings', [\App\Http\Controllers\Api\DiagnosticController::class, 'widgetSettings']);
    Route::post('/sync-orders', [\App\Http\Controllers\Api\DiagnosticController::class, 'syncOrders']);
    Route::get('/orders-stats', [\App\Http\Controllers\Api\DiagnosticController::class, 'ordersStats']);
    Route::get('/ai-index-stats', [\App\Http\Controllers\Api\DiagnosticController::class, 'aiIndexStats']);
    Route::get('/ai-index-problems', [\App\Http\Controllers\Api\DiagnosticController::class, 'aiIndexProblems']);
    Route::post('/run-enrichment', [\App\Http\Controllers\Api\DiagnosticController::class, 'runEnrichment']);
    Route::post('/enrich-one', [\App\Http\Controllers\Api\DiagnosticController::class, 'enrichOne']);
    Route::get('/slang-stats', [\App\Http\Controllers\Api\DiagnosticController::class, 'slangStats']);
    Route::get('/embedding-stats', [\App\Http\Controllers\Api\DiagnosticController::class, 'embeddingStats']);
    Route::post('/generate-embeddings', [\App\Http\Controllers\Api\DiagnosticController::class, 'generateEmbeddings']);
    Route::get('/ab-test-stats', [\App\Http\Controllers\Api\DiagnosticController::class, 'abTestStats']);
    Route::post('/ab-test-reset', [\App\Http\Controllers\Api\DiagnosticController::class, 'abTestReset']);
    Route::get('/ab-test-variant', [\App\Http\Controllers\Api\DiagnosticController::class, 'abTestVariant']);
    Route::post('/ab-test-force', [\App\Http\Controllers\Api\DiagnosticController::class, 'abTestForce']);
    Route::post('/clear-product-shown', [\App\Http\Controllers\Api\DiagnosticController::class, 'clearProductShown']);
    Route::post('/reset-views-count', [\App\Http\Controllers\Api\DiagnosticController::class, 'resetViewsCount']);
    Route::get('/chat-events-stats', [\App\Http\Controllers\Api\DiagnosticController::class, 'chatEventsStats']);
    Route::post('/clear-all-analytics', [\App\Http\Controllers\Api\DiagnosticController::class, 'clearAllAnalytics']);
    Route::get('/users', [\App\Http\Controllers\Api\DiagnosticController::class, 'listUsers']);
    Route::post('/set-super-admin', [\App\Http\Controllers\Api\DiagnosticController::class, 'setSuperAdmin']);
    Route::get('/trigger-events', [\App\Http\Controllers\Api\DiagnosticController::class, 'triggerEvents']);
    Route::get('/scheduler-status', [\App\Http\Controllers\Api\DiagnosticController::class, 'schedulerStatus']);
    Route::post('/run-sync', [\App\Http\Controllers\Api\DiagnosticController::class, 'runSyncJob']);
    Route::post('/clear-queue', [\App\Http\Controllers\Api\DiagnosticController::class, 'clearQueue']);
    Route::get('/test-queue', [\App\Http\Controllers\Api\DiagnosticController::class, 'testQueue']);
    Route::get('/test-queue-result', [\App\Http\Controllers\Api\DiagnosticController::class, 'testQueueResult']);
    Route::post('/fix-null-tenants', [\App\Http\Controllers\Api\DiagnosticController::class, 'fixNullTenants']);
    Route::post('/update-product-color', [\App\Http\Controllers\Api\DiagnosticController::class, 'updateProductColor']);
    Route::get('/test-color-picker', [\App\Http\Controllers\Api\DiagnosticController::class, 'testColorPicker']);
    
    // Tenant management
    Route::get('/tenants', [\App\Http\Controllers\Api\DiagnosticController::class, 'tenants']);
    Route::get('/tenant/{id}', [\App\Http\Controllers\Api\DiagnosticController::class, 'tenantDetails']);
    Route::post('/migrate-data', [\App\Http\Controllers\Api\DiagnosticController::class, 'migrateDataToTenant']);
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
    // Echo endpoint - returns what was received
    Route::post('/echo', [\App\Http\Controllers\Api\AnalyticsController::class, 'echo']);
    // Dashboard data (protected)
    Route::get('/dashboard', [\App\Http\Controllers\Api\AnalyticsController::class, 'dashboard'])
        ->middleware('admin.token');
});

Route::get('/admin/jobs/sync-horoshop', [AdminJobsController::class, 'syncHoroshop']);

Route::get('/admin/jobs/rebuild-category-index', [AdminJobsController::class, 'rebuildCategoryIndex']);

Route::get('/search/products', [ProductSearchController::class, 'index']);

// Health check endpoint
Route::get('/health', \App\Http\Controllers\Api\HealthController::class);

// ====================
// MULTI-TENANT WIDGET ROUTES
// ====================

// Widget loader per tenant: /widget/{slug}.js
Route::get('/widget/{slug}.js', [\App\Http\Controllers\Api\TenantWidgetController::class, 'serveWidget'])
    ->middleware('widget.cors');

// Widget config per tenant
Route::get('/widget/{slug}/config', [\App\Http\Controllers\Api\TenantWidgetController::class, 'getConfig'])
    ->middleware('widget.cors');

// Get embed code for a tenant
Route::get('/widget/{slug}/embed', [\App\Http\Controllers\Api\TenantWidgetController::class, 'getEmbedCode'])
    ->middleware('admin.token');

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
    
    // Store Context - auto-generate prompts
    Route::post('/store-context/analyze', [\App\Http\Controllers\Api\Admin\StoreContextController::class, 'analyze']);
    Route::get('/store-context', [\App\Http\Controllers\Api\Admin\StoreContextController::class, 'show']);
    Route::post('/store-context/generate-prompt', [\App\Http\Controllers\Api\Admin\StoreContextController::class, 'generatePrompt']);
    Route::post('/store-context/create-preset', [\App\Http\Controllers\Api\Admin\StoreContextController::class, 'createPreset']);
    
    // Tenant Management (SaaS)
    Route::get('/tenants', [\App\Http\Controllers\Api\Admin\TenantController::class, 'index']);
    Route::post('/tenants', [\App\Http\Controllers\Api\Admin\TenantController::class, 'store']);
    Route::get('/tenants/{id}', [\App\Http\Controllers\Api\Admin\TenantController::class, 'show']);
    Route::put('/tenants/{id}', [\App\Http\Controllers\Api\Admin\TenantController::class, 'update']);
    Route::delete('/tenants/{id}', [\App\Http\Controllers\Api\Admin\TenantController::class, 'destroy']);
    Route::post('/tenants/{id}/suspend', [\App\Http\Controllers\Api\Admin\TenantController::class, 'suspend']);
    Route::post('/tenants/{id}/reactivate', [\App\Http\Controllers\Api\Admin\TenantController::class, 'reactivate']);
    Route::post('/tenants/{id}/reset-usage', [\App\Http\Controllers\Api\Admin\TenantController::class, 'resetUsage']);
    Route::get('/tenants/{id}/usage', [\App\Http\Controllers\Api\Admin\TenantController::class, 'usage']);
});

// ==================== BILLING WEBHOOKS ====================
// These endpoints are called by payment providers (WayForPay, LiqPay)
// They should be excluded from CSRF protection in VerifyCsrfToken middleware
Route::prefix('billing/webhook')->group(function () {
    Route::post('/wayforpay', [BillingWebhookController::class, 'wayforpay'])->name('billing.webhook.wayforpay');
    Route::post('/liqpay', [BillingWebhookController::class, 'liqpay'])->name('billing.webhook.liqpay');
});

// ==================== TELEGRAM BOT WEBHOOK ====================
Route::post('/telegram/webhook', [\App\Http\Controllers\Api\TelegramWebhookController::class, 'handle'])
    ->name('telegram.webhook');
Route::post('/telegram/generate-code', [\App\Http\Controllers\Api\TelegramWebhookController::class, 'generateCode'])
    ->middleware('admin.token')
    ->name('telegram.generate-code');

// ==================== CANNED RESPONSES (OPERATOR) ====================
Route::prefix('canned-responses')->middleware('admin.token')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\CannedResponseController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\CannedResponseController::class, 'store']);
    Route::get('/suggestions', [\App\Http\Controllers\Api\CannedResponseController::class, 'suggestions']);
    Route::post('/find-shortcut', [\App\Http\Controllers\Api\CannedResponseController::class, 'findByShortcut']);
    Route::post('/seed-defaults', [\App\Http\Controllers\Api\CannedResponseController::class, 'seedDefaults']);
    Route::get('/{id}', [\App\Http\Controllers\Api\CannedResponseController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Api\CannedResponseController::class, 'update']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\CannedResponseController::class, 'destroy']);
    Route::post('/{id}/use', [\App\Http\Controllers\Api\CannedResponseController::class, 'use']);
});

// ==================== ANALYTICS EXPORT ====================
Route::prefix('export')->middleware('admin.token')->group(function () {
    Route::get('/chat-sessions', [\App\Http\Controllers\Api\AnalyticsExportController::class, 'chatSessions']);
    Route::get('/chat-messages', [\App\Http\Controllers\Api\AnalyticsExportController::class, 'chatMessages']);
    Route::get('/events', [\App\Http\Controllers\Api\AnalyticsExportController::class, 'events']);
    Route::get('/payments', [\App\Http\Controllers\Api\AnalyticsExportController::class, 'payments']);
    Route::get('/conversion-funnel', [\App\Http\Controllers\Api\AnalyticsExportController::class, 'conversionFunnel']);
    Route::get('/daily-summary', [\App\Http\Controllers\Api\AnalyticsExportController::class, 'dailySummary']);
    Route::get('/product-mentions', [\App\Http\Controllers\Api\AnalyticsExportController::class, 'productMentions']);
});
