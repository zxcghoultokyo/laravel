<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Agent\MinimalAgent;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Models\Tenant;

$tenant = Tenant::where('domain', 'contractor.kiev.ua')->first();
echo "Tenant: {$tenant->name} (ID: {$tenant->id})\n";

$searchTool = app(MeiliProductSearchTool::class);

echo "\n--- Testing search tool directly ---\n";
$result = $searchTool->search('термобілизна', ['tenant_id' => $tenant->id], 3);
echo "Search result count: " . count($result['products'] ?? []) . "\n";
if (!empty($result['products'])) {
    foreach (array_slice($result['products'], 0, 2) as $p) {
        echo "- {$p['title']} ({$p['price']} грн)\n";
    }
}

echo "\n--- Testing MinimalAgent ---\n";
try {
    $agent = new MinimalAgent($searchTool);
    $agent->setTenantId($tenant->id);
    
    $response = $agent->handle('жіноча термобілизна');
    
    echo "Response type: " . gettype($response) . "\n";
    if (is_array($response)) {
        echo "Message: " . ($response['message'] ?? 'N/A') . "\n";
        echo "Products count: " . count($response['products'] ?? []) . "\n";
        if (!empty($response['products'])) {
            foreach (array_slice($response['products'], 0, 2) as $p) {
                echo "- {$p['title']} ({$p['price']} грн)\n";
            }
        }
        if (!empty($response['meta'])) {
            echo "Meta: " . json_encode($response['meta']) . "\n";
        }
    } else {
        echo "Response: " . substr(print_r($response, true), 0, 500) . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
