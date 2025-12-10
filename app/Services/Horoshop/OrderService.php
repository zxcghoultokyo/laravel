<?php

namespace App\Services\Horoshop;

use Illuminate\Support\Arr;

class OrderService
{
    public function __construct(
        protected HoroshopClient $client,
    ) {}

    public function getById(int $orderId): ?array
    {
        $response = $this->client->request('orders/get', [
            'ids'            => [$orderId],
            'additionalData' => true,
        ]);

        $orders = $response['orders'] ?? [];

        if (empty($orders)) {
            return null;
        }

        return $orders[0];
    }

    public function normalize(array $raw): array
    {
        $statusCode = (int) ($raw['stat_status'] ?? 0);

        $statusLabelMap = [
            1 => 'новий',
            2 => 'в обробці',
            3 => 'доставлено',
            4 => 'не доставлено',
            6 => 'доставляється',
        ];

        $statusLabel = $statusLabelMap[$statusCode] ?? 'невідомий статус';

        $items = [];

        foreach ($raw['products'] ?? [] as $p) {
            $items[] = [
                'title'             => $p['title'] ?? '',
                'article'           => $p['article'] ?? '',
                'price'             => $p['price'] ?? 0,
                'quantity'          => $p['quantity'] ?? 0,
                'total_price'       => $p['total_price'] ?? ($p['price'] ?? 0) * ($p['quantity'] ?? 0),
                'discount_marker'   => $p['discount_marker'] ?? null,
                'type'              => $p['type'] ?? 'product',
                'storage_id'        => $p['storage_id'] ?? null,
                'parent_storage_id' => $p['parent_storage_id'] ?? null,
            ];
        }

        return [
            'order_id'     => (int) ($raw['order_id'] ?? 0),
            'status_code'  => $statusCode,
            'status_label' => $statusLabel,
            'created_at'   => $raw['stat_created'] ?? null,
            'currency'     => $raw['currency'] ?? null,

            'total' => [
                'total_default'          => $raw['total_default'] ?? 0,
                'total_sum'              => $raw['total_sum'] ?? 0,
                'total_quantity'         => $raw['total_quantity'] ?? 0,
                'discount_value'         => $raw['discount_value'] ?? 0,
                'coupon_code'            => $raw['coupon_code'] ?? '',
                'coupon_discount_value'  => $raw['coupon_discount_value'] ?? 0,
            ],

            'customer' => [
                'name'    => $raw['delivery_name'] ?? '',
                'email'   => $raw['delivery_email'] ?? '',
                'phone'   => $raw['delivery_phone'] ?? '',
                'city'    => $raw['delivery_city'] ?? ($raw['delivery_city_stable'] ?? ''),
                'address' => $raw['delivery_address'] ?? '',
            ],

            'delivery' => [
                'type_id'    => Arr::get($raw, 'delivery_type.id'),
                'type_title' => Arr::get($raw, 'delivery_type.title'),
                'price'      => $raw['delivery_price'] ?? 0,
                'comment'    => $raw['comment'] ?? '',
                'data'       => $raw['delivery_data'] ?? ($raw['additional_data'] ?? []),
            ],

            'payment' => [
                'type_id'    => Arr::get($raw, 'payment_type.id'),
                'type_title' => Arr::get($raw, 'payment_type.title'),
                'price'      => $raw['payment_price'] ?? 0,
                'payed'      => $raw['payed'] ?? 0,
            ],

            'items' => $items,

            '_raw'  => $raw,
        ];
    }
}
