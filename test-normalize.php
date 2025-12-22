<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$aiRouter = app(\App\Services\Ai\AiRouter::class);

$query = "допоможи підібрати плитоноску мультикам";

echo "Original query: $query\n";
echo "Normalized: " . $aiRouter->normalizeSearchQuery($query) . "\n";
