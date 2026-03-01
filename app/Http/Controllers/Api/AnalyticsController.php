<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AnalyticsController extends Controller
{
    /**
     * Receive batch of analytics events from widget
     */
    public function events(Request $request)
    {
        // Read raw body directly from php://input (most reliable)
        $rawBody = file_get_contents('php://input');
        
        // Log incoming request for debugging
        Log::info('Analytics events received', [
            'content_type' => $request->header('Content-Type'),
            'content_length' => strlen($rawBody),
            'raw_body_preview' => substr($rawBody, 0, 500),
        ]);
        
        // Parse JSON
        $data = json_decode($rawBody, true);
        
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Analytics JSON parse error', [
                'error' => json_last_error_msg(),
                'raw_body' => substr($rawBody, 0, 200),
            ]);
            return response()->json(['status' => 'ok', 'received' => 0, 'error' => 'json_parse_failed']);
        }
        
        $events = $data['events'] ?? [];

        Log::info('Analytics events parsed', [
            'events_count' => count($events),
            'event_types' => array_column($events, 'event_type'),
        ]);

        if (empty($events)) {
            return response()->json(['status' => 'ok', 'received' => 0, 'version' => 'v5']);
        }

        $inserted = 0;

        try {
            $rows = [];
            
            // Pre-resolve tenant_id from merchant_id for all events
            // merchant_id can be either tenant slug or api_token
            $tenantCache = [];
            
            foreach ($events as $event) {
                // Validate required fields
                if (empty($event['event_type']) || empty($event['session_id'])) {
                    continue;
                }
                
                // Skip checkout and add_to_cart events without actual chat conversation
                // These are noise from users who never used the chat
                $eventType = $event['event_type'] ?? '';
                if (in_array($eventType, ['checkout_submit', 'checkout_success', 'add_to_cart'])) {
                    $hadChat = !empty($event['had_chat_conversation']);
                    $fromChat = !empty($event['product_from_chat']);
                    if (!$hadChat && !$fromChat) {
                        Log::debug('Skipping event without chat conversation', [
                            'event_type' => $eventType,
                            'session_id' => $event['session_id'] ?? ''
                        ]);
                        continue;
                    }
                }

                // Convert ISO 8601 timestamp to MySQL format
                $createdAt = now();
                if (!empty($event['timestamp'])) {
                    try {
                        $createdAt = \Carbon\Carbon::parse($event['timestamp'])->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        $createdAt = now();
                    }
                }

                // Build metadata from extra fields
                $metadata = $event['metadata'] ?? [];
                if (!empty($event['had_chat_conversation'])) {
                    $metadata['had_chat_conversation'] = $event['had_chat_conversation'];
                }
                if (!empty($event['product_from_chat'])) {
                    $metadata['product_from_chat'] = $event['product_from_chat'];
                }
                if (!empty($event['chat_session_id'])) {
                    $metadata['chat_session_id'] = $event['chat_session_id'];
                }
                // Store product title for display in analytics
                if (!empty($event['product_title'])) {
                    $metadata['product_title'] = $event['product_title'];
                }
                // Store order/checkout fields in metadata for display in dashboard
                if (!empty($event['order_id'])) {
                    $metadata['order_id'] = $event['order_id'];
                }
                if (!empty($event['order_total'])) {
                    $metadata['order_total'] = $event['order_total'];
                }
                if (!empty($event['items_count'])) {
                    $metadata['items_count'] = $event['items_count'];
                }
                if (!empty($event['order_items_count'])) {
                    $metadata['order_items_count'] = $event['order_items_count'];
                }
                if (!empty($event['has_product_from_chat'])) {
                    $metadata['has_product_from_chat'] = $event['has_product_from_chat'];
                }
                if (!empty($event['customer_name']) || !empty($event['name'])) {
                    $metadata['customer_name'] = $event['customer_name'] ?? $event['name'];
                }
                if (!empty($event['phone'])) {
                    $metadata['phone'] = $event['phone'];
                }
                if (!empty($event['email'])) {
                    $metadata['email'] = $event['email'];
                }
                if (!empty($event['delivery_type'])) {
                    $metadata['delivery_type'] = $event['delivery_type'];
                }
                if (!empty($event['payment_type'])) {
                    $metadata['payment_type'] = $event['payment_type'];
                }
                
                // Resolve tenant_id from merchant_id (slug) or tenant_id from event
                $merchantId = $event['merchant_id'] ?? '';
                $tenantId = $event['tenant_id'] ?? null;
                
                if (!$tenantId && $merchantId) {
                    // Check cache first
                    if (!isset($tenantCache[$merchantId])) {
                        // Try to find tenant by slug first
                        $tenant = DB::table('tenants')->where('slug', $merchantId)->first();
                        if (!$tenant) {
                            // Try to find by api_token in widget_settings
                            $widgetSettings = DB::table('widget_settings')
                                ->where('api_token', $merchantId)
                                ->first();
                            if ($widgetSettings) {
                                $tenantId = $widgetSettings->tenant_id;
                            }
                        } else {
                            $tenantId = $tenant->id;
                        }
                        $tenantCache[$merchantId] = $tenantId;
                    } else {
                        $tenantId = $tenantCache[$merchantId];
                    }
                }

                $rows[] = [
                    'session_id' => substr($event['session_id'] ?? '', 0, 64),
                    'merchant_id' => substr($merchantId, 0, 64),
                    'tenant_id' => $tenantId,
                    'event_type' => substr($event['event_type'] ?? '', 0, 50),
                    'event_source' => substr($event['event_source'] ?? 'widget', 0, 30),
                    'product_id' => $event['product_id'] ?? null,
                    'product_article' => isset($event['product_article']) ? substr($event['product_article'], 0, 100) : null,
                    'product_price' => $event['product_price'] ?? null,
                    'message_type' => isset($event['message_type']) ? substr($event['message_type'], 0, 30) : null,
                    'message_text' => isset($event['message_text']) ? substr($event['message_text'], 0, 65535) : null,
                    'utm_source' => isset($event['utm_source']) ? substr($event['utm_source'], 0, 100) : null,
                    'utm_medium' => isset($event['utm_medium']) ? substr($event['utm_medium'], 0, 100) : null,
                    'utm_campaign' => isset($event['utm_campaign']) ? substr($event['utm_campaign'], 0, 100) : null,
                    'utm_content' => isset($event['utm_content']) ? substr($event['utm_content'], 0, 100) : null,
                    'utm_term' => isset($event['utm_term']) ? substr($event['utm_term'], 0, 100) : null,
                    'client_id' => isset($event['client_id']) ? substr($event['client_id'], 0, 64) : null,
                    'device_type' => isset($event['device_type']) ? substr($event['device_type'], 0, 20) : null,
                    'page_url' => isset($event['page_url']) ? substr($event['page_url'], 0, 500) : null,
                    'referrer' => isset($event['referrer']) ? substr($event['referrer'], 0, 500) : null,
                    'metadata' => !empty($metadata) ? json_encode($metadata) : null,
                    'created_at' => $createdAt,
                ];
            }

            if (!empty($rows)) {
                // Batch insert
                DB::table('chat_events')->insert($rows);
                $inserted = count($rows);
                Log::info('Analytics events inserted', ['count' => $inserted]);
                
                // Auto-create conversions for add_to_cart and checkout_submit events
                $this->createConversionsFromEvents($events);
                
                // Auto-create ChatSession for message/quick_action events
                // This ensures analytics links work even for quick actions that don't hit the agent
                $this->ensureChatSessionsExist($events);
            }

        } catch (\Throwable $e) {
            Log::error('Analytics events insert failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'events_count' => count($events)
            ]);
            return response()->json([
                'status' => 'error',
                'received' => 0,
                'version' => 'v5',
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'received' => $inserted,
            'version' => 'v5'
        ]);
    }

    /**
     * Debug endpoint to check recent events (temporary)
     */
    public function debugEvents(Request $request)
    {
        $hours = $request->get('hours', 1);
        
        try {
            $events = DB::table('chat_events')
                ->where('created_at', '>=', now()->subHours($hours))
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'event_type', 'session_id', 'created_at']);
            
            $counts = DB::table('chat_events')
                ->where('created_at', '>=', now()->subHours($hours))
                ->selectRaw('event_type, COUNT(*) as cnt')
                ->groupBy('event_type')
                ->get();
            
            return response()->json([
                'status' => 'ok',
                'hours' => $hours,
                'total' => count($events),
                'by_type' => $counts,
                'recent_events' => $events,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Echo endpoint - returns exactly what was received (for debugging)
     */
    public function echo(Request $request)
    {
        $rawBody = $request->getContent();
        $parsed = json_decode($rawBody, true);
        
        return response()->json([
            'content_type' => $request->header('Content-Type'),
            'content_length' => strlen($rawBody),
            'raw_body_first_500' => substr($rawBody, 0, 500),
            'json_parse_success' => $parsed !== null,
            'json_error' => $parsed === null ? json_last_error_msg() : null,
            'events_count' => isset($parsed['events']) ? count($parsed['events']) : 0,
            'php_input' => substr(file_get_contents('php://input'), 0, 500),
        ]);
    }

    /**
     * Track a conversion (add_to_cart, purchase, lead)
     */
    public function conversion(Request $request)
    {
        $data = $request->json()->all();

        $validator = Validator::make($data, [
            'conversion_type' => 'required|string|in:add_to_cart,checkout,purchase,lead',
            'session_id' => 'required|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid data'], 422);
        }

        try {
            // Find related chat session
            $chatSession = DB::table('chat_sessions')
                ->where('session_id', $data['session_id'])
                ->first();

            // Get products shown/clicked in this session from events
            $chatProducts = [];
            if ($chatSession) {
                $productEvents = DB::table('chat_events')
                    ->where('session_id', $data['session_id'])
                    ->whereIn('event_type', ['product_shown', 'product_click'])
                    ->whereNotNull('product_id')
                    ->pluck('product_id')
                    ->unique()
                    ->toArray();
                $chatProducts = $productEvents;
            }

            // Check if converted product was from chat
            $productFromChat = false;
            if (!empty($data['product_id']) && in_array($data['product_id'], $chatProducts)) {
                $productFromChat = true;
            }

            DB::table('chat_conversions')->insert([
                'session_id' => $data['session_id'],
                'merchant_id' => $data['merchant_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'conversion_type' => $data['conversion_type'],
                'conversion_status' => 'confirmed',
                'order_id' => $data['order_id'] ?? null,
                'order_total' => $data['order_total'] ?? null,
                'items_count' => $data['items_count'] ?? null,
                'product_ids' => !empty($data['product_ids']) ? json_encode($data['product_ids']) : null,
                'product_from_chat' => $productFromChat,
                'chat_attributed_value' => $data['chat_attributed_value'] ?? null,
                'chat_timestamp' => $chatSession->created_at ?? null,
                'conversion_timestamp' => now(),
                'minutes_to_conversion' => $chatSession 
                    ? now()->diffInMinutes($chatSession->created_at) 
                    : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update session outcome if this is the first conversion
            $this->updateSessionOutcome($data['session_id'], $data['conversion_type']);

            return response()->json(['status' => 'ok']);

        } catch (\Throwable $e) {
            Log::error('Conversion tracking failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return response()->json(['error' => 'Failed to track'], 500);
        }
    }

    /**
     * Receive webhook from merchant's e-commerce platform
     */
    public function webhook(Request $request)
    {
        $data = $request->json()->all();
        $type = $data['type'] ?? $request->header('X-Webhook-Type', 'unknown');

        Log::info('Analytics webhook received', [
            'type' => $type,
            'data_keys' => array_keys($data)
        ]);

        try {
            switch ($type) {
                case 'order.created':
                case 'order.paid':
                    $this->handleOrderWebhook($data);
                    break;

                case 'cart.updated':
                    $this->handleCartWebhook($data);
                    break;

                default:
                    Log::info('Unknown webhook type', ['type' => $type]);
            }

            return response()->json(['status' => 'ok']);

        } catch (\Throwable $e) {
            Log::error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'type' => $type
            ]);
            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle order webhook
     */
    private function handleOrderWebhook(array $data)
    {
        $orderId = $data['order_id'] ?? $data['id'] ?? null;
        $orderTotal = $data['total'] ?? $data['order_total'] ?? null;
        $items = $data['items'] ?? $data['products'] ?? [];

        // Try to find associated chat session
        // Option 1: By client_id cookie
        $clientId = $data['client_id'] ?? $data['customer']['client_id'] ?? null;
        
        // Option 2: By UTM params
        $utmContent = $data['utm_content'] ?? null; // We put session_id there

        // Option 3: By product matching within attribution window
        $productIds = array_column($items, 'product_id') ?: array_column($items, 'id');

        $sessionId = $utmContent;

        if (!$sessionId && $clientId) {
            // Find recent session by client_id
            $recentSession = DB::table('chat_events')
                ->where('client_id', $clientId)
                ->where('created_at', '>=', now()->subHours(72))
                ->orderBy('created_at', 'desc')
                ->value('session_id');
            $sessionId = $recentSession;
        }

        if (!$sessionId && !empty($productIds)) {
            // Find session where these products were clicked
            $sessionWithProduct = DB::table('chat_events')
                ->whereIn('product_id', $productIds)
                ->where('event_type', 'product_click')
                ->where('created_at', '>=', now()->subHours(72))
                ->orderBy('created_at', 'desc')
                ->value('session_id');
            $sessionId = $sessionWithProduct;
        }

        if ($sessionId) {
            // Determine how much revenue is attributable to chat
            $chatClickedProducts = DB::table('chat_events')
                ->where('session_id', $sessionId)
                ->where('event_type', 'product_click')
                ->whereIn('product_id', $productIds)
                ->pluck('product_id')
                ->toArray();

            $chatAttributedValue = 0;
            foreach ($items as $item) {
                $itemId = $item['product_id'] ?? $item['id'];
                if (in_array($itemId, $chatClickedProducts)) {
                    $chatAttributedValue += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                }
            }

            DB::table('chat_conversions')->insert([
                'session_id' => $sessionId,
                'merchant_id' => $data['merchant_id'] ?? null,
                'client_id' => $clientId,
                'conversion_type' => 'purchase',
                'conversion_status' => 'confirmed',
                'order_id' => $orderId,
                'order_total' => $orderTotal,
                'items_count' => count($items),
                'product_ids' => json_encode($productIds),
                'product_from_chat' => !empty($chatClickedProducts),
                'chat_attributed_value' => $chatAttributedValue > 0 ? $chatAttributedValue : null,
                'conversion_timestamp' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->updateSessionOutcome($sessionId, 'order_placed');

            Log::info('Order attributed to chat', [
                'order_id' => $orderId,
                'session_id' => $sessionId,
                'attributed_value' => $chatAttributedValue
            ]);
        }
    }

    /**
     * Handle cart webhook
     */
    private function handleCartWebhook(array $data)
    {
        // Similar logic to order webhook but for add_to_cart
        $clientId = $data['client_id'] ?? null;
        $productId = $data['product_id'] ?? null;

        if (!$clientId && !$productId) return;

        $sessionId = DB::table('chat_events')
            ->where(function ($q) use ($clientId, $productId) {
                if ($clientId) $q->orWhere('client_id', $clientId);
                if ($productId) $q->orWhere('product_id', $productId);
            })
            ->where('created_at', '>=', now()->subHours(72))
            ->orderBy('created_at', 'desc')
            ->value('session_id');

        if ($sessionId) {
            DB::table('chat_conversions')->insert([
                'session_id' => $sessionId,
                'merchant_id' => $data['merchant_id'] ?? null,
                'client_id' => $clientId,
                'conversion_type' => 'add_to_cart',
                'conversion_status' => 'confirmed',
                'product_ids' => $productId ? json_encode([$productId]) : null,
                'conversion_timestamp' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->updateSessionOutcome($sessionId, 'add_to_cart');
        }
    }

    /**
     * Update session outcome
     */
    private function updateSessionOutcome(string $sessionId, string $outcome)
    {
        $outcomeCategory = match ($outcome) {
            'order_placed', 'purchase', 'add_to_cart', 'lead_captured', 'handoff_to_manager' => 'success',
            'no_answer', 'no_relevant_products' => 'failure',
            default => 'neutral'
        };

        DB::table('chat_session_outcomes')->updateOrInsert(
            ['session_id' => $sessionId],
            [
                'outcome' => $outcome,
                'outcome_category' => $outcomeCategory,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Get analytics dashboard data for a merchant
     */
    public function dashboard(Request $request)
    {
        $merchantId = $request->input('merchant_id');
        $days = $request->input('days', 30);
        $startDate = now()->subDays($days)->startOfDay();

        // Sessions count
        $sessionsCount = DB::table('chat_events')
            ->where('merchant_id', $merchantId)
            ->where('event_type', 'session_start')
            ->where('created_at', '>=', $startDate)
            ->count();

        // Messages count
        $messagesCount = DB::table('chat_events')
            ->where('merchant_id', $merchantId)
            ->where('event_type', 'message')
            ->where('created_at', '>=', $startDate)
            ->count();

        // Conversions
        $conversions = DB::table('chat_conversions')
            ->where('merchant_id', $merchantId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('conversion_type, COUNT(*) as count, SUM(order_total) as total_value')
            ->groupBy('conversion_type')
            ->get()
            ->keyBy('conversion_type');

        // Product clicks
        $productClicks = DB::table('chat_events')
            ->where('merchant_id', $merchantId)
            ->where('event_type', 'product_click')
            ->where('created_at', '>=', $startDate)
            ->count();

        // Outcomes distribution
        $outcomes = DB::table('chat_session_outcomes')
            ->where('merchant_id', $merchantId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('outcome, outcome_category, COUNT(*) as count')
            ->groupBy('outcome', 'outcome_category')
            ->get();

        // Average messages per session
        $avgMessages = $messagesCount > 0 && $sessionsCount > 0 
            ? round($messagesCount / $sessionsCount, 1) 
            : 0;

        // Conversion rate
        $purchaseConversions = $conversions->get('purchase')?->count ?? 0;
        $conversionRate = $sessionsCount > 0 
            ? round(($purchaseConversions / $sessionsCount) * 100, 2) 
            : 0;

        return response()->json([
            'period' => [
                'days' => $days,
                'start' => $startDate->toDateString(),
                'end' => now()->toDateString(),
            ],
            'volume' => [
                'sessions' => $sessionsCount,
                'messages' => $messagesCount,
                'avg_messages_per_session' => $avgMessages,
            ],
            'engagement' => [
                'product_clicks' => $productClicks,
                'products_shown' => DB::table('chat_events')
                    ->where('merchant_id', $merchantId)
                    ->where('event_type', 'product_shown')
                    ->where('created_at', '>=', $startDate)
                    ->count(),
            ],
            'conversions' => [
                'add_to_cart' => $conversions->get('add_to_cart')?->count ?? 0,
                'purchases' => $purchaseConversions,
                'total_revenue' => $conversions->get('purchase')?->total_value ?? 0,
                'leads' => $conversions->get('lead')?->count ?? 0,
                'conversion_rate' => $conversionRate,
            ],
            'outcomes' => $outcomes,
        ]);
    }
    
    /**
     * Auto-create conversions from add_to_cart and checkout_submit events.
     * This ensures these events are tracked in both chat_events AND chat_conversions.
     */
    private function createConversionsFromEvents(array $events): void
    {
        foreach ($events as $event) {
            $eventType = $event['event_type'] ?? '';
            
            // Only process conversion-relevant events
            if (!in_array($eventType, ['add_to_cart', 'checkout_submit', 'checkout_success'])) {
                continue;
            }
            
            $sessionId = $event['session_id'] ?? '';
            if (empty($sessionId)) {
                continue;
            }
            
            // Skip add_to_cart events without actual chat conversation
            // These are noise from users who never used the chat
            if ($eventType === 'add_to_cart') {
                $hadChat = !empty($event['had_chat_conversation']);
                $fromChat = !empty($event['product_from_chat']);
                if (!$hadChat && !$fromChat) {
                    continue;
                }
            }
            
            // For checkout_success - fetch order details from Horoshop
            if ($eventType === 'checkout_success') {
                $this->handleCheckoutSuccess($event, $sessionId);
                continue;
            }
            
            try {
                // Determine conversion type
                $conversionType = $eventType === 'checkout_submit' ? 'checkout' : 'add_to_cart';
                
                // Find related chat session
                $chatSession = DB::table('chat_sessions')
                    ->where('session_id', $sessionId)
                    ->first();
                
                // Check if product was from chat recommendations
                $productFromChat = !empty($event['product_from_chat']);
                $hadChatConversation = !empty($event['had_chat_conversation']);
                
                // Build conversion record
                $conversionData = [
                    'session_id' => substr($sessionId, 0, 64),
                    'merchant_id' => isset($event['merchant_id']) ? substr($event['merchant_id'], 0, 64) : null,
                    'client_id' => isset($event['client_id']) ? substr($event['client_id'], 0, 64) : null,
                    'conversion_type' => $conversionType,
                    'conversion_status' => 'confirmed',
                    'order_total' => $event['product_price'] ?? $event['order_total'] ?? null,
                    'items_count' => $event['order_items_count'] ?? $event['items_count'] ?? 1,
                    'product_ids' => !empty($event['product_id']) ? json_encode([$event['product_id']]) : null,
                    'product_from_chat' => $productFromChat,
                    'chat_timestamp' => $chatSession->created_at ?? null,
                    'conversion_timestamp' => now(),
                    'minutes_to_conversion' => $chatSession 
                        ? now()->diffInMinutes($chatSession->created_at) 
                        : null,
                    'metadata' => json_encode([
                        'had_chat_conversation' => $hadChatConversation,
                        'product_article' => $event['product_article'] ?? null,
                        'product_title' => $event['product_title'] ?? null,
                        'source' => 'auto_from_event',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                
                DB::table('chat_conversions')->insert($conversionData);
                
                Log::info('Conversion auto-created from event', [
                    'event_type' => $eventType,
                    'conversion_type' => $conversionType,
                    'session_id' => $sessionId,
                    'product_from_chat' => $productFromChat,
                ]);
                
                // Update session outcome
                if ($hadChatConversation) {
                    $this->updateSessionOutcome($sessionId, $conversionType);
                }
                
            } catch (\Throwable $e) {
                Log::warning('Failed to auto-create conversion from event', [
                    'event_type' => $eventType,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
    
    /**
     * Handle checkout_success event - dispatch job to fetch order details from Horoshop
     */
    private function handleCheckoutSuccess(array $event, string $sessionId): void
    {
        $orderId = $event['order_id'] ?? $event['metadata']['order_id'] ?? null;
        
        Log::info('Checkout success event received', [
            'session_id' => $sessionId,
            'order_id' => $orderId,
            'event' => $event,
        ]);
        
        try {
            // Dispatch job to fetch order details from Horoshop API
            // Job will run with delay to allow order to be fully created in Horoshop
            \App\Jobs\FetchHoroshopOrdersJob::dispatch(
                sessionId: $sessionId,
                fromDate: now()->subMinutes(30)->format('Y-m-d H:i:s'), // Look at recent orders
                toDate: now()->addMinutes(5)->format('Y-m-d H:i:s'),
                orderIds: $orderId ? [$orderId] : null,
                linkToChat: true
            )->delay(now()->addSeconds(30)); // Wait 30s for order to be created in Horoshop
            
            Log::info('FetchHoroshopOrdersJob dispatched for checkout_success', [
                'session_id' => $sessionId,
                'order_id' => $orderId,
            ]);
            
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch FetchHoroshopOrdersJob', [
                'session_id' => $sessionId,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensure ChatSession records exist for message/quick_action events.
     * This allows analytics links to work even for interactions that don't hit the agent.
     */
    private function ensureChatSessionsExist(array $events): void
    {
        $sessionsToCreate = [];
        
        foreach ($events as $event) {
            $eventType = $event['event_type'] ?? '';
            
            // Only create sessions for chat interaction events
            if (!in_array($eventType, ['message', 'quick_action_click', 'session_start'])) {
                continue;
            }
            
            $sessionId = $event['session_id'] ?? '';
            if (empty($sessionId) || isset($sessionsToCreate[$sessionId])) {
                continue;
            }
            
            // Resolve tenant_id
            $tenantId = $event['tenant_id'] ?? null;
            if (!$tenantId && !empty($event['merchant_id'])) {
                $tenant = DB::table('tenants')->where('slug', $event['merchant_id'])->first();
                if ($tenant) {
                    $tenantId = $tenant->id;
                } else {
                    $widgetSettings = DB::table('widget_settings')
                        ->where('api_token', $event['merchant_id'])
                        ->first();
                    if ($widgetSettings) {
                        $tenantId = $widgetSettings->tenant_id;
                    }
                }
            }
            
            $sessionsToCreate[$sessionId] = [
                'session_id' => $sessionId,
                'tenant_id' => $tenantId,
                'status' => 'open',
                'started_at' => now(),
                'last_message_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        
        if (empty($sessionsToCreate)) {
            return;
        }
        
        // Insert only sessions that don't exist yet
        foreach ($sessionsToCreate as $sessionId => $data) {
            try {
                $exists = DB::table('chat_sessions')->where('session_id', $sessionId)->exists();
                if (!$exists) {
                    DB::table('chat_sessions')->insert($data);
                    Log::debug('ChatSession created from analytics event', ['session_id' => $sessionId]);
                }
            } catch (\Throwable $e) {
                // Ignore duplicate key errors (race condition)
                Log::debug('ChatSession creation skipped', ['session_id' => $sessionId, 'reason' => $e->getMessage()]);
            }
        }
    }
}
