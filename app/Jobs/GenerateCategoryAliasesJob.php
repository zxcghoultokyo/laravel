<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\CategoryAlias;
use App\Models\SyncLog;
use App\Scopes\TenantScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Job to generate category aliases using AI
 */
class GenerateCategoryAliasesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes max (AI calls can be slow)
    public int $tries = 1;

    private bool $force;
    private int $batchSize;

    public function __construct(bool $force = false, int $batchSize = 10)
    {
        $this->force = $force;
        $this->batchSize = $batchSize;
    }

    public function handle(): void
    {
        $startTime = microtime(true);
        
        $syncLog = SyncLog::create([
            'sync_type' => SyncLog::TYPE_CATEGORY_ALIASES,
            'status' => SyncLog::STATUS_RUNNING,
            'started_at' => now(),
            'notes' => 'AI category aliases generation',
        ]);

        try {
            $apiKey = config('services.openai.key', '');
            $model = config('services.openai.model', 'gpt-4.1-mini');
            $baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');

            if (empty($apiKey)) {
                throw new \Exception('OPENAI_API_KEY not configured');
            }

            // Get active categories with products
            $categories = Category::withoutGlobalScope(TenantScope::class)
                ->where('is_active', true)
                ->where('products_count', '>', 0)
                ->orderByDesc('products_count')
                ->get();

            if ($categories->isEmpty()) {
                throw new \Exception('No active categories found');
            }

            Log::info('GenerateCategoryAliasesJob: processing categories', ['count' => $categories->count()]);

            // Process in batches
            $chunks = $categories->chunk($this->batchSize);
            $totalGenerated = 0;

            foreach ($chunks as $chunkIndex => $chunk) {
                $categoryData = $chunk->map(fn($c) => [
                    'id' => $c->id,
                    'path' => $c->path,
                ])->values()->toArray();

                Log::info("GenerateCategoryAliasesJob: batch " . ($chunkIndex + 1) . "/" . $chunks->count());

                $aliases = $this->generateAliasesViaAI($apiKey, $model, $baseUrl, $categoryData);

                if (empty($aliases)) {
                    continue;
                }

                foreach ($aliases as $catId => $phrases) {
                    $category = $categories->firstWhere('id', $catId);
                    if (!$category) continue;

                    foreach ($phrases as $phrase) {
                        $phrase = trim($phrase);
                        if (mb_strlen($phrase) < 2) continue;

                        $norm = $this->normalize($phrase);
                        if (mb_strlen($norm) < 2) continue;

                        $exists = CategoryAlias::query()
                            ->where('category_id', $catId)
                            ->where('phrase_norm', $norm)
                            ->exists();

                        if ($exists && !$this->force) {
                            continue;
                        }

                        CategoryAlias::query()->updateOrCreate(
                            ['category_id' => $catId, 'phrase_norm' => $norm],
                            [
                                'phrase' => $phrase,
                                'weight' => 40, // AI-generated weight
                                'source' => 'ai_generated',
                                'is_active' => true,
                            ]
                        );

                        $totalGenerated++;
                    }
                }

                // Small delay between batches to avoid rate limits
                if ($chunkIndex < $chunks->count() - 1) {
                    usleep(500000); // 0.5 second
                }
            }

            $duration = round(microtime(true) - $startTime, 2);

            $syncLog->update([
                'status' => SyncLog::STATUS_COMPLETED,
                'finished_at' => now(),
                'duration_seconds' => $duration,
                'total_processed' => $categories->count(),
                'created' => $totalGenerated,
                'notes' => "Generated {$totalGenerated} AI aliases for {$categories->count()} categories",
            ]);

            // Clear category pattern cache
            try {
                app(\App\Services\Catalog\CategoryPatternService::class)->clearAllCache();
            } catch (\Throwable $e) {
                // Ignore if service doesn't exist
            }

            Log::info('GenerateCategoryAliasesJob completed', [
                'categories' => $categories->count(),
                'aliases' => $totalGenerated,
                'duration' => $duration,
            ]);

        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);
            
            $syncLog->update([
                'status' => SyncLog::STATUS_FAILED,
                'finished_at' => now(),
                'duration_seconds' => $duration,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('GenerateCategoryAliasesJob failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function generateAliasesViaAI(string $apiKey, string $model, string $baseUrl, array $categories): array
    {
        $categoryList = collect($categories)
            ->map(fn($c) => "- ID:{$c['id']} = \"{$c['path']}\"")
            ->implode("\n");

        $prompt = <<<PROMPT
Ти — експерт з e-commerce та пошуку товарів для українського тактичного магазину.

Для кожної категорії згенеруй 5-10 альтернативних фраз/слів, які покупці можуть використовувати при пошуку товарів цієї категорії.

Включай:
- Синоніми (український, російський варіанти)
- Сленг та розмовні форми ("броник" замість "бронежилет")
- Англійські терміни ("plate carrier", "belt", "pouch")
- Скорочення та абревіатури
- Типові помилки написання
- Загальні запити ("жилет тактичний", "розвантаження")

Категорії:
{$categoryList}

Поверни JSON у форматі:
{
  "123": ["фраза1", "фраза2", "фраза3"],
  "456": ["фраза1", "фраза2"]
}

Де ключ — це ID категорії. Поверни ТІЛЬКИ JSON без пояснень.
PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($baseUrl . '/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Ти генеруєш пошукові синоніми для категорій товарів. Відповідай тільки JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 2000,
            ]);

            if (!$response->successful()) {
                Log::warning('GenerateCategoryAliasesJob: AI request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $content = $response->json('choices.0.message.content', '');
            
            // Clean response
            $content = preg_replace('/```json\s*/', '', $content);
            $content = preg_replace('/```\s*$/', '', $content);
            $content = trim($content);

            $result = json_decode($content, true);

            if (!is_array($result)) {
                Log::warning('GenerateCategoryAliasesJob: invalid JSON', ['content' => substr($content, 0, 500)]);
                return [];
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('GenerateCategoryAliasesJob: AI error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $s) ?: '';
        $s = preg_replace('/\s+/u', ' ', $s) ?: '';
        return trim($s);
    }
}
