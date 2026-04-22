<?php

/**
 * Simple chat stress test.
 *
 * Usage:
 *   php test-chat-stress.php --url=https://aintento-dev.laravel.cloud --token=<widget_token> --concurrency=50 --requests=500
 *
 * Measures: success rate, p50/p95/p99 latency, queue pressure, 429/5xx rate.
 * Runs plain HTTP POST /api/chat (not SSE) with curl_multi for parallelism.
 */
$opts = getopt('', [
    'url::',
    'token::',
    'concurrency::',
    'requests::',
    'timeout::',
]);
$baseUrl = rtrim($opts['url'] ?? 'http://localhost:8000', '/');
$token = $opts['token'] ?? '';
$concurrency = (int) ($opts['concurrency'] ?? 10);
$totalRequests = (int) ($opts['requests'] ?? 100);
$timeout = (int) ($opts['timeout'] ?? 30);

if (! $token) {
    fwrite(STDERR, "ERROR: --token=<widget_token> is required.\n");
    exit(1);
}

// Real-looking test queries (short = fast path, long = GPT path).
$queries = [
    'шоломи', 'підсумки', 'берці',                          // short_query_handler
    'покажи шоломи Ops-Core', 'підсумок для магазинів',       // GPT
    'що брати взимку на полігон', 'дай топ-3 по броніках',
    'ціна від 500 до 2000', 'показати наступні 3 варіанти',
];

echo "=== Chat Stress Test ===\n";
echo "URL:         {$baseUrl}/api/chat\n";
echo "Concurrency: {$concurrency}\n";
echo "Requests:    {$totalRequests}\n";
echo "Timeout:     {$timeout}s\n";
echo "Start:       ".date('c')."\n\n";

$latencies = [];
$statusCodes = [];
$errors = 0;
$completed = 0;

$multi = curl_multi_init();
$handles = [];
$startTimes = [];
$queue = range(1, $totalRequests);
$active = 0;
$startAll = microtime(true);

$spawn = function () use (&$queue, &$multi, &$handles, &$startTimes, &$active, $baseUrl, $token, $queries, $timeout) {
    if (empty($queue)) {
        return false;
    }
    $id = array_shift($queue);
    $query = $queries[array_rand($queries)];
    $sessionId = 'stress_'.bin2hex(random_bytes(6)).'_'.$id;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl.'/api/chat',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'message' => $query,
            'session_id' => $sessionId,
            'token' => $token,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    curl_multi_add_handle($multi, $ch);
    $handleId = (int) $ch;
    $handles[$handleId] = $ch;
    $startTimes[$handleId] = microtime(true);
    $active++;

    return true;
};

for ($i = 0; $i < $concurrency; $i++) {
    if (! $spawn()) {
        break;
    }
}

do {
    $status = curl_multi_exec($multi, $running);
    if ($running > 0) {
        curl_multi_select($multi, 0.1);
    }

    while ($info = curl_multi_info_read($multi)) {
        $ch = $info['handle'];
        $handleId = (int) $ch;
        $elapsed = (microtime(true) - $startTimes[$handleId]) * 1000;
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);

        if ($curlErrno !== 0) {
            $errors++;
            $statusCodes['curl_error'] = ($statusCodes['curl_error'] ?? 0) + 1;
        } else {
            $latencies[] = $elapsed;
            $statusCodes[$code] = ($statusCodes[$code] ?? 0) + 1;
        }
        $completed++;
        if ($completed % 25 === 0 || $completed === $totalRequests) {
            $rps = $completed / (microtime(true) - $startAll);
            fprintf(STDERR, "  [%4d/%4d]  rps=%.1f  last=%.0fms  code=%d\n",
                $completed, $totalRequests, $rps, $elapsed, $code);
        }

        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        unset($handles[$handleId], $startTimes[$handleId]);
        $active--;

        $spawn();
    }
} while ($active > 0);

curl_multi_close($multi);
$totalElapsed = microtime(true) - $startAll;

sort($latencies);
$n = count($latencies);
$p = fn ($q) => $n === 0 ? 0 : $latencies[(int) floor(($n - 1) * $q)];

echo "\n=== Results ===\n";
printf("Total time:      %.2fs\n", $totalElapsed);
printf("Throughput:      %.1f req/s\n", $completed / max($totalElapsed, 0.001));
printf("Completed:       %d\n", $completed);
printf("Curl errors:     %d\n", $errors);
echo  "Status codes:    ".json_encode($statusCodes)."\n";
printf("Latency p50:     %.0fms\n", $p(0.50));
printf("Latency p95:     %.0fms\n", $p(0.95));
printf("Latency p99:     %.0fms\n", $p(0.99));
printf("Latency max:     %.0fms\n", $latencies[$n - 1] ?? 0);

// Health check — fail loudly if > 5% errors or p95 > 15s.
$errorRate = ($errors + ($statusCodes[500] ?? 0) + ($statusCodes[502] ?? 0) + ($statusCodes[503] ?? 0)) / max($completed, 1);
$p95 = $p(0.95);
echo "\n=== Health ===\n";
if ($errorRate > 0.05) {
    echo "❌ Error rate {$errorRate} exceeds 5% threshold\n";
    exit(2);
}
if ($p95 > 15000) {
    echo "❌ p95 latency {$p95}ms exceeds 15s threshold\n";
    exit(2);
}
echo "✅ Error rate ok, p95 ok.\n";
