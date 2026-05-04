#!/usr/bin/env php
<?php
/**
 * Comprehensive Chat Search Test Suite
 * Tests all product types for all tenants
 * 
 * Usage: php test-comprehensive-search.php [tenant_id]
 */

$baseUrl = 'https://aintento.laravel.cloud/api';
$diagnosticKey = '<DIAGNOSTIC_KEY>';

// Widget tokens by tenant
$widgetTokens = [
    2 => '<WIDGET_TOKEN>', // Attack tactical store
    // Add tenant 10 token when available
];

// Ukrainian query mappings for ai_product_types
$productTypeQueries = [
    // Tenant 2 - Tactical store
    'helmet' => ['шолом', 'каска', 'helmet'],
    'plate_carrier' => ['плитоноска', 'plate carrier', 'бронежилет'],
    'plate' => ['бронеплита', 'плита', 'броня'],
    'pouch' => ['підсумок', 'підсумки', 'pouch'],
    'backpack' => ['рюкзак', 'backpack'],
    'jacket' => ['куртка', 'jacket'],
    'shirt' => ['сорочка', 'бойова сорочка', 'убакс'],
    'uniform' => ['форма', 'uniform', 'комплект'],
    'gloves' => ['рукавички', 'gloves'],
    'glasses' => ['окуляри', 'тактичні окуляри', 'glasses'],
    'tourniquet' => ['турнікет', 'джгут', 'tourniquet'],
    'bandage' => ['бандаж', 'бинт', 'bandage'],
    'headphones' => ['навушники', 'headphones'],
    'headset' => ['гарнітура', 'headset'],
    'active_headset' => ['активні навушники', 'активна гарнітура'],
    'ear_protection' => ['захист слуху', 'ear protection'],
    'knee_pads' => ['наколінники', 'knee pads'],
    'helmet_cover' => ['кавер', 'чохол на шолом', 'helmet cover'],
    'helmet_pads' => ['подушки для шолома', 'helmet pads'],
    'multitool' => ['мультитул', 'multitool'],
    'patch' => ['патч', 'нашивка', 'patch'],
    'sleeping_bag' => ['спальний мішок', 'sleeping bag', 'спальник'],
    'watch_band' => ['ремінець для годинника', 'watch band'],
    'weapon_sling' => ['ремінь для зброї', 'sling'],
    'optics' => ['оптика', 'приціл', 'optics'],
    'retractor' => ['ретрактор', 'retractor'],
    
    // Tenant 10 - Clothing store (fashion)
    'dress' => ['сукня', 'плаття', 'dress'],
    'coat' => ['пальто', 'coat'],
    'hoodie' => ['худі', 'hoodie'],
    'sweater' => ['светр', 'sweater'],
    'pants' => ['штани', 'брюки', 'pants'],
    'skirt' => ['спідниця', 'skirt'],
    'suit' => ['костюм', 'suit'],
    'overalls' => ['комбінезон', 'overalls'],
    'hat' => ['капелюх', 'шапка', 'hat'],
    'bag' => ['сумка', 'bag'],
    'smartphone' => ['смартфон', 'телефон', 'smartphone'],
];

// Edge cases to test
$edgeCases = [
    // Single word queries (should work with short_query_handler)
    'single_word' => ['підсумки', 'шоломи', 'куртки', 'рюкзаки', 'плити'],
    
    // Brand queries
    'brands' => ['Ops-Core', 'Salomon', 'Mechanix', 'АТАКА', 'Helikon-Tex'],
    
    // Color + product
    'color_product' => ['чорні рукавички', 'олива куртка', 'мультикам підсумок'],
    
    // Slang/colloquial
    'slang' => ['плитник', 'броник', 'каска', 'берці'],
    
    // English queries
    'english' => ['helmet', 'plate carrier', 'tourniquet', 'backpack'],
    
    // Multi-word specific
    'specific' => ['бойова сорочка', 'тактичний пояс', 'медичний підсумок'],
    
    // Price queries
    'price' => ['недорогий шолом', 'бюджетна куртка', 'преміум плитоноска'],
    
    // Empty/garbage (should handle gracefully)
    'garbage' => ['asdfgh', '12345', '!!!'],
    
    // Greetings (should NOT search)
    'greetings' => ['привіт', 'hello', 'добрий день'],
];

function testChat($query, $widgetToken, $sessionPrefix = 'test') {
    global $baseUrl;
    
    $sessionId = $sessionPrefix . '_' . time() . '_' . rand(1000, 9999);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$baseUrl/chat",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'message' => $query,
            'session_id' => $sessionId,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Widget-Token: ' . $widgetToken,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        return ['error' => $error, 'http_code' => $httpCode];
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        return ['error' => 'Invalid JSON', 'raw' => substr($response, 0, 200)];
    }
    
    return [
        'type' => $data['type'] ?? 'unknown',
        'products_count' => count($data['products'] ?? []),
        'source' => $data['meta']['source'] ?? null,
        'first_product' => isset($data['products'][0]) ? substr($data['products'][0]['title'] ?? '', 0, 40) : null,
    ];
}

function runTestSuite($tenantId, $widgetToken) {
    global $productTypeQueries, $edgeCases;
    
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "TESTING TENANT $tenantId\n";
    echo str_repeat('=', 60) . "\n";
    
    $results = [
        'passed' => 0,
        'failed' => 0,
        'errors' => 0,
        'details' => [],
    ];
    
    // Test product type queries
    echo "\n--- Product Type Queries ---\n";
    foreach ($productTypeQueries as $type => $queries) {
        foreach ($queries as $query) {
            $result = testChat($query, $widgetToken, "type_{$type}");
            $status = '?';
            
            if (isset($result['error'])) {
                $status = '❌ ERROR';
                $results['errors']++;
            } elseif ($result['type'] === 'products' && $result['products_count'] > 0) {
                $status = '✅ PASS';
                $results['passed']++;
            } elseif ($result['type'] === 'text') {
                // Text response might be OK for greetings, but not for product queries
                $status = '⚠️ TEXT';
                $results['failed']++;
            } else {
                $status = '❌ FAIL';
                $results['failed']++;
            }
            
            $details = sprintf("  %-30s %s (count: %d, src: %s)", 
                "\"$query\"", 
                $status, 
                $result['products_count'] ?? 0,
                $result['source'] ?? 'gpt'
            );
            echo $details . "\n";
            
            $results['details'][] = [
                'query' => $query,
                'type' => $type,
                'status' => $status,
                'result' => $result,
            ];
            
            // Small delay to avoid rate limiting
            usleep(200000); // 200ms
        }
    }
    
    // Test edge cases
    echo "\n--- Edge Cases ---\n";
    foreach ($edgeCases as $category => $queries) {
        echo "  [$category]\n";
        foreach ($queries as $query) {
            $result = testChat($query, $widgetToken, "edge_{$category}");
            
            // Determine expected behavior
            $expectProducts = !in_array($category, ['garbage', 'greetings']);
            
            if (isset($result['error'])) {
                $status = '❌ ERROR';
                $results['errors']++;
            } elseif ($expectProducts && $result['type'] === 'products' && $result['products_count'] > 0) {
                $status = '✅ PASS';
                $results['passed']++;
            } elseif (!$expectProducts && $result['type'] === 'text') {
                $status = '✅ PASS (text expected)';
                $results['passed']++;
            } elseif ($result['type'] === 'text' && $expectProducts) {
                $status = '⚠️ TEXT';
                $results['failed']++;
            } else {
                $status = $result['products_count'] === 0 ? '⚠️ EMPTY' : '✅ PASS';
                if ($status === '⚠️ EMPTY') $results['failed']++;
                else $results['passed']++;
            }
            
            printf("    %-25s %s (count: %d)\n", 
                "\"$query\"", 
                $status, 
                $result['products_count'] ?? 0
            );
            
            usleep(200000);
        }
    }
    
    // Summary
    echo "\n" . str_repeat('-', 60) . "\n";
    echo "TENANT $tenantId SUMMARY:\n";
    echo "  ✅ Passed: {$results['passed']}\n";
    echo "  ❌ Failed: {$results['failed']}\n";
    echo "  🔴 Errors: {$results['errors']}\n";
    $total = $results['passed'] + $results['failed'] + $results['errors'];
    $successRate = $total > 0 ? round($results['passed'] / $total * 100, 1) : 0;
    echo "  Success Rate: {$successRate}%\n";
    echo str_repeat('-', 60) . "\n";
    
    return $results;
}

// Main execution
echo "🚀 Comprehensive Chat Search Test Suite\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";

// Get tenant ID from args or test all
$targetTenant = $argv[1] ?? null;

if ($targetTenant) {
    if (!isset($widgetTokens[$targetTenant])) {
        die("No widget token configured for tenant $targetTenant\n");
    }
    runTestSuite($targetTenant, $widgetTokens[$targetTenant]);
} else {
    // Test all tenants with tokens
    $allResults = [];
    foreach ($widgetTokens as $tenantId => $token) {
        $allResults[$tenantId] = runTestSuite($tenantId, $token);
    }
    
    // Grand summary
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "GRAND SUMMARY\n";
    echo str_repeat('=', 60) . "\n";
    
    $totalPassed = 0;
    $totalFailed = 0;
    $totalErrors = 0;
    
    foreach ($allResults as $tenantId => $results) {
        $totalPassed += $results['passed'];
        $totalFailed += $results['failed'];
        $totalErrors += $results['errors'];
    }
    
    $grandTotal = $totalPassed + $totalFailed + $totalErrors;
    $grandSuccessRate = $grandTotal > 0 ? round($totalPassed / $grandTotal * 100, 1) : 0;
    
    echo "Total Tests: $grandTotal\n";
    echo "✅ Passed: $totalPassed\n";
    echo "❌ Failed: $totalFailed\n";
    echo "🔴 Errors: $totalErrors\n";
    echo "Success Rate: $grandSuccessRate%\n";
}

echo "\nCompleted at: " . date('Y-m-d H:i:s') . "\n";
