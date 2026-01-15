<?php

namespace App\Services\Analytics;

use App\Models\ChatEvent;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Analytics Export Service - export chat data, analytics, and reports.
 */
class AnalyticsExportService
{
    /**
     * Export chat sessions as CSV.
     */
    public function exportChatSessions(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $format = 'csv'
    ): array {
        $query = ChatSession::where('tenant_id', $tenantId)
            ->with(['messages' => fn($q) => $q->orderBy('created_at')])
            ->orderByDesc('created_at');

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $sessions = $query->get();

        $rows = [];
        foreach ($sessions as $session) {
            $firstMessage = $session->messages->first();
            $lastMessage = $session->messages->last();
            $userMessages = $session->messages->where('role', 'user')->count();
            $assistantMessages = $session->messages->where('role', 'assistant')->count();
            
            $rows[] = [
                'session_id' => $session->session_id,
                'started_at' => $session->created_at->toDateTimeString(),
                'ended_at' => $lastMessage ? $lastMessage->created_at->toDateTimeString() : '',
                'duration_minutes' => $lastMessage 
                    ? round($session->created_at->diffInMinutes($lastMessage->created_at), 1) 
                    : 0,
                'total_messages' => $session->messages->count(),
                'user_messages' => $userMessages,
                'assistant_messages' => $assistantMessages,
                'first_question' => $firstMessage?->content ? mb_substr($firstMessage->content, 0, 200) : '',
                'operator_takeover' => $session->operator_id ? 'Так' : 'Ні',
                'locale' => $session->meta['locale'] ?? 'uk',
                'utm_source' => $session->meta['utm_source'] ?? '',
                'utm_campaign' => $session->meta['utm_campaign'] ?? '',
                'page_url' => $session->meta['page_url'] ?? '',
            ];
        }

        return $this->formatOutput($rows, $format, 'chat_sessions');
    }

    /**
     * Export chat messages as CSV.
     */
    public function exportChatMessages(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $sessionId = null,
        ?string $format = 'csv'
    ): array {
        $query = ChatMessage::whereHas('session', function ($q) use ($tenantId) {
            $q->where('tenant_id', $tenantId);
        })->orderByDesc('created_at');

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }
        if ($sessionId) {
            $query->whereHas('session', fn($q) => $q->where('session_id', $sessionId));
        }

        $messages = $query->with('session')->get();

        $rows = [];
        foreach ($messages as $message) {
            $rows[] = [
                'session_id' => $message->session?->session_id ?? '',
                'timestamp' => $message->created_at->toDateTimeString(),
                'role' => $this->translateRole($message->role),
                'content' => $message->content,
                'products_shown' => isset($message->meta['products']) 
                    ? count($message->meta['products']) 
                    : 0,
            ];
        }

        return $this->formatOutput($rows, $format, 'chat_messages');
    }

    /**
     * Export analytics events.
     */
    public function exportEvents(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $eventType = null,
        ?string $format = 'csv'
    ): array {
        $query = ChatEvent::where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }
        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        $events = $query->limit(10000)->get();

        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'timestamp' => $event->created_at->toDateTimeString(),
                'session_id' => $event->session_id,
                'event_type' => $event->event_type,
                'event_data' => json_encode($event->event_data, JSON_UNESCAPED_UNICODE),
                'product_id' => $event->event_data['product_id'] ?? '',
                'product_title' => $event->event_data['product_title'] ?? '',
                'value' => $event->event_data['value'] ?? '',
            ];
        }

        return $this->formatOutput($rows, $format, 'analytics_events');
    }

    /**
     * Export payment history.
     */
    public function exportPayments(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $format = 'csv'
    ): array {
        $query = Payment::where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $payments = $query->get();

        $rows = [];
        foreach ($payments as $payment) {
            $rows[] = [
                'date' => $payment->created_at->toDateTimeString(),
                'transaction_id' => $payment->transaction_id,
                'provider' => $payment->provider,
                'plan' => $payment->plan_id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $this->translatePaymentStatus($payment->status),
                'period_start' => $payment->period_start?->toDateString() ?? '',
                'period_end' => $payment->period_end?->toDateString() ?? '',
            ];
        }

        return $this->formatOutput($rows, $format, 'payments');
    }

    /**
     * Export conversion funnel data.
     */
    public function exportConversionFunnel(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $format = 'csv'
    ): array {
        // Get funnel stages aggregated by day
        $query = ChatEvent::where('tenant_id', $tenantId)
            ->select(
                DB::raw('DATE(created_at) as date'),
                'event_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('COUNT(DISTINCT session_id) as unique_sessions')
            )
            ->groupBy(DB::raw('DATE(created_at)'), 'event_type');

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $events = $query->get()->groupBy('date');

        $rows = [];
        foreach ($events as $date => $dayEvents) {
            $eventCounts = $dayEvents->pluck('count', 'event_type');
            $sessionCounts = $dayEvents->pluck('unique_sessions', 'event_type');
            
            $rows[] = [
                'date' => $date,
                'widget_opened' => $eventCounts['widget_opened'] ?? 0,
                'chat_started' => $eventCounts['chat_started'] ?? 0,
                'product_viewed' => $eventCounts['product_viewed'] ?? 0,
                'product_clicked' => $eventCounts['product_clicked'] ?? 0,
                'add_to_cart' => $eventCounts['add_to_cart'] ?? 0,
                'purchase' => $eventCounts['purchase'] ?? 0,
                'unique_sessions' => max($sessionCounts->values()->all() ?: [0]),
            ];
        }

        // Sort by date
        usort($rows, fn($a, $b) => $a['date'] <=> $b['date']);

        return $this->formatOutput($rows, $format, 'conversion_funnel');
    }

    /**
     * Export daily summary report.
     */
    public function exportDailySummary(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $format = 'csv'
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(30)->toDateString();
        $dateTo = $dateTo ?? now()->toDateString();

        // Get daily chat stats
        $chatStats = ChatSession::where('tenant_id', $tenantId)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as chats'),
                DB::raw('SUM(CASE WHEN operator_id IS NOT NULL THEN 1 ELSE 0 END) as operator_chats')
            )
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get()
            ->keyBy('date');

        // Get daily message stats
        $messageStats = ChatMessage::whereHas('session', fn($q) => $q->where('tenant_id', $tenantId))
            ->select(
                DB::raw('DATE(chat_messages.created_at) as date'),
                DB::raw('COUNT(*) as messages')
            )
            ->whereDate('chat_messages.created_at', '>=', $dateFrom)
            ->whereDate('chat_messages.created_at', '<=', $dateTo)
            ->groupBy(DB::raw('DATE(chat_messages.created_at)'))
            ->get()
            ->keyBy('date');

        // Get daily event stats
        $eventStats = ChatEvent::where('tenant_id', $tenantId)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw("SUM(CASE WHEN event_type = 'product_clicked' THEN 1 ELSE 0 END) as product_clicks"),
                DB::raw("SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) as add_to_cart"),
                DB::raw("SUM(CASE WHEN event_type = 'purchase' THEN 1 ELSE 0 END) as purchases")
            )
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get()
            ->keyBy('date');

        // Combine all stats
        $rows = [];
        $current = \Carbon\Carbon::parse($dateFrom);
        $end = \Carbon\Carbon::parse($dateTo);

        while ($current <= $end) {
            $date = $current->toDateString();
            $chat = $chatStats[$date] ?? null;
            $message = $messageStats[$date] ?? null;
            $event = $eventStats[$date] ?? null;

            $rows[] = [
                'date' => $date,
                'day_of_week' => $this->translateDayOfWeek($current->dayOfWeek),
                'chats' => $chat?->chats ?? 0,
                'operator_chats' => $chat?->operator_chats ?? 0,
                'messages' => $message?->messages ?? 0,
                'product_clicks' => $event?->product_clicks ?? 0,
                'add_to_cart' => $event?->add_to_cart ?? 0,
                'purchases' => $event?->purchases ?? 0,
            ];

            $current->addDay();
        }

        return $this->formatOutput($rows, $format, 'daily_summary');
    }

    /**
     * Export products mentioned in chats.
     */
    public function exportProductMentions(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $format = 'csv'
    ): array {
        $query = ChatEvent::where('tenant_id', $tenantId)
            ->whereIn('event_type', ['product_viewed', 'product_clicked', 'add_to_cart'])
            ->select(
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.product_id')) as product_id"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.product_title')) as product_title"),
                DB::raw("SUM(CASE WHEN event_type = 'product_viewed' THEN 1 ELSE 0 END) as views"),
                DB::raw("SUM(CASE WHEN event_type = 'product_clicked' THEN 1 ELSE 0 END) as clicks"),
                DB::raw("SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) as cart_adds")
            )
            ->groupBy('product_id', 'product_title')
            ->orderByDesc(DB::raw("SUM(CASE WHEN event_type = 'product_clicked' THEN 1 ELSE 0 END)"));

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $products = $query->limit(500)->get();

        $rows = [];
        foreach ($products as $product) {
            if (!$product->product_id) continue;
            
            $rows[] = [
                'product_id' => $product->product_id,
                'product_title' => $product->product_title ?? '',
                'views' => $product->views,
                'clicks' => $product->clicks,
                'cart_adds' => $product->cart_adds,
                'ctr' => $product->views > 0 
                    ? round(($product->clicks / $product->views) * 100, 1) . '%'
                    : '0%',
            ];
        }

        return $this->formatOutput($rows, $format, 'product_mentions');
    }

    /**
     * Format output as CSV or JSON.
     */
    private function formatOutput(array $rows, string $format, string $filename): array
    {
        if ($format === 'json') {
            return [
                'format' => 'json',
                'filename' => "{$filename}.json",
                'content' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                'mime' => 'application/json',
                'rows_count' => count($rows),
            ];
        }

        // Default: CSV
        if (empty($rows)) {
            return [
                'format' => 'csv',
                'filename' => "{$filename}.csv",
                'content' => '',
                'mime' => 'text/csv',
                'rows_count' => 0,
            ];
        }

        $csv = [];
        
        // Header
        $csv[] = implode(',', array_map(fn($h) => $this->escapeCsv($h), array_keys($rows[0])));
        
        // Data rows
        foreach ($rows as $row) {
            $csv[] = implode(',', array_map(fn($v) => $this->escapeCsv($v), $row));
        }

        return [
            'format' => 'csv',
            'filename' => "{$filename}.csv",
            'content' => implode("\n", $csv),
            'mime' => 'text/csv; charset=utf-8',
            'rows_count' => count($rows),
        ];
    }

    /**
     * Escape CSV value.
     */
    private function escapeCsv($value): string
    {
        $value = (string) $value;
        
        // Escape quotes and wrap in quotes if needed
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }
        
        return $value;
    }

    /**
     * Translate role to Ukrainian.
     */
    private function translateRole(string $role): string
    {
        return match($role) {
            'user' => 'Клієнт',
            'assistant' => 'Бот',
            'operator' => 'Оператор',
            'system' => 'Система',
            default => $role,
        };
    }

    /**
     * Translate payment status to Ukrainian.
     */
    private function translatePaymentStatus(string $status): string
    {
        return match($status) {
            'pending' => 'Очікує',
            'success' => 'Успішно',
            'failed' => 'Помилка',
            'refunded' => 'Повернено',
            default => $status,
        };
    }

    /**
     * Translate day of week to Ukrainian.
     */
    private function translateDayOfWeek(int $day): string
    {
        return match($day) {
            0 => 'Неділя',
            1 => 'Понеділок',
            2 => 'Вівторок',
            3 => 'Середа',
            4 => 'Четвер',
            5 => 'Пʼятниця',
            6 => 'Субота',
            default => '',
        };
    }
}
