#!/usr/bin/env php
<?php

/**
 * Тестування флоу підбору товарів через AgentOrchestrator
 * 
 * Запускає серію запитів і показує результати з категоріями, фільтрами, narrative
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Agent\AgentOrchestrator;
use Illuminate\Support\Facades\Log;

$orchestrator = app(AgentOrchestrator::class);

function separator($title) {
    echo "\n\n";
    echo str_repeat('=', 80) . "\n";
    echo "  {$title}\n";
    echo str_repeat('=', 80) . "\n\n";
}

function runTest($title, $message, $context = []) {
    global $orchestrator;
    
    separator($title);
    echo "Запит: \033[1;36m{$message}\033[0m\n\n";
    
    try {
        $result = $orchestrator->handle($message, $context);
        
        echo "📊 Мета-дані:\n";
        echo "  • Intent: \033[1;33m" . ($result['meta']['intent'] ?? 'N/A') . "\033[0m\n";
        echo "  • Query: " . ($result['meta']['refined_query'] ?? 'N/A') . "\n";
        
        if (!empty($result['meta']['filters'])) {
            echo "  • Фільтри: " . json_encode($result['meta']['filters'], JSON_UNESCAPED_UNICODE) . "\n";
        }
        
        if (!empty($result['meta']['chosen_ids'])) {
            echo "  • Обрано товарів: \033[1;32m" . count($result['meta']['chosen_ids']) . "\033[0m (IDs: " . implode(', ', array_slice($result['meta']['chosen_ids'], 0, 5)) . ")\n";
        }
        
        if (!empty($result['meta']['search_debug']['steps'])) {
            echo "\n🔍 Етапи пошуку:\n";
            foreach ($result['meta']['search_debug']['steps'] as $step) {
                $stepName = $step['step'];
                $duration = $step['duration_ms'];
                
                if ($stepName === 'search') {
                    echo "  1. Search (Meili): {$step['candidates_found']} кандидатів за {$duration}ms\n";
                } elseif ($stepName === 'dedupe') {
                    echo "  2. Dedupe: {$step['before']} → {$step['after']} (-{$step['removed']}) за {$duration}ms\n";
                } elseif ($stepName === 'accessory_filter') {
                    echo "  3. Accessory filter: {$step['reranked']} після фільтрації за {$duration}ms\n";
                } elseif ($stepName === 'ai_rerank') {
                    echo "  4. AI Rerank: {$step['before']} → {$step['after']} (dynamic limit: {$step['dynamic_limit']}) за {$duration}ms\n";
                } elseif ($stepName === 'get_details') {
                    echo "  5. Get details: {$step['products_fetched']} товарів за {$duration}ms\n";
                }
            }
        }
        
        echo "\n💬 Відповідь бота:\n";
        echo str_repeat('-', 80) . "\n";
        echo wordwrap($result['message'], 78, "\n") . "\n";
        echo str_repeat('-', 80) . "\n";
        
        if (!empty($result['products'])) {
            echo "\n📦 Товари (топ-3):\n";
            foreach (array_slice($result['products'], 0, 3) as $idx => $p) {
                $num = $idx + 1;
                $title = $p['title'] ?? 'N/A';
                $price = isset($p['price']) ? round($p['price']) . ' ₴' : 'N/A';
                $article = $p['article'] ?? 'N/A';
                echo "  {$num}. [{$article}] {$title} — \033[1;32m{$price}\033[0m\n";
            }
        }
        
    } catch (\Throwable $e) {
        echo "\n\033[1;31m❌ Помилка: " . $e->getMessage() . "\033[0m\n";
        echo "  Файл: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

// Генеруємо session_id для контексту
$sessionId = 'test_session_' . time();

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  Тестування Product Discovery Flows — AgentOrchestrator                   ║\n";
echo "║  Session: {$sessionId}                                   ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";

// 1. Discovery — SAPI плити (має виключити бокові)
runTest(
    '1. DISCOVERY: SAPI плити (мають бути тільки full-size, без бокових)',
    'SAPI плити',
    ['session_id' => $sessionId]
);

sleep(1);

// 2. Discovery з кольором — мультикам підсумки (медичні)
runTest(
    '2. DISCOVERY: Медичні підсумки Multicam (OR фільтр color|camo)',
    'підсумок аптечний мультикам',
    ['session_id' => $sessionId]
);

sleep(1);

// 3. Discovery з розміром — взуття 43
runTest(
    '3. DISCOVERY: Взуття розмір 43 (має бути 43, не 37/50)',
    'берці 43 розмір',
    ['session_id' => $sessionId]
);

sleep(1);

// 4. Comparison — АТАКА vs НАТО Classic
runTest(
    '4. COMPARISON: Плитоноска АТАКА vs НАТО Classic (token matching)',
    'плитоноска АТАКА vs НАТО Classic',
    ['session_id' => $sessionId]
);

sleep(1);

// 5. Details — конкретна плитоноска
runTest(
    '5. DETAILS: Деталі конкретної плитоноски',
    'розкажи про плитоноску АТАКА Архангел',
    ['session_id' => $sessionId]
);

sleep(1);

// 6. Followup — аксесуари до плитоноски
runTest(
    '6. FOLLOWUP: Аксесуари до плитоноски (deterministic suggestions)',
    'що докупити до цієї плитоноски?',
    ['session_id' => $sessionId]
);

separator('✅ ТЕСТУВАННЯ ЗАВЕРШЕНО');

echo "\nПеревірте логи в storage/logs/laravel.log для деталей AI викликів.\n\n";
