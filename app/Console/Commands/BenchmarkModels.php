<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Бенчмарк порівняння GPT моделей.
 * Запуск: php artisan benchmark:models
 */
class BenchmarkModels extends Command
{
    protected $signature = 'benchmark:models 
                            {--models=gpt-4o,gpt-5.1 : Моделі для тесту через кому}
                            {--runs=2 : Кількість запусків на модель}';

    protected $description = 'Порівняння швидкості та якості GPT моделей';

    private ?string $apiKey = null;
    private string $baseUrl = 'https://api.openai.com/v1';

    public function handle(): int
    {
        $this->apiKey = config('services.openai.api_key') ?: null;
        $this->baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');

        if (empty($this->apiKey)) {
            $this->error('❌ OPENAI_API_KEY не налаштовано!');
            return 1;
        }

        $models = explode(',', $this->option('models'));
        $runs = (int) $this->option('runs');

        $this->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->info('║   BENCHMARK GPT МОДЕЛЕЙ (прямі виклики OpenAI API)              ║');
        $this->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->info("🔑 API Key: " . substr($this->apiKey, 0, 8) . '...' . substr($this->apiKey, -4));
        $this->info("🌐 Base URL: {$this->baseUrl}");
        $this->info("🤖 Моделі: " . implode(', ', $models));
        $this->info("🔄 Запусків на модель: {$runs}");
        $this->newLine();

        // Тестові кейси
        $testCases = [
            [
                'name' => 'Простий пошук',
                'messages' => [
                    ['role' => 'system', 'content' => 'Ти консультант інтернет-магазину тактичного спорядження. Відповідай коротко.'],
                    ['role' => 'user', 'content' => 'покажи берці'],
                ],
                'tools' => $this->getTools(),
            ],
            [
                'name' => 'Сленг',
                'messages' => [
                    ['role' => 'system', 'content' => 'Ти консультант. Виправляй сленг і помилки автоматично.'],
                    ['role' => 'user', 'content' => 'покаж бойовку'],
                ],
                'tools' => $this->getTools(),
            ],
            [
                'name' => 'FAQ (без tools)',
                'messages' => [
                    ['role' => 'system', 'content' => 'Ти консультант. Відповідай коротко.'],
                    ['role' => 'user', 'content' => 'яка у вас доставка?'],
                ],
                'tools' => null,
            ],
            [
                'name' => 'Англійська',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a shop assistant. Reply in the user\'s language.'],
                    ['role' => 'user', 'content' => 'show me tactical gloves'],
                ],
                'tools' => $this->getTools(),
            ],
        ];

        $results = [];

        foreach ($models as $model) {
            $model = trim($model);
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("🤖 Тестуємо модель: {$model}");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

            $modelResults = [];

            foreach ($testCases as $case) {
                $times = [];
                $lastResponse = null;

                for ($i = 0; $i < $runs; $i++) {
                    $result = $this->callOpenAI($model, $case['messages'], $case['tools']);
                    $times[] = $result['time_ms'];
                    $lastResponse = $result;
                    
                    if ($runs > 1) {
                        usleep(500000); // 0.5s пауза між запусками
                    }
                }

                $avgTime = round(array_sum($times) / count($times));
                $minTime = min($times);
                $maxTime = max($times);

                $hasToolCall = !empty($lastResponse['tool_calls']);
                $toolName = $hasToolCall ? $lastResponse['tool_calls'][0]['function']['name'] : '-';
                $content = mb_substr($lastResponse['content'] ?? '', 0, 80);

                $this->line("  📝 {$case['name']}:");
                $this->line("     ⏱️  Час: {$avgTime}ms (min: {$minTime}, max: {$maxTime})");
                $this->line("     🔧 Tool: {$toolName}");
                $this->line("     💬 " . ($content ?: '[tool call]'));

                if ($lastResponse['error']) {
                    $this->error("     ❌ Error: {$lastResponse['error']}");
                }

                $modelResults[] = [
                    'case' => $case['name'],
                    'avg_ms' => $avgTime,
                    'min_ms' => $minTime,
                    'max_ms' => $maxTime,
                    'tool_call' => $toolName,
                    'tokens' => $lastResponse['tokens'] ?? 0,
                ];
            }

            $results[$model] = $modelResults;
            $this->newLine();
        }

        // Зведена таблиця
        $this->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->info('║                    ЗВЕДЕНА ТАБЛИЦЯ                              ║');
        $this->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $headers = ['Тест'];
        foreach ($models as $m) {
            $headers[] = trim($m) . ' (ms)';
        }
        $headers[] = 'Різниця';

        $rows = [];
        foreach ($testCases as $i => $case) {
            $row = [$case['name']];
            $times = [];
            foreach ($models as $m) {
                $m = trim($m);
                $time = $results[$m][$i]['avg_ms'] ?? '?';
                $row[] = $time;
                if (is_numeric($time)) $times[] = $time;
            }
            
            // Різниця
            if (count($times) >= 2) {
                $diff = $times[0] - $times[1];
                $percent = $times[1] > 0 ? round(($times[0] / $times[1] - 1) * 100) : 0;
                $sign = $diff > 0 ? '+' : '';
                $row[] = "{$sign}{$diff}ms ({$sign}{$percent}%)";
            } else {
                $row[] = '-';
            }
            
            $rows[] = $row;
        }

        // Середнє
        $avgRow = ['СЕРЕДНЄ'];
        $avgTimes = [];
        foreach ($models as $m) {
            $m = trim($m);
            $sum = array_sum(array_column($results[$m], 'avg_ms'));
            $avg = round($sum / count($testCases));
            $avgRow[] = $avg;
            $avgTimes[] = $avg;
        }
        if (count($avgTimes) >= 2) {
            $diff = $avgTimes[0] - $avgTimes[1];
            $percent = $avgTimes[1] > 0 ? round(($avgTimes[0] / $avgTimes[1] - 1) * 100) : 0;
            $sign = $diff > 0 ? '+' : '';
            $avgRow[] = "{$sign}{$diff}ms ({$sign}{$percent}%)";
        } else {
            $avgRow[] = '-';
        }
        $rows[] = $avgRow;

        $this->table($headers, $rows);

        // Рекомендація
        $this->newLine();
        if (count($avgTimes) >= 2) {
            $faster = $avgTimes[0] < $avgTimes[1] ? $models[0] : $models[1];
            $slower = $avgTimes[0] < $avgTimes[1] ? $models[1] : $models[0];
            $ratio = max($avgTimes) / max(1, min($avgTimes));
            
            $this->info("💡 ВИСНОВОК:");
            $this->info("   {$faster} швидша за {$slower} в " . round($ratio, 1) . "x разів");
            
            if ($ratio > 2) {
                $this->warn("   ⚡ Рекомендую переключитись на {$faster} для кращого UX");
            }
        }

        return 0;
    }

    private function callOpenAI(string $model, array $messages, ?array $tools): array
    {
        $start = microtime(true);

        try {
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.3,
            ];

            if ($tools) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = 'auto';
            }

            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post($this->baseUrl . '/chat/completions', $payload);

            $elapsed = round((microtime(true) - $start) * 1000);
            $data = $response->json();

            if (isset($data['error'])) {
                return [
                    'time_ms' => $elapsed,
                    'content' => null,
                    'tool_calls' => [],
                    'tokens' => 0,
                    'error' => $data['error']['message'] ?? 'Unknown error',
                ];
            }

            $choice = $data['choices'][0]['message'] ?? [];

            return [
                'time_ms' => $elapsed,
                'content' => $choice['content'] ?? null,
                'tool_calls' => $choice['tool_calls'] ?? [],
                'tokens' => $data['usage']['total_tokens'] ?? 0,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'time_ms' => round((microtime(true) - $start) * 1000),
                'content' => null,
                'tool_calls' => [],
                'tokens' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function getTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_products',
                    'description' => 'Пошук товарів в каталозі магазину',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Пошуковий запит (виправлений від помилок)',
                            ],
                            'price_max' => [
                                'type' => 'number',
                                'description' => 'Максимальна ціна',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
        ];
    }
}
