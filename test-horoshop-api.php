<?php
/**
 * Direct Horoshop API test
 * Usage: php test-horoshop-api.php [domain] [login] [password]
 */

$domain = $argv[1] ?? 'https://contractor.kiev.ua';
$login = $argv[2] ?? 'owner';
$password = $argv[3] ?? 'Y6wR4j';

echo "Testing Horoshop API: $domain\n";
echo "Login: $login\n\n";

// 1. Auth
$authUrl = rtrim($domain, '/') . '/api/auth/';
$authData = json_encode(['login' => $login, 'password' => $password]);

$ch = curl_init($authUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $authData);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$authResponse = curl_exec($ch);
curl_close($ch);

$authResult = json_decode($authResponse, true);
echo "Auth response: " . json_encode($authResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

if (($authResult['status'] ?? '') !== 'OK') {
    die("Auth failed!\n");
}

$token = $authResult['response']['token'] ?? null;
if (!$token) {
    die("No token received!\n");
}

echo "Token: $token\n\n";

// 2. Export products
$exportUrl = rtrim($domain, '/') . '/api/catalog/export/';
$exportData = json_encode([
    'token' => $token,
    'expr' => ['display_in_showcase' => 1],
    'limit' => 500,
    'offset' => 0,
    'includedParams' => ['article', 'presence', 'title'],
]);

$allProducts = [];
$offset = 0;
$limit = 500;

while (true) {
    $exportData = json_encode([
        'token' => $token,
        'expr' => ['display_in_showcase' => 1],
        'limit' => $limit,
        'offset' => $offset,
        'includedParams' => ['article', 'presence', 'title'],
    ]);
    
    $ch = curl_init($exportUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $exportData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $exportResponse = curl_exec($ch);
    curl_close($ch);
    
    $exportResult = json_decode($exportResponse, true);
    
    if (($exportResult['status'] ?? '') !== 'OK') {
        echo "Export failed at offset $offset: " . json_encode($exportResult) . "\n";
        break;
    }
    
    $products = $exportResult['products'] ?? [];
    if (empty($products)) {
        break;
    }
    
    echo "Fetched " . count($products) . " products at offset $offset\n";
    $allProducts = array_merge($allProducts, $products);
    
    if (count($products) < $limit) {
        break;
    }
    
    $offset += $limit;
}

echo "\n=== RESULTS ===\n";
echo "Total products: " . count($allProducts) . "\n\n";

// Count by presence
$presenceCounts = [];
$inStock = 0;
$outOfStock = 0;

foreach ($allProducts as $p) {
    $presence = $p['presence'] ?? 'unknown';
    if (is_array($presence)) {
        $presence = $presence['value'] ?? $presence['ua'] ?? $presence['ru'] ?? json_encode($presence);
    }
    
    $presenceCounts[$presence] = ($presenceCounts[$presence] ?? 0) + 1;
    
    $lower = mb_strtolower(trim($presence));
    if (str_contains($lower, 'немає') || str_contains($lower, 'нема') || str_contains($lower, 'відсутн')) {
        $outOfStock++;
    } else {
        $inStock++;
    }
}

echo "In stock: $inStock\n";
echo "Out of stock: $outOfStock\n\n";

echo "Presence breakdown:\n";
foreach ($presenceCounts as $status => $count) {
    echo "  '$status': $count\n";
}
