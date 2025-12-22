<?php
echo "=== BRANDS TABLE ===\n";
echo "Count: " . App\Models\Brand::count() . "\n\n";
echo "Top 10:\n";
App\Models\Brand::orderBy('product_count', 'desc')->limit(10)->get(['name', 'slug', 'product_count'])->each(function($b) {
    echo "  {$b->name} ({$b->slug}): {$b->product_count}\n";
});

echo "\n=== HOFFMANN PRODUCTS ===\n";
$hoffmann = App\Models\Product::where('brand', 'LIKE', '%hoffmann%')->limit(3)->get(['brand', 'title']);
echo "Found: " . $hoffmann->count() . "\n";
$hoffmann->each(function($p) {
    echo "  {$p->brand}: {$p->title}\n";
});

echo "\n=== ATAKA PRODUCTS ===\n";
$ataka = App\Models\Product::where('brand', 'LIKE', '%АТАКА%')->orWhere('brand', 'LIKE', '%ATAKA%')->limit(3)->get(['brand', 'title']);
echo "Found: " . $ataka->count() . "\n";
$ataka->each(function($p) {
    echo "  {$p->brand}: {$p->title}\n";
});

echo "\n=== BRAND CACHE ===\n";
$cache = Cache::get('brands:all');
echo "Cached: " . (is_array($cache) ? count($cache) . " brands" : "NULL") . "\n";
if (is_array($cache)) {
    echo "First 10:\n";
    foreach (array_slice($cache, 0, 10) as $b) {
        echo "  - $b\n";
    }
}

echo "\n=== BRAND DETECTION TEST ===\n";
$service = app(App\Services\Search\BrandDetectionService::class);

$r1 = $service->detectBrand('hoffmann');
echo "Query: 'hoffmann'\n";
echo "  is_brand: " . ($r1['is_brand'] ? 'YES' : 'NO') . "\n";
echo "  brand: " . ($r1['brand'] ?? 'N/A') . "\n";
echo "  enhanced_query: " . $r1['enhanced_query'] . "\n\n";

$r2 = $service->detectBrand('атака плитоноска');
echo "Query: 'атака плитоноска'\n";
echo "  is_brand: " . ($r2['is_brand'] ? 'YES' : 'NO') . "\n";
echo "  brand: " . ($r2['brand'] ?? 'N/A') . "\n";
echo "  enhanced_query: " . $r2['enhanced_query'] . "\n";
