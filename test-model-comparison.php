<?php

/**
 * Порівняння якості GPT-5.2 vs GPT-4o на реальних use cases
 * 
 * Запуск: php test-model-comparison.php
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

// =====================================================
// НАЛАШТУВАННЯ
// =====================================================

$models = [
    'gpt-4o' => 'GPT-4o (швидкий, дешевий)',
    'gpt-4o-mini' => 'GPT-4o-mini (найшвидший, найдешевший)',
    'gpt-4.1' => 'GPT-4.1 (balanced)',
    'gpt-5.1' => 'GPT-5.1 (current production)',
];

$apiKey = config('services.openai.key');
$baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');

// =====================================================
// ТЕСТОВІ КЕЙСИ (українською)
// =====================================================

$testCases = [
    [
        'name' => '1. Простий пошук товару',
        'user' => 'покажи берці',
        'expected' => 'Має викликати search_products з "берці"',
    ],
    [
        'name' => '2. Пошук з фільтром ціни',
        'user' => 'хочу недорогу куртку до 2000 грн',
        'expected' => 'search_products з price_max=2000',
    ],
    [
        'name' => '3. Уточнення контексту',
        'user' => 'softshell',
        'context' => '[Попередньо обговорювали куртки]',
        'expected' => 'search_products("softshell куртка")',
    ],
    [
        'name' => '4. Сленг/помилки',
        'user' => 'покаж плитноску мультікам',
        'expected' => 'Виправити на "плитоноска мультикам" і шукати',
    ],
    [
        'name' => '5. Англійська мова',
        'user' => 'show me tactical gloves',
        'expected' => 'Відповідь АНГЛІЙСЬКОЮ + search_products',
    ],
    [
        'name' => '6. Питання про магазин',
        'user' => 'як оплатити?',
        'expected' => 'Текстова відповідь про оплату, НЕ search_products',
    ],
    [
        'name' => '7. "Покажи ще"',
        'user' => 'покажи ще',
        'context' => '[Показані товари: шеврон-1, шеврон-2, шеврон-3]',
        'expected' => 'search_products("шеврон") з exclude_shown=true',
    ],
    [
        'name' => '8. Складний запит',
        'user' => 'потрібен шолом балістичний NIJ IIIA до 15000, бажано Ops-Core або аналог',
        'expected' => 'search_products з product_type, price_max, brand',
    ],
];

// =====================================================
// СИСТЕМНИЙ ПРОМПТ (скорочений для тесту)
// =====================================================

$systemPrompt = <<<PROMPT
Ти — AI-консультант тактичного магазину.

ІНСТРУМЕНТИ:
- search_products(query, price_max?, brand?, exclude_shown?) — пошук товарів

ПРАВИЛА:
1. ЗАВЖДИ відповідай мовою користувача
2. На пошукові запити — виклич search_products
3. На питання про магазин — текстова відповідь
4. Виправляй помилки: плитноска→плитоноска, берци→берці
5. Формат: JSON {"action": "search"|"text", "params": {...}, "response": "..."}

ОПЛАТА: карткою, накладений платіж, ПриватБанк.
ДОСТАВКА: Нова Пошта, УкрПошта, 1-3 дні.
PROMPT;

$tools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'search_products',
            'description' => 'Пошук товарів в каталозі',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Пошуковий запит'],
                    'price_max' => ['type' => 'number', 'description' => 'Максимальна ціна'],
                    'brand' => ['type' => 'string', 'description' => 'Бренд'],
                    'exclude_shown' => ['type' => 'boolean', 'description' => 'Виключити показані'],
                ],
                'required' => ['query'],
            ],
        ],
    ],
];

// =====================================================
// ФУНКЦІЯ ТЕСТУВАННЯ
// =====================================================

function testModel(string $model, array $testCase, string $systemPrompt, array $tools, string $apiKey, string $baseUrl): array
{
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
    ];
    
    // Додаємо контекст якщо є
    if (!empty($testCase['context'])) {
        $messages[] = ['role' => 'assistant', 'content' => $testCase['context']];
    }
    
    $messages[] = ['role' => 'user', 'content' => $testCase['user']];
    
    $startTime = microtime(true);
    
    try {
        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])
            ->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => $messages,
                'tools' => $tools,
                'temperature' => 0.3,
                'max_tokens' => 500,
            ]);
        
        $elapsed = round((microtime(true) - $startTime) * 1000);
        
        if (!$response->successful()) {
            return [
                'success' => false,
                'error' => $response->body(),
                'time_ms' => $elapsed,
            ];
        }
        
        $data = $response->json();
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        
        return [
            'success' => true,
            'time_ms' => $elapsed,
            'tool_calls' => $message['tool_calls'] ?? null,
            'content' => $message['content'] ?? null,
            'tokens' => [
                'prompt' => $data['usage']['prompt_tokens'] ?? 0,
                'completion' => $data['usage']['completion_tokens'] ?? 0,
                'total' => $data['usage']['total_tokens'] ?? 0,
            ],
        ];
        
    } catch (\Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'time_ms' => round((microtime(true) - $startTime) * 1000),
        ];
    }
}

function formatToolCall($toolCalls): string
{
    if (empty($toolCalls)) {
        return '(no tool call)';
    }
    
    $call = $toolCalls[0];
    $name = $call['function']['name'] ?? 'unknown';
    $args = json_decode($call['function']['arguments'] ?? '{}', true);
    
    return "{$name}(" . json_encode($args, JSON_UNESCAPED_UNICODE) . ")";
}

// =====================================================
// ЗАПУСК ТЕСТІВ
// =====================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║      ПОРІВНЯННЯ МОДЕЛЕЙ GPT НА РЕАЛЬНИХ USE CASES               ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$modelsToTest = ['gpt-4o', 'gpt-4o-mini', 'gpt-5.1'];
$results = [];

foreach ($testCases as $testCase) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📝 {$testCase['name']}\n";
    echo "   User: \"{$testCase['user']}\"\n";
    if (!empty($testCase['context'])) {
        echo "   Context: {$testCase['context']}\n";
    }
    echo "   Expected: {$testCase['expected']}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    foreach ($modelsToTest as $model) {
        echo "   🤖 {$model}... ";
        
        $result = testModel($model, $testCase, $systemPrompt, $tools, $apiKey, $baseUrl);
        
        if (!$result['success']) {
            echo "❌ ERROR: {$result['error']}\n";
            continue;
        }
        
        $results[$model][] = $result;
        
        $toolCall = formatToolCall($result['tool_calls']);
        $content = $result['content'] ? substr($result['content'], 0, 60) . '...' : '(empty)';
        
        echo "✅ {$result['time_ms']}ms | {$result['tokens']['total']} tokens\n";
        echo "      Tool: {$toolCall}\n";
        if ($result['content']) {
            echo "      Text: {$content}\n";
        }
        echo "\n";
        
        // Невелика пауза між запитами
        usleep(500000);
    }
    
    echo "\n";
}

// =====================================================
// ПІДСУМОК
// =====================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                         ПІДСУМОК                                ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

foreach ($modelsToTest as $model) {
    if (empty($results[$model])) continue;
    
    $times = array_column($results[$model], 'time_ms');
    $tokens = array_map(fn($r) => $r['tokens']['total'], $results[$model]);
    
    $avgTime = round(array_sum($times) / count($times));
    $avgTokens = round(array_sum($tokens) / count($tokens));
    $totalTokens = array_sum($tokens);
    
    // Приблизна вартість (за 1M tokens)
    $costs = [
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-5.1' => ['input' => 5.00, 'output' => 15.00], // estimate
    ];
    
    $cost = $costs[$model] ?? ['input' => 5, 'output' => 15];
    $estimatedCost = ($totalTokens / 1000000) * (($cost['input'] + $cost['output']) / 2);
    
    echo "🤖 {$model}:\n";
    echo "   ⏱️  Середній час: {$avgTime} ms\n";
    echo "   📊 Середні токени: {$avgTokens}\n";
    echo "   💰 Вартість тесту: ~\${$estimatedCost}\n";
    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "💡 Рекомендація: порівняй ЯКІСТЬ відповідей вище!\n";
echo "   - Чи правильно викликає search_products?\n";
echo "   - Чи правильні параметри (query, price_max, exclude_shown)?\n";
echo "   - Чи відповідає правильною мовою?\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
