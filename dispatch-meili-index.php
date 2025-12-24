<?php
/**
 * Simple dispatcher for IndexProductsToMeiliJob
 * Usage: php dispatch-meili-index.php [chunkSize]
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$chunkSize = (int) ($argv[1] ?? 500);

echo "Dispatching IndexProductsToMeiliJob with chunk size: {$chunkSize}\n";

\App\Jobs\IndexProductsToMeiliJob::dispatch($chunkSize);

echo "Job dispatched successfully!\n";
echo "Run queue worker: php artisan queue:work --queue=meili,default --tries=1\n";
