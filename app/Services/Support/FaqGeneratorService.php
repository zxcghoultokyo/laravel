<?php

namespace App\Services\Support;

use App\Models\WidgetSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Service for parsing website pages and generating FAQ content using AI.
 * 
 * Flow:
 * 1. Fetch HTML from configured URLs (delivery, payment, contacts, about)
 * 2. Extract meaningful text content
 * 3. Send to GPT for structured FAQ generation
 * 4. Save structured FAQ to WidgetSettings
 */
class FaqGeneratorService
{
    private const MAX_CONTENT_LENGTH = 8000;
    private const MAX_FAQ_LENGTH = 4000;
    
    /**
     * Generate FAQ from configured URLs using AI.
     */
    public function generate(WidgetSettings $settings): array
    {
        $results = [
            'success' => false,
            'sections' => [],
            'errors' => [],
        ];
        
        // Map of URL fields to their FAQ sections
        $sections = [
            'faq_payment_delivery' => [
                'url_field' => 'faq_payment_delivery_url',
                'text_field' => 'faq_payment_delivery_text',
                'name' => 'Оплата та доставка',
                'prompt_focus' => 'способи оплати, методи доставки, терміни, вартість доставки, умови безкоштовної доставки',
            ],
            'faq_returns' => [
                'url_field' => 'faq_returns_url', 
                'text_field' => 'faq_returns_text',
                'name' => 'Обмін та повернення',
                'prompt_focus' => 'умови повернення, терміни повернення, процедура обміну, гарантія',
            ],
            'faq_contacts' => [
                'url_field' => 'faq_contacts_url',
                'text_field' => 'faq_contacts_text', 
                'name' => 'Контакти',
                'prompt_focus' => 'адреса магазину, телефони, email, графік роботи, соцмережі (Instagram, Telegram, Facebook)',
            ],
            'faq_about' => [
                'url_field' => 'faq_about_url',
                'text_field' => 'faq_about_text',
                'name' => 'Про магазин',
                'prompt_focus' => 'назва магазину, спеціалізація, переваги, історія',
            ],
        ];

        $updates = [];
        $parsedContents = [];

        // Step 1: Fetch and parse all URLs
        foreach ($sections as $key => $section) {
            $url = trim((string) $settings->{$section['url_field']});
            if (empty($url)) {
                continue;
            }

            try {
                $content = $this->fetchAndParse($url);
                if ($content) {
                    $parsedContents[$key] = [
                        'content' => mb_substr($content, 0, self::MAX_CONTENT_LENGTH),
                        'section' => $section,
                        'url' => $url,
                    ];
                    $results['sections'][$key] = ['status' => 'parsed', 'url' => $url];
                } else {
                    $results['errors'][] = "Не вдалося розпарсити: {$section['name']} ({$url})";
                    $results['sections'][$key] = ['status' => 'empty', 'url' => $url];
                }
            } catch (\Throwable $e) {
                Log::warning('FaqGeneratorService: fetch failed', [
                    'url' => $url,
                    'section' => $key,
                    'error' => $e->getMessage(),
                ]);
                $results['errors'][] = "Помилка завантаження {$section['name']}: {$e->getMessage()}";
                $results['sections'][$key] = ['status' => 'error', 'url' => $url, 'error' => $e->getMessage()];
            }
        }

        if (empty($parsedContents)) {
            $results['errors'][] = 'Не знайдено жодного URL для парсингу';
            return $results;
        }

        // Step 2: Generate FAQ using AI for each section
        foreach ($parsedContents as $key => $data) {
            try {
                $faqText = $this->generateFaqWithAi($data['content'], $data['section']);
                if ($faqText) {
                    $updates[$data['section']['text_field']] = mb_substr($faqText, 0, self::MAX_FAQ_LENGTH);
                    $results['sections'][$key]['status'] = 'generated';
                    $results['sections'][$key]['preview'] = mb_substr($faqText, 0, 200) . '...';
                }
            } catch (\Throwable $e) {
                Log::error('FaqGeneratorService: AI generation failed', [
                    'section' => $key,
                    'error' => $e->getMessage(),
                ]);
                $results['errors'][] = "Помилка AI генерації для {$data['section']['name']}: {$e->getMessage()}";
                $results['sections'][$key]['status'] = 'ai_error';
            }
        }

        // Step 3: Save to database
        if (!empty($updates)) {
            $settings->fill($updates);
            $settings->save();
            
            // Clear cache for this tenant
            $tenantId = $settings->tenant_id;
            Cache::forget('widget_settings_faq:' . ($tenantId ?? 'global'));
            
            $results['success'] = true;
            $results['updated_fields'] = array_keys($updates);
        }

        return $results;
    }

    /**
     * Fetch page and extract meaningful text content.
     */
    private function fetchAndParse(string $url): ?string
    {
        $response = Http::timeout(15)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; AIntentoBot/1.0)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'uk-UA,uk;q=0.9,en;q=0.8',
            ])
            ->get($url);

        if (!$response->ok()) {
            Log::warning('FaqGeneratorService: HTTP error', [
                'url' => $url,
                'status' => $response->status(),
            ]);
            return null;
        }

        $html = (string) $response->body();
        return $this->extractContent($html);
    }

    /**
     * Extract meaningful content from HTML.
     */
    private function extractContent(string $html): ?string
    {
        // Remove scripts, styles, comments
        $html = preg_replace('#<script[\s\S]*?</script>#i', '', $html) ?? $html;
        $html = preg_replace('#<style[\s\S]*?</style>#i', '', $html) ?? $html;
        $html = preg_replace('#<noscript[\s\S]*?</noscript>#i', '', $html) ?? $html;
        $html = preg_replace('#<!--[\s\S]*?-->#', '', $html) ?? $html;
        $html = preg_replace('#<header[\s\S]*?</header>#i', '', $html) ?? $html;
        $html = preg_replace('#<footer[\s\S]*?</footer>#i', '', $html) ?? $html;
        $html = preg_replace('#<nav[\s\S]*?</nav>#i', '', $html) ?? $html;

        // Try to extract main content areas
        $content = null;
        
        // Priority 1: main, article tags
        if (preg_match('#<main[^>]*>([\s\S]*?)</main>#i', $html, $m)) {
            $content = $m[1];
        } elseif (preg_match('#<article[^>]*>([\s\S]*?)</article>#i', $html, $m)) {
            $content = $m[1];
        }
        
        // Priority 2: content div/section
        if (!$content) {
            $patterns = [
                '#<div[^>]*(?:id|class)=["\'][^"\']*(?:content|page-content|main-content|article)[^"\']*["\'][^>]*>([\s\S]*?)</div>#i',
                '#<section[^>]*(?:id|class)=["\'][^"\']*(?:content|page-content)[^"\']*["\'][^>]*>([\s\S]*?)</section>#i',
            ];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $content = $m[1];
                    break;
                }
            }
        }

        // Fallback: body content
        if (!$content) {
            if (preg_match('#<body[^>]*>([\s\S]*?)</body>#i', $html, $m)) {
                $content = $m[1];
            } else {
                $content = $html;
            }
        }

        // Convert to text
        $text = $this->htmlToText($content);
        $text = $this->cleanText($text);

        return strlen($text) > 50 ? $text : null;
    }

    /**
     * Convert HTML to readable text.
     */
    private function htmlToText(string $html): string
    {
        // IMPORTANT: Extract social media links BEFORE stripping tags
        $html = $this->extractSocialLinks($html);
        
        // Preserve some structure
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</p>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#</div>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</li>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<h[1-6][^>]*>#i', "\n### ", $html) ?? $html;
        $html = preg_replace('#</h[1-6]>#i', "\n", $html) ?? $html;
        
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $text;
    }

    /**
     * Extract social media links from HTML and convert to readable format.
     * This preserves Telegram, Instagram, Facebook, etc. links that would be lost by strip_tags.
     */
    private function extractSocialLinks(string $html): string
    {
        // Telegram links: t.me/username or telegram.me/username
        $html = preg_replace_callback(
            '#<a[^>]*href=["\']?(https?://(?:t\.me|telegram\.me)/([a-zA-Z0-9_]+))["\']?[^>]*>([^<]*)</a>#i',
            function ($m) {
                $url = $m[1];
                $username = $m[2];
                $text = trim($m[3]);
                // Return formatted: "Telegram: @username (t.me/username)"
                return " Telegram: @{$username} ({$url}) ";
            },
            $html
        ) ?? $html;

        // Instagram links: instagram.com/username
        $html = preg_replace_callback(
            '#<a[^>]*href=["\']?(https?://(?:www\.)?instagram\.com/([a-zA-Z0-9_.]+)/?)["\']?[^>]*>([^<]*)</a>#i',
            function ($m) {
                $url = $m[1];
                $username = $m[2];
                return " Instagram: @{$username} ({$url}) ";
            },
            $html
        ) ?? $html;

        // Facebook links: facebook.com/pagename
        $html = preg_replace_callback(
            '#<a[^>]*href=["\']?(https?://(?:www\.)?facebook\.com/([a-zA-Z0-9_.]+)/?)["\']?[^>]*>([^<]*)</a>#i',
            function ($m) {
                $url = $m[1];
                $pagename = $m[2];
                return " Facebook: {$pagename} ({$url}) ";
            },
            $html
        ) ?? $html;

        // Viber links
        $html = preg_replace_callback(
            '#<a[^>]*href=["\']?(viber://[^"\'>\s]+)["\']?[^>]*>([^<]*)</a>#i',
            function ($m) {
                return " Viber: {$m[1]} ";
            },
            $html
        ) ?? $html;

        // WhatsApp links: wa.me/number or api.whatsapp.com
        $html = preg_replace_callback(
            '#<a[^>]*href=["\']?(https?://(?:wa\.me|api\.whatsapp\.com)/([0-9+]+))["\']?[^>]*>([^<]*)</a>#i',
            function ($m) {
                $number = $m[2];
                return " WhatsApp: +{$number} ";
            },
            $html
        ) ?? $html;

        // Email links: mailto:
        $html = preg_replace_callback(
            '#<a[^>]*href=["\']?mailto:([^"\'>\s]+)["\']?[^>]*>([^<]*)</a>#i',
            function ($m) {
                $email = $m[1];
                return " Email: {$email} ";
            },
            $html
        ) ?? $html;

        // Phone links: tel:
        $html = preg_replace_callback(
            '#<a[^>]*href=["\']?tel:([^"\'>\s]+)["\']?[^>]*>([^<]*)</a>#i',
            function ($m) {
                $phone = $m[1];
                return " Телефон: {$phone} ";
            },
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Clean extracted text.
     */
    private function cleanText(string $text): string
    {
        // Normalize whitespace
        $text = preg_replace('/\r\n|\r/', "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n[ \t]+/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        
        // Remove navigation keywords (simple cleanup)
        $navPatterns = [
            '/^(Головна|Каталог|Кошик|Увійти|Вийти|Меню)\s*$/mi',
            '/^\d+\s*$/m', // lone numbers
        ];
        foreach ($navPatterns as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }
        
        return trim($text);
    }

    /**
     * Check if AI generation is available (API key configured).
     */
    public function isAiAvailable(): bool
    {
        return !empty(config('services.openai.key'));
    }

    /**
     * Generate FAQ text using GPT, or return cleaned raw text as fallback.
     */
    private function generateFaqWithAi(string $content, array $section): ?string
    {
        $apiKey = config('services.openai.key');
        
        // If no API key, return cleaned raw content as fallback
        if (empty($apiKey)) {
            Log::warning('FaqGeneratorService: OpenAI API key not configured, using raw parsed content');
            return $this->formatRawContentAsFaq($content, $section);
        }

        $prompt = $this->buildFaqPrompt($content, $section);

        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post(config('services.openai.base_url', 'https://api.openai.com/v1') . '/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 1500,
            ]);

        if (!$response->ok()) {
            Log::error('FaqGeneratorService: OpenAI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('OpenAI API error: ' . $response->status());
        }

        $data = $response->json();
        return $data['choices'][0]['message']['content'] ?? null;
    }

    /**
     * Get system prompt for FAQ generation.
     */
    private function getSystemPrompt(): string
    {
        return <<<PROMPT
Ти — помічник для створення FAQ контенту для чат-бота інтернет-магазину.
Твоя задача — витягнути найважливішу інформацію зі сторінки і структурувати її для чат-бота.

ПРАВИЛА:
1. Пиши УКРАЇНСЬКОЮ мовою
2. Будь ЛАКОНІЧНИМ — тільки факти, без води
3. Структуруй інформацію чітко, з підзаголовками якщо потрібно
4. Вказуй КОНКРЕТНІ дані: ціни, терміни, номери телефонів, адреси
5. Для соцмереж вказуй username/посилання повністю
6. НЕ вигадуй інформацію — тільки те що є в тексті
7. Якщо інформації немає — НЕ пиши про це

ФОРМАТ ВІДПОВІДІ:
- Простий текст з переносами рядків
- Підзаголовки: великі літери або emoji
- Списки через • або нумеровані
- Без markdown (* ** #)
PROMPT;
    }

    /**
     * Build user prompt for specific FAQ section.
     */
    private function buildFaqPrompt(string $content, array $section): string
    {
        return <<<PROMPT
Проаналізуй текст зі сторінки "{$section['name']}" інтернет-магазину.
Витягни і структуруй інформацію про: {$section['prompt_focus']}

ТЕКСТ СТОРІНКИ:
---
{$content}
---

Створи структурований FAQ-текст для чат-бота. Включи тільки фактичну інформацію яка є в тексті.
PROMPT;
    }

    /**
     * Format raw parsed content as FAQ (fallback when AI is not available).
     */
    private function formatRawContentAsFaq(string $content, array $section): string
    {
        $lines = explode("\n", $content);
        $result = [];
        $result[] = "=== {$section['name']} ===";
        $result[] = "";
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // Skip very short lines (likely menu items)
            if (mb_strlen($line) < 10) {
                continue;
            }
            
            // Skip lines that look like navigation
            if (preg_match('/^(головна|каталог|кошик|увійти|меню|вхід|реєстрація)$/ui', $line)) {
                continue;
            }
            
            $result[] = $line;
        }
        
        // Limit output
        $text = implode("\n", array_slice($result, 0, 50));
        
        return mb_substr($text, 0, self::MAX_FAQ_LENGTH);
    }
}
