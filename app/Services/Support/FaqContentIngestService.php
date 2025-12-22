<?php

namespace App\Services\Support;

use App\Models\WidgetSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FaqContentIngestService
{
    /**
     * Fetch and store FAQ page content into WidgetSettings text fields.
     */
    public function ingest(WidgetSettings $settings): void
    {
        $map = [
            'faq_payment_delivery_url' => 'faq_payment_delivery_text',
            'faq_returns_url' => 'faq_returns_text',
            'faq_contacts_url' => 'faq_contacts_text',
            'faq_about_url' => 'faq_about_text',
        ];

        $updates = [];

        foreach ($map as $urlField => $textField) {
            $url = trim((string) $settings->{$urlField});
            if ($url === '') {
                continue;
            }

            try {
                $content = $this->fetchText($url);
                if ($content) {
                    // Limit size to avoid oversized prompts
                    $updates[$textField] = mb_substr($content, 0, 4000);
                }
            } catch (\Throwable $e) {
                Log::warning('FaqContentIngestService: fetch failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($updates)) {
            $settings->fill($updates);
            $settings->save();
        }
    }

    /**
     * Fetch page HTML and extract readable text.
     */
    private function fetchText(string $url): ?string
    {
        $resp = Http::timeout(10)->get($url);
        if (!$resp->ok()) {
            return null;
        }
        $html = (string) $resp->body();

        // Remove scripts/styles
        $html = preg_replace('#<script[\s\S]*?</script>#i', '', $html) ?? $html;
        $html = preg_replace('#<style[\s\S]*?</style>#i', '', $html) ?? $html;
        $html = preg_replace('#<!--[\s\S]*?-->#', '', $html) ?? $html;

        // Try to capture main/article content first
        $main = $this->extractFirst($html, ['main', 'article']);
        if ($main) {
            return $this->htmlToText($main);
        }
        // Try common content containers
        $content = $this->extractByIdOrClass($html, ['content','page','article','post','entry','main']);
        if ($content) {
            return $this->htmlToText($content);
        }
        // Fallback: whole page
        return $this->htmlToText($html);
    }

    private function extractFirst(string $html, array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (preg_match('#<'.$tag.'[^>]*>([\s\S]*?)</'.$tag.'>#i', $html, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function extractByIdOrClass(string $html, array $keys): ?string
    {
        foreach ($keys as $key) {
            // id match
            if (preg_match('#<([a-z0-9]+)[^>]*id=["\\\']'.$key.'["\\\'][^>]*>([\s\S]*?)</\1>#i', $html, $m)) {
                return $m[2];
            }
            // class match (first occurrence)
            if (preg_match('#<([a-z0-9]+)[^>]*class=["\\\'][^"\\\']*'.$key.'[^"\\\']*["\\\'][^>]*>([\s\S]*?)</\1>#i', $html, $m)) {
                return $m[2];
            }
        }
        return null;
    }

    private function htmlToText(string $html): string
    {
        // Allow basic separators, then strip tags
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</p>#i', "\n\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalize whitespace
        $text = preg_replace('/\r?\n\s*\r?\n+/', "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        return trim($text);
    }
}
