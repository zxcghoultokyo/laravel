<?php

namespace App\Services\Ai;

use App\Models\Scenario;
use App\Services\Ai\Scenarios\ScenarioHandlerInterface;
use App\Services\FaqService;
use App\Services\Horoshop\ProductService;
use App\Services\Horoshop\OrderService;
use Illuminate\Support\Arr;

class AiSalesAgent
{
    public function __construct(
        protected AiClient $aiClient,
        protected ProductService $productService,
        protected OrderService $orderService,
        protected FaqService $faqService,
    ) {}

    /**
     * Головний метод: одна точка входу для /api/chat.
     */
    public function respond(string $message): array
    {
        // 1) Тягнемо сценарії з БД
        $scenarios = Scenario::query()
            ->where('is_active', true)
            ->get()
            ->map(function (Scenario $s) {
                return [
                    'code'        => $s->code,
                    'name'        => $s->name,
                    'description' => $s->description,
                ];
            })
            ->values()
            ->all();

        // 2) Питаємо ЛЛМ: який сценарій і які параметри?
        $routerPayload = $this->detectScenario($message, $scenarios);

        $scenarioCode = $routerPayload['scenario_code'] ?? null;
        $scenario     = $scenarioCode
            ? Scenario::where('code', $scenarioCode)->where('is_active', true)->first()
            : null;

        // 3) Якщо ЛЛМ сказав сценарій, але в БД немає — фолбек
        if (! $scenario) {
            return $this->fallbackGeneric($message);
        }

        // 4) Якщо у сценарію є handler_class — викликаємо його
        if ($scenario->handler_class) {
            /** @var ScenarioHandlerInterface $handler */
            $handler = app()->make($scenario->handler_class);

            return $handler->handle($message, $scenario, $routerPayload);
        }

        // 5) Якщо handler_class немає — простий універсальний сценарій
        return $this->handleGenericScenario($message, $scenario, $routerPayload);
    }

    /**
     * LLM-детектор сценаріїв.
     *
     * @param string $message
     * @param array  $availableScenarios [{code,name,description}, ...]
     * @return array{
     *   scenario_code: string|null,
     *   semantic_query: string|null,
     *   order_number: string|null,
     *   parameters: array
     * }
     */
    protected function detectScenario(string $message, array $availableScenarios): array
    {
        $system = <<<SYS
Ти — LLM-роутер для чат-ассистента інтернет-магазину спорядження.
У тебе є список можливих сценаріїв (записів з БД), кожен має:
- code — технічний код сценарію;
- name — назва;
- description — опис, коли і для чого цей сценарій використовується.

Твоє завдання:
1. Проаналізувати повідомлення користувача.
2. Обрати НАЙБІЛЬШ ПІДХОДЯЩИЙ сценарій з тих, що тобі передані.
3. Сформувати семантичний пошуковий запит (semantic_query), який підходить для пошуку по товарах/БД.
4. Якщо користувач явно вказав номер замовлення (наприклад "замовлення 55", "order #123") — витягнути це у поле order_number.
5. Додаткові параметри сценарію (parameters) — це довільний JSON (наприклад, { "need_kit_or_single": "unknown" } або { "color": "black" }).

Ти повинен відповісти СУВОРО в форматі JSON:

{
  "scenario_code": "<один з code зі списку scenarios>",
  "semantic_query": "<рядок або null>",
  "order_number": "<рядок або null>",
  "parameters": { ... довільні ключ-значення ... }
}

Жодних пояснень поза JSON.
SYS;

        $user = [
            'message'   => $message,
            'scenarios' => $availableScenarios,
        ];

        $response = $this->aiClient->chatJson($system, $user);

        return [
            'scenario_code'  => $response['scenario_code'] ?? null,
            'semantic_query' => $response['semantic_query'] ?? null,
            'order_number'   => $response['order_number'] ?? null,
            'parameters'     => $response['parameters'] ?? [],
        ];
    }

    /**
     * Універсальний обробник сценарію, якщо немає свого handler_class.
     * Наприклад, продукт-пошук/FAQ/small-talk.
     */
    protected function handleGenericScenario(string $message, Scenario $scenario, array $routerPayload): array
    {
        $code          = $scenario->code;
        $semanticQuery = $routerPayload['semantic_query'] ?? $message;

        // Простий приклад:
        // - якщо сценарій типу "ORDER_STATUS" — шукаємо замовлення
        // - якщо "FAQ" — шукаємо в FAQ
        // - інакше — шукаємо товари
        if (str_starts_with($code, 'ORDER')) {
            $orderNumber = $routerPayload['order_number'] ?? null;

            if (! $orderNumber) {
                return [
                    'type'    => 'assistant',
                    'scenario'=> $code,
                    'message' => 'Вкажи, будь ласка, номер замовлення (наприклад: "замовлення 123").',
                ];
            }

            $order = $this->orderService->getById((int)$orderNumber);
            if (! $order) {
                return [
                    'type'    => 'assistant',
                    'scenario'=> $code,
                    'message' => "Я не знайшов замовлення №{$orderNumber}. Перевір, будь ласка, номер.",
                ];
            }

            // Тут можна оформити красивий текст, але для простоти:
            return [
                'type'    => 'assistant',
                'scenario'=> $code,
                'message' => "Замовлення №{$orderNumber} зараз у статусі: " . ($order['status']['title'] ?? 'обробка') . '.',
                'order'   => $order,
            ];
        }

        if (str_starts_with($code, 'FAQ')) {
            $answer = $this->faqService->match(mb_strtolower($message, 'UTF-8'));
            if ($answer) {
                return [
                    'type'    => 'assistant',
                    'scenario'=> $code,
                    'message' => $answer,
                ];
            }
        }

        // Фолбек: продукт-пошук
        $products = $this->productService->searchByText($semanticQuery, 10);

        return [
            'type'     => 'assistant',
            'scenario' => $code,
            'message'  => 'Ось, що я знайшов під твій запит:',
            'products' => $products,
        ];
    }

    /**
     * Фолбек, якщо сценарій не визначився/відсутній.
     */
    protected function fallbackGeneric(string $message): array
    {
        $products = $this->productService->searchByText($message, 10);

        if (! empty($products)) {
            return [
                'type'     => 'assistant',
                'scenario' => 'GENERIC_PRODUCT_SEARCH',
                'message'  => 'Не впевнений, який саме сценарій підійде, але ось кілька товарів під твій запит:',
                'products' => $products,
            ];
        }

        return [
            'type'     => 'assistant',
            'scenario' => 'UNKNOWN',
            'message'  => 'Я поки не зрозумів, як краще допомогти з цим запитом. Можеш переформулювати або уточнити, що саме шукаєш?',
        ];
    }
}
