<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Notifications\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Controller for Telegram bot webhook.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramService $telegram
    ) {}

    /**
     * Handle incoming webhook from Telegram.
     */
    public function handle(Request $request): JsonResponse
    {
        $update = $request->all();
        
        Log::info('Telegram webhook received', ['update' => $update]);

        $result = $this->telegram->processWebhook($update);
        
        if (!$result) {
            return response()->json(['ok' => true]);
        }

        // Handle different result types
        switch ($result['type'] ?? '') {
            case 'connect':
                $this->handleConnect($result);
                break;
                
            case 'status':
                $this->handleStatus($result);
                break;
                
            case 'stats':
                $this->handleStats($result);
                break;
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Generate connection code for a tenant.
     * Called from admin panel.
     */
    public function generateCode(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        
        // Generate 6-character code
        $code = strtoupper(Str::random(6));
        
        // Store in cache for 10 minutes
        Cache::put("telegram_connect_{$code}", [
            'tenant_id' => $tenant->id,
            'created_at' => now()->toDateTimeString(),
        ], now()->addMinutes(10));

        return response()->json([
            'code' => $code,
            'expires_in' => 600, // 10 minutes
            'instruction' => "Надішліть боту команду: /connect {$code}",
        ]);
    }

    /**
     * Handle /connect command.
     */
    protected function handleConnect(array $result): void
    {
        $chatId = $result['chat_id'];
        $code = $result['code'];
        $from = $result['from'];

        // Check if code exists
        $data = Cache::get("telegram_connect_{$code}");
        
        if (!$data) {
            $this->telegram->sendMessage($chatId, "❌ Невірний або застарілий код. Спробуйте отримати новий код у панелі керування.");
            return;
        }

        // Find tenant
        $tenant = Tenant::find($data['tenant_id']);
        
        if (!$tenant) {
            $this->telegram->sendMessage($chatId, "❌ Магазин не знайдено.");
            return;
        }

        // Save Telegram chat ID to tenant settings
        $settings = $tenant->settings ?? [];
        $settings['notifications']['telegram'] = [
            'chat_id' => $chatId,
            'connected_at' => now()->toDateTimeString(),
            'connected_by' => $from['username'] ?? $from['first_name'] ?? 'Unknown',
        ];
        $tenant->settings = $settings;
        $tenant->save();

        // Delete used code
        Cache::forget("telegram_connect_{$code}");

        // Send success message
        $this->telegram->sendMessage($chatId, 
            "✅ <b>Успішно підключено!</b>\n\n" .
            "Магазин: {$tenant->name}\n\n" .
            "Тепер ви будете отримувати сповіщення в цей чат.\n" .
            "Налаштувати типи сповіщень можна в панелі керування."
        );

        Log::info('Telegram connected to tenant', [
            'tenant_id' => $tenant->id,
            'chat_id' => $chatId,
        ]);
    }

    /**
     * Handle /status command.
     */
    protected function handleStatus(array $result): void
    {
        $chatId = $result['chat_id'];

        // Find tenant by chat ID
        $tenant = $this->findTenantByChatId($chatId);
        
        if (!$tenant) {
            $this->telegram->sendMessage($chatId, 
                "❌ Цей чат не підключено до жодного магазину.\n\n" .
                "Використовуйте /connect CODE для підключення."
            );
            return;
        }

        $status = $tenant->isActive() ? '✅ Активний' : '❌ Неактивний';
        $plan = $tenant->getPlanLabel();
        $usage = $tenant->messages_used . ' / ' . $tenant->messages_limit;

        $this->telegram->sendMessage($chatId,
            "📊 <b>Статус підключення</b>\n\n" .
            "Магазин: {$tenant->name}\n" .
            "Статус: {$status}\n" .
            "План: {$plan}\n" .
            "Використання: {$usage} повідомлень"
        );
    }

    /**
     * Handle /stats command.
     */
    protected function handleStats(array $result): void
    {
        $chatId = $result['chat_id'];

        $tenant = $this->findTenantByChatId($chatId);
        
        if (!$tenant) {
            $this->telegram->sendMessage($chatId, "❌ Чат не підключено до магазину.");
            return;
        }

        // Get today's stats
        $today = now()->startOfDay();
        
        $sessionsToday = $tenant->chatSessions()
            ->where('created_at', '>=', $today)
            ->count();
            
        $messagesTotal = $tenant->messages_used;
        $messagesLimit = $tenant->messages_limit;
        $percentage = $messagesLimit > 0 ? round(($messagesTotal / $messagesLimit) * 100) : 0;

        $this->telegram->sendMessageWithButtons($chatId,
            "📈 <b>Статистика за сьогодні</b>\n\n" .
            "Нових чатів: {$sessionsToday}\n" .
            "Повідомлень цього місяця: {$messagesTotal}\n" .
            "Ліміт: {$messagesLimit}\n" .
            "Використано: {$percentage}%",
            [
                [
                    ['text' => '📊 Детальна статистика', 'url' => config('app.url') . '/dashboard'],
                ],
            ]
        );
    }

    /**
     * Find tenant by Telegram chat ID.
     */
    protected function findTenantByChatId(string $chatId): ?Tenant
    {
        return Tenant::whereJsonContains('settings->notifications->telegram->chat_id', $chatId)->first()
            ?? Tenant::where('settings->notifications->telegram->chat_id', $chatId)->first();
    }
}
