<?php

namespace App\Jobs;

use App\Models\ColorSynonym;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Ai\AiRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to generate color synonyms using AI
 */
class GenerateColorSynonymsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes max
    public int $tries = 1;

    private bool $force;

    public function __construct(bool $force = false)
    {
        $this->force = $force;
    }

    public function handle(AiRouter $aiRouter): void
    {
        $startTime = microtime(true);
        
        $syncLog = SyncLog::create([
            'sync_type' => SyncLog::TYPE_COLOR_SYNONYMS,
            'status' => SyncLog::STATUS_RUNNING,
            'started_at' => now(),
            'notes' => 'AI color synonyms generation',
        ]);

        try {
            // Get unique colors from products
            $colors = Product::query()
                ->whereNotNull('color')
                ->where('color', '!=', '')
                ->select('color', DB::raw('COUNT(*) as cnt'))
                ->groupBy('color')
                ->orderByDesc('cnt')
                ->pluck('cnt', 'color')
                ->toArray();

            if (empty($colors)) {
                throw new \Exception('No colors found in products');
            }

            Log::info('GenerateColorSynonymsJob: found colors', ['count' => count($colors)]);

            // Generate synonyms via AI
            $synonymsMap = $this->generateSynonymsWithAI($aiRouter, array_keys($colors));

            if (empty($synonymsMap)) {
                $synonymsMap = $this->getFallbackSynonyms();
            }

            // Save to database
            $inserted = $this->saveSynonyms($synonymsMap);

            $duration = round(microtime(true) - $startTime, 2);

            $syncLog->update([
                'status' => SyncLog::STATUS_COMPLETED,
                'finished_at' => now(),
                'duration_seconds' => $duration,
                'total_processed' => count($synonymsMap),
                'created' => $inserted,
                'notes' => "Generated {$inserted} synonyms in " . count($synonymsMap) . " groups",
            ]);

            // Clear cache
            try {
                app(\App\Services\Search\ColorService::class)->clearCache();
            } catch (\Throwable $e) {
                // Ignore if service doesn't exist
            }

            Log::info('GenerateColorSynonymsJob completed', [
                'groups' => count($synonymsMap),
                'synonyms' => $inserted,
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

            Log::error('GenerateColorSynonymsJob failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function generateSynonymsWithAI(AiRouter $aiRouter, array $colors): array
    {
        $colorsList = implode("\n", array_map(fn($c, $i) => ($i + 1) . ". {$c}", $colors, array_keys($colors)));

        $prompt = <<<PROMPT
Ти — експерт з кольорів тактичного/військового спорядження для українського магазину.

Ось список унікальних кольорів з бази товарів:
{$colorsList}

Твоє завдання:
1. Згрупуй ці кольори в КАНОНІЧНІ групи (англійською, lowercase)
2. Для кожної групи додай ВСІ можливі синоніми (укр, рус, англ, сленг, скорочення)

ВАЖЛИВО:
- Канонічна група = англійське слово lowercase (black, olive, multicam, pixel, tan, coyote, green, khaki, brown, white, red, blue, camo)
- Синоніми включають: оригінальну назву з бази, переклади, сленг, скорочення
- "мультікам" і "Мультикам" = одна група "multicam"
- "Оливковий" і "Олива" = одна група "olive"
- "Піксель", "MM14", "укрпіксель" = одна група "pixel"

Поверни JSON:
{
  "black": ["чорний", "чорна", "чорне", "черный", "black", "blk"],
  "multicam": ["мультикам", "мультікам", "multicam", "mc", "мульт"],
  ...
}

Поверни ТІЛЬКИ JSON без пояснень.
PROMPT;

        try {
            $response = $aiRouter->callOpenAI($prompt, 0.3);
            
            // Clean response
            $response = preg_replace('/```json\s*/', '', $response);
            $response = preg_replace('/```\s*$/', '', $response);
            $response = trim($response);
            
            $result = json_decode($response, true);
            
            if (!is_array($result)) {
                Log::warning('GenerateColorSynonymsJob: invalid AI response', ['response' => substr($response, 0, 500)]);
                return [];
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('GenerateColorSynonymsJob: AI failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function getFallbackSynonyms(): array
    {
        return [
            'black' => ['чорний', 'чорна', 'чорне', 'черный', 'black', 'blk'],
            'multicam' => ['мультикам', 'мультікам', 'multicam', 'mc', 'мульт', 'Multicam'],
            'olive' => ['олива', 'оливковий', 'оліва', 'olive', 'od', 'Оливковий', 'Олива'],
            'pixel' => ['піксель', 'пиксель', 'mm14', 'мм14', 'укрпіксель', 'pixel', 'Піксель'],
            'coyote' => ['койот', 'coyote', 'Койот'],
            'tan' => ['тан', 'tan', 'fde', 'пісочний'],
            'khaki' => ['хакі', 'хаки', 'khaki'],
            'green' => ['зелений', 'зелена', 'green'],
            'brown' => ['коричневий', 'коричнева', 'brown'],
            'camo' => ['камуфляж', 'камо', 'camo'],
            'white' => ['білий', 'біла', 'white'],
        ];
    }

    private function saveSynonyms(array $synonymsMap): int
    {
        if ($this->force) {
            ColorSynonym::truncate();
        }

        $inserted = 0;

        foreach ($synonymsMap as $colorGroup => $synonyms) {
            $colorGroup = strtolower(trim($colorGroup));
            
            foreach ($synonyms as $index => $synonym) {
                $synonym = trim($synonym);
                if (empty($synonym)) continue;

                $exists = ColorSynonym::where('color_group', $colorGroup)
                    ->where('synonym', $synonym)
                    ->exists();

                if ($exists && !$this->force) {
                    continue;
                }

                ColorSynonym::updateOrCreate(
                    ['color_group' => $colorGroup, 'synonym' => $synonym],
                    [
                        'language' => $this->detectLanguage($synonym),
                        'is_primary' => $index === 0,
                        'is_active' => true,
                    ]
                );
                $inserted++;
            }
        }

        return $inserted;
    }

    private function detectLanguage(string $text): string
    {
        if (preg_match('/[а-яіїєґ]/ui', $text)) {
            return preg_match('/[іїєґ]/ui', $text) ? 'uk' : 'ru';
        }
        return 'en';
    }
}
