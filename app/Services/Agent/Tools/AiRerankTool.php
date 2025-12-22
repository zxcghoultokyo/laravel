<?php

namespace App\Services\Agent\Tools;

use App\Services\Ai\AiRouter;
use Illuminate\Support\Facades\Log;

class AiRerankTool
{
    public function __construct(private AiRouter $aiRouter)
    {}

    /**
     * Use AI to re-rank products based on query relevance
     * Takes 40 candidates, returns top 3-10 most relevant (dynamic based on quality)
     */
    public function rerank(array $candidates, string $query, array $filters = [], int $limit = 10): array
    {
        if (count($candidates) <= 3) {
            return $candidates; // No need to re-rank
        }

        try {
            // Detect brand from query
            $detectedBrand = $this->detectBrandFromQuery($query, $candidates);
            
            // If explicit brand query (e.g., "hoffmann", "атака") — filter strictly
            if ($detectedBrand && $this->isExplicitBrandQuery($query, $detectedBrand)) {
                $beforeCount = count($candidates);
                $candidates = $this->filterByBrand($candidates, $detectedBrand);
                
                Log::info('AiRerankTool: explicit brand filter applied', [
                    'brand' => $detectedBrand,
                    'before' => $beforeCount,
                    'after' => count($candidates),
                    'query' => $query,
                ]);
            }
            
            $prompt = $this->buildRerankPrompt($candidates, $query, $filters, $detectedBrand);
            $response = $this->aiRouter->callOpenAI($prompt, 0.3);
            
            $result = json_decode($response, true);
            
            if (!is_array($result) || !isset($result['chosen_ids'])) {
                Log::warning('AiRerankTool: Invalid AI response, using original order', [
                    'response' => $response
                ]);
                return array_slice($candidates, 0, min(3, count($candidates)));
            }
            
            // Reorder candidates based on AI choices
            $chosenIds = $result['chosen_ids'];
            $reranked = [];
            $reasoning = $result['reasoning'] ?? [];
            
            foreach ($chosenIds as $idx => $id) {
                $candidate = $this->findCandidateById($candidates, $id);
                if ($candidate) {
                    $candidate['ai_score'] = count($chosenIds) - $idx; // Higher position = higher score
                    $candidate['ai_reasoning'] = $reasoning[$id] ?? null;
                    $reranked[] = $candidate;
                }
            }
            
            // Don't add non-chosen candidates anymore — only show what AI selected
            
            Log::info('AiRerankTool: reranked', [
                'original_count' => count($candidates),
                'reranked_count' => count($reranked),
                'chosen_ids' => $chosenIds,
                'ai_selected' => count($chosenIds),
                'detected_brand' => $detectedBrand ?? 'none',
            ]);
            
            return $reranked;
            
        } catch (\Exception $e) {
            Log::error('AiRerankTool: error, using original order', [
                'error' => $e->getMessage(),
            ]);
            return array_slice($candidates, 0, $limit);
        }
    }

    private function buildRerankPrompt(array $candidates, string $query, array $filters, ?string $detectedBrand = null): string
    {
        $candidatesList = [];
        foreach (array_slice($candidates, 0, 40) as $idx => $c) {
            $categoryPath = $c['category_path'] ?? 'N/A';
            if (is_array($categoryPath)) {
                $categoryPath = implode(' > ', $categoryPath);
            }
            
            $brand = $c['brand'] ?? 'N/A';
            
            $candidatesList[] = sprintf(
                "ID %d: %s | Бренд: %s | %s грн | %s | Popular: %d | Stock: %s",
                $c['id'],
                $c['title'],
                $brand,
                $c['price'],
                $categoryPath,
                $c['popularity'] ?? 0,
                $c['in_stock'] ? 'Yes' : 'No'
            );
        }

        $filterDesc = '';
        if (!empty($filters['budget_max'])) {
            $filterDesc .= "Бюджет до {$filters['budget_max']} грн. ";
        }
        if (!empty($filters['color'])) {
            $filterDesc .= "Колір: {$filters['color']}. ";
        }
        
        // Brand-specific instruction
        $brandInstruction = '';
        if ($detectedBrand) {
            $brandInstruction = <<<BRAND

🔴 КРИТИЧНО ВАЖЛИВО — БРЕНД:
Запит містить бренд "{$detectedBrand}" → показувати ТІЛЬКИ товари бренду "{$detectedBrand}"!
- Бренд МАЄ АБСОЛЮТНИЙ ПРІОРИТЕТ над popularity
- Якщо товар НЕ бренду "{$detectedBrand}" → НЕ додавай його в chosen_ids
- Сортуй товари "{$detectedBrand}" по релевантності всередині бренду
- Ігноруй popularity якщо бренд не співпадає

BRAND;
        }

        return <<<PROMPT
Ти — AI-експерт магазину Contractor (тактичне військове спорядження).

Клієнти: військові ЗСУ, правоохоронці, добровольці, цивільні патріоти.

Запит користувача: "{$query}"
{$filterDesc}

Кандидати ({count($candidates)} товарів):
{implode("\n", $candidatesList)}
{$brandInstruction}

ДУЖЕ ВАЖЛИВО:
- Якщо товар має в назві "ремінь", "плечовий", "одноточков", "двоточков", "слінг", "камбербанд", "панел", "кріплення", "адаптер", "ліхтарик", "ліхтар", "навушник", "гарнітур", "кавер", "чохол" — ЦЕ АКСЕСУАР, показувати ТІЛЬКИ якщо немає основних товарів
- Основні товари: саме плитоноски (без слова "ремінь"), саме шоломи (без "кріплення"), саме плити (без "чохол")
- Якщо є 3+ основних товарів — ігноруй всі аксесуари
- ПРІОРИТЕТ: основні товари перші, аксесуари тільки на кінець

Завдання:
1. Обери ТІЛЬКИ справді релевантні товари (мінімум 3, максимум 10)
2. ЯКЩО релевантних менше 10 — вибери тільки їх (НЕ заповнюй до 10!)
3. ЯКЩО є 3-4 ідеальних товарів + 6 посередніх → вибери тільки 3-4 ідеальних
4. СПОЧАТКУ основні товари, ПОТІМ аксесуари (якщо дуже релевантні)
5. Враховуй: точність відповідності запиту, якість, популярність
6. ВАЖЛИВО: Якість > кількість. 3 точні варіанти краще ніж 10 різних

Приклади:
- "шеврон група крові" → 4 шеврони з різними групами (НЕ додавай MED, СБУ, прапор)
- "плитоноска АТАКА" → 3-4 плитоноски АТАКА (НЕ додавай інші бренди)
- "рукавички зимові" → 5-7 зимових рукавиць (НЕ додавай тактичні літні)

Поверни JSON:

{
  "chosen_ids": [123, 456, 789],
  "reasoning": {
    "123": "Точна відповідність запиту",
    "456": "Альтернативний варіант",
    ...
  }
}

Поверни ТІЛЬКИ JSON. Кількість IDs = кількість РЕЛЕВАНТНИХ товарів (3-10, не обов'язково 10).
PROMPT;
    }

    private function findCandidateById(array $candidates, int $id): ?array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['id'] === $id) {
                return $candidate;
            }
        }
        return null;
    }
    
    /**
     * Detect brand from query by checking if any candidate brand appears in query
     */
    private function detectBrandFromQuery(string $query, array $candidates): ?string
    {
        $query = mb_strtolower(trim($query));
        
        // Collect unique brands from candidates
        $brands = [];
        foreach ($candidates as $c) {
            if (!empty($c['brand'])) {
                $brands[$c['brand']] = mb_strtolower($c['brand']);
            }
        }
        
        // Check if any brand appears in query
        foreach ($brands as $originalBrand => $brandLower) {
            if (str_contains($query, $brandLower)) {
                return $originalBrand; // Return original case
            }
        }
        
        return null;
    }
    
    /**
     * Check if query is EXPLICITLY about a brand (brand is main keyword)
     * Examples: "hoffmann", "атака плитоноска" → true
     * Counter: "плитоноска зелена" → false (no brand)
     */
    private function isExplicitBrandQuery(string $query, string $brand): bool
    {
        $query = mb_strtolower(trim($query));
        $brand = mb_strtolower($brand);
        
        // Query IS the brand (e.g., "hoffmann")
        if ($query === $brand) {
            return true;
        }
        
        // Query starts with brand (e.g., "hoffmann патчі")
        if (str_starts_with($query, $brand)) {
            return true;
        }
        
        // Query ends with brand (e.g., "патчі hoffmann")
        if (str_ends_with($query, $brand)) {
            return true;
        }
        
        // Brand is standalone word in query (surrounded by spaces)
        if (preg_match('/\b' . preg_quote($brand, '/') . '\b/ui', $query)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Filter candidates to keep only specified brand
     */
    private function filterByBrand(array $candidates, string $brand): array
    {
        $brandLower = mb_strtolower($brand);
        
        return array_values(array_filter($candidates, function($c) use ($brandLower) {
            if (empty($c['brand'])) {
                return false;
            }
            return mb_strtolower($c['brand']) === $brandLower;
        }));
    }
}
