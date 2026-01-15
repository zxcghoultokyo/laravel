<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Bot service for notifications and commands.
 */
class TelegramService
{
    protected string $botToken;
    protected string $apiUrl = 'https://api.telegram.org/bot';

    public function __construct(?string $botToken = null)
    {
        $this->botToken = $botToken ?? config('services.telegram.bot_token', '');
    }

    /**
     * Send a message to a chat.
     */
    public function sendMessage(string $chatId, string $text, array $options = []): bool
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $options);

        return $this->request('sendMessage', $params);
    }

    /**
     * Send message with inline keyboard.
     */
    public function sendMessageWithButtons(string $chatId, string $text, array $buttons): bool
    {
        $keyboard = [
            'inline_keyboard' => array_map(function ($row) {
                return array_map(function ($button) {
                    return [
                        'text' => $button['text'],
                        'url' => $button['url'] ?? null,
                        'callback_data' => $button['callback'] ?? null,
                    ];
                }, $row);
            }, $buttons),
        ];

        return $this->sendMessage($chatId, $text, [
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Set webhook URL.
     */
    public function setWebhook(string $url): bool
    {
        return $this->request('setWebhook', [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    /**
     * Delete webhook.
     */
    public function deleteWebhook(): bool
    {
        return $this->request('deleteWebhook');
    }

    /**
     * Get bot info.
     */
    public function getMe(): ?array
    {
        $response = Http::get($this->apiUrl . $this->botToken . '/getMe');
        
        if ($response->successful()) {
            $data = $response->json();
            return $data['result'] ?? null;
        }
        
        return null;
    }

    /**
     * Process incoming webhook update.
     */
    public function processWebhook(array $update): ?array
    {
        // Handle message
        if (isset($update['message'])) {
            return $this->handleMessage($update['message']);
        }

        // Handle callback query (button press)
        if (isset($update['callback_query'])) {
            return $this->handleCallback($update['callback_query']);
        }

        return null;
    }

    /**
     * Handle incoming message.
     */
    protected function handleMessage(array $message): array
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $from = $message['from'] ?? [];

        // Commands
        if (str_starts_with($text, '/')) {
            return $this->handleCommand($chatId, $text, $from);
        }

        return [
            'type' => 'message',
            'chat_id' => $chatId,
            'text' => $text,
            'from' => $from,
        ];
    }

    /**
     * Handle bot command.
     */
    protected function handleCommand(string $chatId, string $text, array $from): array
    {
        $parts = explode(' ', $text);
        $command = strtolower($parts[0]);
        $args = array_slice($parts, 1);

        switch ($command) {
            case '/start':
                $this->sendMessage($chatId, $this->getStartMessage());
                break;

            case '/help':
                $this->sendMessage($chatId, $this->getHelpMessage());
                break;

            case '/connect':
                // Connect Telegram to tenant
                $code = $args[0] ?? null;
                if ($code) {
                    return [
                        'type' => 'connect',
                        'chat_id' => $chatId,
                        'code' => $code,
                        'from' => $from,
                    ];
                }
                $this->sendMessage($chatId, "Для підключення використовуйте код з панелі керування:\n/connect YOUR_CODE");
                break;

            case '/status':
                return [
                    'type' => 'status',
                    'chat_id' => $chatId,
                    'from' => $from,
                ];

            case '/stats':
                return [
                    'type' => 'stats',
                    'chat_id' => $chatId,
                    'from' => $from,
                ];

            default:
                $this->sendMessage($chatId, "Невідома команда. Використовуйте /help для списку команд.");
        }

        return [
            'type' => 'command',
            'command' => $command,
            'chat_id' => $chatId,
            'from' => $from,
        ];
    }

    /**
     * Handle callback query.
     */
    protected function handleCallback(array $callback): array
    {
        $chatId = $callback['message']['chat']['id'] ?? null;
        $data = $callback['data'] ?? '';

        // Answer callback to remove loading state
        $this->request('answerCallbackQuery', [
            'callback_query_id' => $callback['id'],
        ]);

        return [
            'type' => 'callback',
            'chat_id' => $chatId,
            'data' => $data,
            'from' => $callback['from'] ?? [],
        ];
    }

    /**
     * Make API request.
     */
    protected function request(string $method, array $params = []): bool
    {
        try {
            $response = Http::post($this->apiUrl . $this->botToken . '/' . $method, $params);
            
            if (!$response->successful()) {
                Log::error('Telegram API error', [
                    'method' => $method,
                    'response' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Telegram API exception', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get start message.
     */
    protected function getStartMessage(): string
    {
        return <<<TEXT
👋 <b>Вітаємо в Ailure AI Bot!</b>

Цей бот допоможе вам отримувати сповіщення про:
• Нові чати з клієнтами
• Ескалації та запити допомоги
• Ліміти використання
• Платежі та підписки

<b>Для початку:</b>
1. Відкрийте панель керування Ailure
2. Перейдіть в Налаштування → Сповіщення
3. Натисніть "Підключити Telegram"
4. Введіть отриманий код: /connect YOUR_CODE

Використовуйте /help для списку команд.
TEXT;
    }

    /**
     * Get help message.
     */
    protected function getHelpMessage(): string
    {
        return <<<TEXT
📚 <b>Доступні команди:</b>

/start - Початок роботи
/help - Показати цю довідку
/connect CODE - Підключити до магазину
/status - Статус підключення
/stats - Статистика за сьогодні

<b>Типи сповіщень:</b>
🆕 Нові чати
⚠️ Ескалації
⚡ Попередження про ліміти
✅ Успішні оплати
❌ Помилки оплати

Налаштувати сповіщення можна в панелі керування.
TEXT;
    }
}
