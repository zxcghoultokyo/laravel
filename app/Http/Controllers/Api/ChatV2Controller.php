<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Agent\MinimalAgent;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ChatV2Controller - експериментальний endpoint з MinimalAgent.
 * 
 * A/B тест: мінімальний промпт vs поточний складний промпт.
 * URL: POST /api/chat/v2
 */
class ChatV2Controller extends Controller
{
    private const MAX_MESSAGE_LENGTH = 2000;

    public function handle(Request $request)
    {
        $requestId = (string) Str::uuid();
        $startTime = microtime(true);

        Log::info('ChatV2Controller: incoming', [
            'request_id' => $requestId,
            'payload' => $request->except('api_key'),
        ]);

        try {
            // Validate
            $message = trim($request->input('message', ''));
            $sessionId = $request->input('session_id');
            $tenantId = $request->input('tenant_id');
            $isTrigger = (bool) $request->input('is_trigger', false);

            if (empty($message)) {
                return response()->json([
                    'type' => 'text',
                    'text' => 'Напишіть запит',
                    'session_id' => $sessionId,
                ]);
            }

            if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                return response()->json([
                    'type' => 'text', 
                    'text' => 'Повідомлення занадто довге',
                    'session_id' => $sessionId,
                ], 400);
            }

            // Resolve tenant
            $tenant = null;
            if ($tenantId) {
                $tenant = Tenant::find($tenantId);
            }

            // Build context
            $context = [
                'session_id' => $sessionId,
                'tenant_id' => $tenantId,
                'is_trigger' => $isTrigger,
                'shop_name' => $tenant?->name ?? 'магазин',
                'shop_phone' => $tenant?->phone ?? '',
            ];

            // Create agent with dependencies
            $searchTool = app(MeiliProductSearchTool::class);
            $detailsTool = app(ProductDetailsTool::class);

            $agent = new MinimalAgent($searchTool, $detailsTool);
            
            if ($tenantId) {
                $agent->setTenantId($tenantId);
            }

            // Process
            $result = $agent->handle($message, $context);

            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            // Format response (same structure as v1 for frontend compatibility)
            $response = [
                'type' => !empty($result['products']) ? 'products' : 'text',
                'text' => $result['message'] ?? '',
                'data' => !empty($result['products']) ? [
                    'products' => $result['products'],
                    'count' => count($result['products']),
                ] : null,
                'products' => $result['products'] ?? [], // Also include at top level
                'session_id' => $sessionId,
                'meta' => array_merge($result['meta'] ?? [], [
                    'request_id' => $requestId,
                    'response_time_ms' => $responseTime,
                    'agent_version' => 'v2_minimal',
                ]),
            ];

            Log::info('ChatV2Controller: response', [
                'request_id' => $requestId,
                'type' => $response['type'],
                'products_count' => count($result['products'] ?? []),
                'response_time_ms' => $responseTime,
            ]);

            return response()->json($response);

        } catch (\Throwable $e) {
            Log::error('ChatV2Controller: exception', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'type' => 'text',
                'text' => 'Помилка. Спробуйте ще раз.',
                'session_id' => $request->input('session_id'),
                'meta' => ['request_id' => $requestId, 'error' => true],
            ], 500);
        }
    }

    /**
     * Comparison endpoint - run both v1 and v2 and compare.
     */
    public function compare(Request $request)
    {
        try {
            $message = $request->input('message', '');
            $sessionId = $request->input('session_id', 'compare_' . Str::random(8));
            $tenantId = $request->input('tenant_id');

            // Run v1
            $v1Start = microtime(true);
            $v1Response = app(\App\Services\Chat\ChatService::class)->handleMessage($message, $sessionId . '_v1');
            $v1Time = (int) ((microtime(true) - $v1Start) * 1000);

            // Run v2
            $v2Start = microtime(true);
            $searchTool = app(MeiliProductSearchTool::class);
            $detailsTool = app(ProductDetailsTool::class);
            
            $agent = new MinimalAgent($searchTool, $detailsTool);
            if ($tenantId) {
                $agent->setTenantId($tenantId);
            }
            
            $v2Response = $agent->handle($message, [
                'session_id' => $sessionId . '_v2',
                'tenant_id' => $tenantId,
            ]);
            $v2Time = (int) ((microtime(true) - $v2Start) * 1000);

            return response()->json([
                'message' => $message,
                'v1' => [
                    'text' => $v1Response['text'] ?? $v1Response['message'] ?? '',
                    'products' => array_map(fn($p) => [
                        'title' => $p['title'] ?? '',
                        'price' => $p['price'] ?? 0,
                        'article' => $p['article'] ?? '',
                    ], $v1Response['products'] ?? []),
                    'response_time_ms' => $v1Time,
                ],
                'v2' => [
                    'text' => $v2Response['message'] ?? '',
                    'products' => array_map(fn($p) => [
                        'title' => $p['title'] ?? '',
                        'price' => $p['price'] ?? 0,
                        'article' => $p['article'] ?? '',
                    ], $v2Response['products'] ?? []),
                    'response_time_ms' => $v2Time,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
}
