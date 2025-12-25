<?php

namespace App\Services\Agent\Handlers;

use App\DTO\AgentResponseDTO;
use App\Services\Horoshop\OrderSearchService;
use App\Services\Horoshop\DeliveryTrackingService;
use App\Models\WidgetSettings;
use Illuminate\Support\Facades\Log;

/**
 * Handler for order status intent.
 */
class OrderStatusHandler
{
    public function __construct(
        private OrderSearchService $orderSearchService,
        private DeliveryTrackingService $deliveryTrackingService,
    ) {}

    /**
     * Handle order status request.
     */
    public function handle(string $message, array $plan, array $context): AgentResponseDTO
    {
        // Parse query for order criteria
        $criteria = $this->orderSearchService->parseQuery($message);

        $settings = WidgetSettings::first();
        $supportLine = $this->buildSupportLine($settings);

        // If user is angry/rude, de-escalate politely
        if ($this->isAngry($message)) {
            return AgentResponseDTO::orderStatus(
                "Я бот і хочу допомогти. Напишіть номер замовлення, і я перевірю статус. " . ($supportLine ?: ''),
                [],
                [],
                0
            );
        }

        // If user described a problem, return support contacts immediately
        if ($this->deliveryTrackingService->isProblemReport($message)) {
            $issue = $this->deliveryTrackingService->getIssueResolutionInfo();
            return AgentResponseDTO::orderStatus(
                $issue['message'],
                [],
                [],
                0
            );
        }

        if (empty($criteria)) {
            return AgentResponseDTO::orderStatus(
                "Щоб знайти ваше замовлення, вкажіть будь ласка:\n\n" .
                "• Номер замовлення (наприклад: 12345)\n" .
                "• Номер телефону (наприклад: +380 99 123 45 67)\n" .
                "• Прізвище та ім'я (наприклад: Іваненко Петро)\n\n" .
                "Приклад запиту: \"замовлення 12345\" або \"статус +380991234567\"",
                [],
                [],
                0
            );
        }

        $criteria['limit'] = $plan['order_limit'] ?? 5;

        try {
            $searchResult = $this->orderSearchService->search($criteria);
        } catch (\Throwable $e) {
            Log::warning('OrderStatusHandler: search failed', ['error' => $e->getMessage()]);
            return AgentResponseDTO::orderStatus(
                "Дякую. Ви питаєте про замовлення №" . ($criteria['order_id'] ?? '...') . ". Наразі я ще не маю прямого доступу до статусів. " . ($supportLine ?: ''),
                [],
                $criteria,
                0
            );
        }

        $total = $searchResult['total'] ?? 0;
        $orders = $searchResult['orders'] ?? [];
        $searchType = $searchResult['search_type'] ?? 'none';
        $orderIdText = $criteria['order_id'] ?? null;

        // MVP / no integration scenario
        if ($total === 0 && $searchType === 'none') {
            return AgentResponseDTO::orderStatus(
                "Дякую. Ви питаєте про замовлення №" . ($orderIdText ?? '...') . ". Наразі я ще не маю прямого доступу до статусів замовлень. " . ($supportLine ?: ''),
                [],
                $criteria,
                0
            );
        }

        if ($total === 0) {
            $searchText = $this->buildSearchDescription($criteria);
            return AgentResponseDTO::orderStatus(
                "Не вдалося знайти замовлення за {$searchText}.\n\nПеревірте дані або вкажіть номер замовлення. " . ($supportLine ?: ''),
                [],
                $criteria,
                0
            );
        }

        // Enrich with delivery tracking info
        $orders = array_map(function ($order) {
            $delivery = $this->deliveryTrackingService->formatDeliveryInfo($order);
            $order['delivery_tracking'] = $delivery;
            return $order;
        }, $orders);

        $message = $this->buildSuccessMessage($orders, $supportLine);

        return AgentResponseDTO::orderStatus(
            $message,
            $orders,
            $criteria,
            $total
        );
    }

    /**
     * Build success message for found orders.
     */
    private function buildSuccessMessage(array $orders, string $supportLine): string
    {
        $first = $orders[0];
        $delivery = $first['delivery_tracking'] ?? [];

        $msgParts = [];
        $orderIdOut = $first['id'] ?? '';

        if ($orderIdOut !== '') {
            $msgParts[] = "Замовлення №{$orderIdOut}";
        }

        $statusText = $delivery['status'] ?? null;
        $ttn = $delivery['nova_poshta_ttn'] ?? null;
        $tracking = $delivery['tracking_url'] ?? null;

        if (!empty($ttn)) {
            $msgParts[] = "Замовлення відправлено\nТТН: {$ttn}" . (!empty($tracking) ? "\nВідстежити: {$tracking}" : '');
            $msgParts[] = "Можете відстежити посилку у додатку або на сайті перевізника.";
        } elseif (!empty($statusText)) {
            $msgParts[] = "Статус: {$statusText}";

            $statusLower = mb_strtolower($statusText);
            if (str_contains($statusLower, 'доставлено') || str_contains($statusLower, 'delivered') || str_contains($statusLower, 'отримано')) {
                $msgParts[] = "Дякуємо за покупку!";
            } elseif (str_contains($statusLower, 'не доставлено') || str_contains($statusLower, 'неуспішн')) {
                $msgParts[] = "Схоже, виникли труднощі з доставкою. " . ($supportLine ?: '');
            } else {
                $msgParts[] = "Якщо є питання — звертайтесь. " . ($supportLine ?: '');
            }
        } else {
            $msgParts[] = "Статус доставки зараз недоступний. " . ($supportLine ?: '');
        }

        $msgParts[] = "Якщо треба — можу перевірити інше замовлення. Напишіть номер або телефон.";

        return implode("\n\n", $msgParts);
    }

    /**
     * Build search description for error messages.
     */
    private function buildSearchDescription(array $criteria): string
    {
        $searchedBy = [];

        if (!empty($criteria['order_id'])) {
            $searchedBy[] = "номером №{$criteria['order_id']}";
        }
        if (!empty($criteria['phone'])) {
            $searchedBy[] = "телефоном {$criteria['phone']}";
        }
        if (!empty($criteria['name'])) {
            $searchedBy[] = "ім'ям '{$criteria['name']}'";
        }
        if (!empty($criteria['email'])) {
            $searchedBy[] = "email '{$criteria['email']}'";
        }

        return !empty($searchedBy) ? implode(' та ', $searchedBy) : 'вказаними параметрами';
    }

    /**
     * Build support contact line.
     */
    private function buildSupportLine(?WidgetSettings $settings): string
    {
        $parts = [];

        if (!empty($settings?->shop_phone)) {
            $parts[] = 'Телефон: ' . $settings->shop_phone;
        }
        if (!empty($settings?->callback_form_url)) {
            $parts[] = 'Заявка: ' . $settings->callback_form_url;
        }

        return implode(' | ', $parts);
    }

    /**
     * Check if message contains angry/rude language.
     */
    private function isAngry(string $message): bool
    {
        $keywords = [
            'де моя посилка', 'скільки можна', 'ви знущаєтесь', 'обман', 'шахрай', 'лохотрон',
            'мошен', 'кинули', 'ненормальні', 'дурні', 'погано', 'ненавид', 'гнів', 'злость',
            'дебіл', 'придур', 'туп', 'fuck', 'shit', 'idiot',
        ];

        $m = mb_strtolower($message);

        foreach ($keywords as $kw) {
            if (str_contains($m, $kw)) {
                return true;
            }
        }

        return false;
    }
}
