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
        $data = $request->json()->all();
        $events = $data['events'] ?? [];

        if (empty($events)) {
            return response()->json(['status' => 'ok', 'received' => 0]);
        }

        $inserted = 0;

        try {
            $rows = [];
            foreach ($events as $event) {
                // Validate required fields
                if (empty($event['event_type']) || empty($event['session_id'])) {
                    continue;
                }

                $rows[] = [
                    'session_id' => substr($event['session_id'] ?? '', 0, 64),
                    'merchant_id' => substr($event['merchant_id'] ?? '', 0, 64),
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
                    'metadata' => isset($event['metadata']) ? json_encode($event['metadata']) : null,
                    'created_at' => $event['timestamp'] ?? now(),
                ];
            }

            if (!empty($rows)) {
                // Batch insert
                DB::table('chat_events')->insert($rows);
                $inserted = count($rows);
            }

        } catch (\Throwable $e) {
            Log::error('Analytics events insert failed', [
                'error' => $e->getMessage(),
                'events_count' => count($events)
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'received' => $inserted
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
}
