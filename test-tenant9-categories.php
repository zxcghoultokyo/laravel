#!/usr/bin/env php
<?php
/**
 * Тестування AI чат-бота для тенанта 9
 * Категорії: Жіночий одяг, Смартфони (iPhone)
 */

$tenantId = 9;
$baseUrl = 'https://aintento.laravel.cloud';
$timeout = 60;

$testCases = [
    // Жіночий одяг
    [
        'name' => 'Загальний запит жіночого одягу',
        'message' => 'Покажи жіночий одяг',
        'expect_products' => true,
        'expect_category' => 'Жіночий одяг',
    ],
    [
        'name' => 'Сорочки',
        'message' => 'Шукаю сорочку',
        'expect_products' => true,
    ],
    [
        'name' => 'Одяг для жінок (синонім)',
        'message' => 'Що є для жінок?',
        'expect_products' => true,
    ],
    [
        'name' => 'Бюджетний запит',
        'message' => 'Покажи одяг до 1000 грн',
        'expect_products' => true,
    ],
    
    // Електроніка / Смартфони
    [
        'name' => 'iPhone загальний',
        'message' => 'Покажи iPhone',
        'expect_products' => true,
        'expect_brand' => 'Apple',
    ],
    [
        'name' => 'Смартфон',
        'message' => 'Шукаю смартфон',
        'expect_products' => true,
    ],
    [
        'name' => 'Apple бренд',
        'message' => 'Що є від Apple?',
        'expect_products' => true,
    ],
    [
        'name' => 'iPhone 13 конкретна модель',
        'message' => 'iPhone 13 Pro',
        'expect_products' => true,
    ],
    
    // Мікс/Порівняння
    [
        'name' => 'Що у вас є?',
        'message' => 'Що у вас є?',
        'expect_products' => true,
    ],
    [
        'name' => 'Топ товари',
        'message' => 'Покажи топ товари',
        'expect_products' => true,
    ],
];

function sendChat($baseUrl, $tenantId, $message, $sessionId, $timeout) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => "$baseUrl/api/chat",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'message' => $message,
            'session_id' => $sessionId,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Tenant-Id: ' . $tenantId,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $duration = round((microtime(true) - $start) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'http_code' => $httpCode,
        'duration_ms' => $duration,
        'response' => $response ? json_decode($response, true) : null,
        'error' => $error,
    ];
}

echo "=== Тестування AI чат-бота для тенанта $tenantId ===\n";
echo "URL: $baseUrl\n";
echo "Дата: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 60) . "\n\n";

$results = [];
$passed = 0;
$failed = 0;

foreach ($testCases as $i => $test) {
    $num = $i + 1;
    $sessionId = "test_t9_" . time() . "_$num";
    
    echo "[$num/" . count($testCases) . "] {$test['name']}\n";
    echo "  > \"{$test['message']}\"\n";
    
    $result = sendChat($baseUrl, $tenantId, $test['message'], $sessionId, $timeout);
    
    if ($result['error']) {
        echo "  ❌ CURL ERROR: {$result['error']}\n";
        $failed++;
        continue;
    }
    
    if ($result['http_code'] !== 200) {
        echo "  ❌ HTTP {$result['http_code']}\n";
        $failed++;
        continue;
    }
    
    $data = $result['response'];
    $text = $data['text'] ?? '';
    $products = $data['products'] ?? [];
    $productCount = count($products);
    
    echo "  📝 " . mb_substr($text, 0, 100) . (mb_strlen($text) > 100 ? '...' : '') . "\n";
    echo "  📦 Products: $productCount | ⏱ {$result['duration_ms']}ms\n";
    
    // Evaluate
    $testPassed = true;
    
    if ($test['expect_products'] && $productCount === 0) {
        echo "  ⚠️  Expected products but got none\n";
        $testPassed = false;
    }
    
    if (!empty($test['expect_category'])) {
        $categoryFound = false;
        foreach ($products as $p) {
            if (stripos($p['category_path'] ?? '', $test['expect_category']) !== false) {
                $categoryFound = true;
                break;
            }
        }
        if (!$categoryFound && $productCount > 0) {
            echo "  ⚠️  Expected category '{$test['expect_category']}' not found\n";
        }
    }
    
    if (!empty($test['expect_brand'])) {
        $brandFound = false;
        foreach ($products as $p) {
            if (stripos($p['brand'] ?? '', $test['expect_brand']) !== false) {
                $brandFound = true;
                break;
            }
        }
        if (!$brandFound && $productCount > 0) {
            echo "  ⚠️  Expected brand '{$test['expect_brand']}' not found\n";
        }
    }
    
    if ($testPassed && $productCount > 0) {
        echo "  ✅ PASSED\n";
        $passed++;
    } elseif ($productCount > 0) {
        echo "  ⚠️  PARTIAL\n";
        $passed++;
    } else {
        echo "  ❌ FAILED - no products\n";
        $failed++;
    }
    
    echo "\n";
    
    // Delay between requests
    sleep(1);
}

echo str_repeat('=', 60) . "\n";
echo "РЕЗУЛЬТАТИ: $passed/" . count($testCases) . " пройшло\n";
if ($failed > 0) {
    echo "Провалено: $failed\n";
}
