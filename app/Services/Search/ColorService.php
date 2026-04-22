<?php

namespace App\Services\Search;

use App\Models\ColorSynonym;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service for handling color detection and normalization.
 * Uses ColorSynonym model instead of hardcoded mappings.
 */
class ColorService
{
    private const CACHE_TTL_HOURS = 6;

    private const CACHE_KEY = 'color_synonyms_map';

    /**
     * Detect color from user message.
     * Returns normalized color group (e.g., 'black', 'green', 'multicam').
     */
    public function detectColor(string $message): ?string
    {
        $lower = mb_strtolower(trim($message));
        $synonyms = $this->getSynonymsMap();

        foreach ($synonyms as $synonym => $colorGroup) {
            if (str_contains($lower, $synonym)) {
                Log::debug('ColorService: detected color', [
                    'message' => $message,
                    'synonym' => $synonym,
                    'color_group' => $colorGroup,
                ]);

                return $colorGroup;
            }
        }

        return null;
    }

    /**
     * Get all synonyms for a color group.
     */
    public function getSynonymsForColor(string $colorGroup): array
    {
        $colorGroup = strtolower(trim($colorGroup));

        return Cache::remember(
            self::CACHE_KEY.'_'.$colorGroup,
            now()->addHours(self::CACHE_TTL_HOURS),
            function () use ($colorGroup) {
                try {
                    return ColorSynonym::query()
                        ->where('color_group', $colorGroup)
                        ->where('is_active', true)
                        ->pluck('synonym')
                        ->unique()
                        ->values()
                        ->all();
                } catch (\Throwable $e) {
                    Log::warning('ColorService: failed to load synonyms for color', [
                        'color_group' => $colorGroup,
                        'error' => $e->getMessage(),
                    ]);

                    return [];
                }
            }
        );
    }

    /**
     * Get all available color groups.
     */
    public function getColorGroups(): array
    {
        return Cache::remember(
            self::CACHE_KEY.'_groups',
            now()->addHours(self::CACHE_TTL_HOURS),
            function () {
                try {
                    return ColorSynonym::query()
                        ->where('is_active', true)
                        ->distinct()
                        ->pluck('color_group')
                        ->filter()
                        ->values()
                        ->all();
                } catch (\Throwable $e) {
                    Log::warning('ColorService: failed to load color groups', [
                        'error' => $e->getMessage(),
                    ]);

                    return $this->getFallbackColorGroups();
                }
            }
        );
    }

    /**
     * Check if a value is a valid color group.
     */
    public function isValidColorGroup(string $value): bool
    {
        $groups = $this->getColorGroups();

        return in_array(strtolower(trim($value)), array_map('strtolower', $groups), true);
    }

    /**
     * Normalize color value to standard group.
     */
    public function normalizeColor(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $lower = strtolower(trim($value));

        // If already a valid color group, return as is
        if ($this->isValidColorGroup($lower)) {
            return $lower;
        }

        // Try to find in synonyms
        $synonyms = $this->getSynonymsMap();

        return $synonyms[$lower] ?? null;
    }

    /**
     * Extract all colors mentioned in message.
     */
    public function extractAllColors(string $message): array
    {
        $lower = mb_strtolower(trim($message));
        $synonyms = $this->getSynonymsMap();
        $found = [];

        foreach ($synonyms as $synonym => $colorGroup) {
            if (str_contains($lower, $synonym) && ! in_array($colorGroup, $found)) {
                $found[] = $colorGroup;
            }
        }

        return $found;
    }

    /**
     * Get cached map of synonym => color_group.
     */
    private function getSynonymsMap(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addHours(self::CACHE_TTL_HOURS),
            function () {
                try {
                    $synonyms = ColorSynonym::query()
                        ->where('is_active', true)
                        ->get(['synonym', 'color_group']);

                    $map = [];
                    foreach ($synonyms as $row) {
                        $synonym = mb_strtolower(trim($row->synonym));
                        if ($synonym !== '') {
                            $map[$synonym] = strtolower(trim($row->color_group));
                        }
                    }

                    // If no synonyms in DB, use fallback
                    if (empty($map)) {
                        return $this->getFallbackSynonymsMap();
                    }

                    // Sort by synonym length descending (longer matches first)
                    uksort($map, fn ($a, $b) => mb_strlen($b) - mb_strlen($a));

                    return $map;
                } catch (\Throwable $e) {
                    Log::warning('ColorService: failed to load synonyms map', [
                        'error' => $e->getMessage(),
                    ]);

                    return $this->getFallbackSynonymsMap();
                }
            }
        );
    }

    /**
     * Fallback synonyms map when DB is unavailable.
     */
    private function getFallbackSynonymsMap(): array
    {
        return [
            // Ukrainian - sorted by length desc
            'мультикам' => 'multicam',
            'мультікам' => 'multicam',
            'олівковий' => 'olive',
            'оливковий' => 'olive',
            'пісочний' => 'sand',
            'чорному' => 'black',
            'чорного' => 'black',
            'чорним' => 'black',
            'чорній' => 'black',
            'чорний' => 'black',
            'зелена' => 'green',
            'зелене' => 'green',
            'чорну' => 'black',
            'чорна' => 'black',
            'чорне' => 'black',
            'олива' => 'olive',
            'койот' => 'coyote',
            // English
            'multicam' => 'multicam',
            'coyote' => 'coyote',
            'olive' => 'olive',
            'black' => 'black',
            'green' => 'green',
            'sand' => 'sand',
            'tan' => 'tan',
        ];
    }

    /**
     * Fallback color groups when DB is unavailable.
     */
    private function getFallbackColorGroups(): array
    {
        return ['black', 'green', 'olive', 'multicam', 'sand', 'coyote', 'tan'];
    }

    /**
     * Clear color cache (call after updating ColorSynonym table).
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY.'_groups');

        foreach ($this->getFallbackColorGroups() as $group) {
            Cache::forget(self::CACHE_KEY.'_'.$group);
        }
    }
}
