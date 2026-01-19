<?php

namespace App\Services\Catalog;

use ColorThief\ColorThief;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Service for detecting product colors from images and descriptions
 * 
 * Priority:
 * 1. Color field (already set)
 * 2. Color from description/attributes text
 * 3. Color from image analysis (Color Thief)
 */
class ColorDetectionService
{
    /**
     * Color keywords mapping (Ukrainian + English)
     */
    private array $colorKeywords = [
        // Сірий
        'сірий' => 'Сірий',
        'сіра' => 'Сірий',
        'сіре' => 'Сірий',
        'grey' => 'Сірий',
        'gray' => 'Сірий',
        'urban grey' => 'Сірий',
        'foliage' => 'Сірий',
        
        // Чорний
        'чорний' => 'Чорний',
        'чорна' => 'Чорний',
        'чорне' => 'Чорний',
        'black' => 'Чорний',
        
        // Олива
        'олива' => 'Олива',
        'оливков' => 'Олива',
        'olive' => 'Олива',
        'od green' => 'Олива',
        'ranger green' => 'Олива',
        
        // Койот
        'койот' => 'Койот',
        'coyote' => 'Койот',
        'tan' => 'Койот',
        'хакі' => 'Койот',
        'khaki' => 'Койот',
        
        // Мультикам
        'мультикам' => 'Мультикам',
        'multicam' => 'Мультикам',
        'мульти' => 'Мультикам',
        
        // Піксель
        'піксел' => 'Піксель',
        'pixel' => 'Піксель',
        'mm14' => 'Піксель',
        'ukrainian' => 'Піксель',
        
        // Зелений
        'зелен' => 'Зелений',
        'green' => 'Зелений',
        
        // Білий
        'білий' => 'Білий',
        'біла' => 'Білий',
        'біле' => 'Білий',
        'white' => 'Білий',
        'snow' => 'Білий',
        
        // Синій
        'синій' => 'Синій',
        'синя' => 'Синій',
        'синє' => 'Синій',
        'blue' => 'Синій',
        'navy' => 'Синій',
        
        // Коричневий
        'коричнев' => 'Коричневий',
        'brown' => 'Коричневий',
        
        // Пісочний
        'пісоч' => 'Пісочний',
        'sand' => 'Пісочний',
        'desert' => 'Пісочний',
        'dcu' => 'Пісочний',
        
        // Камуфляж (загальний)
        'камуфляж' => 'Камуфляж',
        'camo' => 'Камуфляж',
        'woodland' => 'Камуфляж',
        'flecktarn' => 'Камуфляж',
        'marpat' => 'Камуфляж',
        'aor' => 'Камуфляж',
        'a-tacs' => 'Камуфляж',
        'kryptek' => 'Камуфляж',
    ];

    /**
     * RGB ranges for color matching from image analysis
     * [name => [[minR, minG, minB], [maxR, maxG, maxB]]]
     */
    private array $colorRanges = [
        'Сірий' => [[90, 90, 90], [180, 180, 180]],
        'Чорний' => [[0, 0, 0], [70, 70, 70]],
        'Білий' => [[200, 200, 200], [255, 255, 255]],
        'Олива' => [[60, 70, 30], [140, 160, 90]],
        'Койот' => [[140, 100, 60], [210, 175, 130]],
        'Зелений' => [[20, 70, 20], [100, 170, 100]],
        'Синій' => [[20, 20, 80], [100, 100, 200]],
        'Коричневий' => [[80, 50, 30], [160, 110, 80]],
        'Пісочний' => [[180, 160, 120], [240, 220, 180]],
    ];

    /**
     * Detect color for a product using all available methods
     */
    public function detectColor(
        ?string $currentColor,
        ?string $description,
        ?array $attributes,
        ?string $imageUrl
    ): ?string {
        // 1. If color already set and valid, return it
        if (!empty($currentColor) && $currentColor !== 'null') {
            return $currentColor;
        }

        // 2. Try to extract from description
        $fromDescription = $this->extractColorFromText($description ?? '');
        if ($fromDescription) {
            return $fromDescription;
        }

        // 3. Try to extract from attributes
        if (!empty($attributes)) {
            $attributesText = $this->flattenAttributes($attributes);
            $fromAttributes = $this->extractColorFromText($attributesText);
            if ($fromAttributes) {
                return $fromAttributes;
            }
        }

        // 4. Try to analyze image (if URL provided)
        if ($imageUrl) {
            $fromImage = $this->analyzeImage($imageUrl);
            if ($fromImage) {
                return $fromImage;
            }
        }

        return null;
    }

    /**
     * Extract color from text using keyword matching
     */
    public function extractColorFromText(string $text): ?string
    {
        if (empty($text)) {
            return null;
        }

        $textLower = mb_strtolower($text);
        
        // Sort keywords by length (longer first) to match "urban grey" before "grey"
        $sortedKeywords = $this->colorKeywords;
        uksort($sortedKeywords, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        
        foreach ($sortedKeywords as $keyword => $colorName) {
            if (mb_strpos($textLower, $keyword) !== false) {
                return $colorName;
            }
        }

        return null;
    }

    /**
     * Flatten attributes array to text for color extraction
     */
    private function flattenAttributes(array $attributes): string
    {
        $parts = [];
        
        foreach ($attributes as $key => $value) {
            if (is_string($value)) {
                $parts[] = $value;
            } elseif (is_array($value)) {
                if (isset($value['value'])) {
                    $parts[] = $value['value'];
                } elseif (isset($value['name'])) {
                    $parts[] = $value['name'];
                } else {
                    $parts[] = implode(' ', array_filter($value, 'is_string'));
                }
            }
        }
        
        return implode(' ', $parts);
    }

    /**
     * Analyze image to detect dominant color
     */
    public function analyzeImage(string $imageUrl): ?string
    {
        // Cache results to avoid repeated API calls
        $cacheKey = 'color_detection_' . md5($imageUrl);
        
        return Cache::remember($cacheKey, 86400, function () use ($imageUrl) {
            try {
                // Download image to temp file
                $response = Http::timeout(15)->get($imageUrl);
                
                if (!$response->successful()) {
                    Log::warning('ColorDetection: Failed to download image', ['url' => $imageUrl]);
                    return null;
                }

                $tempFile = tempnam(sys_get_temp_dir(), 'color_');
                file_put_contents($tempFile, $response->body());

                try {
                    // Use Color Thief with quality parameter (higher = faster but less accurate)
                    $dominantColor = ColorThief::getColor($tempFile, 10);
                    
                    if (!$dominantColor) {
                        return null;
                    }

                    [$r, $g, $b] = $dominantColor;
                    
                    // Match RGB to color name
                    $colorName = $this->matchRgbToColorName($r, $g, $b);
                    
                    Log::debug('ColorDetection: Image analyzed', [
                        'url' => $imageUrl,
                        'rgb' => [$r, $g, $b],
                        'matched' => $colorName,
                    ]);

                    return $colorName;
                } finally {
                    @unlink($tempFile);
                }
            } catch (\Exception $e) {
                Log::error('ColorDetection: Error analyzing image', [
                    'url' => $imageUrl,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }

    /**
     * Match RGB values to color name
     */
    private function matchRgbToColorName(int $r, int $g, int $b): ?string
    {
        // First, try exact range matching
        foreach ($this->colorRanges as $name => [$min, $max]) {
            if ($r >= $min[0] && $r <= $max[0] &&
                $g >= $min[1] && $g <= $max[1] &&
                $b >= $min[2] && $b <= $max[2]) {
                return $name;
            }
        }

        // Fallback: determine by dominant channel and saturation
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $saturation = $max > 0 ? ($max - $min) / $max : 0;
        $lightness = ($max + $min) / 2;

        // Low saturation = grayscale
        if ($saturation < 0.15) {
            if ($lightness < 60) return 'Чорний';
            if ($lightness > 200) return 'Білий';
            return 'Сірий';
        }

        // Determine by hue
        if ($r > $g && $r > $b) {
            // Red dominant
            if ($g > $b && $g > 100) {
                return 'Койот'; // Yellowish red = tan/coyote
            }
            return 'Коричневий';
        }

        if ($g > $r && $g > $b) {
            // Green dominant
            if ($r > 80 && $b < 80) {
                return 'Олива'; // Olive green
            }
            return 'Зелений';
        }

        if ($b > $r && $b > $g) {
            return 'Синій';
        }

        // Equal-ish values
        if (abs($r - $g) < 40 && abs($g - $b) < 40) {
            return $lightness > 150 ? 'Сірий' : 'Чорний';
        }

        return null;
    }

    /**
     * Get color palette from image (for debugging/admin)
     */
    public function getColorPalette(string $imageUrl, int $count = 5): array
    {
        try {
            $response = Http::timeout(15)->get($imageUrl);
            
            if (!$response->successful()) {
                return ['error' => 'Failed to download image'];
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'palette_');
            file_put_contents($tempFile, $response->body());

            try {
                $palette = ColorThief::getPalette($tempFile, $count, 10);
                
                $result = [];
                foreach ($palette as $color) {
                    [$r, $g, $b] = $color;
                    $result[] = [
                        'rgb' => [$r, $g, $b],
                        'hex' => sprintf('#%02x%02x%02x', $r, $g, $b),
                        'matched' => $this->matchRgbToColorName($r, $g, $b),
                    ];
                }

                return $result;
            } finally {
                @unlink($tempFile);
            }
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Bulk detect colors for products
     */
    public function detectColorsForProducts(array $products): array
    {
        $results = [];

        foreach ($products as $product) {
            $raw = $product->raw ?? [];
            
            // Get image URL
            $imageUrl = null;
            if (!empty($raw['pictures'][0]['url'])) {
                $imageUrl = $raw['pictures'][0]['url'];
            } elseif (!empty($raw['images'][0]['url'])) {
                $imageUrl = $raw['images'][0]['url'];
            } elseif (!empty($raw['image'])) {
                $imageUrl = $raw['image'];
            }

            $detected = $this->detectColor(
                $product->color,
                $raw['description'] ?? null,
                $raw['properties'] ?? $raw['attributes'] ?? null,
                $imageUrl
            );

            $results[] = [
                'id' => $product->id,
                'article' => $product->article,
                'title' => $product->title,
                'current_color' => $product->color,
                'detected_color' => $detected,
                'source' => $this->determineSource($product->color, $raw, $detected),
            ];
        }

        return $results;
    }

    /**
     * Determine where the color was detected from
     */
    private function determineSource(?string $currentColor, array $raw, ?string $detected): string
    {
        if (!empty($currentColor) && $currentColor !== 'null') {
            return 'field';
        }

        $description = $raw['description'] ?? '';
        if ($this->extractColorFromText($description)) {
            return 'description';
        }

        $attributes = $raw['properties'] ?? $raw['attributes'] ?? [];
        if (!empty($attributes) && $this->extractColorFromText($this->flattenAttributes($attributes))) {
            return 'attributes';
        }

        if ($detected) {
            return 'image';
        }

        return 'unknown';
    }
}
