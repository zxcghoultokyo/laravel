<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$searchTool = app(\App\Services\Agent\Tools\MeiliProductSearchTool::class);

$query = "допоможи підібрати плитоноску мультикам";
$results = $searchTool->search($query, [], 40);

echo "Query: $query\n";
echo "Results count: " . count($results) . "\n";

if (!empty($results)) {
    echo "\nFirst 3 results:\n";
    foreach (array_slice($results, 0, 3) as $r) {
        echo "  - {$r['title']} (#{$r['article']})\n";
    }
}
