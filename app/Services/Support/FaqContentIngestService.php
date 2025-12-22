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
            return $this->cleanText($this->htmlToText($main));
        }
        // Try common content containers
        $content = $this->extractByIdOrClass($html, ['content','page','article','post','entry','main']);
        if ($content) {
            return $this->cleanText($this->htmlToText($content));
        }
        // Fallback: whole page
        return $this->cleanText($this->htmlToText($html));
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

    private function cleanText(string $text): string
    {
        // Remove common emojis/dingbats
        $text = preg_replace('/[\x{2700}-\x{27BF}\x{1F300}-\x{1FAFF}]/u', '', $text) ?? $text;

        $lines = preg_split('/\r?\n/', $text) ?: [];
        $navKeywords = [
            'головна','каталог','бренди','бронезахист','бронежилети','блог','політика конфіденційності',
            'контактна інформація','про нас','оплата і доставка','обмін та повернення'
        ];

        $out = [];
        foreach ($lines as $line) {
            $l = trim($line);
            if ($l === '') { // preserve single blank lines later
                $out[] = '';
                continue;
            }
            $lower = mb_strtolower($l);
            // Remove pure navigation lines (exact match or short menu-like entries)
            if (in_array($lower, $navKeywords, true)) {
                continue;
            }
            // Remove lists that are just navigation clusters
            if (preg_match('/^(головна|каталог|бренди|бронезахист|бронежилети|блог)(?:\s*[•\-–—]\s*(про нас|контактна інформація|оплата і доставка|обмін та повернення))*$/iu', $lower)) {
                continue;
            }
            // Normalize bullets
            $l = preg_replace('/^[\-–—\*]\s*/', '• ', $l) ?? $l;
            $out[] = $l;
        }

        // Collapse excessive blank lines
        $clean = [];
        $prevBlank = false;
        foreach ($out as $l) {
            $isBlank = ($l === '');
            if ($isBlank && $prevBlank) {
                continue;
            }
            $clean[] = $l;
            $prevBlank = $isBlank;
        }

        // Deduplicate adjacent identical lines
        $final = [];
        $last = null;
        foreach ($clean as $l) {
            if ($l === $last) { continue; }
            $final[] = $l;
            $last = $l;
        }

        return trim(implode("\n", $final));
    }
}
