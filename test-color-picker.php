<?php
/**
 * Test color detection from product images
 * Run: php test-color-picker.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use Illuminate\Support\Facades\Http;

// Кольорова палітра для матчингу
$colorMap = [
    'Сірий' => [[100, 100, 100], [180, 180, 180]],
    'Чорний' => [[0, 0, 0], [60, 60, 60]],
    'Олива' => [[70, 80, 40], [130, 150, 90]],
    'Койот' => [[140, 110, 70], [200, 170, 120]],
    'Мультикам' => [[100, 90, 60], [170, 150, 100]], // складний патерн
    'Зелений' => [[30, 80, 30], [100, 180, 100]],
    'Білий' => [[200, 200, 200], [255, 255, 255]],
    'Синій' => [[30, 30, 100], [100, 100, 200]],
    'Піксель' => [[80, 90, 70], [140, 150, 120]], // український піксель
];

function getDominantColor(string $imageUrl): ?array
{
    try {
        // Завантажуємо зображення
        $response = Http::timeout(10)->get($imageUrl);
        if (!$response->successful()) {
            return null;
        }
        
        $imageData = $response->body();
        $image = @imagecreatefromstring($imageData);
        if (!$image) {
            return null;
        }
        
        // Зменшуємо для швидкості
        $width = imagesx($image);
        $height = imagesy($image);
        $newWidth = 50;
        $newHeight = 50;
        
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        // Збираємо кольори пікселів
        $colors = [];
        for ($x = 0; $x < $newWidth; $x++) {
            for ($y = 0; $y < $newHeight; $y++) {
                $rgb = imagecolorat($resized, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                // Ігноруємо білий фон
                if ($r > 240 && $g > 240 && $b > 240) continue;
                // Ігноруємо майже чорний (може бути тінь)
                if ($r < 20 && $g < 20 && $b < 20) continue;
                
                // Групуємо схожі кольори (округлюємо до 20)
                $key = (int)($r/20)*20 . '_' . (int)($g/20)*20 . '_' . (int)($b/20)*20;
                if (!isset($colors[$key])) {
                    $colors[$key] = ['count' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                }
                $colors[$key]['count']++;
                $colors[$key]['r'] += $r;
                $colors[$key]['g'] += $g;
                $colors[$key]['b'] += $b;
            }
        }
        
        imagedestroy($image);
        imagedestroy($resized);
        
        if (empty($colors)) {
            return null;
        }
        
        // Знаходимо найпоширеніший
        uasort($colors, fn($a, $b) => $b['count'] - $a['count']);
        $dominant = array_values($colors)[0];
        
        return [
            'r' => (int)($dominant['r'] / $dominant['count']),
            'g' => (int)($dominant['g'] / $dominant['count']),
            'b' => (int)($dominant['b'] / $dominant['count']),
            'count' => $dominant['count'],
        ];
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        return null;
    }
}

function matchColorName(array $rgb, array $colorMap): string
{
    $r = $rgb['r'];
    $g = $rgb['g'];
    $b = $rgb['b'];
    
    foreach ($colorMap as $name => $range) {
        [$min, $max] = $range;
        if ($r >= $min[0] && $r <= $max[0] &&
            $g >= $min[1] && $g <= $max[1] &&
            $b >= $min[2] && $b <= $max[2]) {
            return $name;
        }
    }
    
    // Якщо не знайшли - визначаємо за домінуючим каналом
    if ($r > $g && $r > $b) return 'Коричневий/Червоний';
    if ($g > $r && $g > $b) return 'Зелений';
    if ($b > $r && $b > $g) return 'Синій';
    if (abs($r - $g) < 30 && abs($g - $b) < 30) {
        if ($r > 150) return 'Світло-сірий';
        return 'Сірий';
    }
    
    return 'Невизначений';
}

function extractColorFromDescription(string $description): ?string
{
    $colorKeywords = [
        'сірий' => 'Сірий',
        'сіра' => 'Сірий', 
        'grey' => 'Сірий',
        'gray' => 'Сірий',
        'чорний' => 'Чорний',
        'чорна' => 'Чорний',
        'black' => 'Чорний',
        'олива' => 'Олива',
        'оливков' => 'Олива',
        'olive' => 'Олива',
        'койот' => 'Койот',
        'coyote' => 'Койот',
        'мультикам' => 'Мультикам',
        'multicam' => 'Мультикам',
        'піксел' => 'Піксель',
        'pixel' => 'Піксель',
        'зелен' => 'Зелений',
        'green' => 'Зелений',
        'білий' => 'Білий',
        'біла' => 'Білий',
        'white' => 'Білий',
    ];
    
    $descLower = mb_strtolower($description);
    foreach ($colorKeywords as $keyword => $color) {
        if (mb_strpos($descLower, $keyword) !== false) {
            return $color;
        }
    }
    
    return null;
}

function getProductImages(Product $product): array
{
    $images = [];
    $raw = $product->raw ?? [];
    
    // Horoshop format
    if (!empty($raw['pictures'])) {
        foreach ($raw['pictures'] as $pic) {
            if (!empty($pic['url'])) {
                $images[] = $pic['url'];
            }
        }
    }
    
    // Alternative format
    if (empty($images) && !empty($raw['images'])) {
        foreach ($raw['images'] as $img) {
            if (!empty($img['url'])) {
                $images[] = $img['url'];
            }
        }
    }
    
    // Single image
    if (empty($images) && !empty($raw['image'])) {
        $images[] = $raw['image'];
    }
    
    if (empty($images) && !empty($raw['main_image'])) {
        $images[] = $raw['main_image'];
    }
    
    return $images;
}

echo "=== Color Picker Test ===\n\n";

// Беремо 10 товарів Level 7 без кольору
$products = Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
    ->where('tenant_id', 2)
    ->where('in_stock', true)
    ->where(function($q) {
        $q->whereNull('color')->orWhere('color', '');
    })
    ->where('title', 'like', '%Level 7%')
    ->limit(10)
    ->get();

echo "Found " . $products->count() . " products without color\n\n";

foreach ($products as $product) {
    echo "---\n";
    echo "ID: {$product->id}, Article: {$product->article}\n";
    echo "Title: {$product->title}\n";
    echo "Current color: '" . ($product->color ?? 'NULL') . "'\n";
    
    // 1. Пробуємо з опису
    $description = $product->raw['description'] ?? '';
    $descColor = extractColorFromDescription($description);
    echo "From description: " . ($descColor ?? 'не знайдено') . "\n";
    
    // 2. Пробуємо з фото
    $images = getProductImages($product);
    if (!empty($images)) {
        echo "Image URL: " . $images[0] . "\n";
        $dominant = getDominantColor($images[0]);
        if ($dominant) {
            $hex = sprintf("#%02x%02x%02x", $dominant['r'], $dominant['g'], $dominant['b']);
            $colorName = matchColorName($dominant, $colorMap);
            echo "Dominant RGB: ({$dominant['r']}, {$dominant['g']}, {$dominant['b']}) = $hex\n";
            echo "Matched color: $colorName\n";
        } else {
            echo "Could not analyze image\n";
        }
    } else {
        echo "No images found\n";
    }
    
    // Рекомендований колір
    $finalColor = $product->color ?: $descColor ?: ($colorName ?? null);
    echo ">>> RECOMMENDED: " . ($finalColor ?? 'manual check needed') . "\n";
    echo "\n";
}

echo "=== Done ===\n";
