<?php

$host = 'db-a08f0371-6315-4eef-aa26-4b6585b1936f.eu-central-1.public.db.laravel.cloud';
$user = 'tjqz74bgt2t1eexm';
$password = '5VzvEWz1uhaN7aGUhMuv';
$database = 'Cloud - laravel';

// Спробуємо з PDO
try {
    $dsn = "mysql:host=$host;port=3306;dbname=" . urlencode($database) . ";charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Connected to MySQL!\n\n";
    
    // Count products
    $count = $pdo->query("SELECT COUNT(*) as cnt FROM products")->fetch()['cnt'];
    echo "Total products: $count\n\n";
    
    // Fetch all products
    echo "Fetching products...\n";
    $stmt = $pdo->query("
        SELECT 
            id, article, parent_article, title, title_json, price, price_old,
            category_path, slug, link, images, raw, search_index,
            orders_count, views_count, added_to_cart_count,
            in_stock, presence, quantity, popularity, we_recommended,
            color, brand, display_in_showcase
        FROM products
        LIMIT 2321
    ");
    
    $products = [];
    while ($row = $stmt->fetch()) {
        // Parse JSON fields
        if ($row['title_json']) {
            $row['title_json'] = json_decode($row['title_json'], true);
        }
        if ($row['images']) {
            $row['images'] = json_decode($row['images'], true);
        }
        if ($row['raw']) {
            $row['raw'] = json_decode($row['raw'], true);
        }
        
        $products[] = $row;
    }
    
    echo "Fetched: " . count($products) . " products\n";
    
    // Save to file
    $file = __DIR__ . '/database/seeds/products-full.json';
    $json = json_encode([
        'total' => $count,
        'exported' => count($products),
        'products' => $products,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    file_put_contents($file, $json);
    
    echo "✅ Saved to: $file\n";
    echo "File size: " . number_format(filesize($file) / 1024 / 1024, 2) . " MB\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
