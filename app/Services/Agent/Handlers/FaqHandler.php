<?php

namespace App\Services\Agent\Handlers;

use App\DTO\AgentResponseDTO;
use App\Services\Ai\AiRouter;
use App\Models\WidgetSettings;
use Illuminate\Support\Facades\Log;

/**
 * Handler for FAQ intent (delivery, payment, returns, contacts).
 */
class FaqHandler
{
    public function __construct(
        private AiRouter $aiRouter,
    ) {}

    /**
     * Handle FAQ request.
     */
    public function handle(string $message, array $plan, array $context): AgentResponseDTO
    {
        $settings = WidgetSettings::first();
        $useCustom = ($settings?->enable_faq_custom_content ?? true) === true;
        $lowerMessage = mb_strtolower($message);

        // If custom FAQ enabled, try to answer directly by keywords
        if ($useCustom) {
            $result = $this->handleByKeyword($message, $lowerMessage, $settings);
            if ($result !== null) {
                return $result;
            }
        }

        // Try general AI summarization if we have ingested texts
        $result = $this->handleByAiSummary($message, $settings);
        if ($result !== null) {
            return $result;
        }

        // Show list of available custom links
        $result = $this->handleByLinks($settings);
        if ($result !== null) {
            return $result;
        }

        // Final fallback
        return AgentResponseDTO::faq(
            'Напишіть, що шукаєте... (доставка, оплата, повернення, контакти)'
        );
    }

    /**
     * Handle FAQ by keyword matching.
     */
    private function handleByKeyword(string $message, string $lowerMessage, ?WidgetSettings $settings): ?AgentResponseDTO
    {
        $map = [
            'оплата' => ['text' => $settings?->faq_payment_delivery_text, 'url' => $settings?->faq_payment_delivery_url, 'title' => 'Оплата і доставка', 'topic' => 'payment'],
            'доставка' => ['text' => $settings?->faq_payment_delivery_text, 'url' => $settings?->faq_payment_delivery_url, 'title' => 'Оплата і доставка', 'topic' => 'delivery'],
            'повернення' => ['text' => $settings?->faq_returns_text, 'url' => $settings?->faq_returns_url, 'title' => 'Обмін та повернення', 'topic' => 'returns'],
            'обмін' => ['text' => $settings?->faq_returns_text, 'url' => $settings?->faq_returns_url, 'title' => 'Обмін та повернення', 'topic' => 'returns'],
            'контакти' => ['text' => $settings?->faq_contacts_text, 'url' => $settings?->faq_contacts_url, 'title' => 'Контактна інформація', 'topic' => 'contacts'],
            'про нас' => ['text' => $settings?->faq_about_text, 'url' => $settings?->faq_about_url, 'title' => 'Про нас', 'topic' => 'about'],
        ];

        foreach ($map as $keyword => $info) {
            if (!str_contains($lowerMessage, $keyword)) {
                continue;
            }

            $contextText = trim((string) ($info['text'] ?? ''));
            $url = $info['url'] ?? null;

            if (!empty($contextText)) {
                try {
                    $prompt = $this->buildPromptForTopic($message, $contextText, $info['topic']);
                    $reply = $this->aiRouter->callOpenAI($prompt, 0.15, 350);
                    $reply = trim($reply);

                    if (empty($reply)) {
                        $reply = mb_substr($contextText, 0, 1000);
                    } elseif (mb_strlen($reply) > 1600) {
                        $reply = mb_substr($reply, 0, 1600);
                    }

                    if (!empty($url)) {
                        $reply .= "\n\nПосилання: " . $url;
                    }

                    return AgentResponseDTO::faq($reply, $keyword);
                } catch (\Throwable $e) {
                    Log::warning('FaqHandler: AI failed', ['error' => $e->getMessage()]);
                    $fallback = !empty($url) ? ($info['title'] . ": " . $url) : ($info['title'] ?? 'FAQ');
                    return AgentResponseDTO::faq($fallback, $keyword);
                }
            }

            // No ingested text – return link or title
            $fallback = !empty($url) ? ($info['title'] . ": " . $url) : ($info['title'] ?? 'FAQ');
            return AgentResponseDTO::faq($fallback, $keyword);
        }

        return null;
    }

    /**
     * Build AI prompt based on topic.
     */
    private function buildPromptForTopic(string $message, string $contextText, string $topic): string
    {
        // Clean context text from garbage
        $contextText = $this->cleanFaqText($contextText);
        
        $basePrompt = "Користувач питає: \"{$message}\"\n\n" .
            "Контекст (з FAQ сторінки магазину):\n" . $contextText . "\n\n";

        return match ($topic) {
            'delivery' => $basePrompt .
                "Завдання: дай КОРОТКУ відповідь українською БЕЗ Markdown/емодзі ТІЛЬКИ про доставку.\n" .
                "Включи рядками: хто доставляє; куди; терміни (якщо є); вартість (якщо згадано).\n" .
                "Завершуй одним простим CTA: 'Можу підібрати товар і перевірити наявність — написати?'.\n" .
                "Не вигадуй фактів. Користуйся лише контекстом. Макс 500 символів.",
            'payment' => $basePrompt .
                "Завдання: дай КОРОТКУ відповідь українською БЕЗ Markdown/емодзі ТІЛЬКИ про оплату.\n" .
                "Включи рядками: доступні способи оплати; що найчастіше використовують (якщо згадано).\n" .
                "Завершуй CTA: 'Готові оформити? Можу підібрати товар — написати?'.\n" .
                "Не вигадуй фактів. Макс 500 символів.",
            'returns' => $basePrompt .
                "Завдання: дай КОРОТКУ відповідь українською БЕЗ Markdown/емодзі ТІЛЬКИ про повернення.\n" .
                "Включи рядками: чи можливе повернення; термін; ключові умови (1-3 пункти).\n" .
                "Завершуй CTA: 'Потрібна допомога з поверненням — написати?'.\n" .
                "Не вигадуй фактів. Макс 450 символів.",
            'contacts' => $basePrompt .
                "Завдання: дай КОРОТКУ відповідь українською БЕЗ Markdown/емодзі. Виведи ТІЛЬКИ:\n" .
                "- Телефон (якщо є)\n" .
                "- Адреса (одним рядком)\n" .
                "- Графік роботи\n" .
                "НЕ дублюй інформацію! Завершуй CTA: 'Чим можу допомогти?'. Макс 200 символів.",
            'about' => $basePrompt .
                "Завдання: дай КОРОТКУ відповідь українською БЕЗ Markdown/емодзі. Виведи:\n" .
                "- Назва магазину\n" .
                "- 1-2 речення про спеціалізацію\n" .
                "- Телефон для звʼязку\n" .
                "НЕ дублюй інформацію! НЕ пиши списки товарів! Завершуй: 'Чим можу допомогти?'. Макс 300 символів.",
            default => $basePrompt .
                "Дай коротку корисну відповідь одним блоком, без Markdown/емодзі, з CTA наприкінці. Макс 500 символів."
        };
    }

    /**
     * Clean FAQ text from garbage.
     */
    private function cleanFaqText(string $text): string
    {
        // Remove ?? artifacts
        $text = str_replace('??', '', $text);
        
        // Remove duplicate lines
        $lines = explode("\n", $text);
        $seen = [];
        $result = [];
        foreach ($lines as $line) {
            $normalized = trim(mb_strtolower($line));
            if (empty($normalized) || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $result[] = trim($line);
        }
        
        // Remove lines that are just social media handles without context
        $result = array_filter($result, function($line) {
            // Skip lines that are just @username or short "Написати" etc
            if (preg_match('/^@\w+$/', $line)) return false;
            if (trim($line) === 'Написати') return false;
            if (trim($line) === 'Замовити дзвінок') return false;
            return true;
        });
        
        return implode("\n", $result);
    }

    /**
     * Handle by AI summary of all FAQ texts.
     */
    private function handleByAiSummary(string $message, ?WidgetSettings $settings): ?AgentResponseDTO
    {
        $allTexts = array_filter([
            trim((string) ($settings?->faq_payment_delivery_text ?? '')),
            trim((string) ($settings?->faq_returns_text ?? '')),
            trim((string) ($settings?->faq_contacts_text ?? '')),
            trim((string) ($settings?->faq_about_text ?? '')),
        ], fn($t) => !empty($t));

        if (empty($allTexts)) {
            return null;
        }

        $joined = implode("\n\n---\n\n", array_map(fn($t) => mb_substr($t, 0, 2000), $allTexts));

        try {
            $prompt = "Користувач питає: \"{$message}\"\n\n" .
                "Контекст (витяги з FAQ сторінок магазину, очищені):\n" . $joined . "\n\n" .
                "Визнач одну найрелевантнішу тему: 'Оплата' або 'Доставка' або 'Повернення' або 'Контакти'.\n" .
                "Відповідай ТІЛЬКИ по цій темі, коротко, без Markdown/емодзі, 3-5 рядків. Завершуй простим CTA. Макс 500 символів.";

            $reply = $this->aiRouter->callOpenAI($prompt, 0.15, 320);
            $reply = trim($reply);

            if (empty($reply)) {
                $reply = mb_substr($joined, 0, 1000);
            } elseif (mb_strlen($reply) > 1600) {
                $reply = mb_substr($reply, 0, 1600);
            }

            // Append link based on detected topic
            $link = $this->detectRelevantLink($message, $settings);
            if ($link) {
                $reply .= "\n\nПосилання: " . $link;
            }

            return AgentResponseDTO::faq($reply, 'general');
        } catch (\Throwable $e) {
            Log::warning('FaqHandler: AI summary failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Detect relevant link based on message keywords.
     */
    private function detectRelevantLink(string $message, ?WidgetSettings $settings): ?string
    {
        $lm = mb_strtolower($message);

        if (str_contains($lm, 'достав')) {
            return $settings?->faq_payment_delivery_url;
        }
        if (str_contains($lm, 'оплат')) {
            return $settings?->faq_payment_delivery_url;
        }
        if (str_contains($lm, 'повернен') || str_contains($lm, 'обмін')) {
            return $settings?->faq_returns_url;
        }
        if (str_contains($lm, 'контакт')) {
            return $settings?->faq_contacts_url;
        }

        return null;
    }

    /**
     * Handle by showing available links.
     */
    private function handleByLinks(?WidgetSettings $settings): ?AgentResponseDTO
    {
        $links = [];

        if (!empty($settings?->faq_payment_delivery_url)) {
            $links[] = 'Оплата і доставка: ' . $settings->faq_payment_delivery_url;
        }
        if (!empty($settings?->faq_returns_url)) {
            $links[] = 'Обмін та повернення: ' . $settings->faq_returns_url;
        }
        if (!empty($settings?->faq_contacts_url)) {
            $links[] = 'Контактна інформація: ' . $settings->faq_contacts_url;
        }
        if (!empty($settings?->faq_about_url)) {
            $links[] = 'Про нас: ' . $settings->faq_about_url;
        }

        if (empty($links)) {
            return null;
        }

        $message = "Ось корисні сторінки:\n" . implode("\n", array_map(fn($l) => '• ' . $l, $links));
        return AgentResponseDTO::faq($message);
    }
}
