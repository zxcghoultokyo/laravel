<?php

namespace App\Services\Notifications;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Service for sending notifications to tenant owners/operators.
 * Supports Email and Telegram channels.
 */
class NotificationService
{
    /**
     * Notification types.
     */
    public const TYPE_NEW_CHAT = 'new_chat';
    public const TYPE_ESCALATION = 'escalation';
    public const TYPE_USAGE_WARNING = 'usage_warning';
    public const TYPE_USAGE_LIMIT = 'usage_limit';
    public const TYPE_PAYMENT_SUCCESS = 'payment_success';
    public const TYPE_PAYMENT_FAILED = 'payment_failed';
    public const TYPE_TRIAL_ENDING = 'trial_ending';
    public const TYPE_SUBSCRIPTION_CANCELLED = 'subscription_cancelled';

    /**
     * Send notification to tenant.
     */
    public function notify(Tenant $tenant, string $type, array $data = []): void
    {
        $settings = $tenant->settings['notifications'] ?? [];
        
        // Check if this notification type is enabled
        if (!$this->isEnabled($settings, $type)) {
            return;
        }

        // Get channels to notify
        $channels = $this->getChannels($settings, $type);
        
        foreach ($channels as $channel) {
            try {
                match ($channel) {
                    'email' => $this->sendEmail($tenant, $type, $data),
                    'telegram' => $this->sendTelegram($tenant, $type, $data),
                    default => null,
                };
            } catch (\Exception $e) {
                Log::error("Notification failed: {$channel}", [
                    'tenant_id' => $tenant->id,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send email notification.
     */
    protected function sendEmail(Tenant $tenant, string $type, array $data): void
    {
        $recipients = $this->getEmailRecipients($tenant, $type);
        
        if (empty($recipients)) {
            return;
        }

        $subject = $this->getEmailSubject($type, $data);
        $content = $this->getEmailContent($type, $data);

        foreach ($recipients as $email) {
            Mail::raw($content, function ($message) use ($email, $subject, $tenant) {
                $message->to($email)
                    ->subject("[{$tenant->name}] {$subject}");
            });
        }

        Log::info('Email notification sent', [
            'tenant_id' => $tenant->id,
            'type' => $type,
            'recipients' => count($recipients),
        ]);
    }

    /**
     * Send Telegram notification.
     */
    protected function sendTelegram(Tenant $tenant, string $type, array $data): void
    {
        $settings = $tenant->settings['notifications']['telegram'] ?? [];
        $botToken = $settings['bot_token'] ?? config('services.telegram.bot_token');
        $chatId = $settings['chat_id'] ?? null;

        if (!$botToken || !$chatId) {
            return;
        }

        $message = $this->getTelegramMessage($tenant, $type, $data);
        
        $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Telegram API error: ' . $response->body());
        }

        Log::info('Telegram notification sent', [
            'tenant_id' => $tenant->id,
            'type' => $type,
            'chat_id' => $chatId,
        ]);
    }

    /**
     * Check if notification type is enabled.
     */
    protected function isEnabled(array $settings, string $type): bool
    {
        // Default enabled types
        $defaultEnabled = [
            self::TYPE_ESCALATION,
            self::TYPE_USAGE_LIMIT,
            self::TYPE_PAYMENT_FAILED,
            self::TYPE_TRIAL_ENDING,
        ];

        $enabled = $settings['enabled_types'] ?? $defaultEnabled;
        
        return in_array($type, $enabled);
    }

    /**
     * Get channels for notification type.
     */
    protected function getChannels(array $settings, string $type): array
    {
        // Critical notifications go to all channels
        $criticalTypes = [
            self::TYPE_ESCALATION,
            self::TYPE_USAGE_LIMIT,
            self::TYPE_PAYMENT_FAILED,
        ];

        if (in_array($type, $criticalTypes)) {
            return ['email', 'telegram'];
        }

        return $settings['channels'] ?? ['email'];
    }

    /**
     * Get email recipients for tenant.
     */
    protected function getEmailRecipients(Tenant $tenant, string $type): array
    {
        // Owner always gets critical notifications
        $owner = $tenant->users()->where('role', 'owner')->first();
        $ownerEmail = $owner?->email ?? $tenant->email;

        $recipients = [$ownerEmail];

        // Add operators for chat-related notifications
        if (in_array($type, [self::TYPE_NEW_CHAT, self::TYPE_ESCALATION])) {
            $operators = $tenant->users()
                ->whereIn('role', ['operator', 'admin'])
                ->pluck('email')
                ->toArray();
            
            $recipients = array_merge($recipients, $operators);
        }

        return array_unique(array_filter($recipients));
    }

    /**
     * Get email subject.
     */
    protected function getEmailSubject(string $type, array $data): string
    {
        return match ($type) {
            self::TYPE_NEW_CHAT => 'Новий чат',
            self::TYPE_ESCALATION => '⚠️ Ескалація: клієнт потребує допомоги',
            self::TYPE_USAGE_WARNING => 'Попередження: 80% ліміту використано',
            self::TYPE_USAGE_LIMIT => '🚨 Ліміт повідомлень вичерпано',
            self::TYPE_PAYMENT_SUCCESS => '✅ Оплата успішна',
            self::TYPE_PAYMENT_FAILED => '❌ Помилка оплати',
            self::TYPE_TRIAL_ENDING => '⏰ Пробний період закінчується',
            self::TYPE_SUBSCRIPTION_CANCELLED => 'Підписку скасовано',
            default => 'Сповіщення',
        };
    }

    /**
     * Get email content.
     */
    protected function getEmailContent(string $type, array $data): string
    {
        $content = match ($type) {
            self::TYPE_NEW_CHAT => $this->formatNewChatEmail($data),
            self::TYPE_ESCALATION => $this->formatEscalationEmail($data),
            self::TYPE_USAGE_WARNING => $this->formatUsageWarningEmail($data),
            self::TYPE_USAGE_LIMIT => $this->formatUsageLimitEmail($data),
            self::TYPE_PAYMENT_SUCCESS => $this->formatPaymentSuccessEmail($data),
            self::TYPE_PAYMENT_FAILED => $this->formatPaymentFailedEmail($data),
            self::TYPE_TRIAL_ENDING => $this->formatTrialEndingEmail($data),
            self::TYPE_SUBSCRIPTION_CANCELLED => $this->formatSubscriptionCancelledEmail($data),
            default => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        };

        return $content;
    }

    /**
     * Get Telegram message.
     */
    protected function getTelegramMessage(Tenant $tenant, string $type, array $data): string
    {
        $storeName = $tenant->name;

        return match ($type) {
            self::TYPE_NEW_CHAT => "🆕 <b>Новий чат</b>\n\nМагазин: {$storeName}\nСесія: {$data['session_id']}\nПовідомлення: {$data['message']}",
            
            self::TYPE_ESCALATION => "⚠️ <b>ЕСКАЛАЦІЯ</b>\n\nМагазин: {$storeName}\nСесія: {$data['session_id']}\nПричина: {$data['reason']}\n\n<a href=\"{$data['admin_url']}\">Відкрити чат</a>",
            
            self::TYPE_USAGE_WARNING => "⚡ <b>Попередження</b>\n\nМагазин: {$storeName}\nВикористано: {$data['percentage']}%\nЗалишилось: {$data['remaining']} повідомлень",
            
            self::TYPE_USAGE_LIMIT => "🚨 <b>ЛІМІТ ВИЧЕРПАНО</b>\n\nМагазин: {$storeName}\n\n<a href=\"{$data['upgrade_url']}\">Оновити план</a>",
            
            self::TYPE_PAYMENT_SUCCESS => "✅ <b>Оплата успішна</b>\n\nМагазин: {$storeName}\nСума: {$data['amount']} ₴\nПлан: {$data['plan']}",
            
            self::TYPE_PAYMENT_FAILED => "❌ <b>Помилка оплати</b>\n\nМагазин: {$storeName}\nПричина: {$data['reason']}",
            
            self::TYPE_TRIAL_ENDING => "⏰ <b>Пробний період закінчується</b>\n\nМагазин: {$storeName}\nДнів залишилось: {$data['days_left']}\n\n<a href=\"{$data['upgrade_url']}\">Обрати план</a>",
            
            self::TYPE_SUBSCRIPTION_CANCELLED => "📤 <b>Підписку скасовано</b>\n\nМагазин: {$storeName}\nДіє до: {$data['ends_at']}",
            
            default => "📬 Сповіщення від {$storeName}",
        };
    }

    // Email formatters

    protected function formatNewChatEmail(array $data): string
    {
        return <<<TEXT
Новий чат розпочато

Сесія: {$data['session_id']}
Перше повідомлення: {$data['message']}
URL сторінки: {$data['page_url']}

Відкрити в панелі: {$data['admin_url']}
TEXT;
    }

    protected function formatEscalationEmail(array $data): string
    {
        return <<<TEXT
⚠️ ЕСКАЛАЦІЯ

Клієнт потребує допомоги оператора.

Сесія: {$data['session_id']}
Причина: {$data['reason']}
Останнє повідомлення: {$data['last_message']}

Відкрити чат: {$data['admin_url']}
TEXT;
    }

    protected function formatUsageWarningEmail(array $data): string
    {
        return <<<TEXT
Попередження про використання

Ви використали {$data['percentage']}% місячного ліміту повідомлень.

Використано: {$data['used']} з {$data['limit']}
Залишилось: {$data['remaining']}

Щоб уникнути перебоїв, рекомендуємо оновити план:
{$data['upgrade_url']}
TEXT;
    }

    protected function formatUsageLimitEmail(array $data): string
    {
        return <<<TEXT
🚨 ЛІМІТ ВИЧЕРПАНО

Ваш місячний ліміт повідомлень вичерпано.
Чат-бот тимчасово недоступний для відвідувачів.

Щоб відновити роботу, оновіть план:
{$data['upgrade_url']}

Або дочекайтесь початку нового місяця.
TEXT;
    }

    protected function formatPaymentSuccessEmail(array $data): string
    {
        return <<<TEXT
✅ Оплата успішна

Дякуємо за оплату!

Сума: {$data['amount']} ₴
План: {$data['plan']}
Період: {$data['period']}

Квитанція: {$data['invoice_url']}
TEXT;
    }

    protected function formatPaymentFailedEmail(array $data): string
    {
        return <<<TEXT
❌ Помилка оплати

Не вдалося списати кошти за підписку.

Причина: {$data['reason']}

Будь ласка, перевірте платіжні дані та спробуйте ще раз:
{$data['billing_url']}

Якщо проблема повторюється, зверніться до підтримки.
TEXT;
    }

    protected function formatTrialEndingEmail(array $data): string
    {
        return <<<TEXT
⏰ Пробний період закінчується

Ваш пробний період закінчиться через {$data['days_left']} днів.

Щоб продовжити користуватися Ailure AI, оберіть підходящий план:
{$data['upgrade_url']}

Плани починаються від 799 ₴/місяць.
TEXT;
    }

    protected function formatSubscriptionCancelledEmail(array $data): string
    {
        return <<<TEXT
Підписку скасовано

Вашу підписку було скасовано.

Доступ до сервісу збережено до: {$data['ends_at']}

Якщо це помилка, ви можете відновити підписку:
{$data['billing_url']}
TEXT;
    }

    /**
     * Send usage warning notification.
     */
    public function notifyUsageWarning(Tenant $tenant, int $percentage, int $remaining): void
    {
        $this->notify($tenant, self::TYPE_USAGE_WARNING, [
            'percentage' => $percentage,
            'used' => $tenant->messages_used,
            'limit' => $tenant->messages_limit,
            'remaining' => $remaining,
            'upgrade_url' => config('app.url') . '/billing',
        ]);
    }

    /**
     * Send usage limit notification.
     */
    public function notifyUsageLimit(Tenant $tenant): void
    {
        $this->notify($tenant, self::TYPE_USAGE_LIMIT, [
            'upgrade_url' => config('app.url') . '/billing',
        ]);
    }

    /**
     * Send trial ending notification.
     */
    public function notifyTrialEnding(Tenant $tenant, int $daysLeft): void
    {
        $this->notify($tenant, self::TYPE_TRIAL_ENDING, [
            'days_left' => $daysLeft,
            'upgrade_url' => config('app.url') . '/billing',
        ]);
    }

    /**
     * Send escalation notification.
     */
    public function notifyEscalation(Tenant $tenant, string $sessionId, string $reason, string $lastMessage): void
    {
        $this->notify($tenant, self::TYPE_ESCALATION, [
            'session_id' => $sessionId,
            'reason' => $reason,
            'last_message' => $lastMessage,
            'admin_url' => config('app.url') . "/admin/chats/{$sessionId}",
        ]);
    }
}
