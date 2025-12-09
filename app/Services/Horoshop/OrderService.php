<?php

namespace App\Services\Horoshop;

class OrderService
{
    public function __construct(
        protected HoroshopClient $client
    ) {}

    /**
     * Витягнути одне замовлення по ID (номер замовлення з Хорошопу).
     */
    public function getById(int $orderId): ?array
    {
        $payload = [
            'ids'           => [$orderId],
            'additionalData'=> true,
        ];

        $response = $this->client->call('orders/get', $payload);

        $orders = $response['orders'] ?? [];

        return $orders[0] ?? null;
    }

    /**
     * Перетворити "сире" замовлення з Хорошопа в зручний формат для фронта.
     */
    public function normalize(array $order): array
    {
        $statusCode = (int) ($order['stat_status'] ?? 0);

        return [
            'order_id'   => (int) ($order['order_id'] ?? 0),
            'status_code'=> $statusCode,
            'status_label' => $this->mapStatus($statusCode),
            'created_at' => $order['stat_created'] ?? null,
            'currency'   => $order['currency'] ?? null,

            'total' => [
                'total_default' => $order['total_default'] ?? null,
                'total_sum'     => $order['total_sum'] ?? null,
                'total_quantity'=> $order['total_quantity'] ?? null,
                'discount_value'=> $order['discount_value'] ?? null,
                'coupon_code'   => $order['coupon_code'] ?? null,
            ],

            'customer' => [
                'name'    => $order['delivery_name'] ?? null,
                'email'   => $order['delivery_email'] ?? null,
                'phone'   => $order['delivery_phone'] ?? null,
                'city'    => $order['delivery_city'] ?? ($order['delivery_city_stable'] ?? null),
                'address' => $order['delivery_address'] ?? null,
            ],

            'delivery' => [
                'type_id'    => $order['delivery_type']['id']   ?? null,
                'type_title' => $order['delivery_type']['title']?? null,
                'price'      => $order['delivery_price']        ?? null,
                'comment'    => $order['comment']               ?? null,
                'data'       => $order['delivery_data']         ?? null,
            ],

            'payment' => [
                'type_id'    => $order['payment_type']['id']    ?? null,
                'type_title' => $order['payment_type']['title'] ?? null,
                'price'      => $order['payment_price']         ?? null,
                'payed'      => (int) ($order['payed'] ?? 0),
            ],

            'items' => collect($order['products'] ?? [])
                ->map(function (array $item) {
                    return [
                        'title'         => $item['title']        ?? null,
                        'article'       => $item['article']      ?? null,
                        'price'         => $item['price']        ?? null,
                        'quantity'      => $item['quantity']     ?? null,
                        'total_price'   => $item['total_price']  ?? null,
                        'discount_marker'=> $item['discount_marker'] ?? null,
                        'type'          => $item['type']         ?? null,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * Маппінг числового статусу замовлення у людську назву.
     */
    protected function mapStatus(int $code): string
    {
        return match ($code) {
            1 => 'нове',
            2 => 'в обробці',
            3 => 'доставлено',
            4 => 'не доставлено',
            6 => 'доставляється',
            default => 'невідомий статус',
        };
    }
}
