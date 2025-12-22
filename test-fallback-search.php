<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$searchTool = app(\App\Services\Agent\Tools\MeiliProductSearchTool::class);

$testQueries = [
    "допоможи підібрати плитоноску мультикам",
    "плитоноска мультикам",
    "плитоноска",
    "мультикам",
    "SAPI плити",
];

foreach ($testQueries as $q) {
    $results = $searchTool->search($q, [], 5);
    echo "Query: '$q'\n";
    echo "  Results: " . count($results) . "\n";
    if (!empty($results)) {
        foreach (array_slice($results, 0, 2) as $r) {
            echo "    - {$r['title']} (#{$r['article']})\n";
        }
    }
    echo "\n";
}
