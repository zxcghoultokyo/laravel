<?php
/**
 * Тестування моделей через production API
 * Порівняння якості відповідей gpt-4o vs gpt-4o-mini vs gpt-5.1
 */

$baseUrl = 'https://aintento.laravel.cloud';
$apiKey = 'default_api_key_change_me_in_production';

// Тестові кейси
$testCases = [
    [
        'name' => '1. Простий пошук',
        'message' => 'покажи берці',
        'expected' => 'Має знайти берці і показати картки товарів',
    ],
    [
        'name' => '2. Фільтр ціни',
        'message' => 'хочу недорогу куртку до 2000 грн',
        'expected' => 'Куртки з ціною <= 2000',
    ],
    [
        'name' => '3. Сленг',
        'message' => 'покаж бойовку',
        'expected' => 'Має розпізнати "бойовка" як бойову сорочку',
    ],
    [
        'name' => '4. Англійська',
        'message' => 'show me tactical gloves',
        'expected' => 'Відповідь АНГЛІЙСЬКОЮ',
    ],
    [
        'name' => '5. Питання FAQ',
        'message' => 'яка у вас доставка?',
        'expected' => 'Текстова відповідь про доставку, НЕ товари',
    ],
];

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║   ТЕСТ ЯКОСТІ ЧЕРЕЗ PRODUCTION API (поточна модель gpt-5.1)     ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$results = [];

foreach ($testCases as $case) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📝 {$case['name']}\n";
    echo "   User: \"{$case['message']}\"\n";
    echo "   Expected: {$case['expected']}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    $sessionId = 'test_' . time() . '_' . rand(1000, 9999);
    
    $start = microtime(true);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$baseUrl/api/chat",
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            "X-API-Key: $apiKey",
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'message' => $case['message'],
            'session_id' => $sessionId,
        ]),
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $elapsed = round((microtime(true) - $start) * 1000);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($httpCode !== 200) {
        echo "   ❌ HTTP Error: $httpCode\n";
        echo "   Response: " . substr($response, 0, 200) . "\n\n";
        continue;
    }
    
    $type = $data['type'] ?? 'unknown';
    $text = $data['text'] ?? '';
    $productCount = count($data['products'] ?? []);
    
    echo "   ⏱️  Час: {$elapsed}ms\n";
    echo "   📦 Тип: {$type}\n";
    echo "   🛒 Товарів: {$productCount}\n";
    
    // Показуємо текст відповіді (перші 300 символів)
    $shortText = mb_substr(preg_replace('/\s+/', ' ', $text), 0, 300);
    echo "   💬 Текст: {$shortText}...\n";
    
    // Якщо є товари, показуємо перші 3
    if ($productCount > 0) {
        echo "   📋 Товари:\n";
        foreach (array_slice($data['products'], 0, 3) as $p) {
            $title = mb_substr($p['title'] ?? 'N/A', 0, 50);
            $price = $p['price'] ?? 'N/A';
            echo "      - {$title} | {$price} грн\n";
        }
    }
    
    $results[] = [
        'case' => $case['name'],
        'time_ms' => $elapsed,
        'type' => $type,
        'products' => $productCount,
    ];
    
    echo "\n";
    
    // Пауза між запитами
    sleep(1);
}

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                    ЗВЕДЕНА СТАТИСТИКА                           ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$totalTime = array_sum(array_column($results, 'time_ms'));
$avgTime = count($results) > 0 ? round($totalTime / count($results)) : 0;

echo "Середній час відповіді: {$avgTime}ms\n";
echo "Загальний час: {$totalTime}ms\n\n";

echo "| Тест | Час | Тип | Товарів |\n";
echo "|------|-----|-----|--------|\n";
foreach ($results as $r) {
    echo "| {$r['case']} | {$r['time_ms']}ms | {$r['type']} | {$r['products']} |\n";
}

echo "\n";
echo "💡 Поточна модель: gpt-5.1 (config/services.php)\n";
echo "💡 Для порівняння з gpt-4o потрібно змінити OPENAI_MODEL в .env\n";
