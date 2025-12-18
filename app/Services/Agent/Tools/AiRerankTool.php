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
     * Takes 40 candidates, returns top 10-15 most relevant
     */
    public function rerank(array $candidates, string $query, array $filters = [], int $limit = 10): array
    {
        if (count($candidates) <= $limit) {
            return $candidates; // No need to re-rank
        }

        try {
            $prompt = $this->buildRerankPrompt($candidates, $query, $filters);
            $response = $this->aiRouter->callOpenAI($prompt, 0.3);
            
            $result = json_decode($response, true);
            
            if (!is_array($result) || !isset($result['chosen_ids'])) {
                Log::warning('AiRerankTool: Invalid AI response, using original order', [
                    'response' => $response
                ]);
                return array_slice($candidates, 0, $limit);
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
            
            // Add remaining candidates (not chosen by AI) at the end
            $chosenIdsSet = array_flip($chosenIds);
            foreach ($candidates as $candidate) {
                if (!isset($chosenIdsSet[$candidate['id']]) && count($reranked) < $limit) {
                    $candidate['ai_score'] = 0;
                    $reranked[] = $candidate;
                }
            }
            
            Log::info('AiRerankTool: reranked', [
                'original_count' => count($candidates),
                'reranked_count' => count($reranked),
                'chosen_ids' => $chosenIds,
            ]);
            
            return array_slice($reranked, 0, $limit);
            
        } catch (\Exception $e) {
            Log::error('AiRerankTool: error, using original order', [
                'error' => $e->getMessage(),
            ]);
            return array_slice($candidates, 0, $limit);
        }
    }

    private function buildRerankPrompt(array $candidates, string $query, array $filters): string
    {
        $candidatesList = [];
        foreach (array_slice($candidates, 0, 40) as $idx => $c) {
            $categoryPath = $c['category_path'] ?? 'N/A';
            if (is_array($categoryPath)) {
                $categoryPath = implode(' > ', $categoryPath);
            }
            
            $candidatesList[] = sprintf(
                "ID %d: %s | %s грн | %s | Popular: %d | Stock: %s",
                $c['id'],
                $c['title'],
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

        return <<<PROMPT
Ти — AI-експерт магазину Contractor (тактичне військове спорядження).

Клієнти: військові ЗСУ, правоохоронці, добровольці, цивільні патріоти.

Запит користувача: "{$query}"
{$filterDesc}

Кандидати ({count($candidates)} товарів):
{implode("\n", $candidatesList)}

Завдання:
1. Обери ТОП-10 найрелевантніших товарів
2. Враховуй: відповідність запиту, якість/надійність, популярність, наявність
3. Пріоритет: професійне екіпірування для екстремальних умов
4. Поверни JSON:

{
  "chosen_ids": [123, 456, 789, ...],
  "reasoning": {
    "123": "Найпопулярніша модель, перевірена в бою",
    "456": "Преміум якість, довговічність",
    ...
  }
}

Поверни ТІЛЬКИ JSON, без пояснень.
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
}
