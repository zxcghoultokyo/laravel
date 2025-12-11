<?php

namespace App\Services\Ai\Scenarios;

use App\Models\Scenario;
use App\Services\Ai\AiClient;
use App\Services\Horoshop\ProductService;

class TacticalMedicineScenarioHandler implements ScenarioHandlerInterface
{
    public function __construct(
        protected AiClient $aiClient,
        protected ProductService $productService,
    ) {}

    public function handle(string $message, Scenario $scenario, array $routerPayload): array
    {
        $config = $scenario->config ?? [];

        // 1) Визначаємо, як діставати товари (все в config, без хардкоду в коді)
        // Приклад config['product_source']:
        // {
        //   "type": "category_path_contains",
        //   "value": "Тактична медицина",
        //   "limit": 50
        // }
        $productSource = $config['product_source'] ?? [
            'type'  => 'category_path_contains',
            'value' => 'Тактична медицина',
            'limit' => 50,
        ];

        $products = $this->fetchProductsBySource($productSource);

        // Візьмемо компактний список для LLM (без зайвих полів)
        $compactProducts = collect($products)->map(function ($p) {
            return [
                'id'       => $p['id'],
                'title'    => $p['title'] ?? ($p['title_json']['ua'] ?? ($p['title_json']['ru'] ?? '')),
                'category' => $p['category_path'] ?? '',
                'price'    => $p['price'] ?? null,
                'link'     => $p['link'] ?? null,
            ];
        })->values()->all();

        // 2) Готуємо промпт-персону (частково з config, частково дефолт)
        $persona = $config['persona'] ?? <<<PROMPT
Ти — досвідчений боєць/медик, який працює в магазині тактичного спорядження.
Ти на "ти", говориш просто, по-діловому, без зайвого пафосу, але дуже компетентно.
Твоє завдання — допомогти побратиму підібрати тактичну медицину під його задачі.

Ти отримуєш:
- запит користувача;
- список товарів з категорії тактичної медицини (турнікети, бинти, гемостатики, аптечки, підсумки тощо).

Твій план відповіді:
1. Коротко поясни, що в нас є по такмеду (які групи товарів).
2. Запропонуй логічний шлях:
   - або зібрати повноцінну аптечку/IFAK,
   - або підібрати окремі позиції (наприклад, турнікети, гемостатики, бандажі).
3. Якщо бачиш по запиту, що людині краще готовий набір — порекомендуй 2–3 готові аптечки/набори.
4. Якщо запит більш точковий — запропонуй кілька конкретних позицій.
5. Завжди завершуєш питанням, щоб продовжити діалог (наприклад: "Розкажи, під які задачі тобі аптечка: піхота, артилерія, ТРО, водій?").

У відповіді не пиши JSON, просто нормальний людський текст.
PROMPT;

        $semanticQuery = $routerPayload['semantic_query'] ?? $message;

        $system = $persona;

        $user = [
            'user_message' => $message,
            'semantic_query' => $semanticQuery,
            'products' => $compactProducts,
        ];

        // 3) Просимо LLM згенерувати текстову відповідь + вибрати топ-товари
        // Вихід очікуємо у форматі JSON:
        // {
        //   "message": "тут текст для користувача",
        //   "product_ids": [1,2,3] // не більше 5
        // }
        $routerSystem = <<<SYS
Ти повинен відповісти СТРОГО у форматі JSON наступного вигляду, без пояснень поза JSON:

{
  "message": "тут повний текст відповіді українською",
  "product_ids": [<id1>, <id2>, ...] // не більше 5 id з переданого списку products
}

"message" — це готовий текст, який побачить користувач в чаті.
"product_ids" — список id товарів, які найкраще підходять під запит. Якщо товарів надто багато, вибери найтиповіші/оптимальні.
SYS;

        $response = $this->aiClient->chatJson($system . "\n\n" . $routerSystem, $user);

        $messageText = $response['message'] ?? 'Готовий допомогти з такмедом, але не зміг зібрати відповідь.';
        $ids         = $response['product_ids'] ?? [];

        $productsById = collect($products)->keyBy('id');
        $selectedProducts = collect($ids)
            ->map(fn ($id) => $productsById->get($id))
            ->filter()
            ->values()
            ->all();

        // 4) Готуємо структуровану відповідь для фронта
        return [
            'type'     => 'assistant',
            'scenario' => $scenario->code,
            'message'  => $messageText,
            'products' => $selectedProducts,
        ];
    }

    /**
     * Витяг товарів згідно config['product_source'].
     * Тут логіка універсальна, без жорсткої привʼязки до такмеду — все в конфіг.
     */
    protected function fetchProductsBySource(array $source): array
    {
        $type  = $source['type'] ?? 'category_path_contains';
        $value = $source['value'] ?? null;
        $limit = (int)($source['limit'] ?? 50);

        // Ти можеш в ProductService зробити універсальні методи типу:
        // searchByCategoryPathContains, searchByParentId, searchByExpr...
        // Тут я припускаю наявність одного універсального методу searchByExpr.
        if ($type === 'category_path_contains' && $value) {
            return $this->productService->searchByCategoryPathContains($value, $limit);
        }

        if ($type === 'expr' && is_array($value)) {
            // Наприклад: expr з Horoshop (parent.id, display_in_showcase тощо)
            return $this->productService->searchByExpr($value, $limit);
        }

        // Фолбек — порожній список
        return [];
    }
}
