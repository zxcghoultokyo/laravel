<?php

/**
 * Comprehensive Chat Testing Script
 * Tests all chat features: products, orders, store info, size recommendations
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Chat\ChatService;

echo "=== COMPREHENSIVE CHAT TESTS ===\n\n";

$chatService = app(ChatService::class);

$testCases = [
    // 1. Привітання
    [
        'name' => '1. Привітання',
        'message' => 'Привіт',
        'check' => fn($r) => strlen($r['text'] ?? '') > 5,
    ],
    
    // 2. Топ товари
    [
        'name' => '2. Топ/популярні товари',
        'message' => 'Покажи популярні товари',
        'check' => fn($r) => !empty($r['products']) && count($r['products']) > 0,
    ],
    
    // 3. Пошук за категорією
    [
        'name' => '3. Пошук плитоносок',
        'message' => 'Покажи плитоноски',
        'check' => fn($r) => !empty($r['products']) || stripos($r['text'] ?? '', 'плитон') !== false,
    ],
    
    // 4. Пошук з фільтром
    [
        'name' => '4. Пошук з фільтром (олива)',
        'message' => 'Покажи футболки оливкові',
        'check' => fn($r) => !empty($r['products']) || stripos($r['text'] ?? '', 'футбол') !== false,
    ],
    
    // 5. Пошук за бюджетом
    [
        'name' => '5. Пошук за бюджетом',
        'message' => 'Покажи рюкзаки до 2000 грн',
        'check' => fn($r) => !empty($r['products']) || stripos($r['text'] ?? '', 'рюкзак') !== false,
    ],
    
    // 6. Статус замовлення
    [
        'name' => '6. Статус замовлення',
        'message' => 'Який статус замовлення 12345, телефон 0501234567',
        'check' => fn($r) => stripos($r['text'] ?? '', 'замовлен') !== false || 
                             stripos($r['text'] ?? '', 'статус') !== false ||
                             stripos($r['text'] ?? '', 'знайд') !== false,
    ],
    
    // 7. Контакти магазину
    [
        'name' => '7. Контакти магазину',
        'message' => 'Як зв\'язатися з магазином?',
        'check' => fn($r) => stripos($r['text'] ?? '', 'телефон') !== false || 
                             stripos($r['text'] ?? '', 'viber') !== false ||
                             stripos($r['text'] ?? '', 'зв\'яз') !== false ||
                             stripos($r['text'] ?? '', 'контакт') !== false,
    ],
    
    // 8. Доставка
    [
        'name' => '8. Інформація про доставку',
        'message' => 'Як працює доставка?',
        'check' => fn($r) => stripos($r['text'] ?? '', 'доставк') !== false || 
                             stripos($r['text'] ?? '', 'нова пошта') !== false ||
                             stripos($r['text'] ?? '', 'відправ') !== false,
    ],
    
    // 9. Оплата
    [
        'name' => '9. Способи оплати',
        'message' => 'Які є способи оплати?',
        'check' => fn($r) => stripos($r['text'] ?? '', 'оплат') !== false || 
                             stripos($r['text'] ?? '', 'карт') !== false ||
                             stripos($r['text'] ?? '', 'накладен') !== false,
    ],
    
    // 10. Повернення
    [
        'name' => '10. Повернення товару',
        'message' => 'Як повернути товар?',
        'check' => fn($r) => stripos($r['text'] ?? '', 'поверн') !== false || 
                             stripos($r['text'] ?? '', 'обмін') !== false ||
                             stripos($r['text'] ?? '', '14 дн') !== false,
    ],
    
    // 11. Підбір розміру
    [
        'name' => '11. Підбір розміру',
        'message' => 'Який розмір футболки мені підійде якщо мій зріст 180 см та вага 80 кг?',
        'check' => fn($r) => stripos($r['text'] ?? '', 'розмір') !== false || 
                             stripos($r['text'] ?? '', 'L') !== false ||
                             stripos($r['text'] ?? '', 'M') !== false,
    ],
    
    // 12. Складний запит
    [
        'name' => '12. Складний запит з контекстом',
        'message' => 'Шукаю тактичні рукавички для стрільби, бажано чорні, бюджет до 1500 грн',
        'check' => fn($r) => !empty($r['products']) || stripos($r['text'] ?? '', 'рукавиц') !== false,
    ],
    
    // 13. Брендовий пошук
    [
        'name' => '13. Пошук за брендом',
        'message' => 'Покажи товари M-TAC',
        'check' => fn($r) => !empty($r['products']) || stripos($r['text'] ?? '', 'M-TAC') !== false,
    ],
    
    // 14. Невідомий запит
    [
        'name' => '14. Невідомий запит (fallback)',
        'message' => 'Хто такий Ейнштейн?',
        'check' => fn($r) => stripos($r['text'] ?? '', 'магазин') !== false || 
                             stripos($r['text'] ?? '', 'товар') !== false ||
                             stripos($r['text'] ?? '', 'допомог') !== false ||
                             stripos($r['text'] ?? '', 'питання') !== false,
    ],
    
    // 15. Про магазин
    [
        'name' => '15. Про магазин',
        'message' => 'Розкажи про магазин',
        'check' => fn($r) => stripos($r['text'] ?? '', 'магазин') !== false || 
                             stripos($r['text'] ?? '', 'тактич') !== false ||
                             stripos($r['text'] ?? '', 'військов') !== false,
    ],
];

$passed = 0;
$failed = 0;
$errors = [];

foreach ($testCases as $test) {
    echo "Testing: {$test['name']}...\n";
    echo "  Query: \"{$test['message']}\"\n";
    
    try {
        $sessionId = 'test_' . uniqid();
        
        $result = $chatService->handleMessage(
            $test['message'],
            $sessionId
        );
        
        // Normalize result
        if (is_string($result)) {
            $result = ['text' => $result, 'products' => []];
        }
        
        $checkPassed = $test['check']($result);
        
        if ($checkPassed) {
            echo "  ✅ PASSED\n";
            $passed++;
        } else {
            echo "  ❌ FAILED\n";
            $failed++;
            $errors[] = [
                'test' => $test['name'],
                'message' => $test['message'],
                'result' => $result,
            ];
        }
        
        // Show response summary
        $textPreview = substr($result['text'] ?? '', 0, 100);
        echo "  Response: \"{$textPreview}...\"\n";
        if (!empty($result['products'])) {
            echo "  Products: " . count($result['products']) . " items\n";
        }
        
    } catch (\Throwable $e) {
        echo "  ⚠️ ERROR: " . $e->getMessage() . "\n";
        $failed++;
        $errors[] = [
            'test' => $test['name'],
            'message' => $test['message'],
            'error' => $e->getMessage(),
        ];
    }
    
    echo "\n";
}

echo "=== RESULTS ===\n";
echo "Passed: {$passed}/" . count($testCases) . "\n";
echo "Failed: {$failed}\n";

if (!empty($errors)) {
    echo "\n=== FAILED TESTS ===\n";
    foreach ($errors as $err) {
        echo "- {$err['test']}\n";
        if (isset($err['error'])) {
            echo "  Error: {$err['error']}\n";
        } else {
            echo "  Result: " . json_encode($err['result'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
    }
}

echo "\n=== TEST COMPLETE ===\n";
