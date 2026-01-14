<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Illuminate\Support\Facades\DB;

class ConversionAnalytics extends Component
{
    public int $days = 7;
    public array $conversions = [];
    public array $chatAttributedConversions = [];
    public ?array $selectedSession = null;
    public ?string $selectedSessionId = null;
    
    public function mount()
    {
        $this->loadData();
    }
    
    public function updatedDays()
    {
        $this->loadData();
    }
    
    public function loadData()
    {
        $startDate = now()->subDays($this->days)->startOfDay();
        
        // Get all add_to_cart events with chat context
        $this->conversions = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'add_to_cart')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($event) {
                $meta = json_decode($event->metadata ?? '{}', true);
                return [
                    'id' => $event->id,
                    'session_id' => $event->session_id,
                    'product_id' => $event->product_id,
                    'product_article' => $event->product_article,
                    'product_price' => $event->product_price,
                    'had_chat' => $meta['had_chat_conversation'] ?? false,
                    'from_chat' => $meta['product_from_chat'] ?? false,
                    'chat_session_id' => $meta['chat_session_id'] ?? $event->session_id,
                    'created_at' => $event->created_at,
                ];
            })
            ->toArray();
        
        // Get chat-attributed conversions (had conversation before adding to cart)
        $this->chatAttributedConversions = array_filter($this->conversions, fn($c) => $c['had_chat'] || $c['from_chat']);
    }
    
    public function viewSession($sessionId)
    {
        $this->selectedSessionId = $sessionId;
        
        // Get session info
        $session = DB::table('chat_sessions')
            ->where('session_id', $sessionId)
            ->first();
        
        // Get all messages in this session
        $messages = [];
        if ($session) {
            $messages = DB::table('chat_messages')
                ->where('chat_session_id', $session->id)
                ->orderBy('created_at')
                ->get()
                ->map(function ($msg) {
                    $content = $msg->content;
                    // Try to parse JSON content
                    if (str_starts_with(trim($content), '{')) {
                        $parsed = json_decode($content, true);
                        if ($parsed && isset($parsed['intro'])) {
                            $content = $parsed['intro'];
                            if (!empty($parsed['products'])) {
                                $content .= ' [' . count($parsed['products']) . ' товарів показано]';
                            }
                        }
                    }
                    return [
                        'role' => $msg->role,
                        'content' => $content,
                        'created_at' => $msg->created_at,
                    ];
                })
                ->toArray();
        }
        
        // Get products shown in this session
        $productsShown = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'product_shown')
            ->whereNotNull('product_id')
            ->select('product_id', 'product_article', 'product_price')
            ->distinct()
            ->get()
            ->toArray();
        
        // Get products clicked
        $productsClicked = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'product_click')
            ->whereNotNull('product_id')
            ->select('product_id', 'product_article', 'product_price')
            ->get()
            ->toArray();
        
        // Get add to cart events for this session
        $addedToCart = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'add_to_cart')
            ->whereNotNull('product_id')
            ->select('product_id', 'product_article', 'product_price', 'created_at')
            ->get()
            ->toArray();
        
        // Get product titles
        $productIds = collect($productsShown)
            ->merge($productsClicked)
            ->merge($addedToCart)
            ->pluck('product_id')
            ->unique()
            ->filter()
            ->toArray();
        
        $productTitles = [];
        if (!empty($productIds)) {
            $productTitles = DB::table('products')
                ->whereIn('id', $productIds)
                ->pluck('title', 'id')
                ->toArray();
        }
        
        $this->selectedSession = [
            'session_id' => $sessionId,
            'created_at' => $session->created_at ?? null,
            'messages' => $messages,
            'products_shown' => array_map(function ($p) use ($productTitles) {
                return [
                    ...(array)$p,
                    'title' => $productTitles[$p->product_id] ?? 'Unknown'
                ];
            }, $productsShown),
            'products_clicked' => array_map(function ($p) use ($productTitles) {
                return [
                    ...(array)$p,
                    'title' => $productTitles[$p->product_id] ?? 'Unknown'
                ];
            }, $productsClicked),
            'added_to_cart' => array_map(function ($p) use ($productTitles) {
                return [
                    ...(array)$p,
                    'title' => $productTitles[$p->product_id] ?? 'Unknown'
                ];
            }, $addedToCart),
        ];
    }
    
    public function closeSession()
    {
        $this->selectedSession = null;
        $this->selectedSessionId = null;
    }
    
    public function render()
    {
        return view('livewire.admin.conversion-analytics')->layout('admin.layout');
    }
}
